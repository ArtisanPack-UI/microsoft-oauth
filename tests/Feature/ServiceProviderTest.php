<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth as MicrosoftOAuthFacade;
use ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuth;

it( 'binds microsoft-oauth as a singleton in the container', function (): void {
    $first  = app( 'microsoft-oauth' );
    $second = app( 'microsoft-oauth' );

    expect( $first )->toBeInstanceOf( MicrosoftOAuth::class );
    expect( $second )->toBe( $first );
} );

it( 'resolves the same instance through the microsoft_oauth() helper', function (): void {
    expect( microsoft_oauth() )->toBe( app( 'microsoft-oauth' ) );
} );

it( 'exposes the binding through the MicrosoftOAuth facade', function (): void {
    expect( MicrosoftOAuthFacade::getFacadeRoot() )->toBe( app( 'microsoft-oauth' ) );
} );
