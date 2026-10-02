---
title: Exceptions
---

# Exceptions

The package throws four exception types, all under `ArtisanPackUI\MicrosoftOAuth\Exceptions\`.

## Error codes and renew URLs

*Added in 1.1.0.*

`OAuthException` and `TokenRefreshException` (and so their subclasses) use the `Concerns\CarriesOAuthError` trait. Besides the human-readable message, they carry the machine-readable OAuth error code and, for a lapsed broker license, a renewal URL:

```php
public function __construct(
    string $message = '',
    protected ?string $error = null,     // `invalid_grant`, `license_expired`, …
    protected ?string $renewUrl = null,  // set only for `license_expired`
    ?Throwable $previous = null,
);

public function getError(): ?string;
public function getRenewUrl(): ?string;
```

Branch on `getError()` instead of parsing the message, since the message is translated. Codes the package sets itself:

| Code | Raised by | Meaning |
|---|---|---|
| `invalid_client` | `MicrosoftClient` | No client ID configured. |
| `invalid_request` | `MicrosoftClient` | No redirect URI configured. |
| `invalid_tenant` | `MicrosoftClient::refresh()` | The configured tenant isn't a recognized authority form. |
| `invalid_payload` | `MicrosoftClient` | A 2xx response without an `access_token`. |
| `exchange_failed` / `refresh_failed` | `MicrosoftClient`, `BrokerClient` | The request failed without an `error` code. |
| `license_expired` | `BrokerClient` | The broker refused because the site license lapsed (HTTP `402`). |
| `broker_not_configured` | `TokenManager` | Broker mode is on but the broker credentials are incomplete. |

Anything else is the `error` Microsoft or the broker reported (`invalid_grant`, `interaction_required`, `access_denied`, …). Exceptions thrown for local reasons (state mismatch, missing session context) have no code.

> **1.0 → 1.1:** the second constructor argument is now the error code string, not an integer exception code. See [Upgrading](Upgrading).

## `OAuthException`

`ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException` — extends `RuntimeException`.

Thrown by:

- `OAuthManager::authorizationUrl()` and `incrementalAuthorizationUrl()` — when `client_id` or `redirect_uri` is missing, or the configured tenant isn't a recognized authority form.
- `OAuthManager::handleCallback()` — on state mismatch, missing PKCE verifier (direct mode), missing user context, failed code exchange, invalid exchange payload, or a `tid`-authority mismatch (direct mode).
- `MicrosoftClient::authorizationUrl()` / `exchangeCode()` / `verifyState()` — same conditions, for [stateless](Stateless-Client) callers.
- `BrokerClient::exchangeCode()`, `BrokerClient::fromConfig()`, `BrokerCredentials`, and `MicrosoftOAuth::broker()` — a failed broker exchange, incomplete broker credentials, or a broker URL that isn't HTTPS. See [Broker Mode](Broker).
- `TenantAuthority::fromConfig()` — on an invalid tenant value.
- `TenantAuthority::assertTidMatches()` — on `tid` failing the configured authority's rules.

Catch it broadly:

```php
try {
    $url = app( OAuthManager::class )->authorizationUrl( $userId );
} catch ( OAuthException $e ) {
    return back()->with( 'error', $e->getMessage() );
}
```

## `TokenRefreshException`

`ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException` — extends `RuntimeException`.

**Independent** of `OAuthException` (not a subclass) — thrown from a different phase of the lifecycle.

Thrown by `TokenManager::getValidAccessToken()`, `TokenManager::refresh()`, `MicrosoftClient::refresh()`, and `BrokerClient::refresh()`:

- Connection is disconnected.
- No refresh token on file.
- `client_id` missing (`invalid_client`), or the configured tenant is invalid (`invalid_tenant`).
- Broker mode with incomplete broker credentials (`broker_not_configured`).
- Refresh HTTP call fails or returns an invalid payload.
- Any Microsoft or broker `error` on refresh (terminal errors additionally mark the connection disconnected).

Downstream consumers usually catch it independently from `MissingConnectionException` so they can render different prompts:

```php
try {
    $token = app( TokenProvider::class )->accessTokenFor( $userId );
} catch ( MissingConnectionException $e ) {
    return redirect()->route( 'microsoft.auth.connect' );
} catch ( TokenRefreshException $e ) {
    // Connection existed but refresh failed. Check if it's now disconnected.
    $connection = MicrosoftConnection::firstWhere( 'user_id', $userId );
    if ( ! $connection?->isConnected() ) {
        return redirect()->route( 'settings.integrations' )
            ->with( 'error', 'Please reconnect Microsoft.' );
    }
    throw $e;
}
```

## `LicenseExpiredException`

*Added in 1.1.0.*

`ArtisanPackUI\MicrosoftOAuth\Exceptions\LicenseExpiredException` — **extends `TokenRefreshException`**.

Thrown by `BrokerClient::refresh()` (and so `TokenManager` in [broker mode](Broker)) when the broker answers `402` / `license_expired` because the site's license has lapsed beyond its grace period. `getError()` is `license_expired` and `getRenewUrl()` carries the broker's renewal URL.

Unlike a revoked grant, the Microsoft connection is still valid, so the connection is **not** marked disconnected. Renewing the license restores refreshes without the user reconnecting. Catch it **before** `TokenRefreshException`:

```php
try {
    $token = app( TokenProvider::class )->accessTokenFor( $userId );
} catch ( LicenseExpiredException $e ) {
    return back()->with( 'renew_url', $e->getRenewUrl() );
} catch ( TokenRefreshException $e ) {
    // …
}
```

## `MissingConnectionException`

`ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException` — **extends `OAuthException`** (not `TokenRefreshException`).

Thrown by `DefaultTokenProvider::accessTokenFor()` when the user has no `MicrosoftConnection` on file at all.

Kept distinct so downstream packages can catch it specifically and route the user through the initial connect flow instead of surfacing a generic OAuth error message.

Because it extends `OAuthException`, a broader `catch ( OAuthException $e )` will also catch it — catch `MissingConnectionException` **first** if you want distinct handling.

## Exception hierarchy

```
RuntimeException
├── OAuthException                 (uses CarriesOAuthError)
│   └── MissingConnectionException
└── TokenRefreshException          (uses CarriesOAuthError)
    └── LicenseExpiredException
