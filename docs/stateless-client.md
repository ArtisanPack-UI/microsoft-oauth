---
title: Stateless Client
---

# Stateless Client

*Added in 1.1.0.*

`OAuthManager` and `TokenManager` are built for the usual case: one app, one Entra registration, connections stored per user. Underneath them is `MicrosoftClient`, a set of **stateless** OAuth primitives that never read the session or write to the database. You supply the credentials, `state`, scopes, and PKCE verifier, and it returns a [`TokenResponse`](API-Reference-Token-Response).

Use it when:

- You're building an **OAuth broker** that relays for other sites with credentials loaded at runtime (the server side of [Broker Mode](Broker)).
- You need tokens for an Entra app other than the configured one.
- You keep state and tokens somewhere other than the session and `microsoft_connections`.

For everything else, use the [OAuth Flow](Oauth) routes and the [token manager](Tokens).

## Getting a client

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftCredentials;

// From the configured credential driver
$client = MicrosoftOAuth::client();

// From explicit credentials
$client = MicrosoftOAuth::client( new MicrosoftCredentials(
    clientId: 'aaaa1111-2222-3333-4444-555566667777',
    clientSecret: 'THE-VALUE-COLUMN',      // null for public clients
    tenant: 'organizations',               // null means `common`
    redirectUri: 'https://broker.example.com/oauth/microsoft/callback',
) );
```

With no arguments, the client reads `client_id`, `client_secret`, `tenant`, and the redirect URI from the bound [`ConfigurationRepository`](Drivers). The redirect URI comes from the driver when it implements `ProvidesRedirectUri`, and from `config('microsoft-oauth.redirect_uri')` otherwise.

The client works the same way in broker mode. `MicrosoftOAuth::client()` always talks to Microsoft directly.

## 1. Build the consent URL

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftClient;
use Illuminate\Support\Str;

$state    = Str::random( 40 );
$verifier = MicrosoftClient::generateCodeVerifier();

// Keep $state and $verifier wherever your flow keeps state.

$url = $client->authorizationUrl(
    state: $state,
    scopes: [ 'openid', 'profile', 'email', 'offline_access', 'https://graph.microsoft.com/User.Read' ],
    parameters: [ 'prompt' => 'select_account', 'login_hint' => 'user@contoso.com' ],
    codeVerifier: $verifier,
);
```

- The URL points at `https://login.microsoftonline.com/{authority}/oauth2/v2.0/authorize` with `response_type=code` and `response_mode=query`.
- `$scopes` is sent as-is, and nothing is added for you. Include `offline_access` to get a refresh token.
- `$parameters` adds extra query parameters such as `prompt`, `login_hint`, or `domain_hint`, and can override `response_mode`. The flow-security keys in `MicrosoftClient::RESERVED_PARAMETERS` (`client_id`, `redirect_uri`, `response_type`, `scope`, `state`, `code_challenge`, `code_challenge_method`) are dropped, so a forwarded parameter can't swap the state, client, redirect, or PKCE challenge.
- PKCE (`S256`) is added only when you pass a `$codeVerifier`. `MicrosoftClient::codeChallenge( $verifier )` returns the challenge if you need it.

Throws `OAuthException` when the client ID or redirect URI is missing, or the tenant isn't a valid authority.

## 2. Verify state and exchange the code

```php
MicrosoftClient::verifyState( $storedState, $request->query( 'state' ) );

$tokens = $client->exchangeCode(
    code: $request->query( 'code' ),
    scopes: $requestedScopes,
    codeVerifier: $verifier,
);
```

`verifyState()` compares in constant time and throws `OAuthException("OAuth state mismatch; possible CSRF attempt.")` on a mismatch or an empty expected state.

`exchangeCode()` sends the requested scopes with the code, as Microsoft requires, and checks the id_token `tid` against the tenant authority, so a `consumers`-only app can't be handed a work account. See [Tenants](Tenants). On failure it throws `OAuthException`, and `getError()` returns Microsoft's error code (or `exchange_failed` / `invalid_payload`).

## 3. Refresh

```php
$tokens = $client->refresh( $storedRefreshToken, $grantedScopes );

$storedRefreshToken = $tokens->refreshToken; // Microsoft rotates it
```

Takes a raw refresh-token string, so no `MicrosoftConnection` is needed. Pass the scopes the grant holds. Microsoft requires `scope` on v2.0 refreshes, and sending the full set keeps the grant from being downgraded. When Microsoft doesn't return a new refresh token, the response carries the one you passed in.

Throws `TokenRefreshException`. `getError()` returns `invalid_grant` for a revoked grant, `invalid_tenant` for a misconfigured tenant, `invalid_client` for a missing client ID, or Microsoft's own error code. The stateless client doesn't disconnect anything; that's up to you.

## The `TokenResponse`

Both `exchangeCode()` and `refresh()` return an immutable `TokenResponse`:

```php
$tokens->accessToken;   // string
$tokens->refreshToken;  // ?string, the rotated token
$tokens->tokenType;     // 'Bearer'
$tokens->expiresIn;     // ?int seconds
$tokens->expiresAt;     // ?Carbon
$tokens->scopes;        // list<string> granted scopes
$tokens->idToken;       // ?string raw JWT (unverified)
$tokens->accountId;     // ?string, `oid` claim, else `sub`
$tokens->accountEmail;  // ?string, `email`, else `preferred_username`
$tokens->accountName;   // ?string, `name` claim
$tokens->tenantId;      // ?string, `tid` claim

return response()->json( $tokens->toArray() ); // broker wire shape
```

`toArray()` renders the JSON shape that `BrokerClient` reads from a broker's `/token` and `/refresh` endpoints, so a broker can return it as-is. Full reference: [TokenResponse](API-Reference-Token-Response).

## A minimal relay

```php
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftClient;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftCredentials;
use Illuminate\Support\Str;

$client = MicrosoftOAuth::client( new MicrosoftCredentials(
    clientId: $settings->client_id,
    clientSecret: $settings->client_secret,
    tenant: $settings->tenant,
    redirectUri: route( 'broker.microsoft.callback' ),
) );

// Authorize: remember state + verifier against the requesting site.
$pending = PendingAuthorization::create( [
    'state'    => Str::random( 40 ),
    'verifier' => MicrosoftClient::generateCodeVerifier(),
    'scopes'   => $scopes,
] );

return redirect()->away(
    $client->authorizationUrl( $pending->state, $pending->scopes, [ 'prompt' => 'select_account' ], $pending->verifier ),
);

// Callback: look $pending up by the returned state, verify, exchange,
// then hand the site a one-time code for $tokens->toArray().
MicrosoftClient::verifyState( $pending->state, $returnedState );

$tokens = $client->exchangeCode( $code, $pending->scopes, $pending->verifier );
```

`PendingAuthorization` stands in for wherever your broker stores in-flight requests.
