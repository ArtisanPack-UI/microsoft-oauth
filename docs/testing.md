---
title: Testing
---

# Testing

The package ships with a Pest test suite you can use as a reference. This page collects patterns for testing your own code against `artisanpack-ui/microsoft-oauth`.

## Test bootstrap

The package's own tests use Orchestra Testbench with a base `TestCase` in `tests/`. To wire up an app-level test that talks to the package:

```php
use ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuthServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders( $app ): array
    {
        return [ MicrosoftOAuthServiceProvider::class ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom( __DIR__ . '/../vendor/artisanpack-ui/microsoft-oauth/database/migrations' );
    }
}
```

Or use `RefreshDatabase` in a normal Laravel `TestCase` — the package registers its migrations via `$this->loadMigrationsFrom()` in `boot()`, so `php artisan migrate` against the test DB is enough.

## Testing OAuth flows

Fake Microsoft's endpoints:

```php
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://login.microsoftonline.com/*' => Http::sequence()
        ->push( [
            'access_token'  => 'initial-access-token',
            'refresh_token' => 'a-refresh-token',
            'expires_in'    => 3600,
            'token_type'    => 'Bearer',
            'scope'         => 'openid profile email offline_access https://graph.microsoft.com/User.Read',
            'id_token'      => makeIdToken(
                oid:   'ms-oid-123',
                email: 'you@example.com',
                tid:   '9188040d-6c67-4c5b-b112-36a304b66dad',
            ),
        ] ),
] );
```

