<?php

/**
 * MicrosoftOAuth service provider.
 *
 * Bootstraps the MicrosoftOAuth package by registering services and bindings.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth;

use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the MicrosoftOAuth package.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */
class MicrosoftOAuthServiceProvider extends ServiceProvider
{
    /**
     * Registers any application services.
     *
     * @since 1.0.0
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/microsoft-oauth.php',
            'microsoft-oauth',
        );

        $this->app->singleton( 'microsoft-oauth', function ( $app ) {
            return new MicrosoftOAuth();
        } );

        $this->app->singleton( OAuthManager::class, function ( $app ) {
            return new OAuthManager(
                $app->make( 'config' ),
                $app->make( 'session.store' ),
                $app->make( HttpFactory::class ),
            );
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * @since 1.0.0
     */
    public function boot(): void
    {
        $this->loadRoutesFrom( __DIR__ . '/../routes/web.php' );

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../config/microsoft-oauth.php' => config_path( 'microsoft-oauth.php' ),
            ], 'microsoft-oauth-config' );
        }
    }
}
