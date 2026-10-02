---
title: Broker Mode
---

# Broker Mode

*Added in 1.1.0.*

By default the package talks to Microsoft **directly**: your app holds its own Entra `client_id`, `client_secret`, and tenant, and runs the authorization-code flow itself. **Broker mode** sends connect, callback, and refresh through an OAuth broker instead. The broker owns the Microsoft app registration, the client secret, and the tenant authority. Your site only holds three values: the broker URL, its `site_id`, and its site secret.

Use broker mode when you distribute an app (a plugin, a white-label install, a self-hosted product) to sites that shouldn't each have to register their own Entra app, or shouldn't hold a Microsoft client secret at all.

Nothing changes for downstream code. Connections still land in `microsoft_connections`, `MicrosoftOAuth::request( $userId )` still returns a bearer-ready client, and the `connect` / `reauthorize` / `callback` routes keep their names.

## Enable it

```env
MICROSOFT_OAUTH_MODE=broker
MICROSOFT_OAUTH_BROKER_URL=https://broker.example.com
MICROSOFT_OAUTH_BROKER_SITE_ID=your-site-id
MICROSOFT_OAUTH_BROKER_SITE_SECRET=12|your-plain-site-secret
# Optional; defaults to route('microsoft.auth.callback')
MICROSOFT_OAUTH_BROKER_RETURN_URL=https://your-app.test/auth/microsoft/callback
```

Which maps to `config/microsoft-oauth.php`:

```php
'mode'   => env( 'MICROSOFT_OAUTH_MODE', 'direct' ),

'broker' => [
    'url'         => env( 'MICROSOFT_OAUTH_BROKER_URL' ),
    'site_id'     => env( 'MICROSOFT_OAUTH_BROKER_SITE_ID' ),
    'site_secret' => env( 'MICROSOFT_OAUTH_BROKER_SITE_SECRET' ),
    'return_url'  => env( 'MICROSOFT_OAUTH_BROKER_RETURN_URL' ),
],
```

In broker mode the [credential driver](Drivers) values (`client_id`, `client_secret`, `tenant`, `redirect_uri`) are not used for the OAuth flow. You can leave them empty.

### `url` must be HTTPS

The site secret is sent to the broker as a bearer token, so `BrokerCredentials` rejects a broker URL that isn't HTTPS and throws `OAuthException`. Plain HTTP is only accepted for local development hosts:

- `localhost` and `*.localhost`
- `*.test`
- Loopback IPs (`127.x.x.x`, `::1`)

A trailing slash on the URL is trimmed.

### `return_url`

Where the broker sends the browser back after Microsoft consent. It defaults to the package's `microsoft.auth.callback` route, and it must be on the URL the site registered with the broker. Set it explicitly if you don't use the package routes. If it's unset and the callback route isn't registered, building the connect URL throws `OAuthException("Set microsoft-oauth.broker.return_url when the package routes are not registered.")`.

### Supplying credentials at runtime

The three broker values pass through the `ap.microsoft.oauth.broker.credentials` filter before use, so a host such as a CMS can pull them from its own settings store instead of `.env`:

```php
addFilter( 'ap.microsoft.oauth.broker.credentials', function ( array $values ): array {
    return [
        'url'         => apGetSetting( 'my_broker_url' ),
        'site_id'     => apGetSetting( 'my_broker_site_id' ),
        'site_secret' => apGetSetting( 'my_broker_site_secret' ),
    ];
} );
```

The filter receives `[ 'url' => …, 'site_id' => …, 'site_secret' => … ]` from config and must return the same shape. If any of the three ends up empty, the broker counts as unconfigured.

## The flow

```
┌──────────────┐        ┌──────────────────┐        ┌──────────┐        ┌───────────┐
│  Your app    │        │  Package routes  │        │  Broker  │        │ Microsoft │
└──────┬───────┘        └────────┬─────────┘        └────┬─────┘        └─────┬─────┘
       │  GET /connect           │                       │                    │
       │────────────────────────>│                       │                    │
       │                         │  Store state +        │                    │
       │                         │  user_id in session   │                    │
       │                         │  Signed /authorize    │                    │
       │                         │──────────────────────>│  PKCE + consent    │
       │                         │                       │───────────────────>│
       │                         │                       │<───────────────────│
       │                         │  GET /callback?code=…&state=…              │
       │                         │<──────────────────────│                    │
       │                         │  Verify state         │                    │
       │                         │  POST /api/v1/oauth/token (site secret)    │
       │                         │──────────────────────>│                    │
       │                         │<──────────────────────│  TokenResponse     │
       │                         │  Persist connection   │                    │
       │<────────────────────────│  redirect_after_connect                    │
```

### Connect

`OAuthManager::authorizationUrl()` builds a signed link to the broker's `/api/v1/oauth/microsoft/authorize` endpoint with:

| Parameter | Value |
|---|---|
| `site_id` | Your site ID. |
| `state` | A random 40-character string, stored in the session. |
| `return_url` | `broker.return_url`, or the callback route. |
| `expires` | Unix timestamp 5 minutes out (`BrokerClient::LINK_TTL_SECONDS`). The broker accepts at most 10 minutes. |
| `scopes` | Space-separated union from the [scope registry](Scopes). |
| `signature` | HMAC-SHA256 over the parameters (see below). |

The broker runs PKCE and the `prompt` with Microsoft itself, so the package stores only `state` and `user_id` in the session (no PKCE verifier), and the `microsoft-oauth.prompt` setting is not sent.

### Callback

