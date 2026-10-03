# ArtisanPack UI Microsoft OAuth Changelog

## [Unreleased]

### Fixed

- **Broker signing key.** `/authorize` links are now signed with `hash_hmac( 'sha256', 'jmwd-workshop:oauth-authorize', <plain part of the site secret> )` (`BrokerCredentials::SIGNING_KEY_LABEL`) instead of `sha256( <plain part> )`, which the broker no longer accepts. Sites registered with the broker before this change need a new site secret from the broker admin (or need to register again) before connecting an account.
- **Concurrent refreshes no longer disconnect a working connection.** `TokenManager::refresh()` runs behind a per-connection cache lock and reuses the tokens another refresh stored while it waited. The broker's `409 refresh_superseded` error is non-terminal: the manager returns the winning refresh's token when it has landed, and otherwise throws `TokenRefreshException` without disconnecting. A refresh that can't get the lock within 10 seconds throws `TokenRefreshException` (`getError()` = `refresh_locked`).
- `BrokerClient::isTrustedRenewUrl()` now also requires the `renew_url`'s port (or its scheme's default) to match the broker URL's.

## [1.1.0] - 2026-10-02

Adds a broker client mode and stateless OAuth relay primitives, so a site can
connect through an OAuth broker without holding a Microsoft client secret, and
a broker can be built on this package. Default `direct`-mode behavior is
unchanged.

### Added

- **Stateless relay primitives** for OAuth brokers ([#24](https://github.com/ArtisanPack-UI/microsoft-oauth/issues/24)). `MicrosoftOAuth::client( ?MicrosoftCredentials )` returns a `MicrosoftClient` built from runtime credentials (client ID, optional secret, tenant, redirect URI) or the configured driver, and never touches the session or database. `authorizationUrl()` takes the caller's `state`, scopes, extra parameters (`prompt`, `login_hint`, …) and an optional PKCE verifier. `exchangeCode()` sends the requested scopes with the code, keeps the `tid` check against the tenant authority, and returns a `TokenResponse`. `refresh()` takes a raw refresh-token string plus the scopes, with no `MicrosoftConnection` required. `MicrosoftClient::verifyState()` checks the caller's state.
- `TokenResponse` value object: access token, rotated refresh token, expiry, granted scopes, id_token, and identity from the id_token (`oid`/`sub`, `email`/`preferred_username`, `name`, `tid`). `toArray()` renders the broker's wire shape.
- **Broker client mode** (`MICROSOFT_OAUTH_MODE=broker`). Connect, callback and refresh run through an OAuth broker using only `microsoft-oauth.broker.url`, `site_id` and `site_secret`, so the site holds no Microsoft client secret and the broker owns the tenant authority. Signed `/authorize` links, one-time code exchange at `/token`, refreshes at `/refresh`, and incremental consent passing the scope union through `scopes=`. `return_url` defaults to the package callback route. Broker credentials can come from the `ap.microsoft.oauth.broker.credentials` filter. `MicrosoftOAuth::broker()` and `MicrosoftOAuth::usesBroker()` expose the client and mode. The broker URL must be HTTPS (plain HTTP only for `localhost`, `*.localhost`, `*.test` and loopback hosts).
- `LicenseExpiredException` (extends `TokenRefreshException`) for the broker's `402 license_expired` refresh response, carrying `getRenewUrl()`. The connection stays connected, unlike a revoked grant. The callback flashes a broker-host `renew_url` as `microsoft.renew_url`.
- `OAuthException` and `TokenRefreshException` expose the OAuth error code via `getError()`.
- Optional `ProvidesRedirectUri` contract (`getRedirectUri()`) so credential drivers can supply the redirect URI. All three bundled drivers implement it: the database driver stores it in a new nullable `redirect_uri` column, and the CMS driver in the `artisanpack_microsoft_oauth_redirect_uri` setting. When a driver has none (or doesn't implement the contract), `microsoft-oauth.redirect_uri` is used, as before. `ConfigurationRepository` itself is unchanged, so custom drivers keep working.

### Changed

- `OAuthManager::handleCallback()` and `TokenManager::refresh()` are now thin wrappers that run the stateless primitives and then persist to `MicrosoftConnection`. Behavior in the default `direct` mode is unchanged.
- Refreshes now validate the configured tenant like the authorization flow does; an invalid tenant raises `TokenRefreshException` (`getError()` is `invalid_tenant`) without disconnecting the connection.
- The `OAuthException` and `TokenRefreshException` constructors are now `( string $message = '', ?string $error = null, ?string $renewUrl = null, ?Throwable $previous = null )`. The second argument is the OAuth error code instead of an integer exception code.
- `TokenManager` takes an optional third constructor argument (the config repository), resolved from the container when omitted.
- A new migration adds a nullable `redirect_uri` column to `microsoft_oauth_configurations`. Run `php artisan migrate` after upgrading.

### Documentation

- New Broker Mode, Stateless Client, and Upgrading guides, plus API reference pages for `MicrosoftClient` / `MicrosoftCredentials`, `BrokerClient` / `BrokerCredentials`, and `TokenResponse`.
- Updated the configuration, environment variable, driver, OAuth flow, token, tenant, exception, testing, and FAQ docs for broker mode, the driver-supplied redirect URI, and the new error codes.

## [1.0.0] - 2026-09-18

First stable release of `artisanpack-ui/microsoft-oauth`: the shared Microsoft
identity platform (Entra / Azure AD) OAuth2 broker that powers ArtisanPack UI's
Microsoft service integrations.

### Added

- Microsoft identity platform v2.0 authorization-code flow, including PKCE, the
  authorize redirect, and the callback exchange (#2).
- Encrypted token storage with automatic refresh: access and refresh tokens are
  encrypted at rest and refreshed transparently ahead of expiry (#3).
- `ScopeRegistry` for declaring the scopes a connection needs, exposed through
  the `ap.microsoft.oauth.scopes` filter hook so other packages can extend the
  scope set (#4).
- Configuration repository with pluggable drivers: a `config` driver backed by
  Laravel config and a `database` driver for runtime-editable credentials (#5).
- CMS Settings bridge for the `database` driver so admins can edit Microsoft
  credentials through the ArtisanPack UI CMS Settings module, with guards for
  a missing CMS framework and for decrypt-failure config drift (#6).
- Incremental consent flow that re-prompts the user when a new service adds
  scopes to an existing connection (#7).
- `TokenProvider` contract and `MicrosoftOAuthManager::request()` consumer API
  so downstream packages fetch tokens through a single, refresh-aware entry
  point (#8).
- Multi-tenant vs single-tenant support with configured tenant-authority
  validation and `id_token` `tid` claim enforcement so tokens from unexpected
  tenants are rejected (#9).
- Comprehensive Pest test coverage for the OAuth callback, refresh path,
  scope registry, and configuration repository (#11).

### Documentation

- Full README and `docs/` tree covering installation, drivers, the OAuth flow,
  tenants, scopes, tokens, the connection model, API reference, testing, FAQ,
  and contributing, plus an Entra / Azure AD app-registration walkthrough with
  redirect URI, client secret, API permissions, and single- vs. multi-tenant
  guidance (#10).
