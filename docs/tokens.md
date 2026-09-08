---
title: Tokens
---

# Tokens

`ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager` is the piece service packages interact with when making Microsoft API calls. It returns a valid access token, refreshing transparently when the current one is close to expiring, and marks the connection disconnected on a terminal refresh error.

## Getting a valid access token

Most callers use one of the higher-level entry points rather than the token manager directly:

- `MicrosoftOAuth::request( $userId )` — returns a bearer-configured `PendingRequest` (facade).
- `app( TokenProvider::class )->accessTokenFor( $userId )` — returns just the token string (contract).

Both eventually call `TokenManager::getValidAccessToken( $connection )`. Use it directly only when you already have the `MicrosoftConnection` on hand:

```php
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Support\Facades\Http;

$connection = MicrosoftConnection::firstWhere( 'user_id', $user->id );
$token      = app( TokenManager::class )->getValidAccessToken( $connection );

$response = Http::withToken( $token )
    ->acceptJson()
    ->get( 'https://graph.microsoft.com/v1.0/me' );
```

`getValidAccessToken()` decides what to return:

1. If the connection isn't connected → throws `TokenRefreshException("Microsoft connection is disconnected.")`.
2. If the current access token is present and not close to expiring → returns it as-is.
3. Otherwise → calls `refresh()` and returns the fresh token.

## Refresh window

`MicrosoftConnection::isExpired()` treats the token as expired **60 seconds before** `expires_at`:

```php
public function isExpired(): bool
{
    if ( null === $this->expires_at ) {
        return true;
    }

    return $this->expires_at->copy()->subSeconds( 60 )->isPast();
}
```

So the manager refreshes proactively — no API call ever ships with a token about to die mid-request.

Missing `expires_at` (which shouldn't happen but Microsoft's response is technically allowed to omit `expires_in`) is treated as expired: the next call triggers a refresh.

## Forced refresh

If you know for some reason the stored token is bad, force a refresh:

```php
app( TokenManager::class )->refresh( $connection );
```

This bypasses the expiry check and always hits the refresh endpoint. Useful in tests or when reacting to a `401 Unauthorized` from Microsoft that the expiry check didn't predict.

## What happens during refresh

`TokenManager::refresh()`:

1. If no refresh token is on file, calls `$connection->markDisconnected('Missing refresh token.')` and throws `TokenRefreshException("No refresh token stored for this connection.")`.
2. If `client_id` is missing (misconfiguration), throws `TokenRefreshException("Microsoft OAuth is not configured: client_id is missing.")` without marking the connection disconnected — the fix is in configuration, not the connection.
3. POSTs to `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`:
   ```
   client_id      = <from config driver>
   refresh_token  = <from connection>
   grant_type     = refresh_token
   scope          = <space-separated grantedScopes()>
   client_secret  = <from config driver, if present>
   ```
   The `scope` parameter is required on Microsoft's v2.0 refresh — sending the connection's current scope set keeps the grant intact rather than accidentally downgrading.
4. On non-success, extracts `error` from the response body. If it's one of the **terminal errors** (see below), marks the connection disconnected. Either way, throws `TokenRefreshException("Microsoft token refresh failed: {error}")`.
5. On success, updates the connection:
    - `access_token`, `token_type` — always.
    - `expires_at` — computed from `expires_in`.
    - `refresh_token` — only if the response included one. **Microsoft rotates refresh tokens on every refresh**, so this path is usually taken; the guard prevents wiping the existing one if a response is malformed.
    - `scopes` — if the response includes them.

Every path either returns a fresh access token or throws — there's no partial-success state.

## Terminal refresh errors

Four Microsoft error codes are treated as **terminal** — the refresh token is dead and re-consent is required:

- `invalid_grant` — token was revoked or expired.
- `interaction_required` — the user needs to complete an interactive step Microsoft can't do silently.
- `consent_required` — a scope requires consent that hasn't been granted.
- `login_required` — the user's Microsoft session expired or was invalidated.

On any of these, the connection is marked disconnected with reason `"Refresh token revoked or expired (:error)."`. All other errors (`invalid_client`, `unauthorized_client`, network failures) throw `TokenRefreshException` but leave the connection connected — the caller can retry after fixing the underlying issue.

