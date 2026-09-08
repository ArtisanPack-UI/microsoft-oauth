<?php

declare( strict_types=1 );

use ArtisanPackUI\Hooks\Facades\Filter;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\OAuth\IncrementalConsentResult;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

it( 'includes scopes contributed via the ap.microsoft.oauth.scopes filter in the authorization URL', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ): array => array_merge( $s, [
        'https://graph.microsoft.com/User.Read',
    ] ) );

    $url = makeManager()->authorizationUrl( 1 );

    parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query[ 'scope' ] )->toContain( 'https://graph.microsoft.com/User.Read' );
    expect( $query[ 'scope' ] )->toContain( 'offline_access' );
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

it( 'exchanges the code and persists an encrypted MicrosoftConnection', function (): void {
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

    $connection = makeManager()->handleCallback( 'code-1', 'state-1' );

    expect( $connection )->toBeInstanceOf( MicrosoftConnection::class );
    expect( $connection->exists )->toBeTrue();
    expect( $connection->user_id )->toBe( 99 );
    expect( $connection->access_token )->toBe( 'access-token-1' );
    expect( $connection->refresh_token )->toBe( 'refresh-token-1' );
    expect( $connection->token_type )->toBe( 'Bearer' );
    expect( $connection->grantedScopes() )->toContain( 'offline_access' );
    expect( $connection->expires_at->toIso8601String() )->toBe(
        Carbon::parse( '2026-09-07 13:00:00' )->toIso8601String(),
    );
    expect( $connection->microsoft_user_id )->toBe( 'ms-user-1' );
    expect( $connection->email )->toBe( 'user@example.com' );
    expect( $connection->status )->toBe( MicrosoftConnection::STATUS_CONNECTED );

    // Tokens must be encrypted at rest — verify the raw column value is
    // ciphertext that decrypts back to the plaintext.
    $row = DB::table( 'microsoft_connections' )->where( 'id', $connection->id )->first();
    expect( $row->access_token )->not->toBe( 'access-token-1' );
    expect( Crypt::decryptString( $row->access_token ) )->toBe( 'access-token-1' );
    expect( Crypt::decryptString( $row->refresh_token ) )->toBe( 'refresh-token-1' );

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

it( 'reuses the existing connection row on a repeat callback for the same user', function (): void {
    $existing = MicrosoftConnection::create( [
        'user_id'           => 42,
        'microsoft_user_id' => 'ms-user-42',
        'email'             => 'old@example.com',
        'access_token'      => 'old-access',
        'refresh_token'     => 'old-refresh',
        'token_type'        => 'Bearer',
        'scopes'            => [ 'openid', 'profile', 'email', 'offline_access' ],
        'expires_at'        => Carbon::now()->subHour(),
        'status'            => MicrosoftConnection::STATUS_DISCONNECTED,
        'disconnect_reason' => 'Prior revoke',
    ] );

    session( [
        'microsoft_oauth.state'    => 'state-2',
        'microsoft_oauth.verifier' => 'verifier-def',
        'microsoft_oauth.user_id'  => 42,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => 'openid profile email offline_access',
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-2', 'state-2' );

    expect( $connection->id )->toBe( $existing->id );
    expect( MicrosoftConnection::count() )->toBe( 1 );
    expect( $connection->access_token )->toBe( 'new-access' );
    expect( $connection->refresh_token )->toBe( 'new-refresh' );
    expect( $connection->status )->toBe( MicrosoftConnection::STATUS_CONNECTED );
    expect( $connection->disconnect_reason )->toBeNull();
} );

it( 'preserves the stored refresh token when the exchange response omits it', function (): void {
    MicrosoftConnection::create( [
        'user_id'       => 7,
        'access_token'  => 'old-access',
        'refresh_token' => 'preserved-refresh',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    session( [
        'microsoft_oauth.state'    => 'state-3',
        'microsoft_oauth.verifier' => 'verifier-ghi',
        'microsoft_oauth.user_id'  => 7,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'new-access',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-3', 'state-3' );

    expect( $connection->refresh_token )->toBe( 'preserved-refresh' );
} );

it( 'recovers when a concurrent callback for the same user wins the insert race', function (): void {
    // Simulate the race: `firstOrNew` sees no row (nothing has been
    // inserted yet), but between that check and our `save()`, a
    // concurrent callback inserts the row first — our INSERT then
    // hits the unique(user_id) index and throws. The manager must
    // recover by re-fetching and applying our fresh tokens.
    $raceFired = false;
    MicrosoftConnection::creating( function ( MicrosoftConnection $model ) use ( &$raceFired ): void {
        if ( $raceFired ) {
            return;
        }

        if ( 42 !== $model->user_id ) {
            return;
        }

        $raceFired = true;

        // Bypass the model layer so `creating` doesn't recurse.
        DB::table( 'microsoft_connections' )->insert( [
            'user_id'       => 42,
            'access_token'  => encrypt( 'race-loser-access' ),
            'refresh_token' => encrypt( 'race-loser-refresh' ),
            'token_type'    => 'Bearer',
            'scopes'        => json_encode( [ 'openid' ] ),
            'expires_at'    => Carbon::now()->subHour()->toDateTimeString(),
            'status'        => MicrosoftConnection::STATUS_CONNECTED,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ] );
    } );

    session( [
        'microsoft_oauth.state'    => 'state-race',
        'microsoft_oauth.verifier' => 'verifier-race',
        'microsoft_oauth.user_id'  => 42,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'race-winner-access',
            'refresh_token' => 'race-winner-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => 'openid profile email offline_access',
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-race', 'state-race' );

    MicrosoftConnection::flushEventListeners();

    expect( $raceFired )->toBeTrue();
    expect( MicrosoftConnection::count() )->toBe( 1 );
    expect( $connection->access_token )->toBe( 'race-winner-access' );
    expect( $connection->refresh_token )->toBe( 'race-winner-refresh' );
    expect( $connection->status )->toBe( MicrosoftConnection::STATUS_CONNECTED );
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

it( 'returns NoConnection from incrementalAuthorizationUrl when the user has no existing connection', function (): void {
    expect( makeManager()->incrementalAuthorizationUrl( 999 ) )
        ->toBe( IncrementalConsentResult::NoConnection );
    expect( session( 'microsoft_oauth.incremental' ) )->toBeNull();
} );

it( 'returns AlreadyAuthorized from incrementalAuthorizationUrl when every required scope is already granted', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ): array => array_merge( $s, [
        'https://graph.microsoft.com/User.Read',
    ] ) );

    MicrosoftConnection::create( [
        'user_id'       => 10,
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'token_type'    => 'Bearer',
        'scopes'        => [
            'openid',
            'profile',
            'email',
            'offline_access',
            'https://graph.microsoft.com/User.Read',
        ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    expect( makeManager()->incrementalAuthorizationUrl( 10 ) )
        ->toBe( IncrementalConsentResult::AlreadyAuthorized );
    expect( session( 'microsoft_oauth.incremental' ) )->toBeNull();
} );

it( 'builds an incremental authorization URL with prompt=consent when new scopes are needed', function (): void {
    Filter::add( 'ap.microsoft.oauth.scopes', fn ( array $s ): array => array_merge( $s, [
        'https://graph.microsoft.com/User.Read',
        'https://graph.microsoft.com/Mail.Read',
    ] ) );

    MicrosoftConnection::create( [
        'user_id'       => 20,
        'access_token'  => 'a',
        'refresh_token' => 'r',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'email', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    $url = makeManager()->incrementalAuthorizationUrl( 20 );

    expect( $url )->toBeString();
    expect( $url )->toStartWith( 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' );

    $query = [];
    parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

    expect( $query[ 'prompt' ] )->toBe( 'consent' );
    expect( $query[ 'scope' ] )->toContain( 'https://graph.microsoft.com/User.Read' );
    expect( $query[ 'scope' ] )->toContain( 'https://graph.microsoft.com/Mail.Read' );
    expect( $query[ 'scope' ] )->toContain( 'offline_access' );

    // The incremental flag must be stashed so the callback knows to
    // preserve previously-granted scopes.
    expect( session( 'microsoft_oauth.incremental' ) )->toBeTrue();
    expect( session( 'microsoft_oauth.user_id' ) )->toBe( 20 );
    expect( session( 'microsoft_oauth.state' ) )->toBe( $query[ 'state' ] );
} );

it( 'authorizationUrl clears any stale incremental flag left in the session', function (): void {
    session( [ 'microsoft_oauth.incremental' => true ] );

    makeManager()->authorizationUrl( 1 );

    expect( session( 'microsoft_oauth.incremental' ) )->toBeNull();
} );

it( 'unions returned scopes with previously-granted ones after an incremental re-auth callback', function (): void {
    $existing = MicrosoftConnection::create( [
        'user_id'       => 30,
        'access_token'  => 'old-access',
        'refresh_token' => 'old-refresh',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'email', 'offline_access' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    session( [
        'microsoft_oauth.state'       => 'state-inc',
        'microsoft_oauth.verifier'    => 'verifier-inc',
        'microsoft_oauth.user_id'     => 30,
        'microsoft_oauth.incremental' => true,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            // Microsoft may return only the newly-consented scope on the
            // incremental exchange — the union with previously-granted
            // scopes must be preserved in storage.
            'scope'         => 'https://graph.microsoft.com/User.Read',
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-inc', 'state-inc' );

    expect( $connection->id )->toBe( $existing->id );
    expect( $connection->grantedScopes() )->toContain( 'openid' );
    expect( $connection->grantedScopes() )->toContain( 'offline_access' );
    expect( $connection->grantedScopes() )->toContain( 'https://graph.microsoft.com/User.Read' );

    // Incremental flag is single-use; it must have been consumed.
    expect( session( 'microsoft_oauth.incremental' ) )->toBeNull();
} );

it( 'preserves the scope union on an incremental callback that hits the duplicate-key retry path', function (): void {
    // Simulate a concurrent incremental callback for the same user: our
    // firstOrNew sees no row for the model we hydrate, but between that
    // check and save() a racing callback inserts the row first. The retry
    // branch must re-apply tokens WITH the incremental flag so the union
    // (not replace) semantics survive.
    $raceFired = false;
    MicrosoftConnection::creating( function ( MicrosoftConnection $model ) use ( &$raceFired ): void {
        if ( $raceFired || 50 !== $model->user_id ) {
            return;
        }

        $raceFired = true;

        // The winning insert already recorded a broader scope set that
        // ours (a raw exchange returning only the newly-consented scope)
        // must union with, not overwrite.
        DB::table( 'microsoft_connections' )->insert( [
            'user_id'       => 50,
            'access_token'  => encrypt( 'race-loser-access' ),
            'refresh_token' => encrypt( 'race-loser-refresh' ),
            'token_type'    => 'Bearer',
            'scopes'        => json_encode( [ 'openid', 'profile', 'email', 'offline_access' ] ),
            'expires_at'    => Carbon::now()->addHour()->toDateTimeString(),
            'status'        => MicrosoftConnection::STATUS_CONNECTED,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ] );
    } );

    session( [
        'microsoft_oauth.state'       => 'state-race-inc',
        'microsoft_oauth.verifier'    => 'verifier-race-inc',
        'microsoft_oauth.user_id'     => 50,
        'microsoft_oauth.incremental' => true,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'race-winner-access',
            'refresh_token' => 'race-winner-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => 'https://graph.microsoft.com/User.Read',
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-race-inc', 'state-race-inc' );

    MicrosoftConnection::flushEventListeners();

    expect( $raceFired )->toBeTrue();
    expect( MicrosoftConnection::count() )->toBe( 1 );
    expect( $connection->access_token )->toBe( 'race-winner-access' );
    expect( $connection->grantedScopes() )->toContain( 'openid' );
    expect( $connection->grantedScopes() )->toContain( 'offline_access' );
    expect( $connection->grantedScopes() )->toContain( 'https://graph.microsoft.com/User.Read' );
} );

it( 'refuses to build endpoints when the configured tenant is invalid', function (): void {
    config( [ 'microsoft-oauth.tenant' => 'not-a-valid-authority' ] );

    makeManager()->authorizationUrl( 1 );
} )->throws( OAuthException::class, 'Invalid Microsoft OAuth tenant' );

it( 'persists the tid claim from the id_token on the connection', function (): void {
    session( [
        'microsoft_oauth.state'    => 'state-tid',
        'microsoft_oauth.verifier' => 'verifier-tid',
        'microsoft_oauth.user_id'  => 101,
    ] );

    $idToken = makeIdToken( [
        'oid'   => 'ms-user-tid',
        'email' => 'user@contoso.com',
        'tid'   => '11111111-2222-3333-4444-555555555555',
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-token-tid',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid profile email offline_access',
            'id_token'     => $idToken,
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-tid', 'state-tid' );

    expect( $connection->tid )->toBe( '11111111-2222-3333-4444-555555555555' );
} );

it( 'rejects a personal Microsoft account on an organizations-only tenant', function (): void {
    config( [ 'microsoft-oauth.tenant' => 'organizations' ] );

    session( [
        'microsoft_oauth.state'    => 'state-org',
        'microsoft_oauth.verifier' => 'verifier-org',
        'microsoft_oauth.user_id'  => 200,
    ] );

    $idToken = makeIdToken( [
        'oid' => 'ms-user-personal',
        'tid' => ArtisanPackUI\MicrosoftOAuth\OAuth\TenantAuthority::MSA_TENANT_ID,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-token-personal',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid profile email offline_access',
            'id_token'     => $idToken,
        ], 200 ),
    ] );

    try {
        makeManager()->handleCallback( 'code-org', 'state-org' );
        $this->fail( 'Expected OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getMessage() )->toContain( 'personal account' );
    }

    // The rejected exchange must not leave a connection row behind.
    expect( MicrosoftConnection::where( 'user_id', 200 )->exists() )->toBeFalse();
} );

it( 'rejects a work / school account on a consumers-only tenant', function (): void {
    config( [ 'microsoft-oauth.tenant' => 'consumers' ] );

    session( [
        'microsoft_oauth.state'    => 'state-consumer',
        'microsoft_oauth.verifier' => 'verifier-consumer',
        'microsoft_oauth.user_id'  => 201,
    ] );

    $idToken = makeIdToken( [
        'oid' => 'ms-user-work',
        'tid' => '11111111-2222-3333-4444-555555555555',
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/consumers/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'access-token-work',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid profile email offline_access',
            'id_token'     => $idToken,
        ], 200 ),
    ] );

    try {
        makeManager()->handleCallback( 'code-consumer', 'state-consumer' );
        $this->fail( 'Expected OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getMessage() )->toContain( 'work / school account' );
    }

    expect( MicrosoftConnection::where( 'user_id', 201 )->exists() )->toBeFalse();
} );

it( 'rejects a token whose tid does not match the configured single-tenant GUID', function (): void {
    $expected = '11111111-2222-3333-4444-555555555555';
    $actual   = '99999999-8888-7777-6666-555555555555';

    config( [ 'microsoft-oauth.tenant' => $expected ] );

    session( [
        'microsoft_oauth.state'    => 'state-single',
        'microsoft_oauth.verifier' => 'verifier-single',
        'microsoft_oauth.user_id'  => 202,
    ] );

    $idToken = makeIdToken( [
        'oid' => 'ms-user-wrong-tenant',
        'tid' => $actual,
    ] );

    Http::fake( [
        "https://login.microsoftonline.com/{$expected}/oauth2/v2.0/token" => Http::response( [
            'access_token' => 'access-token-wrong',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid profile email offline_access',
            'id_token'     => $idToken,
        ], 200 ),
    ] );

    try {
        makeManager()->handleCallback( 'code-single', 'state-single' );
        $this->fail( 'Expected OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getMessage() )->toContain( 'registered for tenant' );
    }

    expect( MicrosoftConnection::where( 'user_id', 202 )->exists() )->toBeFalse();
} );

it( 'accepts a token whose tid matches the configured single-tenant GUID', function (): void {
    $guid = '11111111-2222-3333-4444-555555555555';

    config( [ 'microsoft-oauth.tenant' => $guid ] );

    session( [
        'microsoft_oauth.state'    => 'state-single-ok',
        'microsoft_oauth.verifier' => 'verifier-single-ok',
        'microsoft_oauth.user_id'  => 203,
    ] );

    $idToken = makeIdToken( [
        'oid' => 'ms-user-right-tenant',
        'tid' => $guid,
    ] );

    Http::fake( [
        "https://login.microsoftonline.com/{$guid}/oauth2/v2.0/token" => Http::response( [
            'access_token' => 'access-token-right',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid profile email offline_access',
            'id_token'     => $idToken,
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-single-ok', 'state-single-ok' );

    expect( $connection->tid )->toBe( $guid );
} );

it( 'requires a tid on non-common authorities', function (): void {
    config( [ 'microsoft-oauth.tenant' => 'organizations' ] );

    session( [
        'microsoft_oauth.state'    => 'state-notid',
        'microsoft_oauth.verifier' => 'verifier-notid',
        'microsoft_oauth.user_id'  => 204,
    ] );

    // id_token deliberately omits tid.
    $idToken = makeIdToken( [ 'oid' => 'ms-user-no-tid' ] );

    Http::fake( [
        'https://login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response( [
            'access_token' => 'a',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'openid offline_access',
            'id_token'     => $idToken,
        ], 200 ),
    ] );

    try {
        makeManager()->handleCallback( 'code-notid', 'state-notid' );
        $this->fail( 'Expected OAuthException.' );
    } catch ( OAuthException $e ) {
        expect( $e->getMessage() )->toContain( 'missing the tid claim' );
    }
} );

it( 'replaces (does not union) scopes on a non-incremental callback', function (): void {
    MicrosoftConnection::create( [
        'user_id'       => 40,
        'access_token'  => 'old-access',
        'refresh_token' => 'old-refresh',
        'token_type'    => 'Bearer',
        'scopes'        => [ 'openid', 'profile', 'email', 'offline_access', 'legacy-scope' ],
        'expires_at'    => Carbon::now()->addHour(),
        'status'        => MicrosoftConnection::STATUS_CONNECTED,
    ] );

    session( [
        'microsoft_oauth.state'    => 'state-full',
        'microsoft_oauth.verifier' => 'verifier-full',
        'microsoft_oauth.user_id'  => 40,
    ] );

    Http::fake( [
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response( [
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => 'openid profile email offline_access',
        ], 200 ),
    ] );

    $connection = makeManager()->handleCallback( 'code-full', 'state-full' );

    // Full-connect flows overwrite scopes with whatever Microsoft returned,
    // so a scope the user is no longer granting is dropped.
    expect( $connection->grantedScopes() )->not->toContain( 'legacy-scope' );
} );
