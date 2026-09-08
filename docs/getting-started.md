---
title: Getting Started
---

# Getting Started

Welcome to ArtisanPack UI Microsoft OAuth. This guide walks through the shortest path from `composer require` to a user with a working Microsoft connection.

See also: [Installation](Installation), [Credential Drivers](Drivers), [OAuth Flow](Oauth), and [Tenants](Tenants).

## Requirements

- PHP 8.2+
- Laravel 10.x, 11.x, 12.x, or 13.x
- An Entra / Azure AD app registration with an OAuth 2.0 client
- A session driver capable of surviving the `/connect` → Microsoft → `/callback` round trip (cookie, file, database, redis — anything but `null`)

Optional peer packages:

| Package | What it enables |
|---|---|
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | The [cms credential driver](Drivers-CMS) — stores credentials via the CMS Settings module. |

The base package boots and works without it.

## 1. Install

```bash
composer require artisanpack-ui/microsoft-oauth
```

The service provider and `MicrosoftOAuth` facade are auto-discovered.

## 2. Run migrations

```bash
php artisan vendor:publish --tag=microsoft-oauth-migrations
php artisan migrate
```

This creates the `microsoft_connections` and `microsoft_oauth_configurations` tables. See [Connection Model](Connection-Model) for the schema.

## 3. Register your Entra / Azure AD app

Follow the [Entra app registration walkthrough](Installation-Entra-App-Registration) to create an app registration and register a redirect URI. Copy the **Application (client) ID** and — for confidential clients — a **client secret**. For single-tenant apps, also copy the **Directory (tenant) ID** or use a verified domain.

## 4. Store your credentials

The default driver reads from `config/microsoft-oauth.php` / `.env`:

```env
MICROSOFT_OAUTH_CLIENT_ID=your-application-client-id
MICROSOFT_OAUTH_CLIENT_SECRET=your-client-secret
MICROSOFT_OAUTH_REDIRECT_URI=https://your-app.test/auth/microsoft/callback
MICROSOFT_OAUTH_TENANT=common
```

For multi-tenant apps or credentials managed through an admin UI, switch to the `database` or `cms` driver — see [Credential Drivers](Drivers).

## 5. Pick the right tenant mode

The `MICROSOFT_OAUTH_TENANT` value decides which Microsoft accounts your app accepts:

- `common` — work, school, and personal Microsoft accounts.
- `organizations` — work / school accounts only.
- `consumers` — personal Microsoft accounts only.
- A tenant GUID or verified domain — single-tenant.

Enforcement happens on callback against the `tid` claim in the returned `id_token`, so misconfigured tenant + account combinations fail loudly instead of silently persisting a connection the downstream integration can't use. See [Tenants](Tenants) for the full rules.

## 6. Connect a user

Send an authenticated user to the `microsoft.auth.connect` route:

```blade
<a href="{{ route('microsoft.auth.connect') }}">Connect Microsoft</a>
```

They'll be redirected to Microsoft's consent screen, then bounced back to `/auth/microsoft/callback`. On success, a `MicrosoftConnection` row is written for the authenticated user and they're redirected to `microsoft-oauth.routes.redirect_after_connect` (default `/`) with `microsoft.status=connected` flashed to the session.

Full walkthrough: [OAuth Flow](Oauth).

## 7. Make an authenticated API call

Service packages retrieve a bearer-ready HTTP client via the facade:

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

$response = MicrosoftOAuth::request( $user->id )
    ->acceptJson()
    ->get( 'https://graph.microsoft.com/v1.0/me' );
```

The token refreshes transparently when the current one is within 60 seconds of expiring. If the refresh fails with a terminal error (`invalid_grant`, `interaction_required`, `consent_required`, `login_required`), the connection is marked disconnected and the caller sees a `TokenRefreshException`.

More on refresh behavior: [Tokens](Tokens).

## 8. Handle incremental consent

When a new service package is installed after the account is already connected, its scopes are added to the registry. Send the user to `/auth/microsoft/reauthorize` to consent to just the delta:

```blade
<a href="{{ route('microsoft.auth.reauthorize') }}">Grant additional access</a>
```

See [Scopes](Scopes) and [OAuth → Reauthorize](Oauth-Reauthorize).

## Next steps

- [Installation](Installation) — full install walkthrough, Entra app registration, publishing assets.
- [Credential Drivers](Drivers) — config vs. database vs. CMS.
- [OAuth Flow](Oauth) — connect, callback, reauthorize internals.
- [Tenants](Tenants) — the five tenant modes and `tid` enforcement rules.
- [Scopes](Scopes) — how service packages register scopes and how incremental consent works.
- [Tokens](Tokens) — refresh cadence, failure modes, terminal errors.
- [API Reference](API-Reference) — the full public surface: `MicrosoftOAuth` facade, `microsoft_oauth()` helper, contracts, and models.

---
Continue to [Installation](Installation) →
