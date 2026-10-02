---
title: Database Driver
---

# `database` Driver

Stores app credentials in the `microsoft_oauth_configurations` table as a single-row singleton. The `client_secret` column is encrypted with Laravel's `Encrypter` (backed by `APP_KEY`) before it's written.

## Enable it

```env
MICROSOFT_OAUTH_DRIVER=database
```

The `MICROSOFT_OAUTH_CLIENT_ID`, `MICROSOFT_OAUTH_CLIENT_SECRET`, and `MICROSOFT_OAUTH_TENANT` env vars are ignored when this driver is active — reads come from the table, not the config.

**The redirect URI can live in either place.** Since 1.1.0 the table has a nullable `redirect_uri` column. When it's set, it's used. When it's `NULL` (or empty), the driver falls back to `MICROSOFT_OAUTH_REDIRECT_URI` / `config('microsoft-oauth.redirect_uri')`, as it did in 1.0. Keep it in config when the redirect URI is tied to the deployment (next to `APP_URL`). Store it in the table when an admin manages the whole credential set.

## Save credentials

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

app( ConfigurationRepository::class )->save( [
    'client_id'     => 'aaaa1111-2222-3333-4444-555566667777',
    'client_secret' => 'THE-VALUE-COLUMN-FROM-CERTIFICATES-AND-SECRETS',
    'tenant'        => 'common',
    'redirect_uri'  => 'https://your-app.test/auth/microsoft/callback', // optional (1.1.0)
] );
```

The keys map to the columns of the same name in `microsoft_oauth_configurations`. `client_id`, `client_secret`, and `tenant` are always written, so omitting one stores `NULL`. Pass all three every time unless you specifically want to null one out.

`redirect_uri` is different. It's only written when the key is present, so callers that don't know about it (1.0 code) leave the stored value alone. Pass `'redirect_uri' => null` (or an empty string) to clear it and fall back to config. Values are trimmed.

`save()` is an atomic upsert on `singleton_key = 'default'`, so concurrent initial saves can't race in a duplicate row and `created_at` is preserved across updates. The per-request cache is invalidated after every `save()` so the next read returns the fresh value.

## Storage shape

```
microsoft_oauth_configurations
├── id                    bigint,   PK
├── singleton_key         string,   unique — always 'default'
├── client_id             string,   nullable
├── client_secret         text,     nullable, encrypted at rest
├── tenant                string,   nullable
├── redirect_uri          string,   nullable — added in 1.1.0; NULL falls back to config
├── created_at            timestamp
└── updated_at            timestamp
```

The `singleton_key` column enforces a one-row constraint. If you need per-tenant credentials in the same database, either put each tenant on its own database connection and swap them per-request, or write a custom driver — see [Drivers](Drivers) → "Writing your own driver".

The `redirect_uri` column is added by the `2026_10_02_000000_add_redirect_uri_to_microsoft_oauth_configurations_table` migration. See [Upgrading](Upgrading).

## Per-request caching

The driver caches the loaded row for the lifetime of one request / job:

```php
protected ?array $cache = null;
```

`save()` invalidates the cache. The container binding is `scoped()` so on Octane / long-lived queue workers the cache is flushed between requests / jobs — a different worker rewriting the row is picked up on the next lifecycle without a manual flush.

## `APP_KEY` rotation

If `APP_KEY` is rotated without re-encrypting the stored secret, decryption fails when the driver loads the row. It logs a warning:

```
artisanpack-ui/microsoft-oauth: failed to decrypt stored client_secret;
treating as unconfigured. Was APP_KEY rotated without re-encrypting the row?
```

…and treats the secret as `null`. `isConfigured()` still returns `true` if `client_id` and `tenant` are set — the secret's presence isn't part of that check — but downstream OAuth calls that need a confidential-client secret will fail on the exchange with `invalid_client`.

To recover: call `save()` again with the client secret. That re-encrypts under the current `APP_KEY`.

Existing `microsoft_connections` rows are a separate concern — their `access_token` and `refresh_token` columns are also encrypted under `APP_KEY`, so every connected user must reconnect after a rotation unless you re-encrypt those columns too.

## When to use it

- Multi-tenant SaaS where each Entra registration is admin-provisioned rather than deployed.
- Any app where an operator needs to rotate credentials without a redeploy.
- Setups where secrets shouldn't live in `.env` files at all.

## When not to use it

- Read-only "immutable-infrastructure" deploys where the config driver's `.env` values already live in your secret store.
- Apps using `artisanpack-ui/cms-framework` — the [`cms` driver](Drivers-CMS) integrates with the framework's Settings UI and is a better fit.

## Testing

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

config( [ 'microsoft-oauth.driver' => 'database' ] );

app( ConfigurationRepository::class )->save( [
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'tenant'        => 'common',
] );

expect( app( ConfigurationRepository::class )->isConfigured() )->toBeTrue();
expect( app( ConfigurationRepository::class )->getClientSecret() )->toBe( 'test-client-secret' );
```

Because `ConfigurationRepository` is `bind()`ed (not `singleton()`), the container re-reads `config('microsoft-oauth.driver')` on each resolve — you can flip drivers mid-test without container-flushing.
