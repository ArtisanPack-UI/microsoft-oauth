<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Support\Carbon;

it( 'considers a connection with no expires_at as expired', function (): void {
    $connection = new MicrosoftConnection( [ 'expires_at' => null ] );

    expect( $connection->isExpired() )->toBeTrue();
} );

it( 'considers a connection expiring within the 60s window as expired', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    $connection             = new MicrosoftConnection();
    $connection->expires_at = Carbon::now()->addSeconds( 30 );

    expect( $connection->isExpired() )->toBeTrue();
} );

it( 'considers a connection with plenty of headroom as still valid', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    $connection             = new MicrosoftConnection();
    $connection->expires_at = Carbon::now()->addMinutes( 10 );

    expect( $connection->isExpired() )->toBeFalse();
} );

it( 'markDisconnected persists the status change and reason', function (): void {
    $connection = MicrosoftConnection::create( [
        'user_id'       => 1,
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    $connection->markDisconnected( 'Testing' );

    $fresh = MicrosoftConnection::find( $connection->id );
    expect( $fresh->status )->toBe( MicrosoftConnection::STATUS_DISCONNECTED );
    expect( $fresh->disconnect_reason )->toBe( 'Testing' );
    expect( $fresh->isConnected() )->toBeFalse();
} );

it( 'grantedScopes returns a list even when nothing is stored', function (): void {
    $connection = new MicrosoftConnection();

    expect( $connection->grantedScopes() )->toBe( [] );
} );
