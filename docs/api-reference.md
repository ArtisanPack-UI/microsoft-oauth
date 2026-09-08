---
title: API Reference
---

# API Reference

The public surface of `artisanpack-ui/microsoft-oauth`.

Sub-pages by class:

- [`MicrosoftOAuth` — the facade / helper root](API-Reference-Microsoft-Oauth)
- [`OAuthManager` — the authorization-code flow](API-Reference-Oauth-Manager)
- [`MicrosoftOAuthManager` — the bearer-ready HTTP client wrapper](API-Reference-Microsoft-Oauth-Manager)
- [`TokenManager`](API-Reference-Token-Manager)
- [`TokenProvider` (contract) + `DefaultTokenProvider`](API-Reference-Token-Provider)
- [`ScopeRegistry`](API-Reference-Scope-Registry)
- [`ConfigurationRepository` (contract + three drivers)](API-Reference-Configuration-Repository)
- [`MicrosoftConnection`](API-Reference-Connection-Model)
- [`TenantAuthority` + `TenantMode`](API-Reference-Tenant-Authority)
- [Exceptions](API-Reference-Exceptions)

## The facade

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

MicrosoftOAuth::request( $userId ); // PendingRequest with bearer token
```

The facade wraps the `MicrosoftOAuth` class, which itself is a thin aggregator over `MicrosoftOAuthManager`. Prefer injecting the `TokenProvider` contract or the `MicrosoftOAuthManager` in downstream packages; the facade exists for call sites where DI is more ceremony than the call warrants.

## The helper

```php
microsoft_oauth();  // same as app( 'microsoft-oauth' )
```

Returns the `ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuth` instance. Equivalent to using the facade — pick whichever style matches your codebase.

## Container bindings

The service provider registers:

| Abstract | Concrete | Scope |
|---|---|---|
| `microsoft-oauth` | `ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuth` | Singleton |
| `ConfigDriver::class` | (self) | Singleton |
| `DatabaseDriver::class` | (self) | **Scoped** |
| `CmsSettingsDriver::class` | (self) | **Scoped** |
| `ConfigurationRepository::class` | `ConfigDriver` / `DatabaseDriver` / `CmsSettingsDriver` (per `microsoft-oauth.driver`) | Bind (re-resolved per lookup) |
| `ScopeRegistry::class` | (self) | Singleton |
| `OAuthManager::class` | (self) | **Scoped** |
| `TokenManager::class` | (self) | **Scoped** |
| `TokenProvider::class` | `DefaultTokenProvider` | **Scoped** |
| `MicrosoftOAuthManager::class` | (self) | **Scoped** |

Rebind `ConfigurationRepository` (or `TokenProvider`) to swap in a custom implementation — the `Google`-style aggregator resolves them fresh on every facade call.

**Why so many `scoped()` bindings?** The credential-loading drivers (`DatabaseDriver`, `CmsSettingsDriver`) hold a per-request row cache. On Octane / long-lived queue workers, a singleton binding would keep serving stale credentials for the lifetime of the worker after another lifecycle rewrote the row. `scoped()` bindings are flushed between requests / jobs, so each lifecycle gets a freshly-loaded cache. The manager classes (`OAuthManager`, `TokenManager`, `TokenProvider`, `MicrosoftOAuthManager`) capture a `ConfigurationRepository` reference in their constructor, so they inherit the same `scoped()`ness by transitivity.

## Namespace map

```
ArtisanPackUI\MicrosoftOAuth\
├── MicrosoftOAuth.php                                — the aggregator
├── MicrosoftOAuthServiceProvider.php
├── helpers.php                                       — microsoft_oauth() function
├── Facades\
│   └── MicrosoftOAuth.php                            — facade
├── Configuration\
│   ├── ConfigDriver.php
│   ├── DatabaseDriver.php
│   └── CmsSettingsDriver.php
├── Contracts\
│   ├── ConfigurationRepository.php
│   └── TokenProvider.php
├── OAuth\
│   ├── OAuthManager.php                              — authorization-code flow
│   ├── MicrosoftOAuthManager.php                     — bearer-ready HTTP client
│   ├── TenantAuthority.php
│   ├── TenantMode.php                                — enum
│   └── IncrementalConsentResult.php                  — enum
├── Scopes\
│   └── ScopeRegistry.php
├── Tokens\
│   ├── TokenManager.php
│   └── DefaultTokenProvider.php
├── Models\
│   └── MicrosoftConnection.php
├── Http\
│   └── Controllers\
│       └── MicrosoftAuthController.php               — the three routes
└── Exceptions\
    ├── OAuthException.php
    ├── TokenRefreshException.php
    └── MissingConnectionException.php                — extends OAuthException
```

## Public routes

Reference: [OAuth Flow → Routes](Oauth#routes)

## Publish tags

| Tag | What it publishes |
|---|---|
| `microsoft-oauth-config` | `config/microsoft-oauth.php` |
| `microsoft-oauth-migrations` | Both migrations to `database/migrations/`. |

## Filter hooks

| Hook | Contract | Purpose |
|---|---|---|
| `ap.microsoft.oauth.scopes` | Filter — receives and returns `array<int, string>` | Contribute scopes to the [registry](Scopes). Fires inside `ScopeRegistry::all()`. |

## Enum types

| Enum | Cases | Purpose |
|---|---|---|
| `TenantMode` | `Common`, `Organizations`, `Consumers`, `Tenant` | Categorizes a parsed tenant value into one of the four authority modes. See [Tenants](Tenants). |
| `IncrementalConsentResult` | `NoConnection`, `AlreadyAuthorized` | Non-URL outcomes of `OAuthManager::incrementalAuthorizationUrl()`. See [OAuth → Reauthorize](Oauth-Reauthorize). |

---
Continue to [Testing](Testing) →
