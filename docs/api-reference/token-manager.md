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
    protected ?ConfigRepository $config = null,
) {}
```

`$config` (added in 1.1.0) is the Laravel config repository, used for the redirect URI fallback and to detect [broker mode](Broker). It's resolved from the container when omitted, so 1.0-style construction keeps working.

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
2. Redeem the refresh token:
    - **Direct mode:** `MicrosoftClient::refresh( $refreshToken, $connection->grantedScopes() )`, which POSTs to `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with `grant_type=refresh_token`, `client_id`, `refresh_token`, `scope`, and (for confidential clients) `client_secret`. A missing `client_id` (`invalid_client`) or an invalid tenant (`invalid_tenant`) throws before any request, and the connection is **not** marked disconnected, since this is a config issue.
    - **Broker mode:** `BrokerClient::refresh( $refreshToken )`, which POSTs to the broker's `/api/v1/oauth/refresh`. Incomplete broker credentials throw with `getError()` = `broker_not_configured`, connection left connected.
3. On failure:
    - If `getError()` is one of the terminal errors (`invalid_grant`, `interaction_required`, `consent_required`, `login_required`) → mark disconnected (`"Refresh token revoked or expired (:error)."`).
    - Rethrow the `TokenRefreshException` (or `LicenseExpiredException`).
4. On success → update `access_token`, `token_type`, and (when returned) `expires_at`, `refresh_token`, and `scopes`. Save the connection. Return the fresh access token.

**Params:**

- `$connection` — the `MicrosoftConnection` to refresh.

**Throws:**

- `LicenseExpiredException` — broker mode only: the site license has lapsed. The connection stays connected. See [Broker Mode](Broker#license-expiry).
- `TokenRefreshException` — refresh failed for any other reason. `getError()` carries the error code.

## Terminal errors

```php
protected const TERMINAL_REFRESH_ERRORS = [
    'invalid_grant',
    'interaction_required',
    'consent_required',
    'login_required',
];
```

These are the Microsoft error codes that indicate the refresh token is no longer usable and the user must reconnect. Anything else (including `license_expired`) is treated as a non-terminal failure — the manager throws but leaves the connection intact.

## Refresh-token rotation

Microsoft rotates the refresh token on every successful refresh. The manager always overwrites the stored `refresh_token` when a new one is returned:

```php
if ( null !== $tokens->refreshToken ) {
    $connection->refresh_token = $tokens->refreshToken;
}
```

`TokenResponse::refreshToken` falls back to the token that was redeemed when the response carries none, so a malformed response can't wipe the stored token.

## Endpoint

```
https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
```

Where `{tenant}` is the resolved authority for `microsoft-oauth.tenant` (defaults to `common`). The refresh endpoint doesn't strictly need the tenant-scoped authority since the refresh token itself is tenant-bound, but sending the configured tenant matches the initial exchange and avoids any silent-fallback behavior. Since 1.1.0 the tenant is validated first, the same way the authorization flow does it.

In broker mode the endpoint is `{broker.url}/api/v1/oauth/refresh`.

## Distinct from `DefaultTokenProvider`

`TokenManager` (this class) works on a `MicrosoftConnection` — it doesn't know how to find one from a user id.

`DefaultTokenProvider` is the container binding for the `TokenProvider` contract; its `accessTokenFor( $userId )` looks up the connection and hands it to `TokenManager::getValidAccessToken()`. Downstream packages should type-hint the contract, not this class.

See [TokenProvider](API-Reference-Token-Provider).
