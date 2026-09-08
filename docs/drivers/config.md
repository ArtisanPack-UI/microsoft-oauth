---
title: Config Driver
---

# `config` Driver

The default driver. Reads credentials from Laravel's config repository, which in turn reads from `.env`.

## Configuration

```env
MICROSOFT_OAUTH_DRIVER=config    # optional, this is the default
MICROSOFT_OAUTH_CLIENT_ID=aaaa1111-2222-3333-4444-555566667777
MICROSOFT_OAUTH_CLIENT_SECRET=THE-VALUE-COLUMN-FROM-CERTIFICATES-AND-SECRETS
MICROSOFT_OAUTH_TENANT=common
MICROSOFT_OAUTH_REDIRECT_URI=https://your-app.test/auth/microsoft/callback
```

The keys map directly to `config/microsoft-oauth.php`:

```php
'client_id'     => env( 'MICROSOFT_OAUTH_CLIENT_ID' ),
'client_secret' => env( 'MICROSOFT_OAUTH_CLIENT_SECRET' ),
'tenant'        => env( 'MICROSOFT_OAUTH_TENANT', 'common' ),
'redirect_uri'  => env( 'MICROSOFT_OAUTH_REDIRECT_URI' ),
```

You can also set these values directly in the config file if you'd rather commit them (obviously don't commit the client secret).

## Read-only

The `config` driver is read-only. Calling `save([...])` throws a `RuntimeException`:

```
The config driver is read-only. Switch to the database driver to persist credentials.
```

This is deliberate — the config driver's whole point is that credentials live in your deploy pipeline. Writing back to the file wouldn't be persisted across container restarts, and mutating environment values at runtime tends to leak between requests.

Need to update credentials programmatically? Switch to the [`database`](Drivers-Database) or [`cms`](Drivers-CMS) driver.

## When to use it

- Single-tenant apps.
- Credentials rotate rarely enough that a redeploy is fine.
- You already manage secrets through a deploy pipeline (Envoyer, Vapor, GitLab CI variables, Kubernetes secrets, …).
- Public clients where there's no client secret to protect (though the driver still works for confidential clients).

## When not to use it

- Multi-tenant apps where each tenant has their own Entra registration.
- Admin-UI-managed credentials.
- Anywhere users need to see or edit the credentials without a deploy.

## `isConfigured()` behavior

Returns `true` only when both `client_id` and `tenant` are non-empty:

```php
public function isConfigured(): bool
{
    return ! empty( $this->getClientId() )
        && ! empty( $this->getTenant() );
}
```

`client_secret` is intentionally excluded — public clients (SPA / native) legitimately have no secret. If your app needs to enforce "must be a confidential client", check `getClientSecret()` yourself at the call site.

## Testing

Point the config repository at fixture values in your test's `setUp()`:

```php
config( [
    'microsoft-oauth.driver'        => 'config',
    'microsoft-oauth.client_id'     => 'test-client-id',
    'microsoft-oauth.client_secret' => 'test-client-secret',
    'microsoft-oauth.tenant'        => 'common',
    'microsoft-oauth.redirect_uri'  => 'https://tests.test/auth/microsoft/callback',
] );
```

Since Orchestra Testbench doesn't read your app's `.env`, the config driver is often the easiest to test against.
