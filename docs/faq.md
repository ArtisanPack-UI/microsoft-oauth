---
title: FAQ
---

# FAQ

## Setup

### Do I need a paid Azure / Microsoft 365 subscription?

No. Entra app registrations are free. Signing in with personal Microsoft accounts is free. Only certain premium features of the Entra platform (conditional access policies, some SLA-backed guarantees) require paid tiers, and none of them are required to run this package.

### Can I use this package with Microsoft Graph?

Yes — Microsoft Graph is the primary target. Add the scopes your app needs under the **Microsoft Graph** section of **API permissions** on your app registration, contribute them via `ap.microsoft.oauth.scopes`, and call Graph endpoints with `MicrosoftOAuth::request( $userId )`.

### Can I use this package without the CMS framework?

Yes. The CMS framework is only required if you set `MICROSOFT_OAUTH_DRIVER=cms`. The default `config` driver and the `database` driver work without it. Selecting `cms` without the framework installed throws at boot — the failure is loud on purpose so misconfiguration is obvious.

### Does this package call Microsoft APIs itself?

No. It only handles OAuth (authentication, tokens, scopes) and gives you a bearer-configured HTTP client. Actual API calls are the responsibility of downstream service packages (Bing Places, Graph integrations, …) or your own code.

## Credentials

### Where should I put my client secret?

Depends on your setup:

- **Single-tenant**, credentials rotate with deploys → `config` driver, `.env`.
- **Multi-tenant** or credentials managed via an admin UI → `database` driver. Secret is encrypted with `APP_KEY`.
- **CMS-driven** projects → `cms` driver. Same encryption story, secret lives with every other CMS setting.

See [Credential Drivers](Drivers) for the full comparison.

### What happens when I rotate `APP_KEY`?

Any encrypted values become unreadable until you re-encrypt them. Two places matter:

1. **`client_secret` in `microsoft_oauth_configurations` / CMS Settings** — the `database` and `cms` drivers log a warning and treat the secret as missing. For the `database` driver, `isConfigured()` still returns `true` if `client_id` and `tenant` are set (because public clients legitimately have no secret) — but downstream OAuth calls that need a confidential-client secret will fail on the exchange with `invalid_client`. For the `cms` driver, `isConfigured()` returns `false`.
2. **`access_token` and `refresh_token` in `microsoft_connections`** — Eloquent's `encrypted` cast throws `DecryptException` on read.

Plan a re-encryption pass alongside key rotations. If you can't, at minimum re-save the client secret via the `ConfigurationRepository::save()` method — every connection will then need to reconnect (their token columns are dead) but the credential driver will start reporting configured again.

### Can I use a different Entra registration per tenant?

Yes, with the `database` driver on a per-tenant database connection:

```php
$this->app->scoped( DatabaseDriver::class, function ( Application $app ): DatabaseDriver {
    return new DatabaseDriver( $app[ 'db' ]->connection( 'tenant' ), $app[ 'encrypter' ] );
} );
```

Every tenant then gets its own `microsoft_oauth_configurations` row on its own database connection.

Alternatively, write a custom `ConfigurationRepository` that reads from wherever your tenant-scoped secrets live — see [Configuration Repository](API-Reference-Configuration-Repository).

## OAuth flow

### Why does the callback route not require `auth`?

The user is mid-redirect from Microsoft and may or may not still have a session cookie in every configuration (cross-site samesite quirks, cookie domain mismatches). The `state` value proves it's the same browser that started the flow — that's the actual CSRF defense.

`/connect` and `/reauthorize` still require `auth` because they need the authenticated user's identifier to associate the resulting connection with.

### Why doesn't the callback verify the `id_token` signature?

The `id_token` arrives over TLS from Microsoft's token endpoint on a connection we initiated. We use the `oid` / `sub` / `email` / `tid` claims for identity **persistence only** — labeling the row so we can display "Connected as {email}" and route per-tenant. We do **not** use them for authorization decisions, so signature validation would be pointless overhead.

If your use case actually authorizes off the `id_token` (e.g. logging users into your app via Microsoft), verify the signature externally with `firebase/php-jwt` and Microsoft's JWKS before trusting the claims.

### Why doesn't the package ship a `/disconnect` route?

Because "disconnect" is a two-line call:

```php
MicrosoftConnection::firstWhere( 'user_id', $user->id )?->markDisconnected( 'Disconnected by user.' );
```

…and the surface it should live on (settings page, admin action, delete-account flow) varies wildly per project. Shipping a canonical route would push everyone into whichever design the package author picked. Wire it into your own controller / Livewire component / Inertia action.

### Do I need to revoke tokens with Microsoft on disconnect?

The package doesn't — it only marks the local connection disconnected. The refresh token stays valid on Microsoft's side until:

