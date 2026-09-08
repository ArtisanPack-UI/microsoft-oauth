<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException;
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftOAuthManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    config( [
        'microsoft-oauth.client_id'     => 'client-abc',
        'microsoft-oauth.client_secret' => 'secret-xyz',
        'microsoft-oauth.tenant'        => 'common',
    ] );
} );

function seedManagerConnection( array $overrides = [] ): MicrosoftConnection
{
    return MicrosoftConnection::create( array_merge( [
        'user_id'       => 7,
        'access_token'  => 'bearer-token-1',
        'refresh_token' => 'refresh-1',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ], $overrides ) );
}

it( 'binds MicrosoftOAuthManager in the container', function (): void {
    expect( app( MicrosoftOAuthManager::class ) )->toBeInstanceOf( MicrosoftOAuthManager::class );
} );

it( 'returns a PendingRequest with the bearer token pre-applied', function (): void {
    Http::fake( [
        'https://graph.microsoft.com/*' => Http::response( [ 'displayName' => 'Ada' ], 200 ),
    ] );
    seedManagerConnection();

    $response = app( MicrosoftOAuthManager::class )
        ->request( 7 )
        ->get( 'https://graph.microsoft.com/v1.0/me' );

    expect( $response->successful() )->toBeTrue();
    expect( $response->json( 'displayName' ) )->toBe( 'Ada' );

    Http::assertSent( function ( $request ): bool {
        return 'Bearer bearer-token-1' === $request->header( 'Authorization' )[ 0 ]
            && 'https://graph.microsoft.com/v1.0/me' === $request->url();
    } );
} );

it( 'refreshes an expired token before the outbound call', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    seedManagerConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'refreshed-token',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
        'https://graph.microsoft.com/*' => Http::response( [ 'ok' => true ], 200 ),
    ] );

    app( MicrosoftOAuthManager::class )
        ->request( 7 )
        ->get( 'https://graph.microsoft.com/v1.0/me' );

    Http::assertSent( function ( $request ): bool {
        if ( 'https://graph.microsoft.com/v1.0/me' !== $request->url() ) {
            return false;
        }

        return 'Bearer refreshed-token' === $request->header( 'Authorization' )[ 0 ];
    } );
} );

it( 'propagates MissingConnectionException when the user has no connection', function (): void {
    Http::fake();

    app( MicrosoftOAuthManager::class )->request( 999 );
} )->throws( MissingConnectionException::class );

it( 'exposes request() through the MicrosoftOAuth facade', function (): void {
    Http::fake( [
        'https://graph.microsoft.com/*' => Http::response( [ 'ok' => true ], 200 ),
    ] );
    seedManagerConnection();

    $pending = MicrosoftOAuth::request( 7 );

    expect( $pending )->toBeInstanceOf( PendingRequest::class );

    $pending->get( 'https://graph.microsoft.com/v1.0/me' );

    Http::assertSent( function ( $request ): bool {
        return 'Bearer bearer-token-1' === $request->header( 'Authorization' )[ 0 ];
    } );
} );

it( 'honors a swapped TokenProvider binding', function (): void {
    Http::fake( [
        'https://graph.microsoft.com/*' => Http::response( [ 'ok' => true ], 200 ),
    ] );

    app()->instance( TokenProvider::class, new class implements TokenProvider {
        public function accessTokenFor( int|string $userId ): string
        {
            return 'stub-token-for-' . $userId;
        }
    } );

    // Rebuild the manager so it picks up the new provider — the scoped
    // binding captures the provider by reference at construction time.
    app()->forgetInstance( MicrosoftOAuthManager::class );

    app( MicrosoftOAuthManager::class )
        ->request( 123 )
        ->get( 'https://graph.microsoft.com/v1.0/me' );

    Http::assertSent( function ( $request ): bool {
        return 'Bearer stub-token-for-123' === $request->header( 'Authorization' )[ 0 ];
    } );
} );
