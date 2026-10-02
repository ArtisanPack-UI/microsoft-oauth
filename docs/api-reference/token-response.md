---
title: TokenResponse
---

# `TokenResponse`

*Added in 1.1.0.*

`ArtisanPackUI\MicrosoftOAuth\OAuth\TokenResponse`: an immutable (`final`, all-`readonly`) value object describing a successful code exchange or token refresh. Returned by [`MicrosoftClient`](API-Reference-Microsoft-Client) and [`BrokerClient`](API-Reference-Broker-Client). Nothing about it is persisted. `OAuthManager` and `TokenManager` copy it onto a `MicrosoftConnection`.

## Properties

| Property | Type | Source |
|---|---|---|
| `accessToken` | `string` | `access_token` |
| `refreshToken` | `?string` | `refresh_token`, else the refresh token passed to `refresh()` |
| `tokenType` | `string` | `token_type`, else `Bearer` |
| `expiresIn` | `?int` | `expires_in` (seconds) |
| `expiresAt` | `?Carbon` | now + `expiresIn` |
| `scopes` | `list<string>` | Microsoft: space-separated `scope`. Broker: `scopes` array. Trimmed, de-duplicated. Empty when not reported. |
| `idToken` | `?string` | Raw `id_token` JWT (signature **not** verified) |
| `accountId` | `?string` | id_token `oid` claim, else `sub` |
| `accountEmail` | `?string` | Broker `account_email`, else id_token `email`, else `preferred_username` |
| `accountName` | `?string` | Broker `account_name`, else id_token `name` |
| `tenantId` | `?string` | id_token `tid` claim |

The id_token claims are decoded without signature verification. They're used to label the stored connection and for the tenant check, never for authorization. See [OAuth → Callback](Oauth-Callback#5-decode-the-id_token).

## Static constructors

### `fromMicrosoft( array $payload, ?string $fallbackRefreshToken = null ): self`

Build from a Microsoft token-endpoint payload. `$payload` must contain `access_token`.

### `fromBroker( array $payload, ?string $fallbackRefreshToken = null ): self`

Build from a broker `/token` or `/refresh` payload. The broker's `scopes` array, `account_email`, and `account_name` take precedence over the id_token claims.

## Methods

### `toArray(): array`

Render in the broker's site-facing JSON shape. A broker can return this verbatim from `/token` and `/refresh`.

```php
[
    'token_type'    => 'Bearer',
    'access_token'  => '…',
    'refresh_token' => '…',
    'expires_in'    => 3600,
    'scopes'        => [ 'openid', 'profile', 'email', 'offline_access', '…' ],
    'account_email' => 'user@contoso.com',
    'account_name'  => 'Ada Lovelace',
    'id_token'      => 'eyJ…',
]
```
