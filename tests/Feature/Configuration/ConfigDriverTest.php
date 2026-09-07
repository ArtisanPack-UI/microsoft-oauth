<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Configuration\ConfigDriver;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;

it( 'is the default driver bound to the contract', function (): void {
    expect( app( ConfigurationRepository::class ) )->toBeInstanceOf( ConfigDriver::class );
} );

it( 'reads credentials from the config repository', function (): void {
    config()->set( 'microsoft-oauth.client_id', 'test-client-id' );
    config()->set( 'microsoft-oauth.client_secret', 'test-secret' );
    config()->set( 'microsoft-oauth.tenant', 'contoso.onmicrosoft.com' );

    /** @var ConfigDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBe( 'test-client-id' );
    expect( $driver->getClientSecret() )->toBe( 'test-secret' );
    expect( $driver->getTenant() )->toBe( 'contoso.onmicrosoft.com' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'is configured without a client secret for public clients', function (): void {
    config()->set( 'microsoft-oauth.client_id', 'test-client-id' );
    config()->set( 'microsoft-oauth.client_secret', null );
    config()->set( 'microsoft-oauth.tenant', 'common' );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeTrue();
} );

it( 'reports unconfigured when the client id is missing', function (): void {
    config()->set( 'microsoft-oauth.client_id', null );
    config()->set( 'microsoft-oauth.tenant', 'common' );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeFalse();
} );

it( 'reports unconfigured when the tenant is missing', function (): void {
    config()->set( 'microsoft-oauth.client_id', 'test-client-id' );
    config()->set( 'microsoft-oauth.tenant', null );

    expect( app( ConfigurationRepository::class )->isConfigured() )->toBeFalse();
} );

it( 'coerces blank strings to null', function (): void {
    config()->set( 'microsoft-oauth.client_id', '   ' );
    config()->set( 'microsoft-oauth.tenant', '' );

    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBeNull();
    expect( $driver->getTenant() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'throws when save is called on the read-only config driver', function (): void {
    $driver = app( ConfigurationRepository::class );

    expect( fn () => $driver->save( [ 'client_id' => 'x' ] ) )
        ->toThrow( RuntimeException::class );
} );