The broker redirects to `return_url` with `?code=…&state=…`. `handleCallback()` checks `state` against the session, then exchanges the broker's **one-time** code at `POST /api/v1/oauth/token` (`grant_type=authorization_code`, `code`), authenticated with the site secret as a bearer token. The broker owns the tenant authority, so there is no `tid` check on this side.

The response is a [`TokenResponse`](API-Reference-Token-Response) that gets persisted on `MicrosoftConnection` just as in direct mode. The broker reports scopes as an array. If it reports none, the connection keeps the scopes it already holds instead of assuming every registered scope was granted, so a needed reauthorization isn't hidden.

### Incremental consent

`/auth/microsoft/reauthorize` works the same way. The signed link carries the full scope union through `scopes=`, and the callback unions the returned scopes with the ones already recorded.

### Refresh

`TokenManager::refresh()` posts to `POST /api/v1/oauth/refresh` with `refresh_token` and `provider=microsoft`, again with the site secret as the bearer token. The broker keeps track of scopes itself, so none are sent. The rotated refresh token is stored as usual.

The same [terminal errors](Tokens#terminal-refresh-errors) (`invalid_grant`, `interaction_required`, `consent_required`, `login_required`) mark the connection disconnected. If the broker is not configured, the refresh throws `TokenRefreshException` with `getError()` = `broker_not_configured` and leaves the connection connected.

## Signing

`/authorize` links are signed with HMAC-SHA256:

1. Take every query parameter except `signature`, sort by key, and encode as an RFC 3986 query string.
2. Prefix it with `microsoft\n`.
3. HMAC that payload with a key equal to `sha256( <plain part of the site secret> )`. The plain part is everything after the `|` in a `{id}|{plain}` secret, or the whole secret when there's no `|`.

`BrokerClient::signature( array $params )` computes it if you need to build or verify a link yourself.

## License expiry

A broker can refuse a refresh with HTTP `402` and `error=license_expired` when the site's license has lapsed beyond its grace period. The token manager throws `LicenseExpiredException`, a subclass of `TokenRefreshException`, and **does not** mark the connection disconnected. The Microsoft grant is still valid, so renewing the license restores refreshes without the user reconnecting.

```php
use ArtisanPackUI\MicrosoftOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

try {
    $response = MicrosoftOAuth::request( $user->id )->get( 'https://graph.microsoft.com/v1.0/me' );
} catch ( LicenseExpiredException $e ) {
    return back()->with( 'error', __( 'Your license has expired.' ) )
        ->with( 'renew_url', $e->getRenewUrl() );
} catch ( TokenRefreshException $e ) {
    // Revoked grant or transient failure — see Tokens.
}
```

Catch `LicenseExpiredException` before `TokenRefreshException`, since the broader catch would also match it.

### `renew_url` on the callback

The broker can also send the browser back to the callback with `?error=…&renew_url=…`. The controller flashes `microsoft.error` as usual, and also flashes `microsoft.renew_url`, but **only** when the URL is safe to show:

- The package is in broker mode.
- The URL's host is the configured broker host.
- It uses HTTPS, or HTTP only when the broker URL itself is HTTP (a local broker). An HTTPS broker never yields an HTTP renew link.
- It has no userinfo (`user@`), backslashes, whitespace, or control characters, which PHP and browsers could parse to different hosts.

Anyone can put a `renew_url` on a callback query string, which is why these checks exist. `OAuthManager::isTrustedRenewUrl( $url )` runs them if you handle the callback yourself.

```blade
@if( $renewUrl = session( 'microsoft.renew_url' ) )
    <a href="{{ $renewUrl }}">{{ __( 'Renew your license' ) }}</a>
@endif
```

## Checking the mode in code

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

if ( MicrosoftOAuth::usesBroker() ) {
    // e.g. hide the Entra credential form from your settings screen
}

$broker = MicrosoftOAuth::broker(); // BrokerClient from the configured credentials
```

`MicrosoftOAuth::broker()` throws `OAuthException("Microsoft OAuth broker credentials are not configured.")` when the configured values are incomplete. Pass a `BrokerCredentials` instance to build a client for other credentials. See [BrokerClient](API-Reference-Broker-Client).

## Building the broker side

The package also includes the pieces for building a broker. A broker relays for many sites with its own Entra app, so it needs OAuth calls that touch neither the session nor the database. See [Stateless Client](Stateless-Client). `TokenResponse::toArray()` renders the exact JSON shape `BrokerClient` expects from `/token` and `/refresh`.

## Testing

Fake the broker endpoints with `Http::fake()`:

```php
use ArtisanPackUI\MicrosoftOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use Illuminate\Support\Facades\Http;

config( [
    'microsoft-oauth.mode'               => 'broker',
    'microsoft-oauth.broker.url'         => 'https://broker.test',
    'microsoft-oauth.broker.site_id'     => 'site-1',
    'microsoft-oauth.broker.site_secret' => '1|plain-secret',
] );

Http::fake( [
    'https://broker.test/api/v1/oauth/refresh' => Http::response( [
        'error'     => 'license_expired',
        'renew_url' => 'https://broker.test/renew',
    ], 402 ),
] );

$connection = MicrosoftConnection::create( [
    'user_id'       => 1,
    'refresh_token' => 'old-refresh-token',
    'expires_at'    => now()->subMinute(),
    'status'        => MicrosoftConnection::STATUS_CONNECTED,
] );

expect( fn () => app( TokenManager::class )->refresh( $connection ) )
    ->toThrow( LicenseExpiredException::class );

expect( $connection->fresh()->isConnected() )->toBeTrue();
```
