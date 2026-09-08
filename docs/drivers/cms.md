---
title: CMS Driver
---

# `cms` Driver

Bridges to the [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) Settings module so Microsoft OAuth credentials live alongside every other site-level setting the CMS manages.

## Enable it

```env
MICROSOFT_OAUTH_DRIVER=cms
```

Requires `artisanpack-ui/cms-framework` to be installed. Selecting `cms` without the framework installed throws at boot:

```
artisanpack-ui/microsoft-oauth: driver=cms requires artisanpack-ui/cms-framework
to be installed. Install the framework or change microsoft-oauth.driver.
```

The failure is loud on purpose — silently falling back to the `config` driver when the operator explicitly asked for `cms` would hide the missing dependency and make credential-persistence bugs impossible to diagnose.

## Setting keys

The driver reads and writes three keys via the CMS framework helpers:

| Constant | Setting key |
|---|---|
| `CmsSettingsDriver::KEY_CLIENT_ID` | `artisanpack_microsoft_oauth_client_id` |
| `CmsSettingsDriver::KEY_CLIENT_SECRET` | `artisanpack_microsoft_oauth_client_secret` |
| `CmsSettingsDriver::KEY_TENANT` | `artisanpack_microsoft_oauth_tenant` |

The service provider registers all three keys via `apRegisterSetting()` during the `app->booted()` phase. `client_secret` gets an encryption sanitize callback so both the driver's `save()` and any operator saving via the CMS Settings UI persist ciphertext.

The `booted()` hook matters: `apRegisterSetting()` is declared from the CMS framework's own `boot()` method, and Laravel's provider boot order is not deterministic. Registering directly from this package's `boot()` risks the CMS framework's helpers not existing yet — the three keys silently wouldn't register and the Settings UI would never expose them. `booted()` guarantees all providers have finished booting first.

## Save credentials programmatically

Either through the framework helpers:

```php
apUpdateSetting( 'artisanpack_microsoft_oauth_client_id', 'aaaa1111-…' );
apUpdateSetting( 'artisanpack_microsoft_oauth_client_secret', 'THE-VALUE-COLUMN' );
apUpdateSetting( 'artisanpack_microsoft_oauth_tenant', 'common' );
```

…or through the `ConfigurationRepository` contract:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

app( ConfigurationRepository::class )->save( [
    'client_id'     => 'aaaa1111-…',
    'client_secret' => 'THE-VALUE-COLUMN',
    'tenant'        => 'common',
] );
```

Both paths write ciphertext for the `client_secret` — the driver hands plaintext through `apUpdateSetting()`, and the sanitize callback registered on the setting key encrypts before persisting.

## Save credentials via the UI

The CMS Settings UI picks up the three registered keys automatically. Operators can edit them there — the sanitize callback ensures the client secret is encrypted before write, regardless of who's saving.

Adding the three keys to a Settings page group is a project-side decision; the driver only registers them, not their UI grouping.

## Encryption

The client secret is stored **ciphertext-only**. The sanitize callback:

```php
$encryptSecret = static function ( mixed $value ) use ( $encrypter, $trim ): ?string {
    $trimmed = $trim( $value );
    if ( null === $trimmed ) {
        return null;
    }
    return $encrypter->encryptString( $trimmed );
};
```

…runs on every `apUpdateSetting()` for the secret key. On read, the driver calls `Encrypter::decryptString()` back to plaintext before returning it via `getClientSecret()`.

## `APP_KEY` rotation

If `APP_KEY` is rotated without re-encrypting the stored secret, `decryptString()` throws. The driver catches, logs a warning, and treats the credential set as unconfigured:

```
artisanpack-ui/microsoft-oauth: failed to decrypt CMS-stored client_secret;
treating as unconfigured. Was APP_KEY rotated without re-encrypting the setting?
```

Unlike the `database` driver, `isConfigured()` here also **returns `false`** when the secret failed to decrypt (even if `client_id` and `tenant` are set). The reason: for CMS-driven projects the whole point of the driver is that credentials come from the Settings UI where operators explicitly save a secret. A silent "well, it works without a secret" would hide that the stored one is corrupt.

Recover by re-saving the secret through the UI or `apUpdateSetting()`.

## Per-request caching

The driver caches values for the lifetime of one request / job (`scoped()` binding). `save()` invalidates the cache. Tests can also call `$driver->flush()` explicitly to clear the cache between assertions.

## When to use it

- Projects using `artisanpack-ui/cms-framework`.
- Setups where non-developers manage credentials via a CMS admin UI.
- Sites that already have a Settings taxonomy and want Microsoft credentials to live there.

## When not to use it

- Any project without the CMS framework installed.
- Setups where credentials should never leave `.env` files (use `config`).
- Pure API apps with no admin UI at all.
