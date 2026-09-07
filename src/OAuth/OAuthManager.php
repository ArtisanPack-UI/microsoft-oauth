<?php

/**
 * Microsoft identity platform OAuth2 authorization-code flow manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Drives the Microsoft identity platform v2.0 authorization-code flow with PKCE.
 *
 * `authorizationUrl()` builds the URL to send the user to, stashing `state`
 * and the PKCE `code_verifier` in the session. `handleCallback()` validates
 * `state`, exchanges the returned `code` for tokens, and persists the
 * connection as a {@see MicrosoftConnection} with encrypted access +
 * refresh tokens (Laravel `Crypt`).
 *
 * The `offline_access` scope is always requested so Microsoft returns a
 * refresh token — a hard requirement for downstream integrations that need
 * long-lived access.
 *
 * @since 1.0.0
 */
class OAuthManager
{
    protected const SESSION_STATE    = 'microsoft_oauth.state';

    protected const SESSION_VERIFIER = 'microsoft_oauth.verifier';

    protected const SESSION_USER_ID  = 'microsoft_oauth.user_id';

    /**
     * Scopes we always request. `offline_access` guarantees a refresh token;
     * `openid`, `profile`, and `email` identify the connecting user via the
     * returned `id_token`. Service packages layer their own scopes on top
     * through the scope registry (issue #4).
     *
     * @var list<string>
     */
    protected array $baselineScopes = [
        'openid',
        'profile',
        'email',
        'offline_access',
    ];

