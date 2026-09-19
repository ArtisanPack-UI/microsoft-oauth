---
title: TokenProvider
---

# `TokenProvider`

`ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider` — the contract downstream packages depend on to obtain a valid Microsoft OAuth access token for a given user, without knowing anything about the OAuth flow, token refresh mechanics, or the underlying connection model.

## Contract

```php
namespace ArtisanPackUI\MicrosoftOAuth\Contracts;

interface TokenProvider
{
    public function accessTokenFor( int|string $userId ): string;
}
```

Implementations **must**:

- Look up the `MicrosoftConnection` for the given user id.
- Refresh the token if the stored one is expired.
- Return a non-empty bearer token suitable for use verbatim in an `Authorization: Bearer …` header.

**Throws:**

- `MissingConnectionException` — user has no `MicrosoftConnection` on file. Caller should route them through the initial connect flow.
- `TokenRefreshException` — a connection exists but its token cannot be refreshed into a usable value.

## Why a contract?

So downstream packages don't hard-depend on the concrete `DefaultTokenProvider`. Test suites can bind a stub that returns fixture tokens without touching the token-refresh HTTP call. Hosts can swap in a custom implementation that draws tokens from somewhere entirely different (a shared token cache, a Kubernetes secret, an in-house vault) without touching consumer code.

## Default binding: `DefaultTokenProvider`

`ArtisanPackUI\MicrosoftOAuth\Tokens\DefaultTokenProvider`.

```php
class DefaultTokenProvider implements TokenProvider
{
    public function __construct( protected TokenManager $tokens ) {}

    public function accessTokenFor( int|string $userId ): string
    {
        $connection = MicrosoftConnection::query()
            ->where( 'user_id', $userId )
            ->first();

        if ( ! $connection instanceof MicrosoftConnection ) {
            throw new MissingConnectionException( __(
                'No Microsoft connection on file for user :user.',
                [ 'user' => (string) $userId ],
            ) );
        }

        return $this->tokens->getValidAccessToken( $connection );
    }
}
```

Registered as a `scoped()` binding so it inherits the same lifecycle behavior as `TokenManager` (see [Drivers](Drivers#why-the-driver-classes-are-scoped) for the reasoning).

## Type-hinting the contract

In downstream service packages:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;

class BingPlacesService
{
    public function __construct( protected TokenProvider $tokens ) {}

    public function fetchBusinesses( int $userId ): array
    {
        return Http::withToken( $this->tokens->accessTokenFor( $userId ) )
            ->acceptJson()
            ->get( 'https://businessapi.microsoftinternal.com/v1.0/businesses' )
            ->json();
    }
}
```

## Swapping the implementation

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;

// AppServiceProvider::register()
$this->app->bind( TokenProvider::class, function ( $app ) {
    return new CachedTokenProvider(
        $app->make( \ArtisanPackUI\MicrosoftOAuth\Tokens\DefaultTokenProvider::class ),
        $app->make( 'cache.store' ),
    );
} );
```

Because the package's binding uses `$this->app->scoped()` and your bind runs after the package's `register()`, your override wins.

## Testing

Swap the contract with a closure in your test's `setUp()`:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;

$this->app->bind( TokenProvider::class, function () {
    return new class implements TokenProvider {
        public function accessTokenFor( int|string $userId ): string
        {
            return 'test-token';
        }
    };
} );
```

Consumer packages type-hinting `TokenProvider` will get the stub and never hit the real HTTP refresh machinery.