## Failure modes

### `Microsoft connection is disconnected.`

The connection's `status` is `'disconnected'` — someone (a prior terminal refresh failure, a user disconnect, an admin) marked it dead. Service packages should either surface a "Reconnect Microsoft" prompt or defer until the user reconnects. Don't retry — a disconnected connection stays disconnected until the user re-runs `/connect`.

### `No refresh token stored for this connection.`

Rare, but possible if:

- The connection was created without `offline_access` in the granted scopes — Microsoft only returns a refresh token when `offline_access` is granted. The baseline includes it, so this only happens if you overrode the registry to remove it.
- The `refresh_token` column was somehow wiped (manual DB edit, encryption failure).

The manager marks the connection disconnected so subsequent calls fail loudly.

### `Microsoft OAuth is not configured: client_id is missing.`

The credential driver returns no `client_id`. The connection is **not** marked disconnected — this is a config problem, not a per-user problem. Fix your driver setup and retry.

### `Microsoft token refresh failed: invalid_grant`

The refresh token is dead. Common causes:

- **User revoked access** at [my Microsoft account permissions](https://account.live.com/consent/Manage) (personal) or via their tenant admin (work / school).
- **Password change / MFA change** that invalidates the underlying refresh token.
- **Tenant admin revoked the app's consent.**
- **Extremely long inactivity** — refresh tokens for Entra apps can eventually expire (typically 90+ days of inactivity, though the exact behavior varies by tenant policy).

The manager marks the connection disconnected with reason `"Refresh token revoked or expired (invalid_grant)."`.

### `Microsoft token refresh failed: interaction_required` / `consent_required` / `login_required`

Terminal errors — treated the same as `invalid_grant`. Marked disconnected, user must reconnect.

## Handling exceptions in service packages

```php
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException;
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

try {
    $response = MicrosoftOAuth::request( $user->id )
        ->acceptJson()
        ->get( 'https://graph.microsoft.com/v1.0/me' );
} catch ( MissingConnectionException $e ) {
    // User has never connected — send them through /connect.
    return redirect()->route( 'microsoft.auth.connect' );
} catch ( TokenRefreshException $e ) {
    // Re-fetch the connection; the manager may have flipped its status.
    $connection = \ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection::firstWhere( 'user_id', $user->id );

    if ( ! $connection?->isConnected() ) {
        return redirect()->route( 'settings.integrations' )
            ->with( 'error', __( 'Please reconnect Microsoft.' ) );
    }

    // Otherwise it's likely a transient error — retry once or bubble up.
    throw $e;
}
```

`MissingConnectionException` extends `OAuthException`, and `TokenRefreshException` is separate — catch them independently so you can render the right prompt.

## Concurrency

`TokenManager` doesn't hold locks. If two workers hit `getValidAccessToken()` for the same connection at the exact same time, both will refresh — one will win the DB write and the other will overwrite it with what it received. In practice this is *mostly* harmless — both access tokens are valid. But because **Microsoft rotates refresh tokens on every refresh**, whichever refresh token got stored last is the only one that will work for the next refresh; the other one is now invalid on Microsoft's side. The next refresh cycle either uses the surviving token (fine) or hits the now-invalidated one (throws `invalid_grant` and disconnects).

If your workload is genuinely concurrent enough for this to bite, wrap the call in `Cache::lock("microsoft-refresh:{$connection->id}")` or serialize refreshes through a queue.

## Testing

Fake the HTTP calls with Laravel's `Http::fake()`:

```php
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://login.microsoftonline.com/*' => Http::response( [
        'access_token'  => 'new-access-token',
        'refresh_token' => 'new-refresh-token',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer',
    ] ),
] );

$connection = MicrosoftConnection::factory()->create( [
    'access_token'  => 'expired',
    'refresh_token' => 'old-refresh-token',
    'expires_at'    => now()->subMinute(),
    'status'        => 'connected',
] );

expect( app( TokenManager::class )->getValidAccessToken( $connection ) )->toBe( 'new-access-token' );
expect( $connection->fresh()->refresh_token )->toBe( 'new-refresh-token' );
```

The manager resolves its HTTP client from `Illuminate\Http\Client\Factory`, which `Http::fake()` swaps in transparently.
