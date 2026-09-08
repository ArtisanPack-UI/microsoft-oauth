---
title: Installation
---

# Installation

## Install via Composer

```bash
composer require artisanpack-ui/microsoft-oauth
```

The package auto-registers via Laravel's package discovery:

- **Service provider**: `ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuthServiceProvider`
- **Facade alias**: `MicrosoftOAuth` (`ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth`)

No manual changes to `config/app.php` are required in a standard Laravel app.

## Publish the migrations

```bash
php artisan vendor:publish --tag=microsoft-oauth-migrations
php artisan migrate
```

This creates two tables:

- `microsoft_connections` — the per-user connection row (encrypted access + refresh tokens, granted scopes, tenant id, expiry, status).
- `microsoft_oauth_configurations` — used by the [database driver](Drivers-Database) to store OAuth client credentials as a single-row singleton. Unused by the `config` and `cms` drivers.

See [Connection Model](Connection-Model) for a full column reference.

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=microsoft-oauth-config
```

Publishes `config/microsoft-oauth.php`. Override the driver, tenant, prompt behavior, redirect targets, or the user model here. Full reference: [Configuration](Installation-Configuration).

## Entra / Azure AD app registration

Before a user can connect, you need an app registration in Entra / Azure AD:

1. Open the [Microsoft Entra admin center](https://entra.microsoft.com/) and navigate to **Applications → App registrations → New registration**.
2. Pick **Supported account types** matching your [tenant mode](Tenants).
3. Set the **Redirect URI**: platform **Web**, value `https://your-app.test/auth/microsoft/callback`.
4. Under **Certificates & secrets → Client secrets**, create a client secret (confidential clients only).
5. Under **API permissions**, add every delegated scope your service packages require.
6. Copy the **Application (client) ID** and, for single-tenant, the **Directory (tenant) ID**.

Full walkthrough with per-step notes: [Entra app registration](Installation-Entra-App-Registration).

## Store your credentials

Pick a driver by setting `MICROSOFT_OAUTH_DRIVER` (default `config`):

```env
MICROSOFT_OAUTH_DRIVER=config      # default; reads .env / config/microsoft-oauth.php
MICROSOFT_OAUTH_CLIENT_ID=your-application-client-id
MICROSOFT_OAUTH_CLIENT_SECRET=your-client-secret
MICROSOFT_OAUTH_REDIRECT_URI=https://your-app.test/auth/microsoft/callback
MICROSOFT_OAUTH_TENANT=common
```

Or switch to a writable driver — see [Credential Drivers](Drivers).

## Session driver

The OAuth flow stores three keys in the session between `/connect` and `/callback` (`microsoft_oauth.state`, `microsoft_oauth.verifier`, `microsoft_oauth.user_id`). Any session driver except `null` works — cookie, file, database, redis. If the session doesn't survive the round trip, `/callback` flashes `"OAuth state mismatch; possible CSRF attempt."` and no connection is written.

For local HTTP dev, make sure:

- `SESSION_SECURE_COOKIE=false` (`true` blocks the cookie on `http://`).
- `SESSION_SAME_SITE=lax` (the default; `strict` blocks the cookie on the cross-site return from Microsoft).

## Verify the install

Run:

```bash
php artisan route:list --path=auth/microsoft
```

You should see:

```
GET  auth/microsoft/connect       microsoft.auth.connect       web, auth
GET  auth/microsoft/reauthorize   microsoft.auth.reauthorize   web, auth
GET  auth/microsoft/callback      microsoft.auth.callback      web
```

## Deeper topics

- [Requirements](Installation-Requirements) — PHP, Laravel, and peer-package versions in full detail.
- [Configuration](Installation-Configuration) — full `config/microsoft-oauth.php` reference.
- [Environment variables](Installation-Environment-Variables) — every env var the package reads.
- [Entra app registration](Installation-Entra-App-Registration) — the Entra admin center walkthrough with notes on redirect URIs, secrets, permissions, and single- vs. multi-tenant choices.

---
Continue to [Credential Drivers](Drivers) →
