<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\Tokens\DefaultTokenProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    config( [
        'microsoft-oauth.client_id'     => 'client-abc',
        'microsoft-oauth.client_secret' => 'secret-xyz',
        'microsoft-oauth.tenant'        => 'common',
    ] );
} );

function makeProviderConnection( array $overrides = [] ): MicrosoftConnection
{
    return MicrosoftConnection::create( array_merge( [
        'user_id'       => 42,
        'access_token'  => 'access-1',
        'refresh_token' => 'refresh-1',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ], $overrides ) );
}

it( 'is bound as the default TokenProvider implementation', function (): void {
    expect( app( TokenProvider::class ) )->toBeInstanceOf( DefaultTokenProvider::class );
} );

it( 'returns the stored access token for a connected user', function (): void {
    Http::fake();
    makeProviderConnection();

    $token = app( TokenProvider::class )->accessTokenFor( 42 );

    expect( $token )->toBe( 'access-1' );
    Http::assertNothingSent();
} );

it( 'refreshes an expired token before returning it', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    makeProviderConnection( [
        'expires_at' => Carbon::now()->subMinute(),
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'access-2',
            'refresh_token' => 'refresh-2',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
        ], 200 ),
    ] );

    $token = app( TokenProvider::class )->accessTokenFor( 42 );

    expect( $token )->toBe( 'access-2' );
    Http::assertSentCount( 1 );
} );

it( 'throws MissingConnectionException when the user has no connection', function (): void {
    Http::fake();

    app( TokenProvider::class )->accessTokenFor( 999 );
} )->throws( MissingConnectionException::class, 'No Microsoft connection on file for user 999' );

it( 'lets a disconnected-connection failure surface as TokenRefreshException', function (): void {
    Http::fake();

    makeProviderConnection( [
        'status' => MicrosoftConnection::STATUS_DISCONNECTED,
    ] );

    app( TokenProvider::class )->accessTokenFor( 42 );
} )->throws( TokenRefreshException::class, 'disconnected' );

