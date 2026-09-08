---
title: TokenManager
---

# `TokenManager`

`ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager` — handles token refresh and returns valid access tokens.

For narrative coverage, see [Tokens](Tokens). This page documents the public method signatures.

## Constructor

Container-resolved, `scoped()` binding:

```php
public function __construct(
    protected ConfigurationRepository $credentials,
    protected HttpFactory $http,
) {}
```

## Methods

### `getValidAccessToken( MicrosoftConnection $connection ): string`

Return a valid access token, refreshing if the current one is expired.

Decision tree:

1. Connection disconnected → throws `TokenRefreshException("Microsoft connection is disconnected.")`.
2. Access token present and not expired (with 60s safety window) → returns as-is.
3. Otherwise → calls `refresh()` and returns the fresh token.

**Params:**

- `$connection` — the `MicrosoftConnection` to get a valid token for.

**Throws:**

- `TokenRefreshException` — connection disconnected, or refresh fails.

### `refresh( MicrosoftConnection $connection ): string`

Force a refresh regardless of expiry. Use in tests or when reacting to a `401 Unauthorized` from Microsoft that the expiry check didn't predict.

Under the hood:

1. No refresh token → mark connection disconnected (`"Missing refresh token."`), throw `TokenRefreshException`.
2. Missing `client_id` → throw `TokenRefreshException` (connection **not** marked disconnected — this is a config issue).
3. POST to `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with `grant_type=refresh_token`, `client_id`, `refresh_token`, `scope`, and (for confidential clients) `client_secret`.
4. On non-2xx:
    - If the error is one of the terminal errors (`invalid_grant`, `interaction_required`, `consent_required`, `login_required`) → mark disconnected (`"Refresh token revoked or expired (:error)."`).
    - Throw `TokenRefreshException("Microsoft token refresh failed: :error")`.
5. On success → update `access_token`, `token_type`, `expires_at`, and (when returned) `refresh_token` and `scopes`. Save the connection. Return the fresh access token.

**Params:**

- `$connection` — the `MicrosoftConnection` to refresh.

**Throws:**

- `TokenRefreshException` — refresh failed for any reason.

## Terminal errors

```php
protected const TERMINAL_REFRESH_ERRORS = [
    'invalid_grant',
    'interaction_required',
    'consent_required',
    'login_required',
];
```

These are the Microsoft error codes that indicate the refresh token is no longer usable and the user must reconnect. Anything else is treated as a transient failure — the manager throws but leaves the connection intact.

## Refresh-token rotation

Microsoft rotates the refresh token on every successful refresh. The manager always overwrites the stored `refresh_token` when a new one is returned:

```php
if ( ! empty( $payload[ 'refresh_token' ] ) ) {
    $connection->refresh_token = (string) $payload[ 'refresh_token' ];
}
```

The guard against an empty value is defensive — Microsoft normally always returns one on refresh, but the check prevents a malformed response from wiping the stored token.

## Endpoint

```
https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
```

Where `{tenant}` is `microsoft-oauth.tenant` (defaults to `common`). The refresh endpoint doesn't strictly need the tenant-scoped authority since the refresh token itself is tenant-bound, but sending the configured tenant matches the initial exchange and avoids any silent-fallback behavior.

## Distinct from `DefaultTokenProvider`

`TokenManager` (this class) works on a `MicrosoftConnection` — it doesn't know how to find one from a user id.

`DefaultTokenProvider` is the container binding for the `TokenProvider` contract; its `accessTokenFor( $userId )` looks up the connection and hands it to `TokenManager::getValidAccessToken()`. Downstream packages should type-hint the contract, not this class.

See [TokenProvider](API-Reference-Token-Provider).
