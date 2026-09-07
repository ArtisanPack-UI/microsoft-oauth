<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;

it( 'points the facade at the microsoft-oauth container binding', function (): void {
    $accessor = new ReflectionMethod( MicrosoftOAuth::class, 'getFacadeAccessor' );

    expect( $accessor->invoke( null ) )->toBe( 'microsoft-oauth' );
} );
