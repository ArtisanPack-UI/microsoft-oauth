---
title: Environment Variables
---

# Environment Variables

Every env var the package reads. All are optional — the config file provides defaults.

| Env var | Default | Meaning |
|---|---|---|
| `MICROSOFT_OAUTH_DRIVER` | `config` | Credential storage driver: `config`, `database`, or `cms`. See [Drivers](Drivers). |
| `MICROSOFT_OAUTH_CLIENT_ID` | *none* | Application (client) ID from your Entra app registration. Read by the `config` driver only. |
| `MICROSOFT_OAUTH_CLIENT_SECRET` | *none* | Client secret **Value** (not the secret ID). Read by the `config` driver only. Optional for public clients. |
| `MICROSOFT_OAUTH_REDIRECT_URI` | *none* | Full HTTPS URL of your callback route, matching an Entra-registered redirect URI exactly. |
| `MICROSOFT_OAUTH_TENANT` | `common` | `common`, `organizations`, `consumers`, a tenant GUID, or a verified domain. See [Tenants](Tenants). |
| `MICROSOFT_OAUTH_PROMPT` | `select_account` | Passed to Microsoft's `prompt` on the initial connect. `login`, `none`, `consent`, `select_account`. |
| `MICROSOFT_OAUTH_REDIRECT_AFTER_CONNECT` | `/` | Path or route name for a successful connect / reauthorize. |
| `MICROSOFT_OAUTH_REDIRECT_AFTER_ERROR` | `/` | Path or route name for OAuth errors. |
| `MICROSOFT_OAUTH_USER_MODEL` | `App\Models\User` | FQCN of the user model `MicrosoftConnection` belongs to. |

## Env-var behavior notes

### `MICROSOFT_OAUTH_CLIENT_SECRET`

The **Value** column of the secret shown in **Certificates & secrets → Client secrets**, not the **Secret ID**. Entra shows the Value **only once**, right after creating the secret — if you missed it, delete and recreate the secret.

### `MICROSOFT_OAUTH_REDIRECT_URI`

Must match an entry under your app registration's **Authentication → Redirect URIs** exactly. Common gotchas:

- `http` vs. `https` — production tenants reject `http://` except for `localhost`.
- Trailing slash — `https://x.test/callback` and `https://x.test/callback/` are different URIs.
- Port — `https://x.test:8080/callback` and `https://x.test/callback` are different URIs.

The value here is also written back to Microsoft on the token exchange (`redirect_uri` parameter). Mismatches show up as `AADSTS50011: redirect_uri_mismatch`.

### `MICROSOFT_OAUTH_TENANT`

If unset, defaults to `common`. Empty string is coerced to `common` at the authority layer. Anything that isn't `common`, `organizations`, `consumers`, a GUID, or a plausible domain throws `OAuthException("Invalid Microsoft OAuth tenant \"…\". Use \"common\", \"organizations\", \"consumers\", a tenant GUID, or a verified domain.")` at authorize-URL build time.

### `MICROSOFT_OAUTH_PROMPT`

Only applied to the **initial** `/connect` URL. The `/reauthorize` URL is always built with `prompt=consent` so the delta-scope prompt actually shows.

`prompt=none` is safe when you know the user is already signed in; if they aren't (or if consent is needed), Microsoft returns `interaction_required` / `login_required` / `consent_required` on the callback and the flow fails with a flash error.

### `MICROSOFT_OAUTH_USER_MODEL`

Set this if your app's user model lives outside `App\Models\User`:

```env
MICROSOFT_OAUTH_USER_MODEL=Domain\Auth\Account
```

The setting only affects the `belongsTo` relation on `MicrosoftConnection::user()`. The OAuth flow uses `Auth::user()->getAuthIdentifier()`, which every Eloquent auth model exposes.
