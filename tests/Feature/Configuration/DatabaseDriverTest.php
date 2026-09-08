<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Configuration\DatabaseDriver;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'microsoft-oauth.driver', 'database' );
} );

it( 'uses the database driver when configured', function (): void {
    expect( app( ConfigurationRepository::class ) )->toBeInstanceOf( DatabaseDriver::class );
} );

it( 'persists and reads credentials with an encrypted secret', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-1',
        'client_secret' => 'super-secret',
        'tenant'        => 'contoso.onmicrosoft.com',
    ] );

    expect( $driver->getClientId() )->toBe( 'app-1' );
    expect( $driver->getClientSecret() )->toBe( 'super-secret' );
    expect( $driver->getTenant() )->toBe( 'contoso.onmicrosoft.com' );
    expect( $driver->isConfigured() )->toBeTrue();

    $stored = DB::table( 'microsoft_oauth_configurations' )->first();
    expect( $stored->client_secret )->not->toBe( 'super-secret' );
} );

it( 'updates the existing row on subsequent saves', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-1',
        'client_secret' => 's1',
        'tenant'        => 'contoso.onmicrosoft.com',
    ] );

    // Fresh instance to bypass the driver's per-request cache.
    app()->forgetInstance( DatabaseDriver::class );
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-2',
        'client_secret' => 's2',
        'tenant'        => 'fabrikam.onmicrosoft.com',
    ] );

    expect( DB::table( 'microsoft_oauth_configurations' )->count() )->toBe( 1 );
    expect( $driver->getClientId() )->toBe( 'app-2' );
    expect( $driver->getTenant() )->toBe( 'fabrikam.onmicrosoft.com' );
} );

it( 'stores a null client_secret for public clients', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id'     => 'app-1',
        'client_secret' => null,
        'tenant'        => 'common',
    ] );

    $stored = DB::table( 'microsoft_oauth_configurations' )->first();
    expect( $stored->client_secret )->toBeNull();
    expect( $driver->getClientSecret() )->toBeNull();
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'preserves created_at across subsequent saves', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    $driver->save( [
        'client_id' => 'app-1',
        'tenant'    => 'common',
    ] );

    $original = DB::table( 'microsoft_oauth_configurations' )->first();

    // A short pause would be more realistic, but we assert the value is
    // unchanged rather than that it advanced — no sleep needed.
    $driver->save( [
        'client_id' => 'app-2',
        'tenant'    => 'common',
    ] );

    $updated = DB::table( 'microsoft_oauth_configurations' )->first();
    expect( $updated->created_at )->toBe( $original->created_at );
    expect( $updated->client_id )->toBe( 'app-2' );
} );

it( 'returns null values when no configuration row exists', function (): void {
    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBeNull();
    expect( $driver->getClientSecret() )->toBeNull();
    expect( $driver->getTenant() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'returns a null client_secret and logs when the stored ciphertext cannot be decrypted', function (): void {
    // Simulates an APP_KEY rotation without re-encrypting the row (or a
    // manual DB write of a bogus value): the row is present with a valid
    // client_id + tenant, but the client_secret column decrypts to garbage.
    // The driver must log and return null rather than throwing, so the
    // OAuth flow surfaces a clean "not configured" state to callers who
    // rely on the secret. Note that isConfigured() intentionally stays
    // true here because it checks only client_id + tenant — that mirrors
    // the CmsSettingsDriver test's opposite behavior and is worth pinning
    // in case the two drivers ever converge by accident.
    DB::table( 'microsoft_oauth_configurations' )->insert( [
        'singleton_key' => 'default',
        'client_id'     => 'app-1',
        'client_secret' => 'not-real-ciphertext',
        'tenant'        => 'common',
        'created_at'    => now(),
        'updated_at'    => now(),
    ] );

    Log::spy();

    /** @var DatabaseDriver $driver */
    $driver = app( ConfigurationRepository::class );

    expect( $driver->getClientId() )->toBe( 'app-1' );
    expect( $driver->getTenant() )->toBe( 'common' );
    expect( $driver->getClientSecret() )->toBeNull();
    expect( $driver->isConfigured() )->toBeTrue();

    Log::shouldHaveReceived( 'warning' )->once()->withArgs( function ( string $message ): bool {
        return str_contains( $message, 'failed to decrypt' );
    } );
} );