```

## Localization

Every message the package throws is wrapped in `__()` so it can be translated. Register translations under the `microsoft-oauth` translation namespace or override the strings in your app's `lang/vendor/microsoft-oauth/` directory.

Example message keys:

- `"OAuth state mismatch; possible CSRF attempt."`
- `"PKCE code verifier missing from session."`
- `"Microsoft OAuth is not configured: client_id is missing."`
- `"Microsoft OAuth is not configured: :key is missing."`
- `"Microsoft code exchange failed: :error"`
- `"Microsoft token refresh failed: :error"`
- `"Microsoft connection is disconnected."`
- `"No Microsoft connection on file for user :user."`
- `"Refresh token revoked or expired (:error)."`
- `"Invalid Microsoft OAuth tenant \":value\". Use \"common\", \"organizations\", \"consumers\", a tenant GUID, or a verified domain."`
- `"Microsoft account (tid :tid) is a personal account and cannot sign in to an \"organizations\"-only tenant."`
- `"Microsoft account (tid :tid) is a work / school account and cannot sign in to a \"consumers\"-only tenant."`
- `"Microsoft token was issued by tenant :actual but this app is registered for tenant :expected."`
- `"Microsoft id_token is missing the tid claim required to enforce the \":mode\" authority."`
- `"Microsoft code exchange returned an invalid payload."`
- `"Microsoft token refresh returned an invalid payload."`
- `"Microsoft OAuth broker credentials are not configured."`
- `"The Microsoft OAuth broker URL must use HTTPS; plain HTTP is only allowed for local development hosts."`
- `"The Microsoft connection cannot be refreshed because the site license has expired."`
- `"Set microsoft-oauth.broker.return_url when the package routes are not registered."`
