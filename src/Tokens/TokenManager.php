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

use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

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

    public function __construct(
        protected ConfigurationRepository $credentials,
        protected HttpFactory $http,
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
     * @since 1.0.0
     *
     * @throws TokenRefreshException
     */
    public function refresh( MicrosoftConnection $connection ): string
    {
        if ( empty( $connection->refresh_token ) ) {
            $connection->markDisconnected( __( 'Missing refresh token.' ) );

            throw new TokenRefreshException( __( 'No refresh token stored for this connection.' ) );
        }

        $clientId = (string) ( $this->credentials->getClientId() ?? '' );
        $scopes   = $connection->grantedScopes();

        if ( '' === $clientId ) {
            throw new TokenRefreshException(
                __( 'Microsoft OAuth is not configured: client_id is missing.' ),
            );
        }

        $body = [
            'client_id'     => $clientId,
            'refresh_token' => (string) $connection->refresh_token,
            'grant_type'    => 'refresh_token',
        ];

        // Microsoft requires the `scope` param on refresh for v2.0; reuse
        // the scopes the connection currently holds so we don't accidentally
        // downgrade the grant.
        if ( [] !== $scopes ) {
            $body[ 'scope' ] = implode( ' ', $scopes );
        }

        $secret = (string) ( $this->credentials->getClientSecret() ?? '' );
        if ( '' !== $secret ) {
            $body[ 'client_secret' ] = $secret;
        }

        $response = $this->http->asForm()->post( $this->tokenEndpoint(), $body );

        if ( ! $response->successful() ) {
            $payload = $response->json();
            $error   = 'refresh_failed';

            if ( is_array( $payload ) ) {
                $error = (string) ( $payload[ 'error' ] ?? 'refresh_failed' );
            }

            if ( in_array( $error, self::TERMINAL_REFRESH_ERRORS, true ) ) {
                $connection->markDisconnected(
                    __( 'Refresh token revoked or expired (:error).', [ 'error' => $error ] ),
                );
            }

            throw new TokenRefreshException(
                __( 'Microsoft token refresh failed: :error', [ 'error' => $error ] ),
            );
        }

        $payload = $response->json();

        if ( ! is_array( $payload ) || empty( $payload[ 'access_token' ] ) ) {
            throw new TokenRefreshException(
                __( 'Microsoft token refresh returned an invalid payload.' ),
            );
        }

        $connection->access_token = (string) $payload[ 'access_token' ];
        $connection->token_type   = (string) ( $payload[ 'token_type' ] ?? 'Bearer' );

        if ( isset( $payload[ 'expires_in' ] ) ) {
            $connection->expires_at = Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] );
        }

        // Microsoft rotates refresh tokens on every refresh — always overwrite
        // when one is returned so we don't keep using a stale token that will
        // eventually be revoked.
        if ( ! empty( $payload[ 'refresh_token' ] ) ) {
            $connection->refresh_token = (string) $payload[ 'refresh_token' ];
        }

        if ( ! empty( $payload[ 'scope' ] ) ) {
            $connection->scopes = array_values( array_filter(
                explode( ' ', (string) $payload[ 'scope' ] ),
                static fn ( string $s ): bool => '' !== $s,
            ) );
        }

        $connection->save();

        return (string) $connection->access_token;
    }

    protected function tokenEndpoint(): string
    {
        $tenant = (string) ( $this->credentials->getTenant() ?? 'common' );
        $tenant = '' === $tenant ? 'common' : $tenant;

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    }
}
