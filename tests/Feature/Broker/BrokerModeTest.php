<?php

declare( strict_types=1 );

use ArtisanPackUI\Hooks\Facades\Filter;
use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\LicenseExpiredException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    // A broker-mode site holds no Microsoft app credentials at all.
    config()->set( 'microsoft-oauth.client_id', null );
    config()->set( 'microsoft-oauth.client_secret', null );
    config()->set( 'microsoft-oauth.redirect_uri', null );

    // A single-tenant authority the site would otherwise enforce; in broker
    // mode the broker owns the authority, so it must not apply.
    config()->set( 'microsoft-oauth.tenant', '11111111-2222-3333-4444-555555555555' );

    config()->set( 'microsoft-oauth.mode', 'broker' );
    config()->set( 'microsoft-oauth.broker.url', 'https://workshop.test' );
    config()->set( 'microsoft-oauth.broker.site_id', 'site-123' );
    config()->set( 'microsoft-oauth.broker.site_secret', '7|plain-site-secret' );
    config()->set( 'microsoft-oauth.routes.redirect_after_connect', '/after-connect' );
    config()->set( 'microsoft-oauth.routes.redirect_after_error', '/after-error' );
} );

/**
 * A broker `/token` or `/refresh` response body for Microsoft.
 */
function microsoftBrokerPayload( array $overrides = [] ): array
{
    return $overrides + [
        'token_type'    => 'Bearer',
        'access_token'  => 'broker-access',
        'refresh_token' => 'broker-refresh',
        'expires_in'    => 3600,
        'scopes'        => [ 'openid', 'profile', 'email', 'offline_access' ],
        'account_email' => 'user@contoso.com',
        'account_name'  => 'Jane Doe',
        'id_token'      => microsoftIdToken(),
    ];
}

/**
 * Store a connection for the given user whose access token has expired.
 */
