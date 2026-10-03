---
title: BrokerClient
---

# `BrokerClient`

*Added in 1.1.0.*

`ArtisanPackUI\MicrosoftOAuth\Broker\BrokerClient`: the site-facing side of the OAuth broker contract. It builds signed `/authorize` links, exchanges the one-time code at `/token`, and refreshes at `/refresh`.

For narrative coverage, see [Broker Mode](Broker). `OAuthManager` and `TokenManager` use this class when `microsoft-oauth.mode` is `broker`.

## Getting an instance

```php
MicrosoftOAuth::broker();                         // configured credentials
MicrosoftOAuth::broker( $credentials );           // explicit BrokerCredentials
app( OAuthManager::class )->brokerClient();
BrokerClient::fromConfig( $config, $http );
```

## Constants

| Constant | Value |
|---|---|
| `PROVIDER` | `microsoft` |
| `LINK_TTL_SECONDS` | `300`. How long a signed `/authorize` link stays valid. The broker accepts at most 10 minutes. |

## Constructor

```php
public function __construct(
    protected BrokerCredentials $credentials,
    protected HttpFactory $http,
) {}
```

## Static methods

### `isEnabled( ConfigRepository $config ): bool`

Whether `microsoft-oauth.mode` is `broker`.

### `fromConfig( ConfigRepository $config, HttpFactory $http ): self`

Build a client from `BrokerCredentials::fromConfig()`.

**Throws:** `OAuthException("Microsoft OAuth broker credentials are not configured.")` when the URL, site ID, or site secret is missing. Also throws `OAuthException` when the URL isn't secure.

## Methods

### `credentials(): BrokerCredentials`

The credentials this client authenticates with.

### `authorizationUrl( string $state, string $returnUrl, ?array $scopes = null ): string`

Build the signed `{url}/api/v1/oauth/microsoft/authorize` URL with `site_id`, `state`, `return_url`, `expires` (now + `LINK_TTL_SECONDS`), `scopes` (space-separated, omitted when null or empty), and `signature`. Encoded per RFC 3986.

**Params:**

- `$state`: site-generated state, echoed back on return.
- `$returnUrl`: where the broker sends the browser back. Must be on the site's registered URL.
- `$scopes`: scopes to request. `null` asks for every scope the broker allows.

### `signature( array $params ): string`

HMAC-SHA256 signature for a set of `/authorize` parameters. `signature` is dropped if present, the rest are key-sorted and RFC 3986-encoded, prefixed with `microsoft\n`, and signed with `BrokerCredentials::signingKey()`.

### `exchangeCode( string $code ): TokenResponse`

`POST {url}/api/v1/oauth/token` with `grant_type=authorization_code` and `code`, authenticated with the site secret as a bearer token.

**Returns:** [`TokenResponse`](API-Reference-Token-Response), built with `TokenResponse::fromBroker()`.

**Throws:** `OAuthException`. `getError()` is the broker's `error`, `license_expired` for a `402` with none, or `exchange_failed`. `getRenewUrl()` carries the broker's `renew_url` when present.

### `refresh( string $refreshToken ): TokenResponse`

`POST {url}/api/v1/oauth/refresh` with `refresh_token` and `provider=microsoft`, authenticated with the site secret.

**Returns:** [`TokenResponse`](API-Reference-Token-Response). Its `refreshToken` is the rotated token, or `$refreshToken` when the broker returned none.

**Throws:**

- `LicenseExpiredException`: `error=license_expired`, or HTTP `402` with no `error`. `getRenewUrl()` carries the renewal URL.
- `TokenRefreshException`: any other failure. `getError()` is `invalid_grant` for a revoked grant, or `refresh_failed` when no error code was reported (including a 2xx response without `access_token`).

### `isTrustedRenewUrl( ?string $url ): bool`

Whether a `renew_url` is safe to link to. Returns true only when the URL:

- has no backslashes, whitespace, control characters, or userinfo,
- is on the configured broker host, and
- uses HTTPS, or HTTP when the broker itself is HTTP.

`OAuthManager::isTrustedRenewUrl()` wraps this and additionally returns false outside broker mode.

## `BrokerCredentials`

`ArtisanPackUI\MicrosoftOAuth\Broker\BrokerCredentials` is an immutable `final` value object.

```php
public function __construct(
    public readonly string $url,         // broker base URL, no trailing slash
    public readonly string $siteId,
    public readonly string $siteSecret,  // `{id}|{plain}`, sent as the bearer token
) {}
```

**Throws:** `OAuthException` when `$url` isn't secure (see `isSecureUrl()`).

### `isSecureUrl( string $url ): bool` (static)

True for `https://` URLs, and for `http://` only on `localhost`, `*.localhost`, `*.test`, `127.x.x.x`, and `::1`.

### `fromConfig( ConfigRepository $config ): ?self` (static)

Read `microsoft-oauth.broker.url`, `site_id`, and `site_secret`, pass them through the `ap.microsoft.oauth.broker.credentials` filter, trim them (and the URL's trailing slash), and build credentials. Returns `null` when any of the three is empty or the filter doesn't return an array.

**Throws:** `OAuthException` when the resolved URL isn't secure.

### `signingKey(): string`

`hash_hmac( 'sha256', BrokerCredentials::SIGNING_KEY_LABEL, $plain )`, where `SIGNING_KEY_LABEL` is `jmwd-workshop:oauth-authorize` and `$plain` is the plain part of the site secret: everything after the first `|`, or the whole secret when there is no `|`. Used to sign `/authorize` links. (Before 1.2.0 the key was `sha256( $plain )`, which the broker no longer accepts.)
