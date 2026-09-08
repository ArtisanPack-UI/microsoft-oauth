---
title: Exceptions
---

# Exceptions

The package throws three exception types, all under `ArtisanPackUI\MicrosoftOAuth\Exceptions\`.

## `OAuthException`

`ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException` — extends `RuntimeException`.

Thrown by:

- `OAuthManager::authorizationUrl()` and `incrementalAuthorizationUrl()` — when `client_id` or `redirect_uri` is missing, or the configured tenant isn't a recognized authority form.
- `OAuthManager::handleCallback()` — on state mismatch, missing PKCE verifier, missing user context, failed code exchange, invalid exchange payload, or a `tid`-authority mismatch.
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

Thrown by `TokenManager::getValidAccessToken()` and `TokenManager::refresh()`:

- Connection is disconnected.
- No refresh token on file.
- `client_id` missing.
- Refresh HTTP call fails or returns an invalid payload.
- Any Microsoft `error` on refresh (terminal errors additionally mark the connection disconnected).

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

## `MissingConnectionException`

`ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException` — **extends `OAuthException`** (not `TokenRefreshException`).

Thrown by `DefaultTokenProvider::accessTokenFor()` when the user has no `MicrosoftConnection` on file at all.

Kept distinct so downstream packages can catch it specifically and route the user through the initial connect flow instead of surfacing a generic OAuth error message.

Because it extends `OAuthException`, a broader `catch ( OAuthException $e )` will also catch it — catch `MissingConnectionException` **first** if you want distinct handling.

## Exception hierarchy

```
RuntimeException
├── OAuthException
│   └── MissingConnectionException
└── TokenRefreshException
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