Where `makeIdToken()` builds a minimal `id_token` payload (base64url of `{header}.{payload}.{signature}` — the signature can be dummy since the package doesn't verify it):

```php
function makeIdToken( string $oid, string $email, string $tid ): string
{
    $header  = base64url( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
    $payload = base64url( json_encode( [ 'oid' => $oid, 'email' => $email, 'tid' => $tid ] ) );

    return "{$header}.{$payload}.signature";
}

function base64url( string $raw ): string
{
    return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}
```

Then drive the flow:

```php
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;

$user = User::factory()->create();

// Pretend the /connect handler ran.
$url = app( OAuthManager::class )->authorizationUrl( $user->id );

// Extract state from the session; simulate the callback.
$state = session( 'microsoft_oauth.state' );

app( OAuthManager::class )->handleCallback( 'test-auth-code', $state );

$connection = MicrosoftConnection::firstWhere( 'user_id', $user->id );

expect( $connection )->not->toBeNull();
expect( $connection->email )->toBe( 'you@example.com' );
expect( $connection->microsoft_user_id )->toBe( 'ms-oid-123' );
expect( $connection->tid )->toBe( '9188040d-6c67-4c5b-b112-36a304b66dad' );
expect( $connection->access_token )->toBe( 'initial-access-token' );
```

## Testing the tenant-authority check

The tenant-authority check is one of the more subtle behaviors — worth testing per authority:

```php
// consumers-only registration
config( [ 'microsoft-oauth.tenant' => 'consumers' ] );

// …drive the flow with a work-account tid…
$state = session( 'microsoft_oauth.state' );

expect( fn () => app( OAuthManager::class )->handleCallback( 'code', $state ) )
    ->toThrow( \ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException::class );

expect( MicrosoftConnection::where( 'user_id', $user->id )->exists() )->toBeFalse();
```

Note that the exchange has already happened by the time `assertTidMatches()` throws — you're testing that the connection isn't persisted, not that Microsoft is never called.

## Testing the token manager

Fake the token endpoint and assert the manager returns the fresh token:

```php
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Support\Facades\Http;

Http::fake( [
    'https://login.microsoftonline.com/*' => Http::response( [
        'access_token'  => 'refreshed',
        'refresh_token' => 'rotated-refresh-token',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer',
    ] ),
] );

$connection = MicrosoftConnection::factory()->create( [
    'access_token'  => 'expired-token',
    'refresh_token' => 'old-refresh-token',
    'expires_at'    => now()->subMinute(),
    'status'        => 'connected',
] );

expect( app( TokenManager::class )->getValidAccessToken( $connection ) )->toBe( 'refreshed' );
expect( $connection->fresh()->access_token )->toBe( 'refreshed' );
expect( $connection->fresh()->refresh_token )->toBe( 'rotated-refresh-token' );
```

Test terminal-error disconnect:

```php
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;

Http::fake( [
    'https://login.microsoftonline.com/*' => Http::response( [
        'error' => 'invalid_grant',
    ], 400 ),
] );

expect( fn () => app( TokenManager::class )->refresh( $connection ) )
    ->toThrow( TokenRefreshException::class );

expect( $connection->fresh()->status )->toBe( 'disconnected' );
expect( $connection->fresh()->disconnect_reason )->toBe( 'Refresh token revoked or expired (invalid_grant).' );
```

Test that non-terminal errors don't mark disconnected:

```php
Http::fake( [
    'https://login.microsoftonline.com/*' => Http::response( [
        'error' => 'invalid_client',
    ], 401 ),
] );

expect( fn () => app( TokenManager::class )->refresh( $connection ) )
    ->toThrow( TokenRefreshException::class );

expect( $connection->fresh()->status )->toBe( 'connected' );
```

## Testing scope contributions

Register a scope inside the test and assert `all()` picks it up:

```php
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;
use ArtisanPackUI\Hooks\Facades\Filter;

Filter::add( 'ap.microsoft.oauth.scopes', function ( array $scopes ): array {
    $scopes[] = 'https://graph.microsoft.com/User.Read';
    return $scopes;
} );

expect( app( ScopeRegistry::class )->all() )->toContain( 'https://graph.microsoft.com/User.Read' );
```

Filter registrations persist across tests within the same process — call `Filter::remove()` in `tearDown()` if you need isolation between test methods.

## Testing configuration drivers

Switch drivers in the test's `setUp()`:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

config( [ 'microsoft-oauth.driver' => 'database' ] );

app( ConfigurationRepository::class )->save( [
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'tenant'        => 'common',
] );

expect( app( ConfigurationRepository::class )->isConfigured() )->toBeTrue();
expect( app( ConfigurationRepository::class )->getClientSecret() )->toBe( 'test-client-secret' );
```

Because `ConfigurationRepository` is `bind()`ed (not `singleton()`), the container re-reads `config('microsoft-oauth.driver')` on each resolve — flip drivers mid-test without container-flushing.

For the CMS driver, the CMS framework's helper functions need to exist. Either install the framework as a dev dependency, or stub the helpers globally in your test bootstrap.

## Testing controllers

```php
$user = User::factory()->create();

$this->actingAs( $user )
    ->get( route( 'microsoft.auth.connect' ) )
    ->assertRedirectContains( 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize' );
```

For the callback, prime the session first:

```php
session( [
    'microsoft_oauth.state'    => 'test-state',
    'microsoft_oauth.verifier' => 'test-verifier',
    'microsoft_oauth.user_id'  => $user->id,
] );

Http::fake( [ 'https://login.microsoftonline.com/*' => Http::response( [ /* … */ ] ) ] );

$this->actingAs( $user )
    ->get( route( 'microsoft.auth.callback' ) . '?code=test-code&state=test-state' )
    ->assertRedirect( '/' )
    ->assertSessionHas( 'microsoft.status', 'connected' );

$this->assertDatabaseHas( 'microsoft_connections', [
    'user_id' => $user->id,
    'status'  => 'connected',
] );
```

Test the callback rejects the wrong state:

```php
session( [
    'microsoft_oauth.state'    => 'stored-state',
    'microsoft_oauth.verifier' => 'test-verifier',
    'microsoft_oauth.user_id'  => $user->id,
] );

$this->actingAs( $user )
    ->get( route( 'microsoft.auth.callback' ) . '?code=test-code&state=WRONG-STATE' )
    ->assertRedirect( '/' )
    ->assertSessionHas( 'microsoft.error' );
```

## Stubbing the `TokenProvider` contract

For downstream package tests where you don't want the whole OAuth stack:

```php
use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;

$this->app->bind( TokenProvider::class, function () {
    return new class implements TokenProvider {
        public function accessTokenFor( int|string $userId ): string
        {
            return 'test-token';
        }
    };
} );

// Now MicrosoftOAuth::request( $userId ) and any downstream consumer
// that type-hints TokenProvider get the stub.
```

## Running the package's own tests

From the package directory:

```bash
composer test          # runs Pest
composer lint          # php-cs-fixer --dry-run + phpcs
composer fix           # php-cs-fixer fix
```

From the ArtisanPack UI dev app (with the package symlinked):

```bash
php artisan test --compact
```

If you get transient failures about Blade views rendering `<livewire:>` tags literally, delete the Orchestra Testbench compiled-view cache:

```bash
rm -f vendor/orchestra/testbench-core/laravel/storage/framework/views/*.php
```
