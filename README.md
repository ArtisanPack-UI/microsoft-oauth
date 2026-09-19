# ArtisanPack UI Microsoft OAuth

Shared Microsoft identity platform (Entra / Azure AD) OAuth2 broker for the ArtisanPack UI ecosystem. This package handles authentication, token storage and refresh, and scope management for any ArtisanPack UI package that talks to a Microsoft service (Bing Places, Microsoft Graph, etc.). It is the Microsoft-side sibling of [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google).

- [What this package does](#what-this-package-does)
- [Installation](#installation)
- [Entra / Azure AD app registration](#entra--azure-ad-app-registration)
- [Credential storage: config vs. database vs. CMS](#credential-storage)
- [Connecting a user](#connecting-a-user)
- [Tenants: single vs. multi-tenant](#tenants-single-vs-multi-tenant)
- [Registering scopes from a service package](#registering-scopes-from-a-service-package)
- [Incremental consent](#incremental-consent)
- [Making API calls](#making-api-calls)
- [Configuration reference](#configuration-reference)
- [Contributing](#contributing)

## What this package does

`artisanpack-ui/microsoft-oauth` is the shared plumbing every ArtisanPack UI Microsoft integration sits on top of. It owns:

- The **OAuth2 authorization-code + PKCE flow** against the Microsoft identity platform v2.0 endpoint — building the consent URL, exchanging the callback code, and extracting the user's Microsoft identity (`oid` / `sub`, `email` / `preferred_username`, `tid`) from the returned `id_token`.
- **Encrypted token storage** on a per-user `microsoft_connections` model, with transparent refresh via the [token manager](docs/tokens.md).
- A **scope registry** that lets any installed service package contribute the scopes it needs. Consent covers the union so users only see one screen.
- **Tenant authority resolution** — parses `common` / `organizations` / `consumers` / GUID / verified domain, builds the right authorize + token URLs, and enforces the returned `tid` claim against the configured authority.
- **Credential storage drivers** (config file, database, or CMS Settings) so credentials can live wherever a project already stores its secrets.
- A **bearer-ready HTTP client** — `MicrosoftOAuth::request( $userId )` returns a Laravel `PendingRequest` that already carries an `Authorization: Bearer …` header for the given user's connection.

Service packages (Bing Places, Graph integrations, …) declare the scopes they need and, once a user has connected, call `MicrosoftOAuth::request( $userId )` (or the underlying `TokenProvider` contract) to make authenticated API calls. They never touch OAuth themselves.

## Installation

```bash
composer require artisanpack-ui/microsoft-oauth
```

The service provider and `MicrosoftOAuth` facade are auto-discovered.

Publish and run migrations to create the `microsoft_connections` and `microsoft_oauth_configurations` tables:

```bash
php artisan vendor:publish --tag=microsoft-oauth-migrations
php artisan migrate
```

Optionally publish the config file to customize the driver, tenant, prompt behavior, or redirect targets:

```bash
php artisan vendor:publish --tag=microsoft-oauth-config
```

### Optional peer packages

| Package | What it enables |
|---|---|
| [`artisanpack-ui/cms-framework`](https://github.com/ArtisanPack-UI/cms-framework) | The `cms` credential driver — stores credentials via the CMS Settings module. |

The base package boots and works without it.

## Entra / Azure AD app registration

1. Sign in to the [Microsoft Entra admin center](https://entra.microsoft.com/) and open **Applications → App registrations → New registration**.
2. **Name**: whatever helps you find it later ("ArtisanPack UI Microsoft — production").
3. **Supported account types**: pick the [tenant mode](#tenants-single-vs-multi-tenant) that matches your app:
    - **Single tenant** → `MICROSOFT_OAUTH_TENANT` = your tenant GUID or verified domain.
    - **Multi-tenant** (work / school accounts) → `MICROSOFT_OAUTH_TENANT=organizations`.
    - **Multi-tenant + personal Microsoft accounts** → `MICROSOFT_OAUTH_TENANT=common`.
    - **Personal Microsoft accounts only** → `MICROSOFT_OAUTH_TENANT=consumers`.
4. **Redirect URI**: platform **Web**, value `https://your-app.test/auth/microsoft/callback`. Add one per environment.
5. Hit **Register**. Copy the **Application (client) ID** and, for single-tenant, the **Directory (tenant) ID**.
6. Under **Certificates & secrets → Client secrets**, click **New client secret**. Copy the **Value** column (not the ID). Confidential (web) clients need the secret; public clients (SPA / native) can skip this step and rely on PKCE alone.
7. Under **API permissions**, click **Add a permission** and add every delegated scope your installed service packages require. See [Scopes](docs/scopes.md) for the per-package list.
8. Under **Manifest**, keep `accessTokenAcceptedVersion: 2` — this package targets the v2.0 endpoint (`https://login.microsoftonline.com/{tenant}/oauth2/v2.0/…`).

Full walkthrough with screenshots-worth-of-notes: [Installation → Entra app registration](docs/installation/entra-app-registration.md).

## Credential storage

`artisanpack-ui/microsoft-oauth` supports three credential storage drivers. Choose one by setting `MICROSOFT_OAUTH_DRIVER` (or `config('microsoft-oauth.driver')`).

### `config` (default)

Reads credentials from `config/microsoft-oauth.php` / `.env`. Best for single-tenant apps where credentials belong in the deploy pipeline:

```env
MICROSOFT_OAUTH_DRIVER=config
MICROSOFT_OAUTH_CLIENT_ID=your-application-client-id
MICROSOFT_OAUTH_CLIENT_SECRET=your-client-secret
MICROSOFT_OAUTH_REDIRECT_URI=https://your-app.test/auth/microsoft/callback
MICROSOFT_OAUTH_TENANT=common
```

The config driver is read-only; `MicrosoftOAuth` facade `→ config()->save()` throws.

### `database`

Stores credentials in the `microsoft_oauth_configurations` table via an atomic upsert against a `singleton_key`. The client secret is encrypted with Laravel's `Encrypter` (`APP_KEY`) before it is written. Good for multi-tenant apps or admin-UI-managed credentials:

```env
MICROSOFT_OAUTH_DRIVER=database
```

```php
app( \ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository::class )->save( [
    'client_id'     => 'your-application-client-id',
    'client_secret' => 'your-client-secret',
    'tenant'        => 'common',
] );
```

If you rotate `APP_KEY` without re-encrypting the stored secret, the driver logs a warning and treats the row as unconfigured.

### `cms` (optional, requires `artisanpack-ui/cms-framework`)

Delegates get/set to the CMS framework's Settings module, so Microsoft credentials live alongside every other site-level setting. The client secret is encrypted at sanitize time before it hits `apUpdateSetting()`. If the CMS framework isn't installed, selecting this driver throws at boot — the operator asked for CMS and silently falling back to another driver would hide the missing dependency.

```env
MICROSOFT_OAUTH_DRIVER=cms
```

Full comparison: [Credential Drivers](docs/drivers.md).

## Connecting a user

The base package ships three web routes under the fixed prefix `/auth/microsoft`:

| Route | Method | Name | Middleware |
|---|---|---|---|
| `/auth/microsoft/connect` | GET | `microsoft.auth.connect` | `web`, `auth` |
| `/auth/microsoft/callback` | GET | `microsoft.auth.callback` | `web` |
| `/auth/microsoft/reauthorize` | GET | `microsoft.auth.reauthorize` | `web`, `auth` |

Send an authenticated user to `route('microsoft.auth.connect')`:

```blade
<a href="{{ route('microsoft.auth.connect') }}">Connect Microsoft</a>
```

The controller redirects them to Microsoft's consent screen, they return to `/auth/microsoft/callback`, the code is exchanged, and a `MicrosoftConnection` row is written for the authenticated user. Redirects afterwards honor `microsoft-oauth.routes.redirect_after_connect` and `microsoft-oauth.routes.redirect_after_error`, with a `microsoft.status` / `microsoft.error` flash on the session.

Details: [OAuth Flow](docs/oauth.md).

## Tenants: single vs. multi-tenant

Which Microsoft accounts your app accepts is decided by `microsoft-oauth.tenant`:

| Value | Who can sign in | `tid` enforcement on callback |
|---|---|---|
| `common` | Work, school, and personal Microsoft accounts | Anything Microsoft returns is accepted. |
| `organizations` | Work / school accounts only | `tid` must not be the MSA tenant (`9188040d-…`). |
| `consumers` | Personal Microsoft accounts only | `tid` must be the MSA tenant. |
| A tenant GUID (single-tenant) | Only accounts in that tenant | `tid` must equal the configured GUID. |
| A verified domain (`contoso.com`, `contoso.onmicrosoft.com`) | Only the tenant that owns the domain | Any `tid` from that domain-scoped authority is accepted; `tid` is required. |

Enforcement is done inside `OAuthManager::handleCallback()` against the `tid` claim from the `id_token`, so a `consumers`-only app cannot accept a work account (and vice versa) — the exchange throws `OAuthException` and the connection is not persisted.

Full reference: [Tenants](docs/tenants.md).

## Registering scopes from a service package

Service packages contribute scopes via the `ap.microsoft.oauth.scopes` filter hook (from [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks)):

```php
use ArtisanPackUI\Hooks\Facades\Filter;

// In your service provider's boot() method:
Filter::add( 'ap.microsoft.oauth.scopes', function ( array $scopes ): array {
    $scopes[] = 'https://www.bingapis.com/api/v7/businesses.readwrite';
    return $scopes;
} );
```

At consent time the base package unions every contributed scope with the baseline (`openid`, `profile`, `email`, `offline_access`) and de-duplicates. A single consent screen covers every dependent service — users don't get a per-package prompt.

Applications that need to add a scope without a service provider can call the `ScopeRegistry::register()` method at runtime instead.

## Incremental consent

When a new service package is installed after the account is already connected, the scope registry starts returning scopes the connection doesn't hold. `GET /auth/microsoft/reauthorize` sends the user through a consent prompt for **just the added scopes** rather than a full disconnect + reconnect. Under the hood the manager requests the full scope union so the returned token covers everything the app now needs, but Microsoft's consent UI still only asks for the delta.

If your app has its own UI, call the manager directly:

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use ArtisanPackUI\MicrosoftOAuth\OAuth\IncrementalConsentResult;

$result = app( OAuthManager::class )->incrementalAuthorizationUrl( $user->id );

return match ( true ) {
    IncrementalConsentResult::NoConnection === $result      => redirect()->route( 'microsoft.auth.connect' ),
    IncrementalConsentResult::AlreadyAuthorized === $result => back()->with( 'status', 'Already authorized.' ),
    default                                                 => redirect()->away( $result ),
};
```

Details: [OAuth → Reauthorize](docs/oauth/reauthorize.md).

## Making API calls

Service packages retrieve a bearer-ready HTTP client via the facade:

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

$response = MicrosoftOAuth::request( $user->id )
    ->acceptJson()
    ->get( 'https://graph.microsoft.com/v1.0/me' );
```

Under the hood, `request()` calls the `TokenProvider` contract, which loads the user's `MicrosoftConnection` and asks the `TokenManager` for a valid access token — refreshing transparently when the current one is within 60 seconds of expiring, and marking the connection disconnected on `invalid_grant` / `interaction_required` / `consent_required` / `login_required`.

Prefer injecting the contract in downstream packages:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;

public function __construct( protected TokenProvider $tokens ) {}

public function fetchProfile( int $userId ): array
{
    return Http::withToken( $this->tokens->accessTokenFor( $userId ) )
        ->acceptJson()
        ->get( 'https://graph.microsoft.com/v1.0/me' )
        ->json();
}
```

## Configuration reference

Key options in `config/microsoft-oauth.php`:

| Key | Default | Meaning |
|---|---|---|
| `driver` | `config` | Credential driver: `config`, `database`, or `cms`. |
| `client_id` / `client_secret` / `redirect_uri` | `env(...)` | Credentials used by the `config` driver. |
| `tenant` | `common` | Tenant authority: `common`, `organizations`, `consumers`, a tenant GUID, or a verified domain. |
| `prompt` | `select_account` | Passed to Microsoft's `prompt` param on initial connect. Common values: `login`, `none`, `consent`, `select_account`. |
| `routes.redirect_after_connect` | `/` | Path or route name for a successful connect / reauthorize. |
| `routes.redirect_after_error` | `/` | Path or route name for OAuth errors. |
| `user_model` | `App\Models\User` | The user model `MicrosoftConnection` belongs to. |

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute.
