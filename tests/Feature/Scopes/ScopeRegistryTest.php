<?php

declare( strict_types=1 );

use ArtisanPackUI\Hooks\Facades\Filter;
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;

it( 'includes baseline identity scopes by default', function (): void {
    $registry = app( ScopeRegistry::class );

    expect( $registry->all() )->toContain( 'openid' );
    expect( $registry->all() )->toContain( 'profile' );
    expect( $registry->all() )->toContain( 'email' );
    expect( $registry->all() )->toContain( 'offline_access' );
} );

it( 'unions scopes contributed via the ap.microsoft.oauth.scopes filter', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', function ( array $scopes ): array {
        $scopes[] = 'https://www.bingapis.com/api/v7/localbusinesses.readwrite';
        $scopes[] = 'https://graph.microsoft.com/User.Read';
        return $scopes;
    } );

    $registry = app( ScopeRegistry::class );
    $all      = $registry->all();

    expect( $all )->toContain( 'https://www.bingapis.com/api/v7/localbusinesses.readwrite' );
    expect( $all )->toContain( 'https://graph.microsoft.com/User.Read' );
} );

it( 'deduplicates scopes from multiple sources', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ) => array_merge( $s, [ 'openid', 'x' ] ) );

    $registry = app( ScopeRegistry::class );
    $registry->register( 'x' );

    $all = $registry->all();
    expect( array_count_values( $all )[ 'openid' ] )->toBe( 1 );
    expect( array_count_values( $all )[ 'x' ] )->toBe( 1 );
} );

it( 'trims whitespace and ignores empty scopes', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ) => array_merge( $s, [ '  spaced  ', '' ] ) );

    $registry = app( ScopeRegistry::class );
    $registry->register( '   ' );
    $registry->register( '  trimmed  ' );

    $all = $registry->all();
    expect( $all )->toContain( 'spaced' );
    expect( $all )->toContain( 'trimmed' );
    expect( $all )->not->toContain( '' );
    expect( $all )->not->toContain( '   ' );
} );

it( 'computes missing scopes vs granted', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ) => array_merge( $s, [ 'a', 'b', 'c' ] ) );

    $registry = app( ScopeRegistry::class );

    expect( $registry->missing( [ 'openid', 'profile', 'email', 'offline_access', 'a' ] ) )->toContain( 'b' );
    expect( $registry->missing( [ 'openid', 'profile', 'email', 'offline_access', 'a' ] ) )->toContain( 'c' );
    expect( $registry->missing( $registry->all() ) )->toBe( [] );
    expect( $registry->hasAllRequired( $registry->all() ) )->toBeTrue();
    expect( $registry->hasAllRequired( [] ) )->toBeFalse();
} );

it( 'ignores non-array filter returns gracefully', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn () => 'not-an-array' );

    $registry = app( ScopeRegistry::class );

    expect( $registry->all() )->toContain( 'openid' );
} );
