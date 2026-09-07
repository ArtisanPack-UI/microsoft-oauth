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

use ArtisanPackUI\MicrosoftOAuth\Configuration\CmsSettingsDriver;
use ArtisanPackUI\MicrosoftOAuth\Configuration\ConfigDriver;
use ArtisanPackUI\MicrosoftOAuth\Configuration\DatabaseDriver;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;
use ArtisanPackUI\MicrosoftOAuth\Tokens\TokenManager;
use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton(
            ConfigDriver::class,
            fn ( Application $app ): ConfigDriver => new ConfigDriver( $app[ 'config' ] ),
        );

        // Scoped: DatabaseDriver holds a per-request row cache. Under Octane
        // or long-lived queue workers, a singleton would keep serving stale
        // credentials after another worker rewrote the row. Scoped bindings
        // are flushed between requests / jobs, so each lifecycle gets a
        // freshly-loaded cache.
        $this->app->scoped(
            DatabaseDriver::class,
            fn ( Application $app ): DatabaseDriver => new DatabaseDriver(
                $app[ 'db' ]->connection(),
                $app[ 'encrypter' ],
            ),
        );

        // Scoped for the same reason as DatabaseDriver: the per-request cache
        // must not survive across Octane requests or queue jobs.
        $this->app->scoped(
            CmsSettingsDriver::class,
            fn ( Application $app ): CmsSettingsDriver => new CmsSettingsDriver( $app[ 'encrypter' ] ),
        );

        // Bind (not singleton) so `config('microsoft-oauth.driver')` is re-read
        // on each resolve; the concrete driver classes are singletons in their
        // own right and hold the per-request cache.
        $this->app->bind( ConfigurationRepository::class, function ( Application $app ): ConfigurationRepository {
            $driver = $app[ 'config' ]->get( 'microsoft-oauth.driver', 'config' );

            return match ( $driver ) {
                'database' => $app->make( DatabaseDriver::class ),
                'cms'      => $app->make( CmsSettingsDriver::class ),
                default    => $app->make( ConfigDriver::class ),
            };
        } );

        $this->app->singleton( ScopeRegistry::class );

        // Scoped: these managers capture a ConfigurationRepository reference
        // in their constructor. Keeping them as singletons on Octane / queue
        // workers would pin a stale DatabaseDriver instance for the worker
        // lifetime even after another lifecycle rewrote the credential row.
        $this->app->scoped( OAuthManager::class, function ( Application $app ): OAuthManager {
            return new OAuthManager(
                $app->make( ConfigurationRepository::class ),
                $app->make( 'config' ),
                $app->make( 'session.store' ),
                $app->make( HttpFactory::class ),
                $app->make( ScopeRegistry::class ),
            );
        } );

        $this->app->scoped( TokenManager::class, function ( Application $app ): TokenManager {
            return new TokenManager(
                $app->make( ConfigurationRepository::class ),
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
        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../config/microsoft-oauth.php' => config_path( 'microsoft-oauth.php' ),
            ], 'microsoft-oauth-config' );

            $this->publishes( [
                __DIR__ . '/../database/migrations' => database_path( 'migrations' ),
            ], 'microsoft-oauth-migrations' );
        }

        $this->registerCmsSettings();
    }

    /**
     * Register OAuth-credential settings with the CMS framework when it is
     * installed. No-op otherwise — the base package must not hard-depend on
     * the CMS framework.
     *
     * Runs inside `$this->app->booted()` because the CMS-framework helpers
     * (apRegisterSetting / apGetSetting / apUpdateSetting) are declared from
     * that package's own boot() method, and Laravel's provider boot order is
     * not deterministic. If MicrosoftOAuthServiceProvider happens to boot
     * first, registering directly from this class's boot() would silently
     * skip the three keys and the CMS Settings UI would never expose them.
     *
     * @since 1.0.0
     */
    protected function registerCmsSettings(): void
    {
        $this->app->booted( function (): void {
            if ( ! function_exists( 'apRegisterSetting' ) ) {
                return;
            }

            $encrypter = $this->app[ 'encrypter' ];

            $trim = static function ( mixed $value ): ?string {
                if ( null === $value || '' === $value ) {
                    return null;
                }

                return trim( (string) $value );
            };

            // The client secret is written to the CMS Settings row by two
            // paths: `$driver->save()` (driver → apUpdateSetting) and the
            // CMS Settings UI (operator → apUpdateSetting directly). Owning
            // encryption inside the sanitize callback makes both paths write
            // ciphertext, so the read-side decryption always sees an
            // encrypted value.
            $encryptSecret = static function ( mixed $value ) use ( $encrypter, $trim ): ?string {
                $trimmed = $trim( $value );

                if ( null === $trimmed ) {
                    return null;
                }

                return $encrypter->encryptString( $trimmed );
            };

            apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_ID, null, $trim );
            apRegisterSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, null, $encryptSecret );
            apRegisterSetting( CmsSettingsDriver::KEY_TENANT, null, $trim );
        } );
    }
}
