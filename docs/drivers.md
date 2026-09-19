---
title: Credential Drivers
---

# Credential Drivers

`artisanpack-ui/microsoft-oauth` stores **OAuth tokens** (`access_token`, `refresh_token`) in the `microsoft_connections` table, always. The choice of driver only affects **app credentials** — the `client_id`, `client_secret`, and `tenant` used to build the OAuth request itself.

Three drivers ship in the box; pick the one that matches how your project stores secrets:

| Driver | Storage | Writable? | Best for |
|---|---|---|---|
| [`config`](Drivers-Config) (default) | `config/microsoft-oauth.php` / `.env` | No | Single-tenant apps where credentials belong in the deploy pipeline. |
| [`database`](Drivers-Database) | `microsoft_oauth_configurations` table, singleton row (client_secret encrypted) | Yes | Multi-tenant apps, admin-UI-managed credentials, credential rotation without a deploy. |
| [`cms`](Drivers-CMS) | CMS framework Settings module (client_secret encrypted) | Yes | Projects already using `artisanpack-ui/cms-framework` — credentials live alongside every other site-level setting. |

## Selecting a driver

Set the driver via `.env`:

```env
MICROSOFT_OAUTH_DRIVER=database
```

Or in `config/microsoft-oauth.php`:

```php
'driver' => 'database',
```

The service provider re-reads `config('microsoft-oauth.driver')` every time it resolves the `ConfigurationRepository` binding, so runtime overrides work — useful for tests, multi-tenant middleware that swaps drivers per-tenant, etc.

## Why the driver classes are scoped

Both `DatabaseDriver` and `CmsSettingsDriver` hold a per-request row cache so repeated `getClientId()` / `getClientSecret()` / `getTenant()` calls don't hit the database (or the CMS Settings store) three times. That cache is bound with `$this->app->scoped()` rather than `$this->app->singleton()` so:

- On stock Laravel each HTTP request gets a fresh instance and the cache is scoped to the request lifecycle.
- On Octane / long-lived queue workers, the scoped container is flushed between requests / jobs. Without `scoped()`, a singleton driver would keep serving stale credentials for the lifetime of the worker after another lifecycle rewrote the row.

The same reasoning applies to `OAuthManager`, `TokenManager`, and `MicrosoftOAuthManager` — they capture a `ConfigurationRepository` reference in their constructor, so pinning them as singletons would defeat the driver's `scoped()`ness.

## The contract

Every driver implements `ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository`:

```php
interface ConfigurationRepository
{
    public function getClientId(): ?string;
    public function getClientSecret(): ?string;
    public function getTenant(): ?string;
    public function save( array $credentials ): void;
    public function isConfigured(): bool;
}
```

Resolve it from the container:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

$config = app( ConfigurationRepository::class );

if ( $config->isConfigured() ) {
    // safe to build authorize URLs
}
```

`isConfigured()` returns `true` when both `client_id` and `tenant` are non-empty. `client_secret` is intentionally not part of the check — public clients (SPA / native) authenticate with PKCE alone and legitimately have no secret.

## The `client_secret` invariant

For every driver, the stored `client_secret` is either:

- A plaintext value read from `.env` (config driver, ephemeral to the process), or
- A ciphertext value at rest that only decrypts under the current `APP_KEY` (database and CMS drivers).

The `database` and `cms` drivers both handle `APP_KEY` rotation the same way: if the stored ciphertext can't be decrypted, they log a warning and treat the credential set as unconfigured. `isConfigured()` returns `false`, and any attempt to build an authorize URL throws `OAuthException("Microsoft OAuth is not configured: client_id is missing.")` (or a similar message).

Fix by re-saving the credentials via `ConfigurationRepository::save()` under the new `APP_KEY`. Existing `microsoft_connections` rows still need to reconnect because their token columns are also encrypted — see [FAQ → What happens when I rotate `APP_KEY`?](FAQ#credentials).

## Writing your own driver

Drivers are trivially replaceable — implement the five-method contract and rebind the `ConfigurationRepository`:

```php
// app/MicrosoftOAuth/VaultDriver.php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

class VaultDriver implements ConfigurationRepository
{
    // ...
}

// AppServiceProvider::register()
$this->app->bind( ConfigurationRepository::class, VaultDriver::class );
```

Because `MicrosoftOAuthServiceProvider::register()` uses `$this->app->bind()` (not `singleton`), your override wins as long as you register it after the package provider — which is the default order for app providers.

If your custom driver holds a per-request cache, register it as `scoped()` for the same Octane / queue-worker reasons the built-in drivers do.

## Deeper topics

- [`config` driver](Drivers-Config) — env / config-file reader.
- [`database` driver](Drivers-Database) — encrypted, upsert-based singleton row.
- [`cms` driver](Drivers-CMS) — Settings-module integration and sanitize-time encryption.

---
Continue to [OAuth Flow](Oauth) →
