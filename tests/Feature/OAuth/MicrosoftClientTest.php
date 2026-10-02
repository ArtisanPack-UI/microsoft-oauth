<?php

declare( strict_types=1 );

use ArtisanPackUI\MicrosoftOAuth\Configuration\ConfigDriver;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;
use ArtisanPackUI\MicrosoftOAuth\Facades\MicrosoftOAuth;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftClient;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftCredentials;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    // The broker's own app credentials, supplied at runtime — nothing in config.
    config( [
        'microsoft-oauth.client_id'     => null,
        'microsoft-oauth.client_secret' => null,
        'microsoft-oauth.redirect_uri'  => null,
    ] );

    $this->client = MicrosoftOAuth::client( new MicrosoftCredentials(
        'runtime-client',
        'runtime-secret',
        'organizations',
        'https://broker.test/oauth/microsoft/callback',
    ) );
} );

afterEach( function (): void {
    Carbon::setTestNow();
} );

it( 'builds a consent URL from runtime credentials and the caller state without touching the session', function (): void {
    $url = $this->client->authorizationUrl(
        'caller-state',
        [ 'openid', 'offline_access', 'User.Read' ],
        [ 'prompt' => 'consent', 'login_hint' => 'user@contoso.com' ],
    );

    expect( $url )->toStartWith( 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?' );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['client_id'] )->toBe( 'runtime-client' );
    expect( $params['redirect_uri'] )->toBe( 'https://broker.test/oauth/microsoft/callback' );
    expect( $params['response_type'] )->toBe( 'code' );
    expect( $params['response_mode'] )->toBe( 'query' );
    expect( $params['state'] )->toBe( 'caller-state' );
    expect( $params['scope'] )->toBe( 'openid offline_access User.Read' );
    expect( $params['prompt'] )->toBe( 'consent' );
    expect( $params['login_hint'] )->toBe( 'user@contoso.com' );
    expect( $params )->not->toHaveKey( 'code_challenge' );

    expect( session()->all() )->not->toHaveKey( 'microsoft_oauth.state' );
    expect( session()->all() )->not->toHaveKey( 'microsoft_oauth.verifier' );
} );

it( 'adds PKCE when the caller supplies a verifier', function (): void {
    $verifier = MicrosoftClient::generateCodeVerifier();

    parse_str( parse_url( $this->client->authorizationUrl( 's', [ 'openid' ], [], $verifier ), PHP_URL_QUERY ), $params );

    expect( $params['code_challenge'] )->toBe( MicrosoftClient::codeChallenge( $verifier ) );
    expect( $params['code_challenge_method'] )->toBe( 'S256' );
} );

it( 'ignores extra parameters that would override the flow-security parameters', function (): void {
    $verifier = MicrosoftClient::generateCodeVerifier();

    $url = $this->client->authorizationUrl( 'caller-state', [ 'openid' ], [
        'state'                 => 'attacker-state',
        'client_id'             => 'attacker-client',
        'redirect_uri'          => 'https://evil.test/cb',
        'scope'                 => 'Mail.ReadWrite',
        'code_challenge'        => 'attacker-challenge',
        'code_challenge_method' => 'plain',
        'response_type'         => 'token',
        'domain_hint'           => 'contoso.com',
    ], $verifier );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );

    expect( $params['state'] )->toBe( 'caller-state' );
    expect( $params['client_id'] )->toBe( 'runtime-client' );
    expect( $params['redirect_uri'] )->toBe( 'https://broker.test/oauth/microsoft/callback' );
    expect( $params['scope'] )->toBe( 'openid' );
    expect( $params['code_challenge'] )->toBe( MicrosoftClient::codeChallenge( $verifier ) );
    expect( $params['code_challenge_method'] )->toBe( 'S256' );
    expect( $params['response_type'] )->toBe( 'code' );
    expect( $params['domain_hint'] )->toBe( 'contoso.com' );
} );

it( 'refuses to build a consent URL without a redirect URI', function (): void {
    MicrosoftOAuth::client( new MicrosoftCredentials( 'runtime-client' ) )->authorizationUrl( 's', [ 'openid' ] );
} )->throws( OAuthException::class, 'redirect_uri is missing' );

