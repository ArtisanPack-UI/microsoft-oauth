<?php

/**
 * Microsoft identity platform tenant authority.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;

/**
 * Parses and validates the configured `tenant` value and enforces the
 * matching `tid` claim rule on tokens issued by Microsoft.
 *
 * Accepted config values (mapped to {@see TenantMode}):
 * - `common`         — any Microsoft account (work, school, personal).
 * - `organizations`  — work / school accounts only.
 * - `consumers`      — personal Microsoft accounts only.
 * - A tenant GUID    — single-tenant, `tid` must match this GUID.
 * - A verified domain (`contoso.onmicrosoft.com`, `contoso.com`) —
 *   single-tenant; the tenant's GUID is not known ahead of time, so `tid`
 *   is captured from the first token but not compared to the config value.
 *
 * @since 1.0.0
 */
class TenantAuthority
{
    /**
     * The GUID Microsoft assigns to the Microsoft Services Account (MSA,
     * i.e. personal Microsoft accounts) tenant. Used to route consumer
     * accounts on multi-tenant authorities. Documented as stable at
     * https://learn.microsoft.com/entra/identity-platform/id-token-claims-reference.
     *
     * @since 1.0.0
     */
    public const MSA_TENANT_ID = '9188040d-6c67-4c5b-b112-36a304b66dad';

    protected function __construct(
        protected string $value,
        protected TenantMode $mode,
        protected ?string $tenantId,
    ) {
    }

    /**
     * Parse a configured tenant value into a validated authority.
     *
     * Null and empty values fall back to `common` — matching the existing
     * config default so that a bare install still boots.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the value is not one of `common`,
     *                        `organizations`, `consumers`, a tenant GUID,
     *                        or a plausible verified domain.
     */
    public static function fromConfig( ?string $value ): self
    {
        $normalized = null === $value ? '' : trim( $value );

        if ( '' === $normalized ) {
            return new self( 'common', TenantMode::Common, null );
        }

        $lower = strtolower( $normalized );

        return match ( true ) {
            'common' === $lower        => new self( 'common', TenantMode::Common, null ),
            'organizations' === $lower => new self( 'organizations', TenantMode::Organizations, null ),
            'consumers' === $lower     => new self( 'consumers', TenantMode::Consumers, null ),
            self::looksLikeGuid( $lower )
                => new self( $lower, TenantMode::Tenant, $lower ),
            self::looksLikeDomain( $lower )
                    => new self( $lower, TenantMode::Tenant, null ),
            default => throw new OAuthException(
                __(
                    'Invalid Microsoft OAuth tenant ":value". Use "common", "organizations", "consumers", a tenant GUID, or a verified domain.',
                    [ 'value' => $normalized ],
                ),
            ),
        };
    }

    /**
     * The tenant string used to build Microsoft identity platform URLs.
     *
     * Reserved authorities are lower-cased; GUIDs and domains are
     * lower-cased as well (Microsoft is case-insensitive for both).
     *
     * @since 1.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Which authority mode this tenant resolves to.
     *
     * @since 1.0.0
     */
    public function mode(): TenantMode
    {
        return $this->mode;
    }

    /**
     * Whether this authority accepts tokens from more than one tenant.
     *
     * `common` / `organizations` / `consumers` all resolve to true;
     * only a GUID- or domain-bound single-tenant configuration is false.
     *
     * @since 1.0.0
     */
    public function isMultiTenant(): bool
    {
        return TenantMode::Tenant !== $this->mode;
    }

    /**
     * The expected `tid` claim value when the config specifies a specific
     * tenant GUID. Null for the three multi-tenant authorities and for
     * verified-domain single-tenant configurations (where the GUID isn't
     * known ahead of time).
     *
     * @since 1.0.0
     */
    public function expectedTenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Assert that a `tid` claim from an id_token matches this authority.
     *
     * Called after the token exchange with the `tid` claim extracted from
     * Microsoft's id_token. The per-mode rules are:
     *
     * - `Common`: any `tid` is accepted, and a missing `tid` is tolerated
     *   — the app has opted in to any tenant Microsoft returns, so we
     *   make no trust decision on the value.
     * - `Organizations`: `tid` is required and must not be the MSA tenant
     *   (personal accounts).
     * - `Consumers`: `tid` is required and must be the MSA tenant.
     * - `Tenant` (GUID): `tid` is required and must equal the configured
     *   GUID.
     * - `Tenant` (verified domain): `tid` is required; the domain-scoped
     *   authority Microsoft resolved has already narrowed to one tenant,
     *   so any tid it returns is accepted, but its absence is still a
     *   correctness failure.
     *
     * @since 1.0.0
     *
     * @throws OAuthException When the `tid` claim does not satisfy the
     *                        configured authority.
     */
    public function assertTidMatches( ?string $tid ): void
    {
        if ( TenantMode::Common === $this->mode ) {
            return;
        }

        $tid = null === $tid ? '' : strtolower( trim( $tid ) );

        if ( '' === $tid ) {
            throw new OAuthException(
                __(
                    'Microsoft id_token is missing the tid claim required to enforce the ":mode" authority.',
                    [ 'mode' => $this->mode->value ],
                ),
            );
        }

        switch ( $this->mode ) {
            case TenantMode::Organizations:
                if ( self::MSA_TENANT_ID === $tid ) {
                    throw new OAuthException(
                        __(
                            'Microsoft account (tid :tid) is a personal account and cannot sign in to an "organizations"-only tenant.',
                            [ 'tid' => $tid ],
                        ),
                    );
                }

                return;

            case TenantMode::Consumers:
                if ( self::MSA_TENANT_ID !== $tid ) {
                    throw new OAuthException(
                        __(
                            'Microsoft account (tid :tid) is a work / school account and cannot sign in to a "consumers"-only tenant.',
                            [ 'tid' => $tid ],
                        ),
                    );
                }

                return;

            case TenantMode::Tenant:
                if ( null !== $this->tenantId && $this->tenantId !== $tid ) {
                    throw new OAuthException(
                        __(
                            'Microsoft token was issued by tenant :actual but this app is registered for tenant :expected.',
                            [ 'actual' => $tid, 'expected' => $this->tenantId ],
                        ),
                    );
                }

                return;
        }
    }

    /**
     * Whether a string is shaped like a Microsoft tenant GUID.
     *
     * @since 1.0.0
     */
    protected static function looksLikeGuid( string $value ): bool
    {
        return 1 === preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $value,
        );
    }

    /**
     * Whether a string is shaped like a verified domain (a dotted host).
     *
     * The identity platform accepts any verified domain associated with
     * a tenant, so the check is deliberately permissive — anything that
     * has at least one dot, uses only host-legal characters, and has a
     * plausible TLD passes.
     *
     * @since 1.0.0
     */
    protected static function looksLikeDomain( string $value ): bool
    {
        return 1 === preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            $value,
        );
    }
}
