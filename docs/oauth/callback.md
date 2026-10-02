---
title: Callback
---

# Callback

`MicrosoftAuthController::callback()` handles Microsoft's redirect back to your app after the user consents (or declines).

## The controller

```php
public function callback( Request $request ): RedirectResponse
{
    $error = $this->stringQuery( $request, 'error' );

    if ( '' !== $error ) {
        $description = $this->stringQuery( $request, 'error_description' );
        $message     = '' !== $description ? $description : $error;

        return $this->redirectWithError( $message, $this->stringQuery( $request, 'renew_url' ) );
    }

    $code  = $this->stringQuery( $request, 'code' );
    $state = $this->stringQuery( $request, 'state' );

    if ( '' === $code || '' === $state ) {
        return $this->redirectAfterError()->with(
            'microsoft.error',
            __( 'Microsoft callback is missing required code or state parameter.' ),
        );
    }

    try {
        $this->oauth->handleCallback( $code, $state );
    } catch ( OAuthException $e ) {
        return $this->redirectWithError( $e->getMessage(), (string) $e->getRenewUrl() );
    }

    return $this->redirectAfterConnect()->with( 'microsoft.status', 'connected' );
}
```

Route: `GET /auth/microsoft/callback` → `microsoft.auth.callback`, middleware `web` (no `auth` — the user is mid-redirect and may not still have a session cookie in every configuration; the `state` value proves same-browser).

`stringQuery()` guards against `?code[]=x` array shapes that would otherwise stringify to "Array" and defeat the equality checks.

