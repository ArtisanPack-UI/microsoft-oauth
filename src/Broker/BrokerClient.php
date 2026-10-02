<?php

/**
 * OAuth broker client.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Broker;

use ArtisanPackUI\MicrosoftOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\OAuth\TokenResponse;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Runs the connect, exchange and refresh steps through an OAuth broker.
 *
 * Implements the site-facing side of the broker contract: signed
 * `/authorize` links, the one-time code exchange at `/token`, and
 * refreshes at `/refresh`. The broker holds the Microsoft app
 * credentials (client ID, client secret and tenant authority); this site
 * only holds its site secret.
 *
 * @since 1.1.0
 */
class BrokerClient
{
    public const PROVIDER = 'microsoft';

    /**
     * How long a signed `/authorize` link stays valid. The broker accepts
     * at most 10 minutes; 5 leaves room for clock skew.
     */
    public const LINK_TTL_SECONDS = 300;

    /**
     * @since 1.1.0
     *
     * @param  BrokerCredentials  $credentials  Broker URL, site ID and site secret.
     * @param  HttpFactory        $http         HTTP client factory.
     */
    public function __construct(
        protected BrokerCredentials $credentials,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Whether `config/microsoft-oauth.php` puts the package in broker client mode.
     *
     * @since 1.1.0
     */
    public static function isEnabled( ConfigRepository $config ): bool
    {
        return 'broker' === $config->get( 'microsoft-oauth.mode', 'direct' );
    }

    /**
     * Build a client from the configured broker credentials.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the broker URL, site ID or site secret is missing.
     */
    public static function fromConfig( ConfigRepository $config, HttpFactory $http ): self
    {
        $credentials = BrokerCredentials::fromConfig( $config );

        if ( null === $credentials ) {
            throw new OAuthException( __( 'Microsoft OAuth broker credentials are not configured.' ) );
        }

        return new self( $credentials, $http );
    }

    /**
     * The credentials this client authenticates with.
     *
     * @since 1.1.0
     */
    public function credentials(): BrokerCredentials
    {
        return $this->credentials;
    }

    /**
     * Build the signed broker `/authorize` URL to redirect the browser to.
     *
     * @since 1.1.0
     *
     * @param  string                   $state      Site-generated state, echoed back on return.
     * @param  string                   $returnUrl  Where the broker sends the browser back; must be on the site's registered URL.
     * @param  array<int, string>|null  $scopes     Scopes to request; null asks for every scope the broker allows.
     */
    public function authorizationUrl( string $state, string $returnUrl, ?array $scopes = null ): string
    {
        $params = [
            'site_id'    => $this->credentials->siteId,
            'state'      => $state,
            'return_url' => $returnUrl,
            'expires'    => (string) ( Carbon::now()->getTimestamp() + self::LINK_TTL_SECONDS ),
        ];

        if ( null !== $scopes && [] !== $scopes ) {
            $params['scopes'] = implode( ' ', $scopes );
        }

        $params['signature'] = $this->signature( $params );

        return $this->endpoint( '/oauth/' . self::PROVIDER . '/authorize' )
            . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Compute the HMAC-SHA256 signature for a set of `/authorize` parameters.
     *
     * @since 1.1.0
     *
     * @param  array<string, string>  $params  Query parameters, excluding `signature`.
     */
    public function signature( array $params ): string
    {
        unset( $params['signature'] );
        ksort( $params );

        $payload = self::PROVIDER . "\n" . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

        return hash_hmac( 'sha256', $payload, $this->credentials->signingKey() );
    }

    /**
     * Exchange the broker's one-time code for tokens.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the broker rejects the exchange.
     */
    public function exchangeCode( string $code ): TokenResponse
    {
        $response = $this->post( '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code'       => $code,
        ] );

        [ $error, $renewUrl ] = $this->errorFrom( $response, 'exchange_failed' );

        if ( null !== $error ) {
            throw new OAuthException(
                __( 'Microsoft code exchange failed: :error', [ 'error' => $error ] ),
                $error,
                $renewUrl,
            );
        }

        return TokenResponse::fromBroker( $response->json() );
    }

    /**
     * Refresh an access token through the broker.
     *
     * @since 1.1.0
     *
     * @param  string  $refreshToken  The stored refresh token. Microsoft rotates it; the response carries the new one, or this one when the broker returns none.
     *
     * @throws LicenseExpiredException When the site's license has lapsed (HTTP 402).
     * @throws TokenRefreshException   For any other failure. `getError()` is `invalid_grant` for a revoked grant.
     */
    public function refresh( string $refreshToken ): TokenResponse
    {
        $response = $this->post( '/oauth/refresh', [
            'refresh_token' => $refreshToken,
            'provider'      => self::PROVIDER,
        ] );

        [ $error, $renewUrl ] = $this->errorFrom( $response, 'refresh_failed' );

        if ( 'license_expired' === $error ) {
            throw new LicenseExpiredException(
                __( 'The Microsoft connection cannot be refreshed because the site license has expired.' ),
                $error,
                $renewUrl,
            );
        }

        if ( null !== $error ) {
            throw new TokenRefreshException(
                __( 'Microsoft token refresh failed: :error', [ 'error' => $error ] ),
                $error,
            );
        }

        return TokenResponse::fromBroker( $response->json(), $refreshToken );
    }

    /**
     * Whether a `renew_url` points at the broker's own host.
     *
     * A `renew_url` arriving on the callback query string is attacker
     * controllable, so it is only surfaced when it is on the configured
     * broker host and uses HTTPS (or the broker's own scheme, for a local
     * HTTP broker) — never a downgrade from an HTTPS broker.
     *
     * @since 1.1.0
     */
    public function isTrustedRenewUrl( ?string $url ): bool
    {
        if ( null === $url || '' === $url ) {
            return false;
        }

        $scheme        = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
        $host          = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        $brokerScheme  = strtolower( (string) parse_url( $this->credentials->url, PHP_URL_SCHEME ) );
        $brokerHost    = strtolower( (string) parse_url( $this->credentials->url, PHP_URL_HOST ) );
        $allowedScheme = 'https' === $scheme || ( 'http' === $scheme && 'http' === $brokerScheme );

        return $allowedScheme && '' !== $host && $host === $brokerHost;
    }

    /**
     * POST a form to a broker API endpoint, authenticated with the site secret.
     *
     * @since 1.1.0
     *
     * @param  array<string, string>  $form
     */
    protected function post( string $path, array $form ): Response
    {
        return $this->http
            ->asForm()
            ->acceptJson()
            ->withToken( $this->credentials->siteSecret )
            ->post( $this->endpoint( $path ), $form );
    }

    /**
     * Build a broker API URL.
     *
     * @since 1.1.0
     */
    protected function endpoint( string $path ): string
    {
        return $this->credentials->url . '/api/v1' . $path;
    }

    /**
     * Resolve the error code and renew URL for a failed (or token-less) response.
     *
     * @since 1.1.0
     *
     * @return array{0: ?string, 1: ?string} [error, renew_url]; both null on success.
     */
    protected function errorFrom( Response $response, string $fallback ): array
    {
        $body = $response->json();
        $body = is_array( $body ) ? $body : [];

        if ( $response->successful() ) {
            return is_string( $body['access_token'] ?? null ) && '' !== $body['access_token'] ? [ null, null ] : [ $fallback, null ];
        }

        $error = is_string( $body['error'] ?? null ) ? $body['error'] : null;

        if ( 402 === $response->status() ) {
            $error ??= 'license_expired';
        }

        $renewUrl = is_string( $body['renew_url'] ?? null ) ? $body['renew_url'] : null;

        return [ $error ?? $fallback, $renewUrl ];
    }
}
