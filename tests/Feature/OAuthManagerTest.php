<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use ArtisanPackUI\MicrosoftOAuth\Support\TokenPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    config( [
        'microsoft-oauth.client_id'     => 'client-abc',
        'microsoft-oauth.client_secret' => 'secret-xyz',
        'microsoft-oauth.redirect_uri'  => 'https://example.test/auth/microsoft/callback',
        'microsoft-oauth.tenant'        => 'common',
        'microsoft-oauth.prompt'        => 'select_account',
    ] );
} );

function makeIdToken( array $claims ): string
{
    $encode = static fn ( array $data ): string => rtrim(
        strtr( base64_encode( json_encode( $data ) ), '+/', '-_' ),
        '=',
    );

    return $encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) . '.' . $encode( $claims ) . '.signature';
}

function makeManager(): OAuthManager
{
    return app( OAuthManager::class );
}

it( 'builds an authorization URL with PKCE, state, and offline_access', function (): void {
    $url = makeManager()->authorizationUrl( 42 );

    expect( $url )->toStartWith( 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' );

    $query = [];
    parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query[ 'client_id' ] )->toBe( 'client-abc' );
    expect( $query[ 'response_type' ] )->toBe( 'code' );
    expect( $query[ 'redirect_uri' ] )->toBe( 'https://example.test/auth/microsoft/callback' );
    expect( $query[ 'code_challenge_method' ] )->toBe( 'S256' );
    expect( $query[ 'code_challenge' ] )->not->toBeEmpty();
    expect( $query[ 'state' ] )->not->toBeEmpty();
    expect( $query[ 'prompt' ] )->toBe( 'select_account' );
    expect( $query[ 'scope' ] )->toContain( 'offline_access' );
    expect( $query[ 'scope' ] )->toContain( 'openid' );

    expect( session( 'microsoft_oauth.state' ) )->toBe( $query[ 'state' ] );
    expect( session( 'microsoft_oauth.verifier' ) )->not->toBeEmpty();
    expect( session( 'microsoft_oauth.user_id' ) )->toBe( 42 );
} );

it( 'honors the configured tenant when building endpoints', function (): void {
    config( [ 'microsoft-oauth.tenant' => 'contoso.onmicrosoft.com' ] );

    $url = makeManager()->authorizationUrl( 1 );

    expect( $url )->toStartWith(
        'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?',
    );
} );

it( 'throws when required config is missing', function (): void {
    config( [ 'microsoft-oauth.client_id' => null ] );

    makeManager()->authorizationUrl( 1 );
} )->throws( OAuthException::class );

it( 'rejects a mismatched state on callback', function (): void {
    session( [
        'microsoft_oauth.state'    => 'expected-state',
        'microsoft_oauth.verifier' => 'verifier-abc',
        'microsoft_oauth.user_id'  => 99,
    ] );

    makeManager()->handleCallback( 'code-1', 'wrong-state' );
} )->throws( OAuthException::class, 'OAuth state mismatch' );

it( 'requires a PKCE verifier to be present', function (): void {
    session( [
        'microsoft_oauth.state'   => 'state-1',
        'microsoft_oauth.user_id' => 99,
    ] );

    makeManager()->handleCallback( 'code-1', 'state-1' );
} )->throws( OAuthException::class, 'PKCE code verifier missing' );

it( 'exchanges the code and returns a TokenPayload', function (): void {
    Carbon::setTestNow( '2026-09-07 12:00:00' );

    session( [
        'microsoft_oauth.state'    => 'state-1',
        'microsoft_oauth.verifier' => 'verifier-abc',
        'microsoft_oauth.user_id'  => 99,
    ] );

    $idToken = makeIdToken( [
        'oid'                => 'ms-user-1',
        'email'              => 'user@example.com',
        'preferred_username' => 'user@example.com',
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'access-token-1',
            'refresh_token' => 'refresh-token-1',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => 'openid profile email offline_access',
            'id_token'      => $idToken,
        ], 200 ),
    ] );

    $payload = makeManager()->handleCallback( 'code-1', 'state-1' );

    expect( $payload )->toBeInstanceOf( TokenPayload::class );
    expect( $payload->accessToken )->toBe( 'access-token-1' );
    expect( $payload->refreshToken )->toBe( 'refresh-token-1' );
    expect( $payload->tokenType )->toBe( 'Bearer' );
    expect( $payload->scopes )->toContain( 'offline_access' );
    expect( $payload->expiresAt->toIso8601String() )->toBe(
        Carbon::parse( '2026-09-07 13:00:00' )->toIso8601String(),
    );
    expect( $payload->microsoftUserId )->toBe( 'ms-user-1' );
    expect( $payload->email )->toBe( 'user@example.com' );
    expect( $payload->userId )->toBe( 99 );

    // Session values should be pulled (single-use)
    expect( session( 'microsoft_oauth.state' ) )->toBeNull();
    expect( session( 'microsoft_oauth.verifier' ) )->toBeNull();
    expect( session( 'microsoft_oauth.user_id' ) )->toBeNull();

    Http::assertSent( function ( $request ): bool {
        $data = $request->data();

        return 'authorization_code' === $data[ 'grant_type' ]
            && 'code-1' === $data[ 'code' ]
            && 'verifier-abc' === $data[ 'code_verifier' ]
            && 'client-abc' === $data[ 'client_id' ]
            && 'secret-xyz' === $data[ 'client_secret' ]
            && 'https://example.test/auth/microsoft/callback' === $data[ 'redirect_uri' ];
    } );
} );

it( 'omits client_secret for public clients', function (): void {
    config( [ 'microsoft-oauth.client_secret' => '' ] );

    session( [
        'microsoft_oauth.state'    => 'state-1',
        'microsoft_oauth.verifier' => 'verifier-abc',
        'microsoft_oauth.user_id'  => 5,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'a',
            'token_type'   => 'Bearer',
            'expires_in'   => 60,
        ], 200 ),
    ] );

    makeManager()->handleCallback( 'code-1', 'state-1' );

    Http::assertSent( function ( $request ): bool {
        return ! array_key_exists( 'client_secret', $request->data() );
    } );
} );

it( 'surfaces Microsoft error_description when the exchange fails', function (): void {
    session( [
        'microsoft_oauth.state'    => 'state-1',
        'microsoft_oauth.verifier' => 'verifier-abc',
        'microsoft_oauth.user_id'  => 1,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'error'             => 'invalid_grant',
            'error_description' => 'AADSTS70008: code expired',
        ], 400 ),
    ] );

    try {
        makeManager()->handleCallback( 'code-1', 'state-1' );
        $this->fail( 'Expected OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getMessage() )->toContain( 'AADSTS70008' );
    }
} );