it( 'exchanges a code and returns tokens and identity without persisting anything', function (): void {
    Carbon::setTestNow( '2026-10-02 12:00:00' );

    Http::fake( [
        'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response( [
            'token_type'    => 'Bearer',
            'access_token'  => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in'    => 3600,
            'scope'         => 'openid offline_access User.Read',
            'id_token'      => microsoftIdToken(),
        ] ),
    ] );

    $tokens = $this->client->exchangeCode( 'code-1', [ 'openid', 'offline_access', 'User.Read' ], 'verifier-1' );

    expect( $tokens->accessToken )->toBe( 'access-1' );
    expect( $tokens->refreshToken )->toBe( 'refresh-1' );
    expect( $tokens->expiresIn )->toBe( 3600 );
    expect( $tokens->expiresAt?->toDateTimeString() )->toBe( '2026-10-02 13:00:00' );
    expect( $tokens->scopes )->toBe( [ 'openid', 'offline_access', 'User.Read' ] );
    expect( $tokens->accountId )->toBe( 'oid-123' );
    expect( $tokens->accountEmail )->toBe( 'user@contoso.com' );
    expect( $tokens->accountName )->toBe( 'Jane Doe' );
    expect( $tokens->tenantId )->toBe( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
    expect( $tokens->idToken )->not->toBeNull();

    expect( MicrosoftConnection::count() )->toBe( 0 );

    Http::assertSent( fn ( $request ): bool => 'authorization_code' === $request->data()['grant_type']
        && 'code-1' === $request->data()['code']
        && 'verifier-1' === $request->data()['code_verifier']
        && 'openid offline_access User.Read' === $request->data()['scope']
        && 'runtime-client' === $request->data()['client_id']
        && 'runtime-secret' === $request->data()['client_secret']
        && 'https://broker.test/oauth/microsoft/callback' === $request->data()['redirect_uri'] );
} );

it( 'prefers sub and email claims when oid and preferred_username are absent or overridden', function (): void {
    Http::fake( [
        'login.microsoftonline.com/*' => Http::response( [
            'access_token' => 'a',
            'id_token'     => microsoftIdToken( [ 'oid' => null, 'email' => 'primary@contoso.com' ] ),
        ] ),
    ] );

    $tokens = $this->client->exchangeCode( 'c', [ 'openid' ] );

    expect( $tokens->accountId )->toBe( 'sub-123' );
    expect( $tokens->accountEmail )->toBe( 'primary@contoso.com' );
} );

it( 'omits the client secret and verifier for a public client without PKCE', function (): void {
    Http::fake( [ 'login.microsoftonline.com/*' => Http::response( [ 'access_token' => 'a', 'id_token' => microsoftIdToken() ] ) ] );

    MicrosoftOAuth::client( new MicrosoftCredentials( 'public-client', null, 'common', 'https://app.test/cb' ) )
        ->exchangeCode( 'c', [ 'openid' ] );

    Http::assertSent( fn ( $request ): bool => ! array_key_exists( 'client_secret', $request->data() )
        && ! array_key_exists( 'code_verifier', $request->data() ) );
} );

it( 'keeps the tid check against the configured authority', function (): void {
    Http::fake( [
        'login.microsoftonline.com/*' => Http::response( [
            'access_token' => 'a',
            'id_token'     => microsoftIdToken( [ 'tid' => '9188040d-6c67-4c5b-b112-36a304b66dad' ] ),
        ] ),
    ] );

    // `organizations` rejects the personal-account (MSA) tenant.
    $this->client->exchangeCode( 'c', [ 'openid' ] );
} )->throws( OAuthException::class, 'personal account' );

it( 'carries the OAuth error code and description from a failed exchange', function (): void {
    Http::fake( [
        'login.microsoftonline.com/*' => Http::response( [
            'error'             => 'invalid_grant',
            'error_description' => 'AADSTS70008: The code has expired.',
        ], 400 ),
    ] );

    try {
        $this->client->exchangeCode( 'expired', [ 'openid' ] );
        $this->fail( 'Expected an OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getError() )->toBe( 'invalid_grant' );
        expect( $e->getMessage() )->toContain( 'AADSTS70008' );
    }
} );

it( 'treats a token-less success as an invalid payload', function (): void {
    Http::fake( [ 'login.microsoftonline.com/*' => Http::response( [ 'token_type' => 'Bearer' ] ) ] );

    $this->client->exchangeCode( 'c', [ 'openid' ] );
} )->throws( OAuthException::class, 'invalid payload' );

it( 'refreshes from a raw refresh token with the scopes and returns the rotated token', function (): void {
    Http::fake( [
        'login.microsoftonline.com/*' => Http::response( [
            'access_token'  => 'fresh',
            'refresh_token' => 'rotated',
            'expires_in'    => 3599,
            'scope'         => 'openid User.Read',
        ] ),
    ] );

    $tokens = $this->client->refresh( 'raw-refresh', [ 'openid', 'User.Read' ] );

    expect( $tokens->accessToken )->toBe( 'fresh' );
    expect( $tokens->refreshToken )->toBe( 'rotated' );
    expect( $tokens->scopes )->toBe( [ 'openid', 'User.Read' ] );
    expect( MicrosoftConnection::count() )->toBe( 0 );

    Http::assertSent( fn ( $request ): bool => 'refresh_token' === $request->data()['grant_type']
        && 'raw-refresh' === $request->data()['refresh_token']
        && 'openid User.Read' === $request->data()['scope']
        && 'runtime-secret' === $request->data()['client_secret'] );
} );

it( 'keeps the passed refresh token when Microsoft returns none', function (): void {
    Http::fake( [ 'login.microsoftonline.com/*' => Http::response( [ 'access_token' => 'fresh' ] ) ] );

    expect( $this->client->refresh( 'raw-refresh' )->refreshToken )->toBe( 'raw-refresh' );

    Http::assertSent( fn ( $request ): bool => ! array_key_exists( 'scope', $request->data() ) );
} );

it( 'reports refresh failures with their OAuth code', function (): void {
    Http::fake( [ 'login.microsoftonline.com/*' => Http::response( [ 'error' => 'interaction_required' ], 400 ) ] );

    try {
        $this->client->refresh( 'r' );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'interaction_required' );
    }
} );

