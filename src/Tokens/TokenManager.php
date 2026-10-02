<?php

/**
 * OAuth2 token manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Tokens;

use ArtisanPackUI\MicrosoftOAuth\Broker\BrokerClient;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Handles token refresh and returns valid access tokens.
 *
 * Callers should always use `getValidAccessToken()` before making
 * Microsoft Graph (or other Microsoft) API calls; the manager checks
 * expiry and refreshes transparently. On refresh failure (Microsoft
 * returned invalid_grant / interaction_required / consent_required,
 * or the request errored) the connection is marked disconnected so
 * downstream code can surface a "reconnect" prompt to the user.
 *
 * Refreshes go to Microsoft through the stateless {@see MicrosoftClient},
 * or through the OAuth broker when `microsoft-oauth.mode` is `broker`.
 *
 * @since 1.0.0
 */
class TokenManager
{
    /**
     * Microsoft error codes that indicate the refresh token is no longer
     * usable and the user must reconnect. Anything else is treated as a
     * transient failure — we throw but leave the connection intact.
     *
     * @var list<string>
     */
    protected const TERMINAL_REFRESH_ERRORS = [
        'invalid_grant',
        'interaction_required',
        'consent_required',
        'login_required',
    ];

    /**
     * @since 1.0.0
     *
     * @param  ConfigurationRepository  $credentials  App credential driver.
     * @param  HttpFactory              $http         HTTP client factory.
     * @param  ConfigRepository|null    $config       Laravel config, for the redirect URI fallback and broker mode. Resolved from the container when omitted.
     */
    public function __construct(
        protected ConfigurationRepository $credentials,
        protected HttpFactory $http,
        protected ?ConfigRepository $config = null,
    ) {
    }

    /**
     * Return a valid access token, refreshing if the current one is expired.
     *
     * @since 1.0.0
     *
     * @throws TokenRefreshException When the connection cannot be refreshed.
     */
    public function getValidAccessToken( MicrosoftConnection $connection ): string
    {
        if ( ! $connection->isConnected() ) {
            throw new TokenRefreshException( __( 'Microsoft connection is disconnected.' ) );
        }

        if ( ! $connection->isExpired() && ! empty( $connection->access_token ) ) {
            return (string) $connection->access_token;
        }

        return $this->refresh( $connection );
    }

    /**
     * Force a refresh regardless of expiry.
     *
     * Goes to Microsoft directly, or through the OAuth broker when
     * `microsoft-oauth.mode` is `broker`.
     *
     * @since 1.0.0
     *
     * @throws LicenseExpiredException When the broker reports the site license has lapsed. The connection stays connected.
     * @throws TokenRefreshException   For any other failure. A revoked grant also marks the connection disconnected.
     */
    public function refresh( MicrosoftConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $refreshToken = (string) $connection->refresh_token;

        try {
            // Microsoft requires the `scope` param on refresh for v2.0; reuse
            // the scopes the connection currently holds so we don't
            // accidentally downgrade the grant. The broker keeps its own.
            $tokens = BrokerClient::isEnabled( $this->config() )
                ? $this->brokerClient()->refresh( $refreshToken )
                : MicrosoftClient::make( $this->credentials, $this->http, $this->config() )
                    ->refresh( $refreshToken, $connection->grantedScopes() );
        } catch ( TokenRefreshException $e ) {
            // Only a revoked or expired grant disconnects. A lapsed broker
            // license (LicenseExpiredException) or a transient failure
            // leaves the connection intact so refreshes can resume.
            if ( in_array( $e->getError(), self::TERMINAL_REFRESH_ERRORS, true ) ) {
                $connection->markDisconnected(
                    __( 'Refresh token revoked or expired (:error).', [ 'error' => $e->getError() ] ),
                );
            }

            throw $e;
        }

        $connection->access_token = $tokens->accessToken;
        $connection->token_type   = $tokens->tokenType;

        if ( null !== $tokens->expiresAt ) {
            $connection->expires_at = $tokens->expiresAt;
        }

        // Microsoft rotates refresh tokens on every refresh — always overwrite
        // when one is returned so we don't keep using a stale token that will
        // eventually be revoked.
        if ( null !== $tokens->refreshToken ) {
            $connection->refresh_token = $tokens->refreshToken;
        }

        if ( [] !== $tokens->scopes ) {
            $connection->scopes = $tokens->scopes;
        }

        $connection->save();

        return (string) $connection->access_token;
    }

    /**
     * Broker client built from the configured broker credentials.
     *
     * @since 1.1.0
     *
     * @throws TokenRefreshException When the broker is not configured.
     */
    protected function brokerClient(): BrokerClient
    {
        try {
            return BrokerClient::fromConfig( $this->config(), $this->http );
        } catch ( OAuthException $e ) {
            throw new TokenRefreshException( $e->getMessage(), 'broker_not_configured', null, $e );
        }
    }

    /**
     * The Laravel config repository.
     *
     * @since 1.1.0
     */
    protected function config(): ConfigRepository
    {
        return $this->config ??= app( 'config' );
    }
}
