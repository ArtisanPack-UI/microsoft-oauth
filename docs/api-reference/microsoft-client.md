---
title: MicrosoftClient
---

# `MicrosoftClient`

*Added in 1.1.0.*

`ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftClient`: stateless OAuth primitives for the Microsoft identity platform v2.0 endpoints. Nothing here touches the session or the database.

For narrative coverage, see [Stateless Client](Stateless-Client). `OAuthManager` and `TokenManager` wrap this class in direct mode.

## Getting an instance

```php
MicrosoftOAuth::client();                    // configured credential driver
MicrosoftOAuth::client( $credentials );      // explicit MicrosoftCredentials
app( OAuthManager::class )->client( $credentials );
MicrosoftClient::make( $credentialsOrRepository, $http, $config );
```

## Constants

| Constant | Value |
|---|---|
| `LOGIN_BASE_URL` | `https://login.microsoftonline.com` |
| `RESERVED_PARAMETERS` | `client_id`, `redirect_uri`, `response_type`, `scope`, `state`, `code_challenge`, `code_challenge_method`. Keys `authorizationUrl()` won't let `$parameters` override. |

## Constructor

```php
public function __construct(
    protected MicrosoftCredentials $credentials,
    protected HttpFactory $http,
) {}
```

## Static methods

### `make( MicrosoftCredentials|ConfigurationRepository $credentials, HttpFactory $http, ConfigRepository $config ): self`

Build a client from explicit credentials, or from a credential driver. With a driver, `config('microsoft-oauth.redirect_uri')` fills in a redirect URI the driver doesn't supply.

### `generateCodeVerifier(): string`

A random PKCE verifier: URL-safe base64 of 64 random bytes.

### `codeChallenge( string $verifier ): string`

The `S256` challenge for a verifier: URL-safe base64 of `SHA-256(verifier)`.

### `verifyState( ?string $expected, string $returned ): void`

Constant-time comparison of the state echoed back on the callback.

**Throws:** `OAuthException("OAuth state mismatch; possible CSRF attempt.")` when they differ or `$expected` is null / empty.

## Methods

### `credentials(): MicrosoftCredentials`

The credentials this client authenticates with.

### `authority(): TenantAuthority`

The [tenant authority](API-Reference-Tenant-Authority) the credentials resolve to. **Throws** `OAuthException` for an unrecognized tenant.

### `authorizationUrl( string $state, array $scopes, array $parameters = [], ?string $codeVerifier = null ): string`

Build the Microsoft consent URL: `{LOGIN_BASE_URL}/{authority}/oauth2/v2.0/authorize`.

**Params:**

- `$state`: caller-generated state, echoed back on the callback.
- `$scopes`: scopes to request, sent as-is. Include `offline_access` for a refresh token.
- `$parameters`: extra query parameters (`prompt`, `login_hint`, `domain_hint`, …). They can override `response_mode`. Keys in `RESERVED_PARAMETERS` are ignored.
- `$codeVerifier`: PKCE verifier. When given, `code_challenge` and `code_challenge_method=S256` are added. Omit it to skip PKCE.

Always sends `response_type=code` and `response_mode=query` (unless overridden).

**Throws:** `OAuthException` when the client ID (`getError()` = `invalid_client`) or redirect URI (`invalid_request`) is missing, or the tenant is invalid.

### `exchangeCode( string $code, array $scopes, ?string $codeVerifier = null ): TokenResponse`

Exchange an authorization code at `/oauth2/v2.0/token` without persisting anything. Sends `client_id`, `redirect_uri`, `grant_type=authorization_code`, `code`, `scope`, `code_verifier` when given, and `client_secret` when the credentials have one.

Then checks the id_token `tid` against the tenant authority (`TenantAuthority::assertTidMatches()`).

**Returns:** [`TokenResponse`](API-Reference-Token-Response).

**Throws:** `OAuthException`. `getError()` is Microsoft's `error`, `exchange_failed` when none was reported, or `invalid_payload` when the 2xx response had no `access_token`. A `tid` mismatch also throws.

### `refresh( string $refreshToken, array $scopes = [] ): TokenResponse`

Redeem a refresh token at `/oauth2/v2.0/token` with `grant_type=refresh_token`. `scope` is sent when `$scopes` is non-empty. Microsoft requires it on v2.0 refreshes, so pass what the grant holds.

**Returns:** [`TokenResponse`](API-Reference-Token-Response). Its `refreshToken` is the rotated token, or `$refreshToken` when Microsoft returned none.

**Throws:** `TokenRefreshException`. `getError()` is:

- `invalid_client`: no client ID configured.
- `invalid_tenant`: the tenant isn't a recognized authority form.
- `invalid_payload`: a 2xx response without `access_token`.
- `refresh_failed`: a non-2xx response with no `error`.
- Otherwise Microsoft's `error` (`invalid_grant`, `interaction_required`, …).

## `MicrosoftCredentials`

`ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftCredentials` is an immutable `final` value object.

```php
public function __construct(
    public readonly string $clientId,
    public readonly ?string $clientSecret = null,  // null for public clients (PKCE only)
    public readonly ?string $tenant = null,        // null means `common`
    public readonly ?string $redirectUri = null,   // needed only for the consent URL and code exchange
) {}
```

### `fromRepository( ConfigurationRepository $repository, ?string $fallbackRedirectUri = null ): self`

Read credentials from a driver. Empty strings become `null`. The redirect URI comes from the driver when it implements [`ProvidesRedirectUri`](API-Reference-Configuration-Repository#providesredirecturi), else from `$fallbackRedirectUri`.