it( 'raises a refresh exception, not an OAuth exception, for an invalid tenant', function (): void {
    Http::fake();

    try {
        MicrosoftOAuth::client( new MicrosoftCredentials( 'c', null, 'not a tenant' ) )->refresh( 'r' );
        $this->fail( 'Expected a TokenRefreshException.' );
    } catch ( TokenRefreshException $e ) {
        expect( $e->getError() )->toBe( 'invalid_tenant' );
    }

    Http::assertNothingSent();
} );

it( 'renders the broker wire shape', function (): void {
    Http::fake( [
        'login.microsoftonline.com/*' => Http::response( [
            'access_token'  => 'a',
            'refresh_token' => 'r',
            'expires_in'    => 3600,
            'scope'         => 'openid User.Read',
            'id_token'      => microsoftIdToken(),
        ] ),
    ] );

    expect( $this->client->exchangeCode( 'c', [ 'openid' ] )->toArray() )->toBe( [
        'token_type'    => 'Bearer',
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'expires_in'    => 3600,
        'scopes'        => [ 'openid', 'User.Read' ],
        'account_email' => 'user@contoso.com',
        'account_name'  => 'Jane Doe',
        'id_token'      => microsoftIdToken(),
    ] );
} );

it( 'verifies the caller state in constant time', function (): void {
    MicrosoftClient::verifyState( 'expected', 'expected' );

    expect( fn () => MicrosoftClient::verifyState( 'expected', 'forged' ) )->toThrow( OAuthException::class );
    expect( fn () => MicrosoftClient::verifyState( null, 'anything' ) )->toThrow( OAuthException::class );
} );

it( 'reads credentials from the configured driver, including the redirect URI', function (): void {
    config( [
        'microsoft-oauth.client_id'    => 'config-client',
        'microsoft-oauth.tenant'       => 'contoso.onmicrosoft.com',
        'microsoft-oauth.redirect_uri' => 'https://app.test/auth/microsoft/callback',
    ] );

    $credentials = MicrosoftOAuth::client()->credentials();

    expect( $credentials->clientId )->toBe( 'config-client' );
    expect( $credentials->clientSecret )->toBeNull();
    expect( $credentials->tenant )->toBe( 'contoso.onmicrosoft.com' );
    expect( $credentials->redirectUri )->toBe( 'https://app.test/auth/microsoft/callback' );
} );

it( 'falls back to the configured redirect URI when the driver has none', function (): void {
    $driver = Mockery::mock( ConfigDriver::class );
    $driver->allows( [
        'getClientId'     => 'c',
        'getClientSecret' => null,
        'getTenant'       => null,
        'getRedirectUri'  => null,
    ] );

    expect( MicrosoftCredentials::fromRepository( $driver, 'https://fallback.test/cb' )->redirectUri )
        ->toBe( 'https://fallback.test/cb' );
} );

it( 'keeps working with a 1.0-style custom driver that has no redirect URI method', function (): void {
    config( [ 'microsoft-oauth.redirect_uri' => 'https://config.test/cb' ] );

    $legacyDriver = new class implements ConfigurationRepository {
        public function getClientId(): ?string
        {
            return 'legacy-client';
        }

        public function getClientSecret(): ?string
        {
            return null;
        }

        public function getTenant(): ?string
        {
            return 'common';
        }

        public function save( array $credentials ): void
        {
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    app()->instance( ConfigurationRepository::class, $legacyDriver );
    app()->forgetScopedInstances();

    parse_str( parse_url( app( OAuthManager::class )->authorizationUrl( 1 ), PHP_URL_QUERY ), $query );

    expect( $query['client_id'] )->toBe( 'legacy-client' );
    expect( $query['redirect_uri'] )->toBe( 'https://config.test/cb' );
} );
