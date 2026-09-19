---
title: Requirements
---

# Requirements

## PHP

- **PHP 8.2 or higher**. The package uses readonly-adjacent features (constructor property promotion, `match` with enums, first-class callable syntax) that all target 8.2+.

## Laravel

- **Laravel 10.x, 11.x, 12.x, or 13.x**. The `illuminate/support` constraint is `^10.0|^11.0|^12.0|^13.0`.

The package uses only stable Laravel APIs — Eloquent, HTTP client, config repository, session, encrypter, service container. No breaking changes are expected across the supported major versions.

## Package dependencies

Installed automatically by Composer when you `require artisanpack-ui/microsoft-oauth`:

| Package | Purpose |
|---|---|
| [`artisanpack-ui/core`](https://github.com/ArtisanPack-UI/core) | Shared utilities. |
| [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks) | Filter hooks — service packages contribute scopes via `ap.microsoft.oauth.scopes`. |

## Optional peer packages

| Package | What it enables | When to install |
|---|---|---|
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | The [cms credential driver](Drivers-CMS) — stores credentials via the CMS Settings module. | Only if you set `MICROSOFT_OAUTH_DRIVER=cms`. Selecting the CMS driver without this package installed throws at boot. |

## Runtime infrastructure

- **A writable session store.** The OAuth flow persists `state`, PKCE verifier, and user id in the session across `/connect` → Microsoft → `/callback`. Any Laravel session driver except `null` works.
- **HTTPS for production redirects.** Microsoft rejects non-HTTPS redirect URIs on production tenants. `http://localhost` is allowed as a special case for local dev.
- **A user model with `getAuthIdentifier()`.** The default Laravel `App\Models\User` satisfies this. Custom auth models must expose the method (any Eloquent auth model does).

## Database

- **A relational database** with support for `text` / `longText` columns and a unique index. MySQL, PostgreSQL, SQLite, and SQL Server all work.

The package creates two tables via published migrations — `microsoft_connections` (per-user) and `microsoft_oauth_configurations` (singleton, only used by the `database` driver).

## Entra / Azure AD

- **An app registration** in Entra / Azure AD with:
  - A **Web** platform redirect URI matching `MICROSOFT_OAUTH_REDIRECT_URI`.
  - For confidential clients: at least one **client secret** under **Certificates & secrets**.
  - **API permissions** covering every scope your installed service packages register.
- **Access token version 2** (`accessTokenAcceptedVersion: 2` in the app manifest). The package targets the v2.0 endpoint — v1.0 tokens will not work with the scopes contributed by service packages.
