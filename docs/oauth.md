---
title: OAuth Flow
---

# OAuth Flow

The package implements the **OAuth 2.0 Authorization Code flow with PKCE** against the Microsoft identity platform v2.0 endpoint. Users visit a "Connect Microsoft" link, get redirected to Microsoft's consent screen, come back to a package-owned callback, and land in your app with a persisted `MicrosoftConnection` row.

This page walks through each leg. See the sub-pages for deeper coverage of specific stages.

## Routes

The service provider mounts three routes under the fixed prefix `/auth/microsoft`:

| Route | Method | Name | Middleware | Purpose |
|---|---|---|---|---|
| `/auth/microsoft/connect` | GET | `microsoft.auth.connect` | `web`, `auth` | Redirect to Microsoft's consent screen. |
| `/auth/microsoft/reauthorize` | GET | `microsoft.auth.reauthorize` | `web`, `auth` | Incremental consent for scopes added since the initial connection. |
| `/auth/microsoft/callback` | GET | `microsoft.auth.callback` | `web` | Handle Microsoft's redirect back. |

The prefix, middleware, and names are **fixed** — they're baked into `routes/web.php` inside the package and not driven by config. If you need a different URL structure, skip the built-in routes entirely and wire your own controller that calls `OAuthManager::authorizationUrl()` / `handleCallback()` directly.

The callback route deliberately does NOT require `auth` — the user is mid-redirect from Microsoft and may or may not still be authenticated in the local session, but the OAuth `state` value verifies it's the same browser that started the flow. `connect` and `reauthorize` do require `auth` because they need the authenticated user's identifier to associate the resulting connection with.

## The flow, end to end

```
┌──────────────┐         ┌──────────────────┐         ┌───────────────┐
│  Your app    │         │  Package routes  │         │  Microsoft    │
└──────┬───────┘         └────────┬─────────┘         └──────┬────────┘
       │                          │                          │
       │  User clicks "Connect"   │                          │
       │─────────────────────────>│  GET /connect            │
       │                          │                          │
       │                          │  Build authorize URL     │
       │                          │  Store state + verifier  │
       │                          │  + user_id in session    │
       │                          │─────────────────────────>│
       │                          │                          │  User picks account
       │                          │                          │  User approves scopes
       │                          │                          │
       │                          │<─────────────────────────│  GET /callback?code=…&state=…
       │                          │                          │
       │                          │  Verify state (CSRF)     │
       │                          │  POST /token             │
       │                          │─────────────────────────>│
       │                          │<─────────────────────────│  { access_token, refresh_token,
       │                          │                          │    id_token, expires_in, ... }
       │                          │                          │
       │                          │  Decode id_token claims  │
       │                          │  Enforce tid vs. tenant  │
       │                          │  Persist MicrosoftConn.  │
       │                          │                          │
       │<─────────────────────────│  redirect_after_connect  │
       │                          │  microsoft.status=connected
```

## Connect

`MicrosoftAuthController::connect()` is the entry point. It resolves the authenticated user, calls `OAuthManager::authorizationUrl( $userId )`, and returns a `redirect()->away()` to the built URL.

`authorizationUrl()` builds the URL with:

- `client_id` from the configured [credential driver](Drivers).
- `redirect_uri` from `config('microsoft-oauth.redirect_uri')`.
- `response_type=code` — the authorization-code grant.
- `response_mode=query` — Microsoft returns the code and state on the callback URL's query string.
- `scope` = the space-separated de-duplicated union of everything the [scope registry](Scopes) returns.
- `state` = a random 40-char string, stored in the session.
- `code_challenge` / `code_challenge_method=S256` — PKCE. The verifier is stored in the session; the challenge is the URL-safe base64 of `SHA-256(verifier)`.
- `prompt` = `config('microsoft-oauth.prompt', 'select_account')` on the initial connect; always `consent` on `/reauthorize`.

If the credential driver reports missing `client_id`, this throws `OAuthException("Microsoft OAuth is not configured: client_id is missing.")`. The controller catches this specifically and flashes the message to `microsoft.error`, redirecting to `redirect_after_error`.

Details: [OAuth → Connect](Oauth-Connect).

## Callback

Microsoft redirects back to `microsoft.auth.callback` with either `?code=…&state=…` on success or `?error=…&error_description=…` on failure.

`MicrosoftAuthController::callback()`:

1. If `?error=…` is present, flashes the `error_description` (or `error` if description is empty) to `microsoft.error` and redirects to `redirect_after_error`.
2. Otherwise, requires both `code` and `state` in the query — missing either flashes `"Microsoft callback is missing required code or state parameter."` and redirects.
3. Delegates to `OAuthManager::handleCallback( $code, $state )`.

`handleCallback()`:

1. Pulls the stored `state`, PKCE `code_verifier`, `user_id`, and `incremental` flag from the session. Any missing state or verifier or user_id throws `OAuthException`.
2. Compares the returned `state` against the stored one with `hash_equals()` to defeat timing attacks. Mismatch = `"OAuth state mismatch; possible CSRF attempt."`.
3. POSTs to the token endpoint with `grant_type=authorization_code`, `code`, `code_verifier`, `client_id`, `redirect_uri`, `scope`. Confidential clients also send `client_secret`; public clients omit it.
4. Decodes the returned `id_token`'s payload (base64url) to extract:
    - `oid` (preferred) or `sub` → `microsoft_user_id` (stable per app+user).
    - `email` (preferred) or `preferred_username` → `email`.
    - `tid` → tenant id of the account.
    **The JWT signature is not verified** — the token arrived over TLS from Microsoft's token endpoint on a connection we initiated, so the identity claims are trustworthy for persistence purposes only (not authorization).
5. Enforces the [tenant authority](Tenants) rules against the `tid` claim. A `consumers`-only registration that gets a work-account `tid` throws `OAuthException`.
6. Upserts a `MicrosoftConnection` for the user, sets `access_token`, `refresh_token`, `token_type`, `expires_at`, `scopes`, `tid`, `microsoft_user_id`, `email`, `status = 'connected'`, `disconnect_reason = null`, and saves.

**Concurrent-callback safety**: two callbacks for the same user racing at the same millisecond can both call `firstOrNew()` and both try to `INSERT` because the row didn't exist yet. `persistConnection()` catches the driver-specific duplicate-key error (`23505` on Postgres; `23000` + `1062` / `UNIQUE constraint failed` / `Duplicate entry` on MySQL / SQLite) and retries against the row the other request just inserted.

**Refresh token behavior**: Microsoft returns a `refresh_token` on every successful exchange when `offline_access` is granted (unlike Google, which only issues one on first consent). `applyTokens()` still guards against a missing `refresh_token` in the response — if it isn't present, the existing stored token is preserved rather than wiped.

Details: [OAuth → Callback](Oauth-Callback).

## Reauthorize

When a new service package is installed after a user is already connected, the [scope registry](Scopes) starts returning scopes the connection doesn't hold. `MicrosoftAuthController::reauthorize()`:

1. Requires an authenticated user.
2. Calls `OAuthManager::incrementalAuthorizationUrl( $userId )`, which returns either an authorization URL or an `IncrementalConsentResult` enum case.
3. On `IncrementalConsentResult::NoConnection` (user has never connected) → redirects to `/connect` so they get a real authorization prompt.
4. On `IncrementalConsentResult::AlreadyAuthorized` (every registered scope already granted) → redirects to `redirect_after_connect` with `microsoft.status=already-authorized`.
5. Otherwise → `redirect()->away()` to the incremental URL.

The incremental URL requests the full scope union (so the returned token covers everything) but always uses `prompt=consent` so the consent screen actually shows. Microsoft's UI only asks the user to approve the delta — scopes they've already granted are silently re-included.

Details: [OAuth → Reauthorize](Oauth-Reauthorize).

## No `/disconnect` route

Unlike some sibling packages, this one does **not** ship a `/disconnect` route or a scope-limited disconnect UI. Marking a connection disconnected is a two-line call:

```php
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;

MicrosoftConnection::where( 'user_id', $user->id )->first()
    ?->markDisconnected( 'Disconnected by user.' );
```

Wire that into whatever surface makes sense in your app (a settings page, an admin action, a delete-account flow). If you need remote revocation on Microsoft's side, POST to Microsoft's logout endpoint from your own code before calling `markDisconnected()` — see [FAQ](FAQ#runtime).

## Exceptions

The OAuth manager throws two exception types:

- `ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException` — thrown by `authorizationUrl()` when the tenant is invalid or `client_id` is missing, and by `handleCallback()` on state mismatch, missing PKCE verifier, missing user_id, `tid`-authority mismatch, or a failed code exchange.
- `ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException` — thrown by the [token manager](Tokens) when a refresh fails. See its page for the terminal-error rules.
- `ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException` — a subclass of `OAuthException` thrown by `DefaultTokenProvider` when a user has no `MicrosoftConnection` on file at all.

The default controller catches `OAuthException` in `connect()`, `reauthorize()`, and `callback()` and flashes the message; other callers should handle it themselves.

## Deeper topics

- [Connect](Oauth-Connect) — building the authorize URL, PKCE, session state, error modes.
- [Callback](Oauth-Callback) — code exchange, id_token decoding, `tid` enforcement, concurrent-callback race handling.
- [Reauthorize](Oauth-Reauthorize) — incremental consent details, `IncrementalConsentResult` cases, when to trigger it.

---
Continue to [Tenants](Tenants) →