function expiredMicrosoftConnection( int $userId = 42 ): MicrosoftConnection
{
    return MicrosoftConnection::create( [
        'user_id'       => $userId,
        'access_token'  => 'stale',
        'refresh_token' => 'broker-refresh',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'offline_access' ],
        'expires_at'    => Carbon::now()->subMinute(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );
}

it( 'reports broker mode on the facade', function (): void {
    expect( MicrosoftOAuth::usesBroker() )->toBeTrue();

    config()->set( 'microsoft-oauth.mode', 'direct' );

    expect( MicrosoftOAuth::usesBroker() )->toBeFalse();
} );

it( 'sends the user to the broker instead of Microsoft, without a PKCE verifier', function (): void {
    session()->put( 'microsoft_oauth.verifier', 'left-over' );

    $url = app( OAuthManager::class )->authorizationUrl( 42 );

    expect( $url )->toStartWith( 'https://workshop.test/api/v1/oauth/microsoft/authorize?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['site_id'] )->toBe( 'site-123' );
    expect( $params['state'] )->toBe( session( 'microsoft_oauth.state' ) );
    expect( $params['return_url'] )->toBe( route( 'microsoft.auth.callback' ) );
    expect( $params['scopes'] )->toContain( 'offline_access' );
    expect( $params )->not->toHaveKey( 'client_id' );

    expect( session( 'microsoft_oauth.user_id' ) )->toBe( 42 );
    expect( session()->has( 'microsoft_oauth.verifier' ) )->toBeFalse();
} );

it( 'uses the configured return URL when set', function (): void {
    config()->set( 'microsoft-oauth.broker.return_url', 'https://site.test/custom/callback' );

    parse_str( parse_url( app( OAuthManager::class )->authorizationUrl( 42 ), PHP_URL_QUERY ), $params );

    expect( $params['return_url'] )->toBe( 'https://site.test/custom/callback' );
} );

it( 'refuses to build a broker link when the broker is not configured', function (): void {
    config()->set( 'microsoft-oauth.broker.site_secret', null );

    expect( fn () => app( OAuthManager::class )->authorizationUrl( 42 ) )->toThrow( OAuthException::class );
    expect( session()->has( 'microsoft_oauth.state' ) )->toBeFalse();
} );

it( 'passes the scope union to the broker on incremental consent', function (): void {
    MicrosoftConnection::create( [
        'user_id' => 42,
        'scopes'  => [ 'openid', 'profile', 'email', 'offline_access' ],
        'status'  => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $scopes ): array => [ ...$scopes, 'https://graph.microsoft.com/User.Read' ] );

    $url = app( OAuthManager::class )->incrementalAuthorizationUrl( 42 );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( explode( ' ', $params['scopes'] ) )->toContain( 'openid', 'offline_access', 'https://graph.microsoft.com/User.Read' );
    expect( session( 'microsoft_oauth.incremental' ) )->toBeTrue();
} );

it( 'exchanges the broker code and stores the connection exactly as in direct mode', function (): void {
    Http::fake( [ 'workshop.test/api/v1/oauth/token' => Http::response( microsoftBrokerPayload() ) ] );

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    $connection = $oauth->handleCallback( 'broker-code', session( 'microsoft_oauth.state' ) );

    expect( $connection->user_id )->toBe( 42 );
    expect( $connection->access_token )->toBe( 'broker-access' );
    expect( $connection->refresh_token )->toBe( 'broker-refresh' );
    expect( $connection->email )->toBe( 'user@contoso.com' );
    expect( $connection->microsoft_user_id )->toBe( 'oid-123' );
    expect( $connection->tid )->toBe( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
    expect( $connection->scopes )->toContain( 'offline_access' );
    expect( $connection->isConnected() )->toBeTrue();

    Http::assertNotSent( fn ( $request ): bool => str_contains( $request->url(), 'login.microsoftonline.com' ) );

    // Consumers keep reading tokens the same way.
    expect( app( TokenProvider::class )->accessTokenFor( 42 ) )->toBe( 'broker-access' );
} );

it( 'unions scopes on an incremental-consent callback through the broker', function (): void {
    MicrosoftConnection::create( [
        'user_id' => 42,
        'scopes'  => [ 'openid', 'Calendars.Read' ],
        'status'  => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $scopes ): array => [ ...$scopes, 'Mail.Read' ] );
    Http::fake( [ 'workshop.test/*' => Http::response( microsoftBrokerPayload( [ 'scopes' => [ 'openid', 'Mail.Read' ] ] ) ) ] );

    $oauth = app( OAuthManager::class );
    $oauth->incrementalAuthorizationUrl( 42 );

    $connection = $oauth->handleCallback( 'c', session( 'microsoft_oauth.state' ) );

    expect( $connection->scopes )->toBe( [ 'openid', 'Calendars.Read', 'Mail.Read' ] );
} );

it( 'keeps the existing scopes when the broker reports none', function (): void {
    MicrosoftConnection::create( [
        'user_id'       => 42,
        'refresh_token' => 'r-old',
        'scopes'        => [ 'openid' ],
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    Http::fake( [ 'workshop.test/*' => Http::response( microsoftBrokerPayload( [ 'scopes' => [] ] ) ) ] );

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    expect( $oauth->handleCallback( 'c', session( 'microsoft_oauth.state' ) )->scopes )->toBe( [ 'openid' ] );
} );

it( 'still rejects a mismatched state in broker mode', function (): void {
    Http::fake();

    $oauth = app( OAuthManager::class );
    $oauth->authorizationUrl( 42 );

    expect( fn () => $oauth->handleCallback( 'c', 'forged' ) )->toThrow( OAuthException::class, 'state mismatch' );

    Http::assertNothingSent();
} );

it( 'refreshes through the broker without a client secret and stores the rotated token', function (): void {
    Http::fake( [
        'workshop.test/api/v1/oauth/refresh' => Http::response( microsoftBrokerPayload( [
            'access_token'  => 'refreshed',
            'refresh_token' => 'rotated',
        ] ) ),
    ] );

    $connection = expiredMicrosoftConnection();

    expect( app( TokenManager::class )->getValidAccessToken( $connection ) )->toBe( 'refreshed' );
    expect( $connection->fresh()->refresh_token )->toBe( 'rotated' );

    Http::assertSent( fn ( $request ): bool => 'broker-refresh' === $request->data()['refresh_token']
        && 'microsoft' === $request->data()['provider']
        && 'Bearer 7|plain-site-secret' === $request->header( 'Authorization' )[0] );
} );

it( 'keeps the connection connected when the license has expired', function (): void {
    Http::fake( [
        'workshop.test/*' => Http::response( [
            'error'     => 'license_expired',
            'renew_url' => 'https://workshop.test/renew',
        ], 402 ),
    ] );

    $connection = expiredMicrosoftConnection();

    try {
        app( TokenManager::class )->refresh( $connection );
        $this->fail( 'Expected a LicenseExpiredException.' );
    } catch ( LicenseExpiredException $e ) {
        expect( $e->getRenewUrl() )->toBe( 'https://workshop.test/renew' );
    }

    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'disconnects on a revoked grant reported by the broker', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'invalid_grant' ], 400 ) ] );

    $connection = expiredMicrosoftConnection();

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
    expect( $connection->fresh()->isConnected() )->toBeFalse();
} );

it( 'keeps the connection on a transient broker failure', function ( int $status, string $error ): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => $error ], $status ) ] );

    $connection = expiredMicrosoftConnection();

    expect( fn () => app( TokenManager::class )->refresh( $connection ) )->toThrow( TokenRefreshException::class );
    expect( $connection->fresh()->isConnected() )->toBeTrue();
} )->with( [
    'broker unavailable'   => [ 503, 'temporarily_unavailable' ],
    'provider unavailable' => [ 502, 'provider_unavailable' ],
] );

it( 'uses the tokens another refresh stored instead of refreshing again', function (): void {
    Http::fake();

    $connection = expiredMicrosoftConnection();

    // Another request rotated the token while this one waited on the lock.
    MicrosoftConnection::findOrFail( $connection->getKey() )->update( [
        'access_token'  => 'fresh',
        'refresh_token' => 'rotated',
        'expires_at'    => Carbon::now()->addHour(),
    ] );

    expect( app( TokenManager::class )->refresh( $connection ) )->toBe( 'fresh' );

    Http::assertNothingSent();
} );

