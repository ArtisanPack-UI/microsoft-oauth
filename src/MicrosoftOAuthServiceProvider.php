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

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the MicrosoftOAuth package.
 *
 * Bootstraps the package by registering services and bindings. Extend this
 * class with the package's configuration, migrations, routes, views, and
 * other service registrations as features are added.
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
     * Binds the MicrosoftOAuth class as a singleton in the container.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton( 'microsoft-oauth', function ( $app ) {
            return new MicrosoftOAuth();
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * Add package bootstrapping here such as:
     * - Configuration publishing: $this->publishes([...])
     * - Migration loading: $this->loadMigrationsFrom(...)
     * - View loading: $this->loadViewsFrom(...)
     * - Route loading: $this->loadRoutesFrom(...)
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        // Add your package bootstrapping here
    }
}
