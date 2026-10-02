<?php

/**
 * Token exchange / refresh response.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use Illuminate\Support\Carbon;

/**
 * Value object describing a successful code exchange or token refresh.
 *
 * Returned by both {@see MicrosoftClient} (talking to Microsoft directly)
 * and {@see \ArtisanPackUI\MicrosoftOAuth\Broker\BrokerClient} (talking to
 * an OAuth broker). Nothing about it is persisted; callers decide where
 * the tokens go. {@see self::toArray()} renders the broker's wire shape,
 * so a broker can return it as its JSON response verbatim.
 *
 * @since 1.1.0
 */
final class TokenResponse
{
    /**
     * @since 1.1.0
     *
     * @param  string        $accessToken   Short-lived access token.
     * @param  string|null   $refreshToken  Refresh token; Microsoft rotates it, so this is the new one when returned and the previous one otherwise.
     * @param  string        $tokenType     Token type, normally `Bearer`.
     * @param  int|null      $expiresIn     Access-token lifetime in seconds, when reported.
     * @param  Carbon|null   $expiresAt     When the access token expires, derived from `$expiresIn`.
     * @param  list<string>  $scopes        Granted scopes; empty when the provider did not report them.
     * @param  string|null   $idToken       Raw id_token JWT (unverified).
     * @param  string|null   $accountId     Microsoft account ID: the `oid` claim, else `sub`.
     * @param  string|null   $accountEmail  Account email: the `email` claim, else `preferred_username`.
     * @param  string|null   $accountName   Account display name (`name` claim).
     * @param  string|null   $tenantId      Tenant that issued the token (`tid` claim).
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly string $tokenType,
        public readonly ?int $expiresIn,
        public readonly ?Carbon $expiresAt,
        public readonly array $scopes,
        public readonly ?string $idToken,
        public readonly ?string $accountId,
        public readonly ?string $accountEmail,
        public readonly ?string $accountName,
        public readonly ?string $tenantId,
    ) {
    }

    /**
     * Build from a Microsoft token-endpoint payload.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $payload               Decoded JSON from Microsoft's token endpoint. Must contain `access_token`.
     * @param  string|null           $fallbackRefreshToken  Refresh token to keep when Microsoft does not return one.
     */
    public static function fromMicrosoft( array $payload, ?string $fallbackRefreshToken = null ): self
    {
        $scopes = isset( $payload['scope'] ) && is_string( $payload['scope'] ) ? explode( ' ', $payload['scope'] ) : [];

        return self::build( $payload, $scopes, $fallbackRefreshToken );
    }

    /**
     * Build from an OAuth broker `/token` or `/refresh` payload.
     *
     * The broker reports scopes as an array and the account email and name
     * directly; both take precedence over the id_token claims.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $payload               Decoded JSON from the broker. Must contain `access_token`.
     * @param  string|null           $fallbackRefreshToken  Refresh token to keep when the broker does not return one.
     */
    public static function fromBroker( array $payload, ?string $fallbackRefreshToken = null ): self
    {
        $scopes = is_array( $payload['scopes'] ?? null ) ? $payload['scopes'] : [];

        return self::build( $payload, $scopes, $fallbackRefreshToken );
    }

    /**
     * Render in the broker's site-facing response shape.
     *
     * @since 1.1.0
     *
     * @return array{token_type: string, access_token: string, refresh_token: ?string, expires_in: ?int, scopes: list<string>, account_email: ?string, account_name: ?string, id_token: ?string}
     */
    public function toArray(): array
    {
        return [
            'token_type'    => $this->tokenType,
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_in'    => $this->expiresIn,
            'scopes'        => $this->scopes,
            'account_email' => $this->accountEmail,
            'account_name'  => $this->accountName,
            'id_token'      => $this->idToken,
        ];
    }

    /**
     * Shared builder for both payload shapes.
     *
     * @since 1.1.0
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>     $scopes
     */
    private static function build( array $payload, array $scopes, ?string $fallbackRefreshToken ): self
    {
        $expiresIn = isset( $payload['expires_in'] ) && is_numeric( $payload['expires_in'] ) ? (int) $payload['expires_in'] : null;
        $idToken   = self::stringOrNull( $payload['id_token'] ?? null );
        $claims    = self::decodeIdToken( $idToken );

        $scopes = array_map( 'trim', array_map( 'strval', array_filter( $scopes, 'is_scalar' ) ) );
        $scopes = array_values( array_unique( array_filter( $scopes, static fn ( string $s ): bool => '' !== $s ) ) );

        // `oid` is stable across tenants for a work/school account; `sub` is
        // stable per app+user for personal accounts. Prefer `oid`.
        $accountId    = self::stringOrNull( $claims['oid'] ?? null ) ?? self::stringOrNull( $claims['sub'] ?? null );
        $claimedEmail = self::stringOrNull( $claims['email'] ?? null ) ?? self::stringOrNull( $claims['preferred_username'] ?? null );

        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: self::stringOrNull( $payload['refresh_token'] ?? null ) ?? $fallbackRefreshToken,
            tokenType: self::stringOrNull( $payload['token_type'] ?? null ) ?? 'Bearer',
            expiresIn: $expiresIn,
            expiresAt: null === $expiresIn ? null : Carbon::now()->addSeconds( $expiresIn ),
            scopes: $scopes,
            idToken: $idToken,
            accountId: $accountId,
            accountEmail: self::stringOrNull( $payload['account_email'] ?? null ) ?? $claimedEmail,
            accountName: self::stringOrNull( $payload['account_name'] ?? null ) ?? self::stringOrNull( $claims['name'] ?? null ),
            tenantId: self::stringOrNull( $claims['tid'] ?? null ),
        );
    }

    /**
     * Decode the claims from an id_token JWT without verifying its signature.
     *
     * The claims are only used for identity persistence and the tenant
     * check, not authorization, and the token arrived over the
     * TLS-terminated exchange, so signature verification is not required.
     * Returns an empty array when the token is missing or malformed.
     *
     * @since 1.1.0
     *
     * @return array<string, mixed>
     */
    private static function decodeIdToken( ?string $idToken ): array
    {
        if ( null === $idToken ) {
            return [];
        }

        $parts = explode( '.', $idToken );
        if ( 3 !== count( $parts ) ) {
            return [];
        }

        $payload = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
        if ( false === $payload ) {
            return [];
        }

        $claims = json_decode( $payload, true );

        return is_array( $claims ) ? $claims : [];
    }

    /**
     * Normalize a scalar payload value to a non-empty string or null.
     *
     * @since 1.1.0
     */
    private static function stringOrNull( mixed $value ): ?string
    {
        if ( ! is_scalar( $value ) || '' === (string) $value ) {
            return null;
        }

        return (string) $value;
    }
}
