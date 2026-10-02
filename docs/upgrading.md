---
title: Upgrading
---

# Upgrading

## From 1.0 to 1.1

1.1 is a backward-compatible minor release. Default `direct`-mode behavior is unchanged, and code written against 1.0 keeps working.

### 1. Update the package

```bash
composer update artisanpack-ui/microsoft-oauth
```

### 2. Run the new migration

1.1 adds a nullable `redirect_uri` column to `microsoft_oauth_configurations` so the [database driver](Drivers-Database) can store its own redirect URI.

The package loads its migrations automatically, so `php artisan migrate` picks it up. If you published the migrations in 1.0, publish again to copy the new file over:

```bash
php artisan vendor:publish --tag=microsoft-oauth-migrations
php artisan migrate
```

Run it even if you don't use the `database` driver. The column is nullable, and a null value falls back to `config('microsoft-oauth.redirect_uri')`, as before.

### 3. (Optional) Publish the updated config

The config file gains `mode` and a `broker` block. If you published `config/microsoft-oauth.php` in 1.0, the package defaults apply until you add them, and `mode` defaults to `direct`. Re-publish (or copy the new keys in) only if you plan to use [Broker Mode](Broker):

```bash
php artisan vendor:publish --tag=microsoft-oauth-config --force
```

`--force` overwrites your copy, so diff it against your changes first.

## What else changed

### Redirect URI can come from the credential driver

All three bundled drivers now implement the optional `ProvidesRedirectUri` contract:

| Driver | Where the redirect URI lives |
|---|---|
| `config` | `microsoft-oauth.redirect_uri` (unchanged) |
| `database` | New `redirect_uri` column |
| `cms` | New `artisanpack_microsoft_oauth_redirect_uri` setting |

When a driver has none, `microsoft-oauth.redirect_uri` is used. `save()` only touches the redirect URI when you pass a `redirect_uri` key, so 1.0 callers keep the stored value.

**Custom drivers** written against 1.0 need no changes. `ConfigurationRepository` itself is unchanged. Implement `ProvidesRedirectUri` only if you want your driver to supply the redirect URI. See [ConfigurationRepository](API-Reference-Configuration-Repository#providesredirecturi).

### Refreshes validate the configured tenant

`TokenManager::refresh()` now validates `microsoft-oauth.tenant` the same way the authorization flow does. A tenant value that isn't a recognized authority form throws `TokenRefreshException` with `getError()` = `invalid_tenant`, and the connection stays connected. In 1.0 the bad value was sent to Microsoft as-is.

If your tenant setting was already valid, nothing changes.

### Exceptions carry an error code

`OAuthException` and `TokenRefreshException` now accept an OAuth error code and expose `getError()` (plus `getRenewUrl()`). Their constructors now take `( string $message = '', ?string $error = null, ?string $renewUrl = null, ?Throwable $previous = null )` and no longer take an integer `$code` as the second argument. If you construct these exceptions yourself with a numeric code, drop it or pass the error string instead. See [Exceptions](API-Reference-Exceptions).

### `TokenManager` constructor

`TokenManager` takes an optional third argument, the Laravel config repository. It's resolved from the container when omitted, so `new TokenManager( $credentials, $http )` still works.

### Internals moved to the stateless client

`OAuthManager::handleCallback()` and `TokenManager::refresh()` now delegate the Microsoft HTTP calls to `MicrosoftClient` and then persist to `MicrosoftConnection`. This only matters if you subclassed either class. These protected helpers were removed from `OAuthManager`: `extractIdentity()`, `authorizeEndpoint()`, `tokenEndpoint()`, `buildEndpoint()`, `authority()`, `requireClientId()`, `requireConfig()`, `generateVerifier()`, and `generateChallenge()`. `TokenManager::tokenEndpoint()` was removed too. Their equivalents now live on `MicrosoftClient`. `persistConnection()` and `applyTokens()` now take a `TokenResponse` in place of the raw payload pieces:

```php
protected function persistConnection( int|string $userId, TokenResponse $tokens, bool $incremental = false ): MicrosoftConnection;
protected function applyTokens( MicrosoftConnection $connection, TokenResponse $tokens, bool $incremental = false ): void;
```

## New in 1.1

- [Broker Mode](Broker): run connect, callback, and refresh through an OAuth broker so the site holds no Microsoft client secret.
- [Stateless Client](Stateless-Client): `MicrosoftOAuth::client()`, `MicrosoftCredentials`, and `TokenResponse` for relays and custom flows.
- `LicenseExpiredException` for a broker's `402 license_expired` refresh response.

Full list: [CHANGELOG](https://github.com/ArtisanPack-UI/microsoft-oauth/blob/main/CHANGELOG.md).
