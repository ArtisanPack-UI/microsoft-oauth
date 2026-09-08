---
title: Reauthorize
---

# Reauthorize

`MicrosoftAuthController::reauthorize()` and `OAuthManager::incrementalAuthorizationUrl()` handle incremental consent — the flow that runs when a new service package is installed after a user is already connected and its scopes are missing from the existing grant.

## Why this exists

Microsoft's consent model is cumulative: once a user has consented to a scope, they don't have to consent to it again. But the *grant* is only cumulative on Microsoft's side — the tokens Microsoft returns cover the specific scopes granted in that exchange. If your app requests a superset that includes both previously-granted and new scopes, Microsoft:

- Silently accepts the previously-granted scopes without prompting.
- Only shows the user a consent screen for the added scopes.
- Returns a token valid for the full requested set.

`/reauthorize` is the friendly URL that triggers this delta-consent flow.

## The controller

```php
public function reauthorize( Request $request ): RedirectResponse
{
    $user = $request->user();

    if ( null === $user ) {
        abort( 401 );
    }

    try {
        $result = $this->oauth->incrementalAuthorizationUrl( $user->getAuthIdentifier() );
    } catch ( OAuthException $e ) {
        return $this->redirectAfterError()->with( 'microsoft.error', $e->getMessage() );
    }

    if ( IncrementalConsentResult::NoConnection === $result ) {
        return redirect()->route( 'microsoft.auth.connect' );
    }

    if ( IncrementalConsentResult::AlreadyAuthorized === $result ) {
        return $this->redirectAfterConnect()->with( 'microsoft.status', 'already-authorized' );
    }

    return redirect()->away( $result );
}
```

Route: `GET /auth/microsoft/reauthorize` → `microsoft.auth.reauthorize`, middleware `web, auth`.

## Three outcomes

`incrementalAuthorizationUrl()` returns one of three things:

### A URL (`string`)

The user has a connection, and there are missing scopes. Redirect them there.

### `IncrementalConsentResult::NoConnection`

The user has no `MicrosoftConnection` at all — they've never gone through the initial connect flow. Route them through `/connect` instead, so they get a real authorization prompt rather than a misleading "already authorized" flash.

### `IncrementalConsentResult::AlreadyAuthorized`

The user has a connection, and every scope the registry now requires is already granted. Nothing to consent to. Redirect to `redirect_after_connect` with `microsoft.status = 'already-authorized'` flashed so the caller can render an "everything's good" message.

## Under the hood

```php
public function incrementalAuthorizationUrl( int|string $userId ): string|IncrementalConsentResult
{
    $connection = MicrosoftConnection::where( 'user_id', $userId )->first();

    if ( null === $connection ) {
        return IncrementalConsentResult::NoConnection;
    }

    $granted = $connection->grantedScopes();
    $missing = $this->scopes->missing( $granted );

    if ( [] === $missing ) {
        return IncrementalConsentResult::AlreadyAuthorized;
    }

    $url = $this->buildAuthorizationUrl(
        $userId,
        $this->mergeScopes( [] ),
        'consent',
    );

    $this->session->put( self::SESSION_INCREMENTAL, true );

    return $url;
}
```

Two things worth noting:

- **Request the full union, not the delta.** The token endpoint always returns a token scoped to what was granted in *that* exchange, so if you only ask for the delta, the returned token would only be valid for those new scopes. Requesting the full union keeps the token useful for every downstream service.
- **`prompt=consent` is hardcoded.** Without it, Microsoft may silently return a token that covers already-granted scopes without ever showing the consent screen for new scopes. The `consent` prompt forces the UI so the user actually sees what they're approving. Note this overrides the `microsoft-oauth.prompt` config value for this flow.

The `microsoft_oauth.incremental` session flag is set so `handleCallback()` knows to union the returned scopes with the previously-recorded ones rather than replacing them. Without the flag, an incremental re-auth that returns just the new scope would look like the user had lost every other previously-granted scope.

## Wiring it into your UI

Compute the "needs reauthorize" state yourself from the connection and the scope registry:

```php
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;

$connection = MicrosoftConnection::where( 'user_id', $user->id )->first();

$needsReauthorize = $connection?->isConnected()
    && ! app( ScopeRegistry::class )->hasAllRequired( $connection->grantedScopes() );
```

Then render a prompt:

```blade
@if( $needsReauthorize )
    <div class="alert alert-info">
        A newly-installed feature needs access to additional Microsoft scopes.
        <a href="{{ route('microsoft.auth.reauthorize') }}">Grant additional access</a>
    </div>
@endif
```

## Reading the after-flash

After a reauthorize, the redirect target sees one of:

- `microsoft.status === 'connected'` — new scopes were granted successfully. Same status flash as the initial connect.
- `microsoft.status === 'already-authorized'` — nothing to do, everything was already granted.
- `microsoft.error === '<message>'` — the flow failed. Common causes: user declined, admin consent required, scope not registered on the app registration.

## Reauthorize vs. reconnect

**Reauthorize** keeps the same `microsoft_connections` row and adds scopes to it. Access tokens continue to work for previously-granted scopes without interruption.

**Reconnect** (a fresh `/connect` from an already-connected user) does the full flow — the user picks an account, sees the full consent screen, and Microsoft issues a new token. The existing row is updated in place (`user_id` is unique). The token from a fresh connect is valid for the requested scope set; any previously-granted scopes not in the current registry are effectively dropped from the connection's `scopes` array.

Reauthorize is almost always what you want after installing a new service package. Reconnect is what you want when the user needs to switch to a different Microsoft account.
