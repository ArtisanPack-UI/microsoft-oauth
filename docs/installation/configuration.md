---
title: Configuration
---

# Configuration

Publish the config file with:

```bash
php artisan vendor:publish --tag=microsoft-oauth-config
```

Copies `config/microsoft-oauth.php` into your app. If you leave it unpublished, the package uses its own defaults — the env vars below still apply.

## Full reference

```php
return [

    'driver' => env( 'MICROSOFT_OAUTH_DRIVER', 'config' ),

    'client_id'     => env( 'MICROSOFT_OAUTH_CLIENT_ID' ),
    'client_secret' => env( 'MICROSOFT_OAUTH_CLIENT_SECRET' ),
    'redirect_uri'  => env( 'MICROSOFT_OAUTH_REDIRECT_URI' ),

    'tenant' => env( 'MICROSOFT_OAUTH_TENANT', 'common' ),

    'prompt' => env( 'MICROSOFT_OAUTH_PROMPT', 'select_account' ),

    'routes' => [
        'redirect_after_connect' => env( 'MICROSOFT_OAUTH_REDIRECT_AFTER_CONNECT', '/' ),
        'redirect_after_error'   => env( 'MICROSOFT_OAUTH_REDIRECT_AFTER_ERROR', '/' ),
    ],

    'user_model' => env( 'MICROSOFT_OAUTH_USER_MODEL', 'App\\Models\\User' ),

];
```

## Keys

### `driver`

Which storage driver backs the app credentials (`client_id`, `client_secret`, `tenant`).

- `config` (default) — reads from `.env` / `config/microsoft-oauth.php`. Read-only.
- `database` — reads and writes `microsoft_oauth_configurations`. Client secret encrypted at rest.
- `cms` — reads and writes CMS framework Settings. Client secret encrypted at rest. Requires `artisanpack-ui/cms-framework`; picking `cms` without the framework installed throws at boot.

The service provider re-reads this value on every `ConfigurationRepository` resolve, so runtime overrides work.

Full comparison: [Credential Drivers](Drivers).

### `client_id` / `client_secret` / `redirect_uri`

Used by the `config` driver only. The `database` and `cms` drivers ignore these and read from their own storage.

- `client_id` — the **Application (client) ID** from your Entra app registration.
- `client_secret` — the **Value** of a client secret from **Certificates & secrets**. Optional for public clients (SPA / native); confidential (web) clients should always provide one.
- `redirect_uri` — must exactly match a redirect URI registered on the Entra app. Character-for-character (scheme, host, port, path, trailing slash — all significant).

### `tenant`

Which Microsoft tenant this app authorizes against. Accepted values:

- `common` — any Microsoft account (work, school, personal).
- `organizations` — work / school accounts only.
- `consumers` — personal Microsoft accounts only.
- A tenant GUID (`aaaa1111-…`) — single-tenant.
- A verified domain (`contoso.com`, `contoso.onmicrosoft.com`) — single-tenant; Microsoft resolves the domain to a specific tenant.

Enforced on callback against the `tid` claim in the returned `id_token`. See [Tenants](Tenants) for the full rules.

### `prompt`

Passed through to Microsoft's `prompt` parameter on the **initial** connect URL. Common values:

- `login` — always show the login screen.
- `none` — silent auth; fails with `interaction_required` if consent is needed.
- `consent` — always show the consent screen.
- `select_account` (default) — let the user pick which Microsoft account to sign in with.

Incremental consent (`/reauthorize`) always uses `prompt=consent`, regardless of this value.

### `routes.redirect_after_connect`

Where to send the user after a successful connect or reauthorize. Value may be a named route or an absolute / relative URL. A `microsoft.status` flash is attached: `connected` after connect, `already-authorized` after a reauthorize where nothing needed consent.

### `routes.redirect_after_error`

Where to send the user when the flow fails. A `microsoft.error` flash carries the error message.

Read it in your redirect target:

```blade
@if( session( 'microsoft.error' ) )
    <div class="alert alert-error">{{ session( 'microsoft.error' ) }}</div>
@endif
```

### `user_model`

Fully-qualified class name of the application's user model. Used by `MicrosoftConnection::user()` for the `belongsTo` relationship. Defaults to `App\Models\User`.

Any Eloquent model works; the OAuth flow calls `Auth::user()->getAuthIdentifier()`, so whatever model is behind the `web` guard needs that method (every Eloquent auth model has it).

## Routes are not configurable

Unlike some sibling packages, the route prefix, middleware, and route names are **fixed**:

- Prefix: `/auth/microsoft`
- Middleware: `web`, `auth` (connect / reauthorize) and `web` (callback)
- Names: `microsoft.auth.connect`, `microsoft.auth.reauthorize`, `microsoft.auth.callback`

If your app needs a different URL structure, set `config('microsoft-oauth.driver')` and other options as usual, then skip the package's routes by wiring your own controller that calls `OAuthManager::authorizationUrl()` / `handleCallback()` directly — see [OAuth → Connect](Oauth-Connect).
