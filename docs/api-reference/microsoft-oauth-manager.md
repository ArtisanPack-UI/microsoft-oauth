---
title: MicrosoftOAuthManager
---

# `MicrosoftOAuthManager`

`ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftOAuthManager` — the consumer-facing entry point for downstream ArtisanPack UI Microsoft integrations.

## Purpose

Wraps token acquisition and the Laravel HTTP client so service packages never touch OAuth internals. Its `request()` method hands back a Laravel `PendingRequest` that already carries a valid `Authorization: Bearer …` header for the given user's Microsoft connection, so the caller only has to chain the actual API call.

Distinct from `OAuthManager`, which drives the authorization-code flow (login / callback / consent) — this manager is only for calling Microsoft APIs after a connection is already on file.

## Constructor

Container-resolved, `scoped()` binding:

```php
public function __construct(
    protected TokenProvider $tokens,
    protected HttpFactory $http,
) {}
```

Depends on the `TokenProvider` contract rather than `TokenManager` directly, so tests and downstream code can bind a stub without touching the token-refresh machinery.

## Methods

### `request( int|string $userId ): PendingRequest`

Return a Laravel `PendingRequest` pre-configured with a valid bearer token for the given user's Microsoft connection. The returned client is otherwise unconfigured — callers add their own base URL, headers, timeout, retry policy, etc. by chaining onto it, then call the terminal HTTP method (`get()`, `post()`, `patch()`, …) to fire the request.

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftOAuthManager;

$response = app( MicrosoftOAuthManager::class )->request( $user->id )
    ->acceptJson()
    ->timeout( 10 )
    ->get( 'https://graph.microsoft.com/v1.0/me' );

$profile = $response->json();
```

**Params:**

- `$userId` — the application user id whose Microsoft connection the token is drawn from.

**Throws:**

- `MissingConnectionException` — when the user has no `MicrosoftConnection` on file.
- `TokenRefreshException` — when a connection exists but its token cannot be refreshed. See [Tokens → Terminal refresh errors](Tokens#terminal-refresh-errors).

## Usage in service packages

Prefer injecting the manager (or the underlying `TokenProvider` contract) rather than reaching for the facade:

```php
namespace ArtisanPackUI\BingPlaces;

use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftOAuthManager;

class BingPlacesClient
{
    public function __construct( protected MicrosoftOAuthManager $microsoft )
    {
    }

    public function fetchBusinesses( int $userId ): array
    {
        return $this->microsoft->request( $userId )
            ->baseUrl( 'https://businessapi.microsoftinternal.com/' )
            ->acceptJson()
            ->get( 'v1.0/businesses' )
            ->json();
    }
}
```

## Distinct from `OAuthManager`

`OAuthManager` handles the **authorization-code flow**. `MicrosoftOAuthManager` handles **authenticated API calls after** the connection exists.

Two classes, two responsibilities. See [OAuthManager](API-Reference-Oauth-Manager).
