<?php

/**
 * Stateless Microsoft identity platform OAuth client.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Stateless OAuth primitives for talking to the Microsoft identity
 * platform v2.0 endpoints directly.
 *
 * Nothing here touches the session or the database: the caller supplies
 * the credentials, `state`, scopes and (optionally) the PKCE verifier, and
 * gets a {@see TokenResponse} back. This is what an OAuth broker relays
 * through, and what {@see OAuthManager} and the token manager wrap to add
 * session handling and persistence.
 *
 * @since 1.1.0
 */
class MicrosoftClient
{
    public const LOGIN_BASE_URL = 'https://login.microsoftonline.com';

    /**
     * Consent-URL parameters the client always sets itself; `$parameters`
     * passed to {@see self::authorizationUrl()} cannot override them.
     *
     * @var list<string>
     */
    public const RESERVED_PARAMETERS = [
        'client_id',
        'redirect_uri',
        'response_type',
        'scope',
        'state',
        'code_challenge',
        'code_challenge_method',
    ];

    /**
     * @since 1.1.0
     *
     * @param  MicrosoftCredentials  $credentials  App credentials to authenticate with.
     * @param  HttpFactory           $http         HTTP client factory.
     */
    public function __construct(
        protected MicrosoftCredentials $credentials,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Build a client with the given (or configured) credentials.
     *
     * When reading from a driver, `config('microsoft-oauth.redirect_uri')`
     * fills in a redirect URI the driver does not supply.
     *
     * @since 1.1.0
     *
     * @param  ConfigurationRepository|MicrosoftCredentials  $credentials  Explicit credentials, or a driver to read them from.
     */
    public static function make(
        MicrosoftCredentials|ConfigurationRepository $credentials,
        HttpFactory $http,
        ConfigRepository $config,
    ): self {
        if ( $credentials instanceof ConfigurationRepository ) {
            $fallback    = $config->get( 'microsoft-oauth.redirect_uri' );
            $credentials = MicrosoftCredentials::fromRepository( $credentials, is_string( $fallback ) ? $fallback : null );
        }

        return new self( $credentials, $http );
    }

    /**
     * Generate a random PKCE code verifier.
     *
     * @since 1.1.0
     */
    public static function generateCodeVerifier(): string
    {
        return rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
    }

    /**
     * Derive the S256 PKCE code challenge for a verifier.
     *
     * @since 1.1.0
     */
    public static function codeChallenge( string $verifier ): string
    {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }

    /**
     * Constant-time check of the `state` echoed back on the callback.
     *
     * @since 1.1.0
     *
     * @param  string|null  $expected  The state the caller generated.
     * @param  string       $returned  The state Microsoft (or the broker) echoed back.
     *
     * @throws OAuthException When the states differ or none was expected.
     */
    public static function verifyState( ?string $expected, string $returned ): void
    {
        if ( null === $expected || '' === $expected || ! hash_equals( $expected, $returned ) ) {
            throw new OAuthException( __( 'OAuth state mismatch; possible CSRF attempt.' ) );
        }
    }

    /**
     * The credentials this client authenticates with.
     *
     * @since 1.1.0
     */
    public function credentials(): MicrosoftCredentials
    {
        return $this->credentials;
    }

    /**
     * The tenant authority the credentials resolve to.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the tenant is not a recognized authority form.
     */
    public function authority(): TenantAuthority
    {
        return TenantAuthority::fromConfig( $this->credentials->tenant );
    }

    /**
     * Build the Microsoft consent URL.
     *
     * Sends `response_type=code` and `response_mode=query`. Pass `prompt`
     * (`select_account`, `consent`, `login`, `none`), `login_hint`,
     * `domain_hint` and the like through `$parameters`; they add to (or, for
     * `response_mode`, override) the defaults. The keys in
     * {@see self::RESERVED_PARAMETERS} are ignored. PKCE is only added when a
     * code verifier is supplied.
     *
     * @since 1.1.0
     *
     * @param  string                 $state         Caller-generated state, echoed back on the callback.
     * @param  array<int, string>     $scopes        Scopes to request. Include `offline_access` to get a refresh token.
     * @param  array<string, string>  $parameters    Extra or overriding query parameters.
     * @param  string|null            $codeVerifier  PKCE verifier; omit to skip PKCE.
     *
     * @throws OAuthException When the client ID, redirect URI or tenant is missing or invalid.
     */
    public function authorizationUrl(
        string $state,
        array $scopes,
        array $parameters = [],
        ?string $codeVerifier = null,
    ): string {
        $params = [
            'client_id'     => $this->requireClientId(),
            'response_type' => 'code',
            'redirect_uri'  => $this->requireRedirectUri(),
            'response_mode' => 'query',
            'scope'         => implode( ' ', $scopes ),
            'state'         => $state,
        ];

        if ( null !== $codeVerifier ) {
            $params['code_challenge']        = self::codeChallenge( $codeVerifier );
            $params['code_challenge_method'] = 'S256';
        }

        // The flow-security parameters always win, so extras forwarded from
        // elsewhere cannot swap the state, client, redirect or PKCE challenge.
        $parameters = array_diff_key( $parameters, array_flip( self::RESERVED_PARAMETERS ) );

        return $this->endpoint( 'authorize' ) . '?' . http_build_query( array_merge( $params, $parameters ) );
    }

    /**
     * Exchange an authorization code for tokens without persisting them.
     *
     * Microsoft wants the requested scopes sent with the code. The id_token
     * `tid` claim is checked against the configured tenant authority, so a
     * `consumers`-only app cannot be handed a work account, and vice versa.
     *
     * @since 1.1.0
     *
     * @param  string              $code          The `code` Microsoft returned to the redirect URI.
     * @param  array<int, string>  $scopes        The scopes requested on the consent URL.
     * @param  string|null         $codeVerifier  The PKCE verifier used to build the consent URL, if any.
     *
     * @throws OAuthException When Microsoft rejects the exchange or the `tid` does not match the authority.
     */
    public function exchangeCode( string $code, array $scopes, ?string $codeVerifier = null ): TokenResponse
    {
        $form = [
            'client_id'    => $this->requireClientId(),
            'redirect_uri' => $this->requireRedirectUri(),
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'scope'        => implode( ' ', $scopes ),
        ];

        if ( null !== $codeVerifier ) {
            $form['code_verifier'] = $codeVerifier;
        }

        $response = $this->http->asForm()->post( $this->endpoint( 'token' ), $this->withSecret( $form ) );

        [ $error, $description ] = $this->errorFrom( $response, 'exchange_failed' );

        if ( 'invalid_payload' === $error ) {
            throw new OAuthException( __( 'Microsoft code exchange returned an invalid payload.' ), $error );
        }

        if ( null !== $error ) {
            throw new OAuthException(
                __( 'Microsoft code exchange failed: :error', [ 'error' => $description ?? $error ] ),
                $error,
            );
        }

        $tokens = TokenResponse::fromMicrosoft( $response->json() );

        $this->authority()->assertTidMatches( $tokens->tenantId );

        return $tokens;
    }

    /**
     * Refresh an access token from a raw refresh-token string.
     *
     * Microsoft rotates refresh tokens, so the returned response normally
     * carries a new one; it carries the one passed in when none came back.
     *
     * @since 1.1.0
     *
     * @param  string              $refreshToken  The refresh token to redeem.
     * @param  array<int, string>  $scopes        Scopes to keep. Microsoft requires `scope` on v2.0 refreshes; send what the grant holds so it is not downgraded.
     *
     * @throws TokenRefreshException When Microsoft rejects the refresh. `getError()` is `invalid_grant` for a revoked grant.
     */
    public function refresh( string $refreshToken, array $scopes = [] ): TokenResponse
    {
        try {
            $clientId = $this->requireClientId();
            $endpoint = $this->endpoint( 'token' );
        } catch ( OAuthException $e ) {
            // Missing client ID, or a tenant that is not a valid authority.
            throw new TokenRefreshException( $e->getMessage(), $e->getError() ?? 'invalid_tenant', null, $e );
        }

        $form = [
            'client_id'     => $clientId,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ];

        if ( [] !== $scopes ) {
            $form['scope'] = implode( ' ', $scopes );
        }

        $response = $this->http->asForm()->post( $endpoint, $this->withSecret( $form ) );

        [ $error ] = $this->errorFrom( $response, 'refresh_failed' );

        if ( 'invalid_payload' === $error ) {
            throw new TokenRefreshException( __( 'Microsoft token refresh returned an invalid payload.' ), $error );
        }

        if ( null !== $error ) {
            throw new TokenRefreshException(
                __( 'Microsoft token refresh failed: :error', [ 'error' => $error ] ),
                $error,
            );
        }

        return TokenResponse::fromMicrosoft( $response->json(), $refreshToken );
    }

    /**
     * Add the client secret for confidential clients. Public clients
     * (SPA / native) rely on PKCE alone and send none.
     *
     * @since 1.1.0
     *
     * @param  array<string, string>  $form
     *
     * @return array<string, string>
     */
    protected function withSecret( array $form ): array
    {
        if ( null !== $this->credentials->clientSecret && '' !== $this->credentials->clientSecret ) {
            $form['client_secret'] = $this->credentials->clientSecret;
        }

        return $form;
    }

    /**
     * Resolve the OAuth error code (and description) for a failed or
     * token-less response.
     *
     * @since 1.1.0
     *
     * @return array{0: ?string, 1: ?string} [error, error_description]; both null for a usable token payload.
     */
    protected function errorFrom( Response $response, string $fallback ): array
    {
        $body = $response->json();
        $body = is_array( $body ) ? $body : null;

        if ( ! $response->successful() ) {
            $error       = is_string( $body['error'] ?? null ) ? $body['error'] : $fallback;
            $description = is_string( $body['error_description'] ?? null ) ? $body['error_description'] : null;

            return [ $error, $description ];
        }

        if ( null === $body || ! is_string( $body['access_token'] ?? null ) || '' === $body['access_token'] ) {
            return [ 'invalid_payload', null ];
        }

        return [ null, null ];
    }

    /**
     * Build a v2.0 endpoint URL for the configured tenant.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the tenant is not a recognized authority form.
     */
    protected function endpoint( string $type ): string
    {
        return self::LOGIN_BASE_URL . "/{$this->authority()->value()}/oauth2/v2.0/{$type}";
    }

    /**
     * @since 1.1.0
     *
     * @throws OAuthException When no client ID is configured.
     */
    protected function requireClientId(): string
    {
        if ( '' === trim( $this->credentials->clientId ) ) {
            throw new OAuthException(
                __( 'Microsoft OAuth is not configured: client_id is missing.' ),
                'invalid_client',
            );
        }

        return $this->credentials->clientId;
    }

    /**
     * @since 1.1.0
     *
     * @throws OAuthException When no redirect URI is configured.
     */
    protected function requireRedirectUri(): string
    {
        if ( null === $this->credentials->redirectUri || '' === trim( $this->credentials->redirectUri ) ) {
            throw new OAuthException(
                __( 'Microsoft OAuth is not configured: :key is missing.', [ 'key' => 'microsoft-oauth.redirect_uri' ] ),
                'invalid_request',
            );
        }

        return $this->credentials->redirectUri;
    }
}
