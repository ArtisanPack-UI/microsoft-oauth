---
title: OAuthManager
---

# `OAuthManager`

`ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager` — drives the Microsoft identity platform v2.0 authorization-code flow with PKCE.

For narrative coverage, see [OAuth Flow](Oauth). This page documents the public method signatures.

Since 1.1.0 the Microsoft HTTP calls go through the stateless [`MicrosoftClient`](API-Reference-Microsoft-Client), or through [`BrokerClient`](API-Reference-Broker-Client) when `microsoft-oauth.mode` is `broker`. `OAuthManager` adds the session handling and the `MicrosoftConnection` persistence on top.

## Constructor

Container-resolved. All dependencies come from the service provider's `scoped()` binding:

```php
public function __construct(
    protected ConfigurationRepository $credentials,
    protected ConfigRepository $config,
    protected Session $session,
    protected HttpFactory $http,
    protected ScopeRegistry $scopes,
) {}
```

## Methods

### `authorizationUrl( int|string $userId, array $additionalScopes = [] ): string`

Build the Microsoft authorization URL for a given user. Clears any stale incremental-consent flag on the session, then delegates to `buildAuthorizationUrl()`.

The returned URL points at `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize` with the parameters described in [OAuth → Connect](Oauth-Connect). In broker mode it's the signed broker `/authorize` link instead. See [Broker Mode](Broker#connect).

**Params:**

- `$userId` — the application user identifier the resulting `MicrosoftConnection` will belong to. Stored in the session so the callback can associate the tokens back to the right user.
- `$additionalScopes` — extra scopes to request alongside the registry's baseline + hooked scopes. Unioned, not overridden.

**Throws:**

- `OAuthException` — when `client_id` or `redirect_uri` is missing, or the configured tenant isn't a recognized authority form. In broker mode: when the broker credentials are incomplete or insecure, or no return URL can be resolved.

### `incrementalAuthorizationUrl( int|string $userId ): string|IncrementalConsentResult`

Build an incremental-consent authorization URL for a user who already has a `MicrosoftConnection` but is missing scopes required by newly-registered dependent services.

**Returns:**

- A `string` — the URL to redirect to. Requests the full scope union with `prompt=consent`.
- `IncrementalConsentResult::NoConnection` — when the user has no `MicrosoftConnection` at all. Caller should route them through the full connect flow.
- `IncrementalConsentResult::AlreadyAuthorized` — when every registered scope is already granted. Caller should flash a "you're all set" message.

Sets `microsoft_oauth.incremental = true` in the session when a URL is returned. `handleCallback()` reads that flag and unions the returned scopes with previously-recorded ones instead of replacing them.

**Throws:**

- `OAuthException` — same conditions as `authorizationUrl()`.

### `handleCallback( string $code, string $returnedState ): MicrosoftConnection`

Handle the OAuth callback: verify state, exchange the code, and persist the resulting tokens on a `MicrosoftConnection`.

In direct mode the code goes to Microsoft with the session's PKCE verifier and the registry's scopes, and the id_token `tid` is checked against the configured authority. In broker mode the broker's one-time code goes to the broker, which owns the tenant authority and PKCE.

**Params:**

- `$code` — the value of the `code` query parameter Microsoft returned.
- `$returnedState` — the value of the `state` query parameter Microsoft (or the broker) returned. Compared in constant time against the session-stored state via `MicrosoftClient::verifyState()`.

**Returns:** The persisted `MicrosoftConnection` — either newly created or updated in place for the connecting user.

**Session state consumed** (all `pull()`ed — read + deleted):

- `microsoft_oauth.state`
- `microsoft_oauth.verifier`
- `microsoft_oauth.user_id`
- `microsoft_oauth.incremental` (defaults to `false`)

**Throws:**

- `OAuthException` — state mismatch, missing PKCE verifier (direct mode only), missing user context, code-exchange failure, invalid payload, or a `tid`-authority mismatch (direct mode only). `getError()` carries the OAuth error code when there is one.

**Stored scopes:** the scopes reported in the token response win. When none are reported, direct mode falls back to the requested scopes (Microsoft omits `scope` only when it granted everything asked). Broker mode keeps the scopes the connection already held, so a needed reauthorization isn't hidden.

**Concurrent-callback safety:** on a race between two callbacks for the same user, the loser (unique-index violation) catches the duplicate-key error, re-fetches the row that just landed, and re-applies its own token payload on top. Detection is driver-aware — Postgres `23505`, MySQL `23000` + vendor `1062`, SQLite `23000` + `"UNIQUE constraint failed"`.

### `client( ?MicrosoftCredentials $credentials = null ): MicrosoftClient`

*Added in 1.1.0.*

A stateless [`MicrosoftClient`](API-Reference-Microsoft-Client) from explicit credentials, or from the bound `ConfigurationRepository` (with `microsoft-oauth.redirect_uri` as the redirect URI fallback). This is what `MicrosoftOAuth::client()` returns.

### `brokerClient(): BrokerClient`

*Added in 1.1.0.*

A [`BrokerClient`](API-Reference-Broker-Client) from the configured broker credentials. **Throws** `OAuthException` when they're incomplete.

### `usesBroker(): bool`

*Added in 1.1.0.*

Whether `microsoft-oauth.mode` is `broker`.

### `isTrustedRenewUrl( ?string $url ): bool`

*Added in 1.1.0.*

Whether a license `renew_url` from the broker's return is safe to link to. Always `false` outside broker mode or when the broker isn't configured. Otherwise delegates to `BrokerClient::isTrustedRenewUrl()`, which requires the broker's own host and HTTPS. The callback controller uses this before flashing `microsoft.renew_url`. See [Broker Mode → License expiry](Broker#license-expiry).

## Session keys

Prefix: `microsoft_oauth.`.

| Key | Set by | Consumed by |
|---|---|---|
| `state` | `authorizationUrl()` / `incrementalAuthorizationUrl()` | `handleCallback()` |
| `verifier` | `authorizationUrl()` / `incrementalAuthorizationUrl()` (direct mode; cleared in broker mode) | `handleCallback()` |
| `user_id` | `authorizationUrl()` / `incrementalAuthorizationUrl()` | `handleCallback()` |
| `incremental` | `incrementalAuthorizationUrl()` (set true), `authorizationUrl()` (cleared) | `handleCallback()` |

All keys are `pull()`ed on callback so a replay of the same callback URL fails cleanly.

## Endpoint format

The manager builds endpoints from the resolved `TenantAuthority`:

```
https://login.microsoftonline.com/{authority}/oauth2/v2.0/authorize
https://login.microsoftonline.com/{authority}/oauth2/v2.0/token
```

Where `{authority}` is `common` / `organizations` / `consumers` / a tenant GUID / a verified domain, per the configured `microsoft-oauth.tenant`. See [Tenants](Tenants) and [TenantAuthority](API-Reference-Tenant-Authority).

In broker mode the endpoints are the broker's instead: `{broker.url}/api/v1/oauth/microsoft/authorize` and `{broker.url}/api/v1/oauth/token`.

## Distinct from `MicrosoftOAuthManager`

`OAuthManager` (this class) drives the **authorization-code flow** (login / callback / consent) — building URLs, verifying state, exchanging codes, persisting connections.

`MicrosoftOAuthManager` is the **consumer-facing HTTP client wrapper** used *after* a connection is already on file — its only public method is `request( $userId ): PendingRequest`.

Two different jobs, two different classes. See [MicrosoftOAuthManager](API-Reference-Microsoft-Oauth-Manager).