it( 'uses the tokens a concurrent refresh stored when the broker says the token was superseded', function (): void {
    $connection = expiredMicrosoftConnection();

    Http::fake( function () use ( $connection ) {
        // The winning request lands its rotated tokens before the broker
        // answers this one.
        MicrosoftConnection::findOrFail( $connection->getKey() )->update( [
            'access_token'  => 'fresh',
            'refresh_token' => 'rotated',
            'expires_at'    => Carbon::now()->addHour(),
        ] );

        return Http::response( [ 'error' => 'refresh_superseded' ], 409 );
    } );

    expect( app( TokenManager::class )->refresh( $connection ) )->toBe( 'fresh' );
    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'keeps the connection when the broker says the token was superseded and nothing landed yet', function (): void {
    Http::fake( [ 'workshop.test/*' => Http::response( [ 'error' => 'refresh_superseded' ], 409 ) ] );

    $connection = expiredMicrosoftConnection();

    try {
        app( TokenManager::class )->refresh( $connection );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'refresh_superseded' );
    }

    expect( $connection->fresh()->isConnected() )->toBeTrue();
} );

it( 'raises a refresh exception when the broker is not configured', function (): void {
    config()->set( 'microsoft-oauth.broker.url', null );

    try {
        app( TokenManager::class )->refresh( expiredMicrosoftConnection() );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'broker_not_configured' );
    }
} );

describe( 'routes', function (): void {
    beforeEach( function (): void {
        $this->user = new class implements Authenticatable {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): int
            {
                return 42;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken( $value ): void
            {
            }

            public function getRememberTokenName(): string
            {
                return '';
            }
        };
    } );

    it( 'redirects connect to the broker', function (): void {
        $response = $this->actingAs( $this->user )->get( '/auth/microsoft/connect' );

        expect( $response->headers->get( 'Location' ) )
            ->toStartWith( 'https://workshop.test/api/v1/oauth/microsoft/authorize?' );
    } );

    it( 'completes the callback through the broker', function (): void {
        Http::fake( [ 'workshop.test/*' => Http::response( microsoftBrokerPayload() ) ] );

        $this->actingAs( $this->user )->get( '/auth/microsoft/connect' );

        $this->get( '/auth/microsoft/callback?code=broker-code&state=' . session( 'microsoft_oauth.state' ) )
            ->assertRedirect( '/after-connect' )
            ->assertSessionHas( 'microsoft.status', 'connected' );

        expect( MicrosoftConnection::query()->first()->access_token )->toBe( 'broker-access' );
    } );

    it( 'flashes the renew URL when the broker exchange reports an expired license', function (): void {
        Http::fake( [
            'workshop.test/*' => Http::response( [
                'error'     => 'license_expired',
                'renew_url' => 'https://workshop.test/renew',
            ], 402 ),
        ] );

        $this->actingAs( $this->user )->get( '/auth/microsoft/connect' );

        $this->get( '/auth/microsoft/callback?code=broker-code&state=' . session( 'microsoft_oauth.state' ) )
            ->assertRedirect( '/after-error' )
            ->assertSessionHas( 'microsoft.renew_url', 'https://workshop.test/renew' );

        expect( MicrosoftConnection::count() )->toBe( 0 );
    } );

    it( 'flashes a license_expired error with a trusted renew URL', function (): void {
        $this->get( '/auth/microsoft/callback?error=license_expired&state=s&renew_url=' . urlencode( 'https://workshop.test/renew' ) )
            ->assertRedirect( '/after-error' )
            ->assertSessionHas( 'microsoft.error', 'license_expired' )
            ->assertSessionHas( 'microsoft.renew_url', 'https://workshop.test/renew' );
    } );

    it( 'drops a renew URL that does not point at the broker', function (): void {
        $this->get( '/auth/microsoft/callback?error=license_expired&state=s&renew_url=' . urlencode( 'https://evil.test/renew' ) )
            ->assertSessionHas( 'microsoft.error', 'license_expired' )
            ->assertSessionMissing( 'microsoft.renew_url' );
    } );

    it( 'still redirects with the error when the broker URL is insecure', function (): void {
        config()->set( 'microsoft-oauth.broker.url', 'http://workshop.example.com' );

        $this->get( '/auth/microsoft/callback?error=license_expired&renew_url=' . urlencode( 'http://workshop.example.com/renew' ) )
            ->assertRedirect( '/after-error' )
            ->assertSessionHas( 'microsoft.error', 'license_expired' )
            ->assertSessionMissing( 'microsoft.renew_url' );
    } );

    it( 'ignores renew URLs outside broker mode', function (): void {
        config()->set( 'microsoft-oauth.mode', 'direct' );

        $this->get( '/auth/microsoft/callback?error=license_expired&renew_url=' . urlencode( 'https://workshop.test/renew' ) )
            ->assertSessionMissing( 'microsoft.renew_url' );
    } );
} );
