---
title: Scopes
---

# Scopes

Microsoft OAuth uses **scopes** to gate what your access token can do. `artisanpack-ui/microsoft-oauth` lets any installed service package contribute the scopes it needs and unions them into a single consent screen — the user only sees one prompt, no matter how many packages depend on Microsoft APIs.

## The registry

`ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry` collects three sources:

1. **The baseline** — always requested:
    - `openid` — required to receive an `id_token` at all.
    - `profile` — the user's basic profile.
    - `email` — the user's email address.
    - `offline_access` — **required to receive a refresh token**. Without this the token expires in an hour and the connection breaks with no way to recover short of re-consent.
2. **Filter-hook contributions** via `ap.microsoft.oauth.scopes`.
3. **Imperative registrations** via `ScopeRegistry::register( $scope )`.

`ScopeRegistry::all()` returns the de-duplicated, trimmed union. Empty strings are dropped, and duplicates across the three sources are collapsed.

## Registering scopes from a service package

Preferred: hook the `ap.microsoft.oauth.scopes` filter in your service package's `boot()` method:

```php
use ArtisanPackUI\Hooks\Facades\Filter;

class BingPlacesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Filter::add( 'ap.microsoft.oauth.scopes', function ( array $scopes ): array {
            $scopes[] = 'https://www.bingapis.com/api/v7/businesses.readwrite';
            return $scopes;
        } );
    }
}
```

The registry calls `Filter::apply('ap.microsoft.oauth.scopes', [])` inside `all()`, so every hooked callback contributes to the union. The order the callbacks fire doesn't matter — the union is de-duplicated at the end.

## Registering scopes from application code

For app-level scopes without a service provider:

```php
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;

app( ScopeRegistry::class )->register( 'https://graph.microsoft.com/Files.Read' );
```

Register anywhere that runs before an authorize URL is built — usually inside a service provider's `boot()`.

## Reading the current union

```php
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;

$scopes = app( ScopeRegistry::class )->all();
// ['openid', 'profile', 'email', 'offline_access', 'https://graph.microsoft.com/...']
```

## Incremental consent: `missing()` and `hasAllRequired()`

When a service package is installed after a user is already connected, the registry starts returning scopes the connection doesn't hold. Two methods help detect this:

```php
$granted = $connection->grantedScopes();

app( ScopeRegistry::class )->missing( $granted );          // ['https://graph.microsoft.com/Files.Read']
app( ScopeRegistry::class )->hasAllRequired( $granted );   // false
```

Use them to render a "Grant additional access" prompt that links to `route('microsoft.auth.reauthorize')`. See [OAuth → Reauthorize](Oauth-Reauthorize).

## Microsoft scope formats

Microsoft accepts scopes in two forms — both are valid, and both flow through the registry unchanged:

- **Short form** (Graph only): `User.Read`, `Sites.Read.All`. Only recognized by Microsoft Graph.
- **Full URI form** (universal): `https://graph.microsoft.com/User.Read`, `https://www.bingapis.com/api/v7/businesses.readwrite`. Required for non-Graph APIs; also works with Graph.

The baseline scopes (`openid`, `profile`, `email`, `offline_access`) are OpenID Connect scopes — they're special-cased by Microsoft and don't have URI forms.

Prefer the full URI form in service packages when both are available — it makes the scope self-documenting and dodges any ambiguity if Microsoft ever surfaces a similarly-named scope on another API.

## Scope hygiene

Microsoft's OAuth policies are lighter than Google's but still meaningful:

- **Only request what you use.** Adding scopes you don't need makes the consent screen scarier and, for admin-consent-required scopes, blocks legitimate users until an admin approves.
- **The app registration must list every scope.** If a scope isn't added under **API permissions** on the app registration, Microsoft rejects the authorize request with `invalid_scope` at runtime. Add every service-package scope in the Entra portal — see [Installation → Entra app registration](Installation-Entra-App-Registration).
- **Some scopes require admin consent.** Any scope with the "Admin consent required" checkbox in the portal can't be granted by an individual user. An admin has to click **Grant admin consent for {tenant}** on the API permissions page, or consent per-user by walking through the flow themselves.

## Common scopes by service package

| Service package | Scope(s) it registers |
|---|---|
| `artisanpack-ui/bing-places` (planned) | `https://www.bingapis.com/api/v7/businesses.readwrite` |
| Microsoft Graph integrations (future) | scope varies per feature — see each package's docs |

Refer to each service package's docs for the authoritative list.

## Requesting extra scopes for a specific flow

`OAuthManager::authorizationUrl()` accepts an `additionalScopes` array:

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;

$url = app( OAuthManager::class )->authorizationUrl(
    $user->id,
    additionalScopes: [ 'https://graph.microsoft.com/Files.Read' ],
);
```

The list is unioned with the registry — you don't get to bypass the baseline or the registered scopes, just add to them. Useful for a one-off "let me pick a file" flow that shouldn't be part of the permanent scope set.

## The `offline_access` requirement

`offline_access` is in the baseline for a hard reason: it's the only way to get a `refresh_token`. Without it:

- Microsoft returns just an `access_token` with a ~1 hour lifetime.
- Once the token expires, there's no way to renew it short of the user re-consenting.
- Every downstream API call breaks after the first hour.

Don't try to remove `offline_access` from the registry. If you do (e.g. via a filter that returns a manually-built list that omits it), the token manager will fail to refresh and every user will hit `TokenRefreshException("No refresh token stored for this connection.")` after their first token expires.
