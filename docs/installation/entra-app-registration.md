---
title: Entra App Registration
---

# Entra App Registration

The package needs an app registration in Microsoft Entra (formerly Azure Active Directory) with an OAuth 2.0 client. This page walks through every step.

You'll need a Microsoft account with permission to register applications in the target tenant. For a personal-app-only setup that's any Microsoft account; for a work / school tenant it's usually the **Application Administrator** or **Cloud Application Administrator** directory role, or a Global Administrator.

## 1. Open the App registrations page

Sign in to the [Microsoft Entra admin center](https://entra.microsoft.com/) and navigate to **Applications → App registrations**. Click **New registration**.

## 2. Name the application

Anything that helps you find it later. This is shown on the consent screen to end users, so make it recognizable — "ArtisanPack UI Microsoft — production", "MyApp (staging)".

## 3. Pick supported account types

This is the most consequential choice on the page. It hard-codes which categories of Microsoft account can ever sign in to your app, and it maps directly onto the [`MICROSOFT_OAUTH_TENANT`](Installation-Environment-Variables) config value.

| Registration option | `MICROSOFT_OAUTH_TENANT` value | Who can sign in |
|---|---|---|
| Accounts in this organizational directory only (Single tenant) | Your tenant GUID or verified domain | Only users in your Entra directory. |
| Accounts in any organizational directory (Multitenant) | `organizations` | Work / school accounts from any Entra directory. |
| Accounts in any organizational directory (Multitenant) and personal Microsoft accounts | `common` | Work, school, and personal Microsoft accounts. |
| Personal Microsoft accounts only | `consumers` | Personal Microsoft accounts only. |

Choose based on your product:

- Internal tool for one company → **single tenant** with the tenant GUID.
- B2B SaaS selling to organizations → **organizations** (unless you also want to serve individual users, in which case **common**).
- Consumer product → **common** or **consumers**.

Full rules and `tid`-enforcement behavior: [Tenants](Tenants).

## 4. Register the redirect URI

- **Platform**: **Web**.
- **URI**: `https://your-app.test/auth/microsoft/callback`.

The path `/auth/microsoft/callback` is the fixed callback route mounted by this package. You cannot change the prefix — set your app's public URL as the host and keep the path as-is.

Add one entry per environment (localhost, staging, production). You can add more redirect URIs later under **Authentication → Redirect URIs**.

**Local dev on `http://localhost:XXXX`** is allowed as a special case; every other production URL must be HTTPS. Herd sites (`https://your-app.test`) work out of the box because Herd terminates TLS locally.

## 5. Register

Click **Register**. Microsoft creates the app and drops you on the app's Overview page.

Copy these values now — you'll need them:

- **Application (client) ID** — shows in **Overview** and **Essentials**. This is the value for `MICROSOFT_OAUTH_CLIENT_ID`.
- **Directory (tenant) ID** — for single-tenant apps only. This is the value for `MICROSOFT_OAUTH_TENANT` if you're not using a verified domain instead.

## 6. Create a client secret (confidential clients)

Under **Certificates & secrets → Client secrets**, click **New client secret**.

- **Description**: whatever helps you find it later ("prod v1", "rotated 2026-01-15").
- **Expires**: pick a duration. Microsoft's max is 24 months. Set a reminder — expired secrets kill the OAuth flow with `invalid_client`.

Click **Add**. Microsoft shows you the secret:

- **Value** — the actual secret. **This is shown once, right after creation.** If you leave the page without copying it, you'll have to delete the secret and create a new one. This is the value for `MICROSOFT_OAUTH_CLIENT_SECRET`.
- **Secret ID** — a GUID that identifies the secret. Not what you want. Ignore this field.

### Skip this step for public clients

Public clients — single-page apps (SPA) and native mobile / desktop apps — should **not** ship a client secret. They authenticate with PKCE alone. This package supports both: leave `client_secret` unset and the token exchange will omit it, relying on the PKCE `code_verifier` for proof-of-possession.

You'd still create the app registration and add the redirect URI; you just skip the client-secret step.

## 7. Add API permissions (scopes)

Under **API permissions**, click **Add a permission**.

For every ArtisanPack UI service package your app installs, add its delegated permissions here. The consent screen only lists permissions Microsoft recognizes, so a missing permission makes the runtime authorize request fail with `invalid_scope`.

### Microsoft Graph

- **Microsoft Graph → Delegated permissions**.
- Search for the specific scope (e.g. `User.Read`, `Sites.Read.All`).
- Check it, then **Add permissions**.

Every Graph-based service package documents the exact scopes it registers via [`ap.microsoft.oauth.scopes`](Scopes). Add all of them here.

### Bing Places (and other non-Graph APIs)

Some Microsoft APIs live outside Graph. Their scopes are usually full URLs (e.g. `https://www.bingapis.com/api/v7/businesses.readwrite`).

- **APIs my organization uses** — search for the API. If it's registered on your tenant, pick it and add the delegated scope.
- **APIs my organization uses → Add a permission** — if the API doesn't show up, you may need to request tenant admin to consent to it first, or the API may not be exposed on your directory at all.

### Baseline scopes

The package always requests four baseline scopes automatically — you don't need to add these in the portal for personal-account flows, but for work / school tenants they should be listed:

- `openid`
- `profile`
- `email`
- `offline_access` — required to receive a refresh token. Without it, tokens expire in an hour and the connection breaks.

### Admin consent (optional)

Some scopes are marked **Admin consent required**. For those:

- Individual users can't consent — an admin has to.
- Click **Grant admin consent for {tenant}** on the API permissions page. This pre-authorizes every user in the tenant.
- Alternatively, an admin can consent per-user by walking through the flow once themselves; every subsequent user hits an ordinary user-consent screen.

## 8. Verify the manifest

Under **Manifest**, confirm:

```json
"accessTokenAcceptedVersion": 2,
```

This package targets the Microsoft identity platform v2.0 endpoint. Access tokens issued for v1.0 (the default when this field is `null` for older registrations) will not carry the v2.0 scope grants and downstream API calls fail.

If your registration was created before ~2018, this field may be `null`. Change it to `2`, click **Save**, then wait a few minutes for the change to propagate.

## 9. Store the credentials in your app

Depending on your [driver](Drivers):

### `config` driver (default)

Add to `.env`:

```env
MICROSOFT_OAUTH_DRIVER=config
MICROSOFT_OAUTH_CLIENT_ID=aaaa1111-2222-3333-4444-555566667777
MICROSOFT_OAUTH_CLIENT_SECRET=THE-VALUE-COLUMN-FROM-CERTIFICATES-AND-SECRETS
MICROSOFT_OAUTH_REDIRECT_URI=https://your-app.test/auth/microsoft/callback
MICROSOFT_OAUTH_TENANT=common
```

For a single-tenant app, replace `common` with your tenant GUID or verified domain.

### `database` driver

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

app( ConfigurationRepository::class )->save( [
    'client_id'     => 'aaaa1111-2222-3333-4444-555566667777',
    'client_secret' => 'THE-VALUE-COLUMN-FROM-CERTIFICATES-AND-SECRETS',
    'tenant'        => 'common',
] );
```

The `redirect_uri` for the `database` driver comes from `config('microsoft-oauth.redirect_uri')` / `MICROSOFT_OAUTH_REDIRECT_URI` — only the three credential columns are stored in the DB.

### `cms` driver

Save through the CMS Settings UI, or programmatically:

```php
apUpdateSetting( 'artisanpack_microsoft_oauth_client_id', 'aaaa1111-2222-3333-4444-555566667777' );
apUpdateSetting( 'artisanpack_microsoft_oauth_client_secret', 'THE-VALUE-COLUMN-FROM-CERTIFICATES-AND-SECRETS' ); // sanitize callback encrypts before write
apUpdateSetting( 'artisanpack_microsoft_oauth_tenant', 'common' );
```

## 10. Test the flow

Log in as an authorized user and hit `route('microsoft.auth.connect')`. You should see Microsoft's consent screen listing every scope your service packages registered, then land back on `microsoft-oauth.routes.redirect_after_connect` with a fresh `microsoft_connections` row and `session('microsoft.status') === 'connected'`.

## Common gotchas

- **`AADSTS50011: redirect_uri_mismatch`** — the value in `MICROSOFT_OAUTH_REDIRECT_URI` doesn't match any URI listed under **Authentication → Redirect URIs** on the app registration. Character-for-character. `http` vs. `https`, trailing slash, port — all significant.
- **`AADSTS7000215: Invalid client secret`** — either the secret expired, or you pasted the **Secret ID** column instead of the **Value** column, or the secret was rotated on the Entra side without updating your app.
- **`AADSTS500011: The resource principal named … was not found in the tenant`** — you asked for a scope that isn't listed under **API permissions**. Add it under **Microsoft Graph** or the relevant API.
- **`AADSTS65001: The user or administrator has not consented`** — the scope requires admin consent that hasn't been granted, or the user declined. Have an admin click **Grant admin consent for {tenant}** on the API permissions page.
- **`AADSTS70000: invalid_grant` on callback** — usually a stale PKCE verifier because the session was lost between `/connect` and `/callback`. Check `SESSION_DRIVER` (not `null`), `SESSION_SAME_SITE=lax`, `SESSION_SECURE_COOKIE=false` for local `http://`.
- **Wrong tenant mode / wrong account type** — an `organizations`-only registration rejects personal Microsoft accounts on callback (`OAuthException: Microsoft account (tid …) is a personal account and cannot sign in to an "organizations"-only tenant`). Fix by changing `MICROSOFT_OAUTH_TENANT` to `common`, or by having the user sign in with a work / school account.
- **Refresh tokens die after an hour** — `offline_access` is missing from the scope request. The package includes it in the baseline automatically, so this only bites if you overrode the scope registry manually. See [Scopes](Scopes).
