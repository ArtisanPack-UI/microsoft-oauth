---
title: MicrosoftOAuth
---

# `MicrosoftOAuth`

`ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuth` — the aggregator class exposed by the `microsoft-oauth` container binding, the `microsoft_oauth()` helper, and the `MicrosoftOAuth` facade.

## Purpose

A thin sugar layer over `MicrosoftOAuthManager` so `MicrosoftOAuth::request( $userId )->get( … )` works at call sites where dependency injection would be more ceremony than the call warrants.

Prefer injecting `MicrosoftOAuthManager` (or the `TokenProvider` contract, when only a token is needed) in application code.

## Methods

### `request( int|string $userId ): PendingRequest`

Return a Laravel `PendingRequest` pre-configured with a valid bearer token for the given user's Microsoft connection.

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

$response = MicrosoftOAuth::request( $user->id )
    ->acceptJson()
    ->get( 'https://graph.microsoft.com/v1.0/me' );
```

Under the hood, `request()` resolves `MicrosoftOAuthManager` from the container on every call — this is deliberate. The manager is a `scoped()` binding, so the container can rebuild it between Octane requests / queue jobs when the underlying credential row changes. A cached reference here would pin a stale manager past the scoped rebuild.

**Throws:**

- `MissingConnectionException` — when the user has no `MicrosoftConnection` on file.
- `TokenRefreshException` — when a connection exists but its token cannot be refreshed. In [broker mode](Broker) this can be a `LicenseExpiredException`.

### `client( ?MicrosoftCredentials $credentials = null ): MicrosoftClient`

*Added in 1.1.0.*

Return a stateless [`MicrosoftClient`](API-Reference-Microsoft-Client). With no arguments it uses the configured credential driver. Pass explicit `MicrosoftCredentials` to talk to Microsoft as another app, the way an OAuth broker relays. The client never touches the session or the database.

```php
$client = MicrosoftOAuth::client();
$url    = $client->authorizationUrl( $state, $scopes, [ 'prompt' => 'consent' ], $verifier );
```

See [Stateless Client](Stateless-Client).

### `broker( ?BrokerCredentials $credentials = null ): BrokerClient`

*Added in 1.1.0.*

Return a [`BrokerClient`](API-Reference-Broker-Client) for explicit credentials, or for the configured `microsoft-oauth.broker` values.

**Throws:** `OAuthException` when no credentials are passed and the configured ones are incomplete, or when the broker URL isn't HTTPS (or HTTP on a local development host).

### `usesBroker(): bool`

*Added in 1.1.0.*

Whether `microsoft-oauth.mode` is `broker`. See [Broker Mode](Broker).

## Facade

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

MicrosoftOAuth::request( $userId );
```

Accessor: `'microsoft-oauth'`.

## Helper

```php
microsoft_oauth();  // returns the MicrosoftOAuth instance
microsoft_oauth()->request( $userId );
```

Global function declared in `src/helpers.php`, autoloaded via `composer.json`.

## Container binding

The service provider registers:

```php
$this->app->singleton( 'microsoft-oauth', function ( $app ) {
    return new MicrosoftOAuth();
} );
```

The class itself takes no constructor arguments. Every dependency it needs is resolved lazily from the container inside each method.
