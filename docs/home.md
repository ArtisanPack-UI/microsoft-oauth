---
title: Home
---

# ArtisanPack UI Microsoft OAuth Documentation

Welcome to the documentation for **ArtisanPack UI Microsoft OAuth** — the shared Microsoft identity platform (Entra / Azure AD) OAuth2 broker that powers every ArtisanPack UI Microsoft service integration.

Service packages like `artisanpack-ui/bing-places` and future Microsoft Graph integrations sit on top of this package — they contribute the scopes they need, then call the shared token manager (or its facade wrapper) to make authenticated API calls. They never handle OAuth themselves.

Use the navigation below to explore topics. Links use the GitLab / GitHub wiki page style, so you can jump between pages like [Getting Started](Getting-Started) or [OAuth Flow](Oauth).

- [Getting Started](Getting-Started)
- [Installation](Installation)
- [Credential Drivers](Drivers)
- [OAuth Flow](Oauth)
- [Tenants](Tenants)
- [Scopes](Scopes)
- [Tokens](Tokens)
- [Connection Model](Connection-Model)
- [API Reference](API-Reference)
- [Testing](Testing)
- [FAQ](FAQ)
- [Contributing](Contributing)

If you're new here, start with [Getting Started](Getting-Started).

## What this package does

`artisanpack-ui/microsoft-oauth` owns the shared plumbing every ArtisanPack UI Microsoft integration sits on top of. It provides:

- **OAuth2 authorization-code + PKCE flow** against the Microsoft identity platform v2.0 endpoint — builds the consent URL, exchanges the callback code, and extracts the user's Microsoft identity from the returned `id_token`.
- **Encrypted token storage** on a per-user `microsoft_connections` model, with transparent refresh via the [token manager](Tokens).
- A **scope registry** that lets any installed service package contribute the scopes it needs. Consent covers the union so users only see one screen.
- **Tenant authority resolution** — `common` / `organizations` / `consumers` / GUID / verified domain, with `tid` enforcement on callback.
- **Credential storage drivers** ([config, database, or CMS](Drivers)) so credentials can live wherever a project already stores its secrets.
- A **bearer-ready HTTP client** — `MicrosoftOAuth::request( $userId )` returns a `PendingRequest` pre-configured with `Authorization: Bearer …` so downstream packages never touch OAuth internals.

## What this package does not do

- It does **not** call Microsoft APIs. That is the responsibility of the service packages (Bing Places, Graph integrations, …).
- It does **not** ship a connection-management UI (Livewire / React / Vue). If you need one, mount your own button on `route('microsoft.auth.connect')` and read connection state directly from the `MicrosoftConnection` model.
- It does **not** ship a `/disconnect` route. Marking a connection disconnected is a two-line call to `MicrosoftConnection::markDisconnected()` on whatever surface makes sense in your app.
- It does **not** perform remote token revocation. If you need to invalidate the refresh token on Microsoft's side, POST to Microsoft's logout / token-revocation endpoints from your own code before you call `markDisconnected()`.
