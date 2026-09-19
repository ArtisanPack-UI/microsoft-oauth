---
title: ScopeRegistry
---

# `ScopeRegistry`

`ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry` — collects the union of OAuth scopes required by dependent packages.

For narrative coverage, see [Scopes](Scopes). This page documents the public method signatures.

## Constructor

No arguments. Registered as a singleton by the service provider.

## Baseline scopes

Always requested. Not configurable:

```php
protected array $baseline = [
    'openid',
    'profile',
    'email',
    'offline_access',
];
```

`offline_access` is required to receive a refresh token — see [Scopes → The `offline_access` requirement](Scopes#the-offline_access-requirement).

## Methods

### `register( string $scope ): void`

Imperatively register a scope from application code. Prefer the `ap.microsoft.oauth.scopes` filter hook for package-supplied scopes.

```php
app( ScopeRegistry::class )->register( 'https://graph.microsoft.com/Files.Read' );
```

Empty / whitespace-only scopes are dropped. Duplicates are ignored.

### `all(): array`

Return the full de-duplicated union of registered scopes: baseline + imperative + filter-hooked.

```php
$scopes = app( ScopeRegistry::class )->all();
```

**Returns:** `list<string>` — trimmed, non-empty, unique.

Internally:

```php
$filtered = Filter::apply( 'ap.microsoft.oauth.scopes', [] );

if ( ! is_array( $filtered ) ) {
    $filtered = [];
}

$merged = array_merge( $this->baseline, $this->imperative, array_values( $filtered ) );
// trim, filter empties, unique, reindex
```

The filter hook receives `[]` as the initial value and can add / remove scopes. Non-array returns are defensively coerced to `[]`.

### `missing( array $grantedScopes ): array`

Compute the set of required scopes not yet granted by the user.

**Params:**

- `$grantedScopes` — scopes currently granted to the connection. Usually `$connection->grantedScopes()`.

**Returns:** `list<string>` — scopes from `all()` that don't appear in `$grantedScopes`.

```php
$granted = $connection->grantedScopes();
$missing = app( ScopeRegistry::class )->missing( $granted );
// [ 'https://graph.microsoft.com/NewScope.Read' ]
```

### `hasAllRequired( array $grantedScopes ): bool`

Whether every required scope has already been granted.

```php
if ( app( ScopeRegistry::class )->hasAllRequired( $connection->grantedScopes() ) ) {
    // no reauthorize needed
}
```

Equivalent to `[] === $this->missing( $grantedScopes )`.

## Filter hook

The registry listens to one hook:

| Hook | Contract | Purpose |
|---|---|---|
| `ap.microsoft.oauth.scopes` | Filter — receives and returns `array<int, string>` | Contribute scopes to the registry. Fires inside `all()`. |

Register from a service provider's `boot()` method:

```php
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.microsoft.oauth.scopes', function ( array $scopes ): array {
    $scopes[] = 'https://graph.microsoft.com/User.Read';
    return $scopes;
} );
```

Order of callback registration doesn't matter — the union is de-duplicated at the end.

## Container binding

Singleton:

```php
$this->app->singleton( ScopeRegistry::class );
```

Because the imperative-registration list lives on the instance, singleton semantics matter — a scoped binding would forget the registrations between requests.

The filter hook contributions, in contrast, live on the shared filter registry and are unaffected by the container scope.
