---
title: TenantAuthority
---

# `TenantAuthority` + `TenantMode`

`ArtisanPackUI\MicrosoftOAuth\OAuth\TenantAuthority` — parses and validates the configured `tenant` value and enforces the matching `tid` claim rule on tokens issued by Microsoft.

`ArtisanPackUI\MicrosoftOAuth\OAuth\TenantMode` — the enum categorizing a parsed tenant value into one of the four Microsoft identity platform authority modes.

For narrative coverage — the five tenant modes and the per-mode enforcement rules — see [Tenants](Tenants). This page is the class reference.

## `TenantMode` enum

```php
enum TenantMode: string
{
    case Common        = 'common';
    case Organizations = 'organizations';
    case Consumers     = 'consumers';
    case Tenant        = 'tenant';
}
```

`Common`, `Organizations`, and `Consumers` are the three multi-tenant authorities Microsoft exposes. `Tenant` is the single-tenant mode used when the configured value is a specific tenant GUID or a verified domain (e.g. `contoso.onmicrosoft.com`).

## `TenantAuthority` constants

```php
public const MSA_TENANT_ID = '9188040d-6c67-4c5b-b112-36a304b66dad';
```

The GUID Microsoft assigns to the Microsoft Services Account (MSA) tenant — i.e. personal Microsoft accounts. Used to route consumer accounts on multi-tenant authorities. Documented as stable at [Microsoft's id_token claims reference](https://learn.microsoft.com/entra/identity-platform/id-token-claims-reference).

## Factory

### `static fromConfig( ?string $value ): self`

Parse a configured tenant value into a validated authority.

- `null` and empty values fall back to `common`.
- `common`, `organizations`, `consumers` (case-insensitive) → their respective `TenantMode` cases.
- A tenant GUID (`aaaa1111-2222-3333-4444-555566667777`) → `TenantMode::Tenant` with `expectedTenantId()` returning the GUID.
- A verified domain (`contoso.com`, `contoso.onmicrosoft.com`) → `TenantMode::Tenant` with `expectedTenantId()` returning `null` (the GUID isn't known ahead of time).
- Anything else throws `OAuthException("Invalid Microsoft OAuth tenant \"…\". Use \"common\", \"organizations\", \"consumers\", a tenant GUID, or a verified domain.")`.

**Throws:**

- `OAuthException` — invalid tenant value.

## Instance methods

### `value(): string`

The tenant string used to build Microsoft identity platform URLs. Lower-cased.

```php
"https://login.microsoftonline.com/{$authority->value()}/oauth2/v2.0/authorize"
```

### `mode(): TenantMode`

Which authority mode this tenant resolves to.

### `isMultiTenant(): bool`

Whether this authority accepts tokens from more than one tenant. `common`, `organizations`, and `consumers` all resolve to `true`; only GUID- or domain-bound single-tenant configurations return `false`.

### `expectedTenantId(): ?string`

The expected `tid` claim value when the config specifies a specific tenant GUID. Returns `null` for the three multi-tenant authorities and for verified-domain single-tenant configurations (where the GUID isn't known ahead of time).

### `assertTidMatches( ?string $tid ): void`

Assert that a `tid` claim from an `id_token` matches this authority. Called after the token exchange with the `tid` claim extracted from Microsoft's `id_token`.

**Per-mode rules:**

- `Common` — any `tid` accepted, missing `tid` tolerated.
- `Organizations` — `tid` required, must not equal `MSA_TENANT_ID`.
- `Consumers` — `tid` required, must equal `MSA_TENANT_ID`.
- `Tenant` (GUID) — `tid` required, must equal `expectedTenantId()`.
- `Tenant` (verified domain) — `tid` required; any value accepted.

**Throws:**

- `OAuthException` — `tid` claim does not satisfy the configured authority.

## Value parsing helpers

Both are `protected static` — not part of the public API, but documented here for reference.

### `looksLikeGuid( string $value ): bool`

Whether a string is shaped like a Microsoft tenant GUID (`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`).

### `looksLikeDomain( string $value ): bool`

Whether a string is shaped like a verified domain — a dotted host that uses only host-legal characters and has a plausible TLD. Deliberately permissive: any dotted host up to 253 chars, with label length up to 63 chars and a TLD of at least 2 letters, passes.

## Usage inside the package

You typically don't touch this class directly. `OAuthManager` calls `TenantAuthority::fromConfig()` on every authorize-URL build and every callback `tid` check, so an operator flipping `microsoft-oauth.tenant` mid-request (via the database or CMS driver) is picked up on the next call.

If you need it in your own code:

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\TenantAuthority;
use ArtisanPackUI\MicrosoftOAuth\OAuth\TenantMode;

$authority = TenantAuthority::fromConfig( config( 'microsoft-oauth.tenant' ) );

if ( TenantMode::Tenant === $authority->mode() ) {
    // Single-tenant configuration
    $tenantGuid = $authority->expectedTenantId(); // null for domain configs
}
```
