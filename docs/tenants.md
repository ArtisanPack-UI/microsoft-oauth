---
title: Tenants
---

# Tenants

Which Microsoft accounts your app accepts is decided by the `microsoft-oauth.tenant` config value. This is the biggest choice you make when wiring up a Microsoft integration — get it wrong and either the wrong users get in, or legitimate users get bounced with a confusing error.

## The five tenant modes

`ArtisanPackUI\MicrosoftOAuth\OAuth\TenantAuthority::fromConfig()` parses the configured value into one of five authority modes:

| Config value | Mode | Who signs in | `{tenant}` in the endpoint URL |
|---|---|---|---|
| `common` (default) | Common | Work, school, and personal Microsoft accounts | `common` |
| `organizations` | Organizations | Work / school accounts only | `organizations` |
| `consumers` | Consumers | Personal Microsoft accounts only | `consumers` |
| A tenant GUID (`aaaa1111-…`) | Tenant (GUID) | Only accounts in that specific tenant | the GUID |
| A verified domain (`contoso.com`, `contoso.onmicrosoft.com`) | Tenant (domain) | Only accounts in the tenant that owns the domain | the domain |

Anything else — a typo, a stray path, a malformed GUID — throws `OAuthException("Invalid Microsoft OAuth tenant \"…\". Use \"common\", \"organizations\", \"consumers\", a tenant GUID, or a verified domain.")` at authorize-URL build time.

Empty and `null` values fall back to `common` so a bare install still boots.

## `tid` enforcement on callback

The tenant mode isn't just a URL parameter — it's a security boundary. After a successful token exchange, `OAuthManager::handleCallback()` reads the `tid` claim from the returned `id_token` and passes it to `TenantAuthority::assertTidMatches( $tid )`. The per-mode rules:

### `Common`

Any `tid` value is accepted. A missing `tid` is also accepted — the app has opted in to any tenant Microsoft returns, so we make no trust decision on the value.

### `Organizations`

- `tid` is **required**. A missing `tid` throws `OAuthException("Microsoft id_token is missing the tid claim required to enforce the "organizations" authority.")`.
- `tid` must **not** be the Microsoft Services Account (MSA) tenant `9188040d-6c67-4c5b-b112-36a304b66dad`. If it is (personal Microsoft account signed into an `organizations`-only app), throws:
  ```
  Microsoft account (tid 9188040d-…) is a personal account
  and cannot sign in to an "organizations"-only tenant.
  ```

### `Consumers`

- `tid` is **required**.
- `tid` must **be** the MSA tenant. If it isn't (work/school account signed into a `consumers`-only app), throws:
  ```
  Microsoft account (tid …) is a work / school account
  and cannot sign in to a "consumers"-only tenant.
  ```

### `Tenant` (GUID)

- `tid` is **required**.
- `tid` must equal the configured GUID. Mismatch throws:
  ```
  Microsoft token was issued by tenant <actual>
  but this app is registered for tenant <expected>.
  ```

### `Tenant` (verified domain)

- `tid` is **required**.
- Any `tid` value is accepted — the domain-scoped authority Microsoft resolved has already narrowed the flow to one tenant on the authorize side, so whatever `tid` Microsoft returns is fine.
- The `tid` value is captured to the connection so downstream code can still route per-tenant.

## The MSA tenant GUID

Microsoft assigns a stable tenant GUID to personal Microsoft accounts:

```
9188040d-6c67-4c5b-b112-36a304b66dad
```

The `assertTidMatches()` rules use this to distinguish personal accounts from work / school accounts on `common`, `organizations`, and `consumers`. It's exposed as `TenantAuthority::MSA_TENANT_ID` if you need to check for it in your own code.

Reference: [Microsoft id_token claims](https://learn.microsoft.com/entra/identity-platform/id-token-claims-reference).

## Picking a mode

- **Internal tool for one company** → single-tenant with the tenant GUID (or a verified domain). Everyone else — even legitimate employees of other companies you might contract with — is denied.
- **B2B SaaS selling to organizations** → `organizations`. Personal accounts are excluded; every work / school account can sign in and the app can call Graph / other Microsoft APIs against their tenant.
- **B2B + B2C SaaS** → `common`. Anyone with a Microsoft account can sign in.
- **Consumer product** → `common` (if you also want to support work / school accounts as identity) or `consumers` (if you specifically want to reject work accounts).

### Why not always `common`?

Because `common` accepts every Microsoft account, and that's often not what you want:

- A B2B SaaS accepting personal Microsoft accounts creates a support burden — personal accounts don't have tenant admins, don't route through IT, and can't grant admin-consent-required scopes.
- A tool for one company that accepts every account is a compliance and security concern — third parties can sign in.

Pick the narrowest mode your product supports.

## Switching modes after launch

Changing the `MICROSOFT_OAUTH_TENANT` value only affects new consent flows. Existing `microsoft_connections` rows are not re-validated — they keep their previously-issued tokens until refresh or reconnect.

If the mode change tightens the authority (e.g. `common` → `organizations`), you may end up with a mix of connections that are all still "connected" but where some are for account types the new mode wouldn't accept. Two options:

- **Do nothing.** The next refresh will still succeed (refresh doesn't re-validate `tid`), so existing users keep working. Only new connects go through the tightened check.
- **Sweep and disconnect.** Query for connections whose `tid` fails the new authority (`MicrosoftConnection::query()->where(...)`) and call `markDisconnected()` on each.

## Enabling multi-tenant in the Entra portal

Multi-tenant modes (`common`, `organizations`) require the app registration to be set to **Accounts in any organizational directory (Multitenant)** or **Accounts in any organizational directory and personal Microsoft accounts** under **Authentication**. If the registration is single-tenant but `microsoft-oauth.tenant=common`, Microsoft rejects the authorize request from foreign tenants at the browser step with `AADSTS50194` / `AADSTS50020`.

Change the registration under **Authentication → Supported account types** to match your intended tenant mode.

## Deeper reading

- [Microsoft identity platform: tenancy in Azure Active Directory](https://learn.microsoft.com/entra/identity-platform/single-and-multi-tenant-apps)
- [id_token claims reference](https://learn.microsoft.com/entra/identity-platform/id-token-claims-reference)
- [TenantAuthority API reference](API-Reference-Tenant-Authority) — the class internals.
