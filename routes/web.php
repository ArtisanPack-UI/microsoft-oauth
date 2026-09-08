<?php

/**
 * Microsoft OAuth package routes.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Http\Controllers\MicrosoftAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware( [ 'web', 'auth' ] )
    ->prefix( 'auth/microsoft' )
    ->name( 'microsoft.auth.' )
    ->group( function (): void {
        Route::get( 'connect', [ MicrosoftAuthController::class, 'connect' ] )->name( 'connect' );
        Route::get( 'reauthorize', [ MicrosoftAuthController::class, 'reauthorize' ] )->name( 'reauthorize' );
    } );

Route::middleware( [ 'web' ] )
    ->prefix( 'auth/microsoft' )
    ->name( 'microsoft.auth.' )
    ->group( function (): void {
        Route::get( 'callback', [ MicrosoftAuthController::class, 'callback' ] )->name( 'callback' );
    } );
