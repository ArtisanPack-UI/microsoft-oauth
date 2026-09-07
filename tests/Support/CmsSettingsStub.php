<?php

/**
 * Stubs for the artisanpack-ui/cms-framework Settings helpers.
 *
 * The CmsSettingsDriver reads and writes credentials through the CMS
 * framework's global helpers. We don't require that package as a test
 * dependency, so these stubs simulate the same shape:
 *
 *   - apRegisterSetting(key, default, callback) — stores the sanitize
 *     callback per key.
 *   - apUpdateSetting(key, value) — invokes the registered callback (which
 *     is where the client_secret encryption now lives, matching the real
 *     framework's behavior) and stores the sanitized value.
 *   - apGetSetting(key, default) — returns the stored value or default.
 *
 * State lives in $GLOBALS so tests can reset it in `beforeEach`.
 *
 * Loaded from tests/Pest.php so the stubs exist before Testbench boots the
 * MicrosoftOAuth service provider (which registers its sanitize callbacks
 * during `$this->app->booted()`).
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

if ( ! function_exists( 'apGetSetting' ) ) {
    function apGetSetting( string $key, mixed $default = null ): mixed
    {
        return $GLOBALS[ '__cms_settings_stub_values' ][ $key ] ?? $default;
    }

    function apUpdateSetting( string $key, mixed $value ): void
    {
        $callback = $GLOBALS[ '__cms_settings_stub_callbacks' ][ $key ] ?? null;

        if ( is_callable( $callback ) ) {
            $value = $callback( $value );
        }

        $GLOBALS[ '__cms_settings_stub_values' ][ $key ] = $value;
    }

    function apRegisterSetting(
        string $key,
        mixed $default,
        callable $callback,
        mixed $type = null,
    ): void {
        $GLOBALS[ '__cms_settings_stub_callbacks' ][ $key ] = $callback;
        $GLOBALS[ '__cms_settings_stub_defaults' ][ $key ]  = $default;
    }
}

if ( ! isset( $GLOBALS[ '__cms_settings_stub_values' ] ) ) {
    $GLOBALS[ '__cms_settings_stub_values' ]    = [];
    $GLOBALS[ '__cms_settings_stub_callbacks' ] = [];
    $GLOBALS[ '__cms_settings_stub_defaults' ]  = [];
}
