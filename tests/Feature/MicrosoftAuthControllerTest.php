<?php

declare( strict_types=1 );

use ArtisanPackUI\Hooks\Facades\Filter;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    config( [
        'microsoft-oauth.client_id'                     => 'client-abc',
        'microsoft-oauth.client_secret'                 => 'secret-xyz',
        'microsoft-oauth.redirect_uri'                  => 'https://example.test/auth/microsoft/callback',
        'microsoft-oauth.tenant'                        => 'common',
        'microsoft-oauth.prompt'                        => 'select_account',
        'microsoft-oauth.routes.redirect_after_connect' => '/after-connect',
        'microsoft-oauth.routes.redirect_after_error'   => '/after-error',
    ] );
} );

function actingUser( int $id = 7 ): Authenticatable
{
    return new class( $id ) implements Authenticatable {
        public function __construct( private int $id )
        {
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken( $value ): void
        {
        }

        public function getRememberTokenName(): string
        {
            return '';
        }
    };
}

it( 'redirects an unauthenticated user away from connect', function (): void {
    $response = $this->get( '/auth/microsoft/connect' );

    // The `auth` middleware redirects to `/login` (or throws) — either way
    // the endpoint is closed off to guests.
    expect( $response->getStatusCode() )->toBeIn( [ 302, 401, 500 ] );
} );

it( 'redirects an authenticated user to the Microsoft authorization URL', function (): void {
    $response = $this
        ->actingAs( actingUser( 42 ) )
        ->get( '/auth/microsoft/connect' );

    $response->assertRedirect();

    $location = $response->headers->get( 'Location' );

    expect( $location )->toStartWith( 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' );

    $query = [];
    parse_str( parse_url( $location, PHP_URL_QUERY ), $query );

    expect( $query[ 'client_id' ] )->toBe( 'client-abc' );
    expect( $query[ 'scope' ] )->toContain( 'offline_access' );
} );

it( 'redirects to the error target when Microsoft returns an error on callback', function (): void {
    $response = $this->get( '/auth/microsoft/callback?error=access_denied&error_description=user+cancelled' );

    $response->assertRedirect( '/after-error' );
    $response->assertSessionHas( 'microsoft.error', 'user cancelled' );
} );

it( 'ignores array-shaped query params instead of stringifying to "Array"', function (): void {
    $response = $this->get( '/auth/microsoft/callback?error[]=x&error_description[]=y' );

    $response->assertRedirect( '/after-error' );
    // Array-shaped params are treated as absent, so we fall through to the
    // "missing code or state" branch rather than flashing "Array" as an error.
    $response->assertSessionHas( 'microsoft.error', function ( $value ): bool {
        return is_string( $value ) && 'Array' !== $value;
    } );
} );

it( 'redirects to the error target when code or state is missing', function (): void {
    $response = $this->get( '/auth/microsoft/callback' );

    $response->assertRedirect( '/after-error' );
    $response->assertSessionHas( 'microsoft.error' );
} );

it( 'completes the callback, exchanges the code, and redirects on success', function (): void {
    session( [
        'microsoft_oauth.state'    => 'state-1',
        'microsoft_oauth.verifier' => 'verifier-abc',
        'microsoft_oauth.user_id'  => 42,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-token-1',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid profile email offline_access',
        ], 200 ),
    ] );

    $response = $this->get( '/auth/microsoft/callback?code=code-1&state=state-1' );

    $response->assertRedirect( '/after-connect' );
    $response->assertSessionHas( 'microsoft.status', 'connected' );

    Http::assertSent( function ( $request ): bool {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token' === $request->url();
    } );
} );

it( 'redirects an unauthenticated user away from reauthorize', function (): void {
    $response = $this->get( '/auth/microsoft/reauthorize' );

    // The `auth` middleware redirects to `/login` (or, under testbench with
    // no `login` route registered, throws — which surfaces as a 500). Any of
    // these three shapes means the endpoint is closed to guests; matches
    // the sibling assertion for `connect`.
    expect( $response->getStatusCode() )->toBeIn( [ 302, 401, 500 ] );
} );

it( 'reauthorize hands the user off to the full connect flow when no connection exists', function (): void {
    $response = $this
        ->actingAs( actingUser( 55 ) )
        ->get( '/auth/microsoft/reauthorize' );

    // Without an existing connection, "incremental" consent is a lie — the
    // user has nothing to build on and needs a real authorization prompt.
    $response->assertRedirect( route( 'microsoft.auth.connect' ) );
    $response->assertSessionMissing( 'microsoft.status' );
} );

it( 'reauthorize redirects to the post-connect target when every required scope is already granted', function (): void {
    MicrosoftConnection::create( [
        'user_id'       => 66,
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'email', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    $response = $this
        ->actingAs( actingUser( 66 ) )
        ->get( '/auth/microsoft/reauthorize' );

    $response->assertRedirect( '/after-connect' );
    $response->assertSessionHas( 'microsoft.status', 'already-authorized' );
} );

it( 'reauthorize redirects to the Microsoft consent URL when new scopes are missing', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ): array => array_merge( $s, [
        'https://graph.microsoft.com/User.Read',
    ] ) );

    MicrosoftConnection::create( [
        'user_id'       => 77,
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'email', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    $response = $this
        ->actingAs( actingUser( 77 ) )
        ->get( '/auth/microsoft/reauthorize' );

    $response->assertRedirect();

    $location = $response->headers->get( 'Location' );

    expect( $location )->toStartWith( 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' );

    $query = [];
    parse_str( parse_url( $location, PHP_URL_QUERY ), $query );

    expect( $query[ 'prompt' ] )->toBe( 'consent' );
    expect( $query[ 'scope' ] )->toContain( 'https://graph.microsoft.com/User.Read' );
} );
