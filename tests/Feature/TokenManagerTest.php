<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    config( [
        'microsoft-oauth.client_id'     => 'client-abc',
        'microsoft-oauth.client_secret' => 'secret-xyz',
        'microsoft-oauth.tenant'        => 'common',
    ] );
} );

function makeTokenManager(): TokenManager
{
    return app( TokenManager::class );
}

function makeConnection( array $overrides = [] ): MicrosoftConnection
{
    return MicrosoftConnection::create( array_merge( [
        'user_id'       => 1,
        'access_token'  => 'access-1',
        'refresh_token' => 'refresh-1',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ], $overrides ) );
}

it( 'returns the stored access token when it is still valid', function (): void {
    Http::fake();

    $connection = makeConnection();

    $token = makeTokenManager()->getValidAccessToken( $connection );

    expect( $token )->toBe( 'access-1' );
    Http::assertNothingSent();
} );

it( 'refreshes the token transparently when it is expired', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'access-2',
            'refresh_token' => 'refresh-2',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => 'openid profile offline_access',
        ], 200 ),
    ] );

    $token = makeTokenManager()->getValidAccessToken( $connection );

    expect( $token )->toBe( 'access-2' );

    $connection->refresh();
    expect( $connection->access_token )->toBe( 'access-2' );
    expect( $connection->refresh_token )->toBe( 'refresh-2' );
    expect( $connection->expires_at->toIso8601String() )->toBe(
        Carbon::parse( '2026-09-07 13:00:00' )->toIso8601String(),
    );

    Http::assertSent( function ( $request ): bool {
        $data = $request->data();

        return 'refresh_token' === $data[ 'grant_type' ]
            && 'refresh-1' === $data[ 'refresh_token' ]
            && 'client-abc' === $data[ 'client_id' ]
            && 'secret-xyz' === $data[ 'client_secret' ]
            && 'openid profile offline_access' === $data[ 'scope' ];
    } );
} );

it( 'treats a token expiring within 60 seconds as expired', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    $connection = makeConnection( [
        'expires_at' => Carbon::now()->addSeconds( 30 ),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-2',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    makeTokenManager()->getValidAccessToken( $connection );

    Http::assertSentCount( 1 );
} );

it( 'refuses to refresh a disconnected connection', function (): void {
    $connection = makeConnection( [
        'status' => MicrosoftConnection::STATUS_DISCONNECTED,
    ] );

    Http::fake();

    makeTokenManager()->getValidAccessToken( $connection );
} )->throws( TokenRefreshException::class, 'disconnected' );

it( 'marks the connection disconnected when Microsoft returns invalid_grant', function (): void {
    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'error'             => 'invalid_grant',
            'error_description' => 'AADSTS700082: refresh token expired',
        ], 400 ),
    ] );

    try {
        makeTokenManager()->refresh( $connection );
        $this->fail( 'Expected TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getMessage() )->toContain( 'invalid_grant' );
    }

    $connection->refresh();
    expect( $connection->isConnected() )->toBeFalse();
    expect( $connection->status )->toBe( MicrosoftConnection::STATUS_DISCONNECTED );
    expect( $connection->disconnect_reason )->toContain( 'invalid_grant' );
} );

it( 'marks the connection disconnected on consent_required / interaction_required', function (): void {
    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'error' => 'interaction_required',
        ], 400 ),
    ] );

    try {
        makeTokenManager()->refresh( $connection );
    } catch ( TokenRefreshException ) {
    }

    $connection->refresh();
    expect( $connection->isConnected() )->toBeFalse();
} );

it( 'leaves the connection intact on a transient refresh failure', function (): void {
    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'error' => 'temporarily_unavailable',
        ], 503 ),
    ] );

    try {
        makeTokenManager()->refresh( $connection );
    } catch ( TokenRefreshException ) {
    }

    $connection->refresh();
    expect( $connection->isConnected() )->toBeTrue();
    expect( $connection->access_token )->toBe( 'access-1' );
} );

it( 'marks the connection disconnected when the refresh token is missing', function (): void {
    $connection = makeConnection( [
        'refresh_token' => null,
        'expires_at'    => Carbon::now()->subMinute(),
    ] );

    Http::fake();

    try {
        makeTokenManager()->refresh( $connection );
    } catch ( TokenRefreshException ) {
    }

    $connection->refresh();
    expect( $connection->isConnected() )->toBeFalse();
    expect( $connection->disconnect_reason )->toContain( 'Missing refresh token' );
    Http::assertNothingSent();
} );

it( 'rotates the refresh token on a successful refresh', function (): void {
    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'access-2',
            'refresh_token' => 'rotated-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
        ], 200 ),
    ] );

    makeTokenManager()->refresh( $connection );

    $connection->refresh();
    expect( $connection->refresh_token )->toBe( 'rotated-refresh' );
} );

it( 'omits client_secret from the refresh body for public clients', function (): void {
    config( [ 'microsoft-oauth.client_secret' => '' ] );

    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-2',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    makeTokenManager()->refresh( $connection );

    Http::assertSent( function ( $request ): bool {
        return ! array_key_exists( 'client_secret', $request->data() );
    } );
} );

it( 'uses the configured tenant when hitting the token endpoint', function (): void {
    config( [ 'microsoft-oauth.tenant' => 'contoso.onmicrosoft.com' ] );

    $connection = makeConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-2',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    makeTokenManager()->refresh( $connection );

    Http::assertSent( function ( $request ): bool {
        return 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token' === $request->url();
    } );
} );
