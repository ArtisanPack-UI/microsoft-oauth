---
title: MicrosoftConnection
---

# `MicrosoftConnection`

`ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection` — the Eloquent model representing a single connected Microsoft account for a user.

For narrative coverage — schema, casts, relationships, and querying — see [Connection Model](Connection-Model). This page is a compact method / property reference.

## Table

```
microsoft_connections
```

One row per user (`user_id` has a unique index).

## Properties

| Property | Type | Notes |
|---|---|---|
| `id` | `int` | Primary key. |
| `user_id` | `int` | Unique. |
| `microsoft_user_id` | `?string` | `oid` preferred, `sub` fallback. |
| `email` | `?string` | `email` preferred, `preferred_username` fallback. |
| `tid` | `?string` | Tenant id from the `id_token`. |
| `access_token` | `?string` | Encrypted at rest. |
| `refresh_token` | `?string` | Encrypted at rest. Microsoft rotates on refresh. |
| `token_type` | `string` | Always `Bearer` in practice. |
| `scopes` | `?array<int, string>` | JSON list. |
| `expires_at` | `?\Illuminate\Support\Carbon` | Access-token expiry. |
| `status` | `string` | `connected` or `disconnected`. |
| `disconnect_reason` | `?string` | Set by `markDisconnected()`. Null while connected. |

## Constants

```php
public const STATUS_CONNECTED    = 'connected';
public const STATUS_DISCONNECTED = 'disconnected';
```

Use these when comparing `$connection->status`.

## Casts

```php
protected function casts(): array
{
    return [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes'        => 'array',
        'expires_at'    => 'datetime',
    ];
}
```

## Methods

### `isConnected(): bool`

Whether the connection is usable for API calls.

```php
return self::STATUS_CONNECTED === $this->status;
```

### `isExpired(): bool`

Whether the stored access token is expired or will expire in the next 60 seconds.

```php
if ( null === $this->expires_at ) {
    return true;
}

return $this->expires_at->copy()->subSeconds( 60 )->isPast();
```

Missing `expires_at` is treated as expired — the next call triggers a refresh.

### `grantedScopes(): array`

The scopes granted by Microsoft to this connection, normalized to a `list<string>`. Handles the null / mixed-type edge cases that a raw `$this->scopes` access could hit.

### `markDisconnected( ?string $reason = null ): void`

Set `status = 'disconnected'` and `disconnect_reason = $reason`, save.

Local-only — does not revoke the token with Microsoft. Called automatically by:

- `TokenManager::refresh()` on terminal errors (`invalid_grant`, `interaction_required`, `consent_required`, `login_required`).
- `TokenManager::refresh()` when there's no refresh token on file.

## Relationships

### `user(): BelongsTo`

The user that owns this Microsoft connection. Target model comes from `config('microsoft-oauth.user_model')` (defaults to `App\Models\User`).

```php
$connection->user;
```

The inverse `hasOne` on the user model is **not** added automatically — add it yourself if you want `$user->microsoftConnection`.

## Fillable

```php
protected $fillable = [
    'user_id',
    'microsoft_user_id',
    'email',
    'tid',
    'access_token',
    'refresh_token',
    'token_type',
    'scopes',
    'expires_at',
    'status',
    'disconnect_reason',
];
```

Every column except `id` and timestamps is mass-assignable. The OAuth flow uses `firstOrNew()` and direct property assignment, so fillable doesn't gate what the package itself writes — this list is here for anyone using `MicrosoftConnection::create()` / `update()` from application code.