- The user manually revokes at [their Microsoft account permissions](https://account.live.com/consent/Manage) (personal) or via their tenant admin (work / school).
- The token expires from inactivity (usually 90+ days).
- Your app calls Microsoft's logout endpoint.

If you need remote revocation, POST to Microsoft's logout endpoint from your own code before calling `markDisconnected()`. There's no direct "revoke this refresh token" endpoint in the same way OAuth 2.0 revocation defines it — Microsoft's model is closer to "sign out this session".

### The refresh flow throws `invalid_grant`. What happened?

Usual causes:

1. **User revoked access** on Microsoft's side.
2. **Password change / MFA change** invalidated the underlying session.
3. **Tenant admin revoked the app's consent.**
4. **Long inactivity** — refresh tokens can eventually expire, though the exact window varies by tenant policy (typically 90+ days).

The token manager marks the connection disconnected with reason `"Refresh token revoked or expired (invalid_grant)."` and throws `TokenRefreshException`. The user needs to re-run `/connect`.

## Scopes

### How do I add a new scope?

For service packages: hook `ap.microsoft.oauth.scopes` in `boot()`:

```php
Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $scopes ) => [ ...$scopes, 'https://graph.microsoft.com/Files.Read' ] );
```

For app code: `app( ScopeRegistry::class )->register( 'https://graph.microsoft.com/Files.Read' )`.

Then: **add the permission under API permissions on the Entra app registration.** Microsoft rejects authorize requests that ask for permissions not listed there with `invalid_scope`.

Users who were already connected will need to reauthorize — the [reauthorize flow](Oauth-Reauthorize) surfaces this via `/auth/microsoft/reauthorize`.

### Do users get re-prompted for every scope on incremental consent?

No. Microsoft's consent UI only asks for scopes that haven't been granted yet — even though the URL requests the full union. Previously-granted scopes are silently included in the resulting token.

### Can I remove a scope?

You can stop registering it, but the connection's `grantedScopes` will still include it until the user manually revokes at their Microsoft account permissions page or reconnects. Nothing in the package unregisters granted scopes.

### Why does `offline_access` show up even though I didn't add it?

It's part of the baseline (`openid`, `profile`, `email`, `offline_access`) and is required to receive a refresh token. Without it, tokens expire in an hour and there's no way to refresh — the connection breaks immediately.

Don't try to remove it from the registry. See [Scopes → The `offline_access` requirement](Scopes#the-offline_access-requirement).

## Runtime

### The `/connect` route redirects with a "credentials are not configured" flash

Your credential driver reports missing `client_id`. Check:

- `config('microsoft-oauth.driver')` — is it the driver you expect?
- For `config` driver: are `MICROSOFT_OAUTH_CLIENT_ID` and `MICROSOFT_OAUTH_TENANT` set in `.env`?
- For `database` / `cms` driver: did you `ConfigurationRepository::save()` credentials? Are they still readable (i.e., did `APP_KEY` rotate)?

### The `/callback` route flashes "OAuth state mismatch"

The session lost the `microsoft_oauth.state` value between `/connect` and `/callback`. Check:

- `SESSION_DRIVER` — cookie, file, database, redis all work; the `null` driver doesn't.
- `SESSION_SAME_SITE` — must be `lax` (default) or `none` for OAuth redirects to include the session cookie.
- `SESSION_SECURE_COOKIE` — must be `false` for `http://` local dev.
- Cookie domain / subdomain mismatches when the redirect URI is on a different host.

### The `/callback` flashes "Microsoft account (tid ...) is a personal account and cannot sign in to an "organizations"-only tenant"

You configured `MICROSOFT_OAUTH_TENANT=organizations` but the user signed in with a personal Microsoft account. Either:

- Change `MICROSOFT_OAUTH_TENANT` to `common` (accepts both), or
- Direct users to sign in with a work / school account, or
- Change the app registration's **Supported account types** in Entra to match.

Same story symmetrically for the `consumers` and GUID cases.

### Can I attach connections to something other than a `User`?

Yes. Set `MICROSOFT_OAUTH_USER_MODEL` (or `config('microsoft-oauth.user_model')`) to a different Eloquent model. The `BelongsTo` relation on `MicrosoftConnection::user()` will target it. The OAuth flow uses `Auth::user()->getAuthIdentifier()`, so whichever guarded model you use for auth must expose that method — which any Eloquent auth model does.

### Can I have more than one Microsoft account per user?

Not with the built-in schema — `user_id` is unique on `microsoft_connections`. If you need it, you'll want a schema change and a new `id`-based lookup path in the OAuth flow. Consider filing an issue rather than forking; multi-account support has come up in discussion for the Google sibling and is likely to land there first.
