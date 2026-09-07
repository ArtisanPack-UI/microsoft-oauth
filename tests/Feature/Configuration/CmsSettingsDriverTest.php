<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Configuration\CmsSettingsDriver;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

beforeEach( function (): void {
    // Reset the stub-backed CMS settings store between tests. Sanitize
    // callbacks were registered when the MicrosoftOAuth service provider
    // booted via `$this->app->booted()` (see tests/Support/CmsSettingsStub.php).
    $GLOBALS[ '__cms_settings_stub_values' ] = [];

    config()->set( 'microsoft-oauth.driver', 'cms' );
    app( CmsSettingsDriver::class )->flush();
} );

it( 'resolves the CmsSettingsDriver when driver=cms', function (): void {
    expect( app( ConfigurationRepository::class ) )
        ->toBeInstanceOf( CmsSettingsDriver::class );
} );

it( 'writes credentials through apUpdateSetting and reads them back', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid-from-cms',
        'client_secret' => 'super-secret',
        'tenant'        => 'contoso.onmicrosoft.com',
    ] );

    $driver->flush();

    expect( $driver->getClientId() )->toBe( 'cid-from-cms' );
    expect( $driver->getClientSecret() )->toBe( 'super-secret' );
    expect( $driver->getTenant() )->toBe( 'contoso.onmicrosoft.com' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'stores the client secret encrypted at rest', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'client_secret' => 'plaintext-secret',
        'tenant'        => 'common',
    ] );

    $raw = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ];

    expect( $raw )->not->toBe( 'plaintext-secret' );
    expect( app( 'encrypter' )->decryptString( $raw ) )->toBe( 'plaintext-secret' );
} );

it( 'treats a missing client_id as unconfigured', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => null,
        'client_secret' => 'secret',
        'tenant'        => 'common',
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'treats a missing tenant as unconfigured', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'client_secret' => 'secret',
        'tenant'        => null,
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'considers a public client (no secret) with id and tenant configured', function (): void {
    // Microsoft supports public clients (SPA / native) using PKCE alone,
    // so client_secret is optional for isConfigured() — matches the
    // ConfigurationRepository contract.
    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );

    $driver->save( [
        'client_id'     => 'cid',
        'client_secret' => null,
        'tenant'        => 'common',
    ] );

    $driver->flush();

    expect( $driver->isConfigured() )->toBeTrue();
    expect( $driver->getClientSecret() )->toBeNull();
} );

it( 'encrypts secrets written directly through apUpdateSetting (Settings UI path)', function (): void {
    // Simulates an operator typing the client secret into the CMS
    // Settings admin UI: apUpdateSetting is called with plaintext. The
    // sanitize callback registered by MicrosoftOAuthServiceProvider must
    // encrypt it, otherwise the driver's decryption step later blows up
    // and isConfigured() flips to false with no visible reason.
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_ID, 'ui-cid' );
    apUpdateSetting( CmsSettingsDriver::KEY_CLIENT_SECRET, 'ui-typed-secret' );
    apUpdateSetting( CmsSettingsDriver::KEY_TENANT, 'contoso.onmicrosoft.com' );

    /** @var CmsSettingsDriver $driver */
    $driver = app( CmsSettingsDriver::class );
    $driver->flush();

    $raw = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_CLIENT_SECRET ];
    expect( $raw )->not->toBe( 'ui-typed-secret' );
    expect( app( 'encrypter' )->decryptString( $raw ) )->toBe( 'ui-typed-secret' );

    expect( $driver->getClientSecret() )->toBe( 'ui-typed-secret' );
    expect( $driver->isConfigured() )->toBeTrue();
} );
