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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

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
 * Microsoft rotates the refresh token on every refresh, so two refreshes
 * racing with the same token can't both win. Refreshes for a connection
 * run one at a time behind a cache lock, and one that waited on another
 * uses the tokens the other stored instead of refreshing again.
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
     * The broker's error for a refresh token another request rotated a
     * moment ago. The grant is still good, so it never disconnects.
     *
     * @since 1.2.0
     */
    protected const SUPERSEDED_REFRESH_ERROR = 'refresh_superseded';

    /**
     * How long a refresh lock is held at most, in seconds.
     *
     * @since 1.2.0
     */
    protected const REFRESH_LOCK_SECONDS = 30;

    /**
     * How long a refresh waits for another one on the same connection to
     * finish, in seconds.
     *
     * @since 1.2.0
     */
    protected const REFRESH_LOCK_WAIT_SECONDS = 10;

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
     * Runs behind a per-connection cache lock. When another refresh replaced
     * the token while this one waited, its stored access token is returned
     * without refreshing again.
     * @since 1.2.0 Refreshes run behind a lock, and the broker's `refresh_superseded` never disconnects.
     *
     * @throws LicenseExpiredException When the broker reports the site license has lapsed. The connection stays connected.
     * @throws TokenRefreshException   For any other failure. A revoked grant also marks the connection disconnected; `refresh_superseded` and `refresh_in_progress` never do.
     */
    public function refresh( MicrosoftConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $sentToken = (string) $connection->refresh_token;
        $lock      = Cache::lock( 'microsoft-oauth:refresh:' . $connection->getKey(), self::REFRESH_LOCK_SECONDS );

        try {
            $lock->block( self::REFRESH_LOCK_WAIT_SECONDS );
        } catch ( LockTimeoutException $e ) {
            throw new TokenRefreshException( __( 'Another refresh of this Microsoft connection is still running.' ), 'refresh_in_progress', null, $e );
        }

        try {
            if ( $connection->exists ) {
                $connection->refresh();
            }

            $refreshed = $this->tokenRefreshedElsewhere( $connection, $sentToken );

            if ( null !== $refreshed ) {
                return $refreshed;
            }

            if ( empty( $connection->refresh_token ) ) {
                throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
            }

            return $this->refreshWith( $connection, (string) $connection->refresh_token );
        } finally {
            $lock->release();
        }
    }

    /**
     * Refresh the connection with its refresh token and store the result.
     *
     * @since 1.2.0
     *
     * @throws LicenseExpiredException When the broker reports the site license has lapsed.
     * @throws TokenRefreshException   For any other failure.
     */
    protected function refreshWith( MicrosoftConnection $connection, string $refreshToken ): string
    {
        try {
            // Microsoft requires the `scope` param on refresh for v2.0; reuse
            // the scopes the connection currently holds so we don't
            // accidentally downgrade the grant. The broker keeps its own.
            $tokens = BrokerClient::isEnabled( $this->config() )
                ? $this->brokerClient()->refresh( $refreshToken )
                : MicrosoftClient::make( $this->credentials, $this->http, $this->config() )
                    ->refresh( $refreshToken, $connection->grantedScopes() );
        } catch ( TokenRefreshException $e ) {
            // Another request (on a server this lock doesn't reach) rotated
            // the token first; use what it stored if it has landed.
            if ( self::SUPERSEDED_REFRESH_ERROR === $e->getError() ) {
                if ( $connection->exists ) {
                    $connection->refresh();
                }

                return $this->tokenRefreshedElsewhere( $connection, $refreshToken ) ?? throw $e;
            }

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
     * The connection's current access token when another refresh replaced
     * the refresh token that was sent and stored a token that is still
     * valid, or null.
     *
     * @since 1.2.0
     */
    protected function tokenRefreshedElsewhere( MicrosoftConnection $connection, string $sentToken ): ?string
    {
        $replaced = ! empty( $connection->refresh_token ) && (string) $connection->refresh_token !== $sentToken;

        return $replaced && $connection->isConnected() && ! $connection->isExpired() && ! empty( $connection->access_token )
            ? (string) $connection->access_token
            : null;
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
