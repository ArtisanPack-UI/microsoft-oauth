---
title: ConfigurationRepository
---

# `ConfigurationRepository`

`ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository` — the contract for app credential storage drivers.

For narrative coverage, see [Credential Drivers](Drivers). This page documents the contract and lists the three built-in implementations.

## Contract

```php
namespace ArtisanPackUI\MicrosoftOAuth\Contracts;

interface ConfigurationRepository
{
    public function getClientId(): ?string;
    public function getClientSecret(): ?string;
    public function getTenant(): ?string;
    public function save( array $credentials ): void;
    public function isConfigured(): bool;
}
```

### `getClientId(): ?string`

The OAuth **Application (client) ID** from the Entra app registration.

### `getClientSecret(): ?string`

The OAuth client secret. Public clients (SPA / native) may return `null` and rely on PKCE alone; confidential clients (web apps) should always provide a secret.

### `getTenant(): ?string`

The Microsoft tenant identifier — one of `common`, `organizations`, `consumers`, a tenant GUID, or a verified domain. Defaults to `common` when nothing is configured.

### `save( array $credentials ): void`

Persist a full credential set. Keys: `client_id`, `client_secret`, `tenant`.

Drivers that are read-only (like the `config` driver) may throw `RuntimeException`.

### `isConfigured(): bool`

Whether the repository has a usable credential set.

A `client_id` and a `tenant` are the minimum; `client_secret` is required only for confidential clients, so its presence is not part of this check.

**Exception:** the `cms` driver additionally returns `false` when the stored client secret failed to decrypt (`APP_KEY` rotated) — see [CMS Driver](Drivers-CMS#app_key-rotation).

## Container binding

Bound (not singleton) so `config('microsoft-oauth.driver')` is re-read on each resolve:

```php
$this->app->bind( ConfigurationRepository::class, function ( Application $app ): ConfigurationRepository {
    $driver = $app[ 'config' ]->get( 'microsoft-oauth.driver', 'config' );

    if ( 'cms' === $driver && ! function_exists( 'apGetSetting' ) ) {
        throw new RuntimeException(
            'artisanpack-ui/microsoft-oauth: driver=cms requires artisanpack-ui/cms-framework to be installed. …',
        );
    }

    return match ( $driver ) {
        'database' => $app->make( DatabaseDriver::class ),
        'cms'      => $app->make( CmsSettingsDriver::class ),
        default    => $app->make( ConfigDriver::class ),
    };
} );
```

The concrete driver classes are `scoped()` (except `ConfigDriver`, which is a singleton — it holds no per-request state).

## Built-in implementations

- [`ConfigDriver`](Drivers-Config) — reads `.env` / `config/microsoft-oauth.php`. Read-only.
- [`DatabaseDriver`](Drivers-Database) — reads and writes `microsoft_oauth_configurations` singleton row. Client secret encrypted at rest.
- [`CmsSettingsDriver`](Drivers-CMS) — reads and writes CMS framework Settings. Client secret encrypted at rest. Requires `artisanpack-ui/cms-framework`.

## Writing your own driver

Implement the five-method contract and re-bind the contract:

```php
namespace App\MicrosoftOAuth;

use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

class VaultDriver implements ConfigurationRepository
{
    public function __construct( protected VaultClient $vault ) {}

    public function getClientId(): ?string     { /* … */ }
    public function getClientSecret(): ?string { /* … */ }
    public function getTenant(): ?string       { /* … */ }
    public function save( array $credentials ): void { /* … */ }
    public function isConfigured(): bool       { /* … */ }
}
```

```php
// AppServiceProvider::register()
$this->app->bind( ConfigurationRepository::class, VaultDriver::class );
```

If your driver holds a per-request cache, register it as `scoped()` for the same Octane / queue-worker reasons the built-in drivers do — see [Drivers → Why the driver classes are scoped](Drivers#why-the-driver-classes-are-scoped).
