---
title: Connection Model
---

# Connection Model

The `MicrosoftConnection` Eloquent model represents a single connected Microsoft account for a user. One row per user (`user_id` has a unique index) — connecting a different Microsoft account overwrites the same row.

## Schema

```php
Schema::create( 'microsoft_connections', function ( Blueprint $table ): void {
    $table->id();
    $table->unsignedBigInteger( 'user_id' );
    $table->string( 'microsoft_user_id' )->nullable();
    $table->string( 'email' )->nullable();
    $table->string( 'tid' )->nullable();
    $table->longText( 'access_token' )->nullable();     // encrypted
    $table->longText( 'refresh_token' )->nullable();    // encrypted
    $table->string( 'token_type' )->default( 'Bearer' );
    $table->text( 'scopes' )->nullable();               // JSON array
    $table->timestamp( 'expires_at' )->nullable();
    $table->string( 'status' )->default( 'connected' );
    $table->text( 'disconnect_reason' )->nullable();
    $table->timestamps();

    $table->unique( 'user_id' );
    $table->index( [ 'user_id', 'status' ] );
} );
```

## Column reference

| Column | Type | Encryption | Purpose |
|---|---|---|---|
| `id` | bigint | — | Primary key. |
| `user_id` | bigint | — | FK to your `users` table (or the model in `microsoft-oauth.user_model`). Unique — one Microsoft account per user. |
| `microsoft_user_id` | string | — | Stable Microsoft account id. Sourced from `oid` when present (work / school accounts — stable across tenants) or `sub` (personal accounts — stable per app+user). Useful for detecting "user reconnected with a different Microsoft account". |
| `email` | string | — | Email address from the `id_token`. Sourced from `email` when present, `preferred_username` otherwise. Displayed as "Connected as {email}". |
| `tid` | string | — | Tenant id (`tid` claim) of the Microsoft account. On multi-tenant authorities (`common` / `organizations` / `consumers`) this identifies which tenant issued the token — useful for downstream integrations that route per-tenant. On single-tenant configs it matches the configured tenant. |
| `access_token` | longText | `encrypted` cast | Bearer token used for API calls. Refreshed transparently by the [token manager](Tokens). Uses `longText` because Microsoft access tokens can be large (Graph tokens frequently exceed 4 KB). |
| `refresh_token` | longText | `encrypted` cast | Long-lived token used to mint new access tokens. **Microsoft rotates this on every refresh** — the token manager overwrites it whenever a refresh response includes one. |
| `token_type` | string | — | Always `Bearer` in practice. |
| `scopes` | text | `array` cast | JSON list of scopes Microsoft returned in the exchange response. Used by the scope registry to compute `missing()`. On incremental consent, unioned with the previously-recorded scopes. |
| `expires_at` | timestamp | `datetime` cast | When the current `access_token` expires. Treated as expired 60s before this value. |
| `status` | string | — | Either `'connected'` or `'disconnected'`. Use `MicrosoftConnection::STATUS_CONNECTED` / `STATUS_DISCONNECTED` constants. |
| `disconnect_reason` | text | — | Why the connection is disconnected. Set by `markDisconnected( $reason )`. Nulled on every successful connect / reauthorize. |

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

- **`encrypted`** — Eloquent transparently encrypts on save, decrypts on read. Depends on `APP_KEY`.
- **`array`** — Stored as JSON, exposed as a PHP array. `$connection->scopes[] = 'new-scope'` and save works as expected.
- **`datetime`** — Returns a Carbon instance.

> **Note on Laravel 10.** This package's `composer.json` includes `illuminate/support: ^10.0` in its constraint, but the `casts()` method syntax used above was added in Laravel 11. On Laravel 10, casts declared this way are silently ignored — the encrypted / array / datetime casts would not apply. If you are running Laravel 10 today, either upgrade to Laravel 11+ or add a `$casts` array property to the model in a bind override.

## Relationships

```php
public function user(): BelongsTo
{
    /** @var class-string<Model> $userModel */
    $userModel = config( 'microsoft-oauth.user_model', 'App\\Models\\User' );

    return $this->belongsTo( $userModel, 'user_id' );
}
```

The user model is resolved from config, so:

```php
$connection->user;                // instance of your configured user model
$user->microsoftConnection;       // define this yourself if you want the inverse
```

The package doesn't add an inverse `hasOne` to your User model automatically — add it if you like:

```php
// app/Models/User.php
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;

public function microsoftConnection(): HasOne
{
    return $this->hasOne( MicrosoftConnection::class );
}
```

## Methods

### `isConnected(): bool`

```php
public function isConnected(): bool
{
    return self::STATUS_CONNECTED === $this->status;
}
```

Whether the connection is usable for API calls. Cheap check; use freely.

### `isExpired(): bool`

```php
public function isExpired(): bool
{
    if ( null === $this->expires_at ) {
        return true;
    }

    return $this->expires_at->copy()->subSeconds( 60 )->isPast();
}
```

Whether the stored access token is expired **or will expire in the next 60 seconds**. The token manager uses this to decide whether to refresh — don't second-guess it in your own code.

### `grantedScopes(): array`

```php
public function grantedScopes(): array
{
    $scopes = $this->scopes;

    if ( ! is_array( $scopes ) ) {
        return [];
    }

    return array_values( array_map( 'strval', $scopes ) );
}
```

The scopes granted by Microsoft to this connection, normalized to a list of strings. Handles the null / mixed-type edge cases that a raw `$this->scopes` access could hit.

Pass this to `ScopeRegistry::missing( $granted )` when computing what to request in an incremental-consent flow — but usually you don't need to, since `OAuthManager::incrementalAuthorizationUrl()` does it for you.

### `markDisconnected( ?string $reason = null ): void`

```php
public function markDisconnected( ?string $reason = null ): void
{
    $this->status            = self::STATUS_DISCONNECTED;
    $this->disconnect_reason = $reason;
    $this->save();
}
```

Marks the connection disconnected. Local-only — does not revoke the refresh token with Microsoft. Called automatically by the token manager on terminal refresh errors (`invalid_grant`, `interaction_required`, `consent_required`, `login_required`) and on missing refresh token.

Call it yourself from a "Disconnect" action in your UI, or from any code path where you want to stop using the connection.

## Constants

- `MicrosoftConnection::STATUS_CONNECTED = 'connected'`
- `MicrosoftConnection::STATUS_DISCONNECTED = 'disconnected'`

Use these instead of string literals when comparing.

## Querying

The package doesn't ship a custom query scope, but the two indexes (`unique user_id` and `[user_id, status]`) cover the common queries:

```php
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;

// The connection for a specific user
MicrosoftConnection::firstWhere( 'user_id', $userId );

// Only connected connections
MicrosoftConnection::where( 'user_id', $userId )
    ->where( 'status', MicrosoftConnection::STATUS_CONNECTED )
    ->first();

// Every disconnected connection (e.g. for a sweep)
MicrosoftConnection::where( 'status', MicrosoftConnection::STATUS_DISCONNECTED )->get();

// Connections that need reauthorization (missing scopes)
// — has to be computed per-connection with the ScopeRegistry
MicrosoftConnection::where( 'status', MicrosoftConnection::STATUS_CONNECTED )
    ->get()
    ->filter( fn ( $c ) => ! app( ScopeRegistry::class )->hasAllRequired( $c->grantedScopes() ) );
```
