---
title: Connect
---

# Connect

`MicrosoftAuthController::connect()` and `OAuthManager::authorizationUrl()` build the redirect that sends a user to Microsoft's consent screen.

## The controller

```php
public function connect( Request $request ): RedirectResponse
{
    $user = $request->user();

    if ( null === $user ) {
        abort( 401 );
    }

    try {
        $url = $this->oauth->authorizationUrl( $user->getAuthIdentifier() );
    } catch ( OAuthException $e ) {
        return $this->redirectAfterError()->with( 'microsoft.error', $e->getMessage() );
    }

    return redirect()->away( $url );
}
```

Route: `GET /auth/microsoft/connect` → `microsoft.auth.connect`, middleware `web, auth`.

`authorizationUrl()` throws `OAuthException` for two reasons — the credential driver reports missing `client_id`, or the configured tenant is not a recognized authority form. Both are caught here and flashed as `microsoft.error`.

## Building the URL

`OAuthManager::authorizationUrl()` clears any stale incremental flag from the session, then delegates to `buildAuthorizationUrl()`:

```php
$params = [
    'client_id'             => $clientId,
    'response_type'         => 'code',
    'redirect_uri'          => $redirect,
    'response_mode'         => 'query',
    'scope'                 => implode( ' ', $scopes ),
    'state'                 => $state,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
    'prompt'                => $prompt,
];

return $this->authorizeEndpoint() . '?' . http_build_query( $params );
```

The endpoint is `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize`, where `{tenant}` is the resolved authority from `microsoft-oauth.tenant` (see [Tenants](Tenants)).

### Parameters explained

- **`response_type=code`** — Authorization Code grant. The code is exchanged for tokens server-side in `handleCallback()`.
- **`response_mode=query`** — Microsoft returns the code and state on the callback URL's query string (`?code=…&state=…`). The alternative (`fragment`) would put them in the hash, which the server never sees.
- **`scope`** — Space-separated list from `ScopeRegistry::all()`. Baseline is `openid profile email offline_access`; service packages add via `ap.microsoft.oauth.scopes`. See [Scopes](Scopes).
- **`state`** — 40-char random string, stored in the session. On callback, compared with `hash_equals()` against the returned value. Defeats CSRF.
- **`code_challenge` + `code_challenge_method=S256`** — PKCE. The verifier (a URL-safe base64 of 64 random bytes) is stored in the session; the challenge is the URL-safe base64 of `SHA-256(verifier)`. Microsoft returns the code to the redirect URI and requires the verifier on the token exchange call — so an attacker who intercepts the redirect can't exchange the code without also having the verifier.
- **`prompt`** — Only on the initial connect: `config('microsoft-oauth.prompt', 'select_account')`. Common values: `login`, `none`, `consent`, `select_account`. The `/reauthorize` variant hardcodes `consent` so the delta-scope prompt actually shows.

Note the deliberate absence of `access_type=offline` — that's a Google-only parameter. Microsoft returns a refresh token whenever `offline_access` is in the granted scopes, which the baseline always includes.

## Session state

Three keys are written to the session on connect:

| Key | Purpose |
|---|---|
| `microsoft_oauth.state` | Verified against the callback's `state` parameter. |
| `microsoft_oauth.verifier` | Sent as `code_verifier` in the token exchange. |
| `microsoft_oauth.user_id` | The user we're building the connection for. Pulled out on callback so `handleCallback()` can attach the new tokens to the right row. |

A fourth key, `microsoft_oauth.incremental`, is set only by `incrementalAuthorizationUrl()` and cleared by `authorizationUrl()` — it tells `handleCallback()` to union the returned scopes with the previously-recorded ones rather than replacing them.

All keys are `pull()`ed (read + deleted) on callback, so a subsequent replay of the same callback URL fails cleanly with `"OAuth state mismatch"`.

## Not-configured errors

If the credential driver reports missing `client_id`, `authorizationUrl()` throws:

```
Microsoft OAuth is not configured: client_id is missing.
```

Similarly for a missing `redirect_uri`. The controller catches these and flashes them as `microsoft.error`, redirecting to `redirect_after_error` — so the user doesn't see a 500, just your error handling.

If you'd rather never let the user click a broken "Connect" link, gate it behind a check:

```blade
@php
    $config = app( \ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository::class );
@endphp

@if( $config->isConfigured() && ! empty( config( 'microsoft-oauth.redirect_uri' ) ) )
    <a href="{{ route('microsoft.auth.connect') }}">Connect Microsoft</a>
@else
    <p>Microsoft integration is not configured yet.</p>
@endif
```

## Calling the manager directly

If your app has its own controller / URL structure, resolve the manager and build the URL yourself:

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;

$url = app( OAuthManager::class )->authorizationUrl( $user->id );

return redirect()->away( $url );
```

You can also request extra scopes for a specific flow via the second argument:

```php
$url = app( OAuthManager::class )->authorizationUrl(
    $user->id,
    additionalScopes: [ 'https://graph.microsoft.com/Files.Read' ],
);
```

The `additionalScopes` list is unioned with the registry's baseline and any hooked scopes — you don't get to bypass the baseline (openid / profile / email / offline_access), and duplicates are de-duplicated.
