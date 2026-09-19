# ArtisanPack UI Microsoft OAuth Changelog

## [1.0.0] - 2026-09-18

First stable release of `artisanpack-ui/microsoft-oauth`: the shared Microsoft
identity platform (Entra / Azure AD) OAuth2 broker that powers ArtisanPack UI's
Microsoft service integrations.

### Added

- Microsoft identity platform v2.0 authorization-code flow, including PKCE, the
  authorize redirect, and the callback exchange (#2).
- Encrypted token storage with automatic refresh: access and refresh tokens are
  encrypted at rest and refreshed transparently ahead of expiry (#3).
- `ScopeRegistry` for declaring the scopes a connection needs, exposed through
  the `ap.microsoft.oauth.scopes` filter hook so other packages can extend the
  scope set (#4).
- Configuration repository with pluggable drivers: a `config` driver backed by
  Laravel config and a `database` driver for runtime-editable credentials (#5).
- CMS Settings bridge for the `database` driver so admins can edit Microsoft
  credentials through the ArtisanPack UI CMS Settings module, with guards for
  a missing CMS framework and for decrypt-failure config drift (#6).
- Incremental consent flow that re-prompts the user when a new service adds
  scopes to an existing connection (#7).
- `TokenProvider` contract and `MicrosoftOAuthManager::request()` consumer API
  so downstream packages fetch tokens through a single, refresh-aware entry
  point (#8).
- Multi-tenant vs single-tenant support with configured tenant-authority
  validation and `id_token` `tid` claim enforcement so tokens from unexpected
  tenants are rejected (#9).
- Comprehensive Pest test coverage for the OAuth callback, refresh path,
  scope registry, and configuration repository (#11).

### Documentation

- Full README and `docs/` tree covering installation, drivers, the OAuth flow,
  tenants, scopes, tokens, the connection model, API reference, testing, FAQ,
  and contributing, plus an Entra / Azure AD app-registration walkthrough with
  redirect URI, client secret, API permissions, and single- vs. multi-tenant
  guidance (#10).
