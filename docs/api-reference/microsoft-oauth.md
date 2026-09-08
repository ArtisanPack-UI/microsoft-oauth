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
- `TokenRefreshException` — when a connection exists but its token cannot be refreshed.

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

The class itself takes no constructor arguments — every dependency it needs is resolved lazily from the container inside `request()`.
