<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\OAuth\TenantAuthority;
use ArtisanPackUI\MicrosoftOAuth\OAuth\TenantMode;

it( 'defaults null and blank values to common', function ( ?string $value ): void {
    $authority = TenantAuthority::fromConfig( $value );

    expect( $authority->value() )->toBe( 'common' );
    expect( $authority->mode() )->toBe( TenantMode::Common );
    expect( $authority->isMultiTenant() )->toBeTrue();
    expect( $authority->expectedTenantId() )->toBeNull();
} )->with( [ null, '', '   ' ] );

it( 'recognizes the three reserved multi-tenant authorities', function ( string $value, TenantMode $mode ): void {
    $authority = TenantAuthority::fromConfig( $value );

    expect( $authority->value() )->toBe( strtolower( $value ) );
    expect( $authority->mode() )->toBe( $mode );
    expect( $authority->isMultiTenant() )->toBeTrue();
    expect( $authority->expectedTenantId() )->toBeNull();
} )->with( [
    [ 'common', TenantMode::Common ],
    [ 'COMMON', TenantMode::Common ],
    [ 'organizations', TenantMode::Organizations ],
    [ 'consumers', TenantMode::Consumers ],
] );

it( 'parses a tenant GUID into a single-tenant authority with the GUID captured', function (): void {
    $guid = '11111111-2222-3333-4444-555555555555';

    $authority = TenantAuthority::fromConfig( $guid );

    expect( $authority->value() )->toBe( $guid );
    expect( $authority->mode() )->toBe( TenantMode::Tenant );
    expect( $authority->isMultiTenant() )->toBeFalse();
    expect( $authority->expectedTenantId() )->toBe( $guid );
} );

it( 'parses a verified domain into a single-tenant authority without a known GUID', function ( string $domain ): void {
    $authority = TenantAuthority::fromConfig( $domain );

    expect( $authority->value() )->toBe( strtolower( $domain ) );
    expect( $authority->mode() )->toBe( TenantMode::Tenant );
    expect( $authority->isMultiTenant() )->toBeFalse();
    expect( $authority->expectedTenantId() )->toBeNull();
} )->with( [
    'contoso.onmicrosoft.com',
    'Contoso.com',
    'sub.example.co.uk',
] );

it( 'rejects tenant values that are neither a reserved authority nor a plausible GUID / domain', function ( string $value ): void {
    TenantAuthority::fromConfig( $value );
} )->with( [
    'unknown-mode',
    'not a guid',
    'contoso',
    '11111111-2222-3333-4444-55555555555',
    'not-a-uuid-but-has-hyphens-12345',
    '.leading-dot.com',
    'trailing-dot.',
] )->throws( OAuthException::class );

it( 'accepts any tid on common, including a missing one', function (): void {
    $authority = TenantAuthority::fromConfig( 'common' );

    $authority->assertTidMatches( '11111111-2222-3333-4444-555555555555' );
    $authority->assertTidMatches( TenantAuthority::MSA_TENANT_ID );
    $authority->assertTidMatches( null );
    $authority->assertTidMatches( '' );

    expect( true )->toBeTrue();
} );

it( 'rejects the MSA tenant on an organizations authority', function (): void {
    TenantAuthority::fromConfig( 'organizations' )
        ->assertTidMatches( TenantAuthority::MSA_TENANT_ID );
} )->throws( OAuthException::class, 'personal account' );

it( 'accepts a work tenant on an organizations authority', function (): void {
    TenantAuthority::fromConfig( 'organizations' )
        ->assertTidMatches( '11111111-2222-3333-4444-555555555555' );

    expect( true )->toBeTrue();
} );

it( 'rejects a work tenant on a consumers authority', function (): void {
    TenantAuthority::fromConfig( 'consumers' )
        ->assertTidMatches( '11111111-2222-3333-4444-555555555555' );
} )->throws( OAuthException::class, 'work / school account' );

it( 'accepts the MSA tenant on a consumers authority', function (): void {
    TenantAuthority::fromConfig( 'consumers' )
        ->assertTidMatches( TenantAuthority::MSA_TENANT_ID );

    expect( true )->toBeTrue();
} );

it( 'rejects a tid that does not match a single-tenant GUID authority', function (): void {
    TenantAuthority::fromConfig( '11111111-2222-3333-4444-555555555555' )
        ->assertTidMatches( '99999999-8888-7777-6666-555555555555' );
} )->throws( OAuthException::class, 'registered for tenant' );

it( 'accepts a matching tid on a single-tenant GUID authority, case-insensitively', function (): void {
    TenantAuthority::fromConfig( '11111111-2222-3333-4444-555555555555' )
        ->assertTidMatches( '11111111-2222-3333-4444-555555555555' );

    TenantAuthority::fromConfig( 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' )
        ->assertTidMatches( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );

    expect( true )->toBeTrue();
} );

it( 'accepts any tid on a verified-domain single-tenant authority', function (): void {
    TenantAuthority::fromConfig( 'contoso.onmicrosoft.com' )
        ->assertTidMatches( '11111111-2222-3333-4444-555555555555' );

    expect( true )->toBeTrue();
} );

it( 'requires a tid on every non-common authority', function ( string $config ): void {
    TenantAuthority::fromConfig( $config )->assertTidMatches( null );
} )->with( [
    'organizations',
    'consumers',
    '11111111-2222-3333-4444-555555555555',
    'contoso.onmicrosoft.com',
] )->throws( OAuthException::class, 'missing the tid claim' );