`redirectWithError()` (since 1.1.0) flashes `microsoft.error` and redirects to `redirect_after_error`. In [broker mode](Broker#renew_url-on-the-callback) it also flashes `microsoft.renew_url`, but only when `OAuthManager::isTrustedRenewUrl()` confirms the URL is on the broker's own host over HTTPS. Outside broker mode, a `renew_url` query parameter is ignored.

## Error responses from Microsoft

Microsoft can return `?error=…` for a range of reasons — user declined consent, invalid_scope, unauthorized_client, interaction_required, admin consent required, etc. The controller shows whichever is more descriptive (`error_description` if present, `error` otherwise) as the flash message.

Common errors and what they mean:

| Error | Meaning |
|---|---|
| `access_denied` | User clicked "Cancel" on the consent screen. |
| `invalid_scope` | A scope in the request isn't listed under **API permissions** on the app registration. |
| `interaction_required` | Only when `prompt=none` — the user needs to actually see a consent screen. |
| `consent_required` | Same class — admin or user consent is required for a requested scope. |
| `login_required` | `prompt=none` and the user isn't signed in to Microsoft. |
| `unauthorized_client` | The app registration is misconfigured — check that the redirect URI is registered as **Web** (not SPA / native). |

Read them from the redirect target:

```blade
@if( $error = session( 'microsoft.error' ) )
    <div class="alert alert-error">{{ $error }}</div>
@endif
```

## `handleCallback()` — step by step

### 1. Pull session state

```php
$storedState = $this->session->pull( self::SESSION_STATE );
$verifier    = $this->session->pull( self::SESSION_VERIFIER );
$userId      = $this->session->pull( self::SESSION_USER_ID );
$incremental = (bool) $this->session->pull( self::SESSION_INCREMENTAL, false );
```

All four values are `pull()`ed — read and deleted in one step. This prevents replaying the same callback URL.

### 2. Validate state (CSRF defense)

```php
MicrosoftClient::verifyState( empty( $storedState ) ? null : (string) $storedState, $returnedState );
```

`verifyState()` throws `OAuthException("OAuth state mismatch; possible CSRF attempt.")` when the stored state is empty or differs. It compares with `hash_equals()`, which runs in constant time to prevent timing attacks. A mismatch here almost always means either:

- The session was lost between `/connect` and `/callback` (bad session driver, cookie samesite, cookie domain).
- A stale callback URL is being replayed.
- Someone is attempting CSRF.

### 3. Validate PKCE verifier and user context

```php
if ( ! $usesBroker && empty( $verifier ) ) {
    throw new OAuthException( __( 'PKCE code verifier missing from session.' ) );
}

if ( empty( $userId ) ) {
    throw new OAuthException( __( 'OAuth session missing user context.' ) );
}
```

Both mean the session state that `authorizationUrl()` wrote is gone. Same debugging story as state mismatch above. In broker mode there is no verifier, because the broker runs PKCE with Microsoft itself.

### 4. Exchange the code

```php
$tokens = $usesBroker
    ? $this->brokerClient()->exchangeCode( $code )
    : $this->client()->exchangeCode( $code, $this->mergeScopes( [] ), (string) $verifier );
```

In direct mode, [`MicrosoftClient::exchangeCode()`](API-Reference-Microsoft-Client) posts:

```php
$form = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'scope'         => implode( ' ', $scopes ),
    'code_verifier' => $verifier,
];

// Confidential clients only:
$form[ 'client_secret' ] = $clientSecret;
```

Both return a [`TokenResponse`](API-Reference-Token-Response). Steps 5 and 6 below describe the direct path. In broker mode the broker has already done them, and its response reports the account email and name directly.

The endpoint is `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`.

`client_secret` is only sent when the credential driver returns a non-empty value — public clients (SPA / native) legitimately have no secret and PKCE alone is enough proof-of-possession.

Non-2xx responses throw `OAuthException("Microsoft code exchange failed: {error}")` — the error string is Microsoft's `error_description` if present, otherwise `error`, otherwise `exchange_failed`. `getError()` returns the code itself. A 2xx response without an `access_token` throws `"Microsoft code exchange returned an invalid payload."` (`invalid_payload`).

### 5. Decode the id_token

`TokenResponse::fromMicrosoft()` decodes the id_token payload into `accountId`, `accountEmail`, `accountName`, and `tenantId`. The claims used:

- **`oid` (preferred) or `sub`** — the stable Microsoft user identifier. `oid` is stable across tenants for a work/school account; `sub` is stable per app+user for personal accounts. Stored as `microsoft_user_id` on the connection.
- **`email` (preferred) or `preferred_username`** — the user's identifier for display. `email` is the actual email address when Microsoft has one; `preferred_username` is the UPN / email-shaped identifier from the account and is present even when `email` isn't.
- **`name`** — the display name (exposed as `TokenResponse::$accountName`; not stored on the connection).
- **`tid`** — the tenant id that issued the token. Used both for the tenant-authority check below and for downstream code that needs to route per-tenant.

**The JWT signature is not verified.** The id_token arrived over TLS from Microsoft's token endpoint on a connection we initiated. We use these claims for identity **persistence only** — labeling the row so we can display "Connected as {email}" and later route per-tenant. We do not use them for authorization decisions, so signature validation would be pointless overhead.

If your use case actually authorizes off the id_token (logging users into your app via Microsoft), verify the signature externally with `firebase/php-jwt` and Microsoft's JWKS before trusting the claims.

### 6. Enforce the tenant authority

```php
$this->authority()->assertTidMatches( $tokens->tenantId );
```

This runs inside `MicrosoftClient::exchangeCode()`, so stateless callers get the same check.

This is the security-critical check specific to Microsoft. See [Tenants](Tenants) for the per-mode rules — but in short:

- `common` accepts any `tid` (or none).
- `organizations` rejects `tid` = MSA tenant (`9188040d-…`).
- `consumers` requires `tid` = MSA tenant.
- Single-tenant GUID requires `tid` = the configured GUID.
- Single-tenant verified domain accepts any `tid` (domain routing already narrowed to one tenant on the authorize side) but requires `tid` to be present.

A mismatch throws `OAuthException` and the connection is not persisted.

### 7. Upsert the connection

```php
$connection = MicrosoftConnection::firstOrNew( [ 'user_id' => $userId ] );

$this->applyTokens( $connection, $tokens, $incremental );

try {
    $connection->save();
} catch ( QueryException $e ) {
    if ( ! $this->isDuplicateKeyException( $e ) ) {
        throw $e;
    }

    $connection = MicrosoftConnection::where( 'user_id', $userId )->firstOrFail();
    $this->applyTokens( $connection, $tokens, $incremental );
    $connection->save();
}
```

**Concurrent-callback safety**: two callbacks for the same user racing at the exact same time can both call `firstOrNew()` and both try to `INSERT` because the row didn't exist yet. The unique index on `user_id` makes one of them fail with a duplicate-key error; the code catches that specific error, re-fetches the row that just landed, and re-applies its own token payload on top. Both callbacks return the same, freshly-updated connection.

The duplicate-key detection is driver-aware — Postgres reports SQLSTATE `23505`, MySQL uses `23000` + vendor code `1062`, SQLite reports `23000` + `"UNIQUE constraint failed"` in the message.

### 8. Apply the payload

```php
$connection->microsoft_user_id = $tokens->accountId ?? $connection->microsoft_user_id;
$connection->email             = $tokens->accountEmail ?? $connection->email;
$connection->tid               = $tokens->tenantId ?? $connection->tid;
$connection->access_token      = $tokens->accessToken;
$connection->token_type        = $tokens->tokenType;
$connection->scopes            = $scopes;
$connection->expires_at        = $tokens->expiresAt;
$connection->status            = MicrosoftConnection::STATUS_CONNECTED;
$connection->disconnect_reason = null;

if ( null !== $tokens->refreshToken ) {
    $connection->refresh_token = $tokens->refreshToken;
}
```

Notes:

- **`microsoft_user_id`, `email`, `tid` are never wiped**. If the exchange somehow doesn't return them, the existing stored values are kept. Prevents a re-consent flow from clearing identifying info.
- **`refresh_token` is only overwritten when present**. Microsoft returns one on every successful exchange when `offline_access` is granted — the guard is defensive for edge cases like an unexpected exchange response shape.
- **`disconnect_reason` is nulled on every successful exchange**. A previously-disconnected connection that reconnects starts clean.
- **`$scopes`** is the scope list the token response reported. When it reported none, direct mode stores the requested scopes, and broker mode keeps the connection's existing scopes.
- **On incremental consent (`$incremental === true`)**, the returned scopes are unioned with the previously-recorded ones before being stored. Microsoft's token response reflects only the scopes granted in *this* exchange, but the user's consent is cumulative — see the union logic in `applyTokens()`.

## After the exchange

The controller flashes `microsoft.status = 'connected'` and redirects to `redirect_after_connect`. Read the flash from the landing page:

```blade
@if( session( 'microsoft.status' ) === 'connected' )
    <div class="alert alert-success">Microsoft account connected.</div>
@endif
```