    public function __construct(
        protected ConfigRepository $config,
        protected Session $session,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Build the Microsoft authorization URL for a given user.
     *
     * @since 1.0.0
     *
     * @param  int|string  $userId  The user we're connecting a Microsoft account to.
     * @param  array<int, string>  $additionalScopes  Extra scopes to request alongside the baseline.
     */
    public function authorizationUrl( int|string $userId, array $additionalScopes = [] ): string
    {
        $clientId = $this->requireConfig( 'microsoft-oauth.client_id' );
        $redirect = $this->requireConfig( 'microsoft-oauth.redirect_uri' );

        $state     = Str::random( 40 );
        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge( $verifier );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->put( self::SESSION_VERIFIER, $verifier );
        $this->session->put( self::SESSION_USER_ID, $userId );

        $scopes = $this->mergeScopes( $additionalScopes );

        $params = [
            'client_id'             => $clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $redirect,
            'response_mode'         => 'query',
            'scope'                 => implode( ' ', $scopes ),
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'prompt'                => (string) $this->config->get( 'microsoft-oauth.prompt', 'select_account' ),
        ];

        return $this->authorizeEndpoint() . '?' . http_build_query( $params );
    }

    /**
     * Handle the OAuth callback: verify state, exchange the code, and
     * persist the resulting tokens on a {@see MicrosoftConnection}.
     *
     * Access and refresh tokens are stored encrypted via Laravel's
     * `encrypted` cast on the model. The returned model is either newly
     * created or updated in place for the connecting user, so downstream
     * code can wire the callback directly into service-specific
     * bootstrapping without another DB round-trip.
     *
     * @since 1.0.0
     */
    public function handleCallback( string $code, string $returnedState ): MicrosoftConnection
    {
        $storedState = $this->session->pull( self::SESSION_STATE );
        $verifier    = $this->session->pull( self::SESSION_VERIFIER );
        $userId      = $this->session->pull( self::SESSION_USER_ID );

        if ( empty( $storedState ) || ! hash_equals( (string) $storedState, $returnedState ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }

        if ( empty( $verifier ) ) {
            throw new OAuthException( __( 'PKCE code verifier missing from session.' ) );
        }

        if ( empty( $userId ) ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $body = [
            'client_id'     => $this->requireConfig( 'microsoft-oauth.client_id' ),
            'redirect_uri'  => $this->requireConfig( 'microsoft-oauth.redirect_uri' ),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => (string) $verifier,
            'scope'         => implode( ' ', $this->mergeScopes( [] ) ),
        ];

        // Confidential clients (web apps registered with a secret) send the
        // secret; public clients (SPA / native) rely on PKCE alone.
        $secret = (string) $this->config->get( 'microsoft-oauth.client_secret', '' );
        if ( '' !== $secret ) {
            $body[ 'client_secret' ] = $secret;
        }

        $response = $this->http->asForm()->post( $this->tokenEndpoint(), $body );

        if ( ! $response->successful() ) {
            $payload = $response->json();
            $error   = 'exchange_failed';

            if ( is_array( $payload ) ) {
                $error = (string) ( $payload[ 'error_description' ]
                    ?? $payload[ 'error' ]
                    ?? 'exchange_failed' );
            }

            throw new OAuthException(
                __( 'Microsoft code exchange failed: :error', [ 'error' => $error ] ),
            );
        }

        $payload = $response->json();

        if ( ! is_array( $payload ) || empty( $payload[ 'access_token' ] ) ) {
            throw new OAuthException( __( 'Microsoft code exchange returned an invalid payload.' ) );
        }

        $expiresAt = isset( $payload[ 'expires_in' ] )
            ? Carbon::now()->addSeconds( (int) $payload[ 'expires_in' ] )
            : null;

        $scopes = isset( $payload[ 'scope' ] )
            ? array_values( array_filter( explode( ' ', (string) $payload[ 'scope' ] ) ) )
            : $this->mergeScopes( [] );

        [ $microsoftUserId, $email ] = $this->extractIdentity( $payload[ 'id_token' ] ?? null );

        $connection = MicrosoftConnection::firstOrNew( [ 'user_id' => $userId ] );

        $connection->microsoft_user_id = $microsoftUserId ?? $connection->microsoft_user_id;
        $connection->email             = $email ?? $connection->email;
        $connection->access_token      = (string) $payload[ 'access_token' ];
        $connection->token_type        = (string) ( $payload[ 'token_type' ] ?? 'Bearer' );
        $connection->scopes            = $scopes;
        $connection->expires_at        = $expiresAt;
        $connection->status            = MicrosoftConnection::STATUS_CONNECTED;
        $connection->disconnect_reason = null;

        // Microsoft returns a refresh_token on every successful exchange
        // when `offline_access` is granted (unlike Google, which only issues
        // it on first consent). Still guard against a missing value so an
        // unexpected response can't wipe the existing token on file.
        if ( ! empty( $payload[ 'refresh_token' ] ) ) {
            $connection->refresh_token = (string) $payload[ 'refresh_token' ];
        }

        $connection->save();

        return $connection;
    }

    /**
     * Merge caller-supplied scopes with the baseline, deduplicated.
     *
     * @param  array<int, string>  $additional
     *
     * @return list<string>
     */
    protected function mergeScopes( array $additional ): array
    {
        $merged = array_merge( $this->baselineScopes, array_map( 'strval', $additional ) );
        $merged = array_map( 'trim', $merged );
        $merged = array_filter( $merged, static fn ( string $s ): bool => '' !== $s );

        return array_values( array_unique( $merged ) );
    }

    /**
     * Decode the `oid`/`sub` and `email`/`preferred_username` claims from
     * Microsoft's `id_token`. Identity is only used for persistence, not
     * authorization, so signature verification is not required here — the
     * token came from the TLS-terminated exchange with Microsoft.
     *
     * @since 1.0.0
     *
     * @return array{0: ?string, 1: ?string} [microsoft_user_id, email]
     */
    protected function extractIdentity( ?string $idToken ): array
    {
        if ( empty( $idToken ) ) {
            return [ null, null ];
        }

        $parts = explode( '.', $idToken );
        if ( 3 !== count( $parts ) ) {
            return [ null, null ];
        }

        $payload = base64_decode( strtr( $parts[ 1 ], '-_', '+/' ), true );
        if ( false === $payload ) {
            return [ null, null ];
        }

        $claims = json_decode( $payload, true );
        if ( ! is_array( $claims ) ) {
            return [ null, null ];
        }

        // `oid` is stable across tenants for a work/school account; `sub` is
        // stable per app+user for personal accounts. Prefer `oid` when both
        // are present.
        $userId = null;
        if ( isset( $claims[ 'oid' ] ) ) {
            $userId = (string) $claims[ 'oid' ];
        } elseif ( isset( $claims[ 'sub' ] ) ) {
            $userId = (string) $claims[ 'sub' ];
        }

        $email = null;
        if ( isset( $claims[ 'email' ] ) ) {
            $email = (string) $claims[ 'email' ];
        } elseif ( isset( $claims[ 'preferred_username' ] ) ) {
            $email = (string) $claims[ 'preferred_username' ];
        }

        return [ $userId, $email ];
    }

    protected function authorizeEndpoint(): string
    {
        return $this->buildEndpoint( 'authorize' );
    }

    protected function tokenEndpoint(): string
    {
        return $this->buildEndpoint( 'token' );
    }

    protected function buildEndpoint( string $type ): string
    {
        $tenant = (string) $this->config->get( 'microsoft-oauth.tenant', 'common' );
        $tenant = '' === $tenant ? 'common' : $tenant;

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$type}";
    }

    protected function requireConfig( string $key ): string
    {
        $value = (string) $this->config->get( $key, '' );

        if ( '' === $value ) {
            throw new OAuthException(
                __( 'Microsoft OAuth is not configured: :key is missing.', [ 'key' => $key ] ),
            );
        }

        return $value;
    }

    protected function generateVerifier(): string
    {
        return rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
    }

    protected function generateChallenge( string $verifier ): string
    {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }
}
