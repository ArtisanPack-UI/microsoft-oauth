<?php

/**
 * Microsoft identity platform OAuth2 authorization-code flow manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use ArtisanPackUI\MicrosoftOAuth\Broker\BrokerClient;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;
use ArtisanPackUI\MicrosoftOAuth\Scopes\ScopeRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Drives the Microsoft identity platform v2.0 authorization-code flow with PKCE.
 *
 * `authorizationUrl()` builds the URL to send the user to, stashing `state`
 * and the PKCE `code_verifier` in the session. `handleCallback()` validates
 * `state`, exchanges the returned `code` for tokens, and persists the
 * connection as a {@see MicrosoftConnection} with encrypted access +
 * refresh tokens (Laravel `Crypt`).
 *
 * The `offline_access` scope is always requested so Microsoft returns a
 * refresh token — a hard requirement for downstream integrations that need
 * long-lived access.
 *
 * The Microsoft calls themselves go through the stateless
 * {@see MicrosoftClient}, or through {@see BrokerClient} when
 * `microsoft-oauth.mode` is `broker`.
 *
 * @since 1.0.0
 */
class OAuthManager
{
    protected const SESSION_STATE       = 'microsoft_oauth.state';

    protected const SESSION_VERIFIER    = 'microsoft_oauth.verifier';

    protected const SESSION_USER_ID     = 'microsoft_oauth.user_id';

    protected const SESSION_INCREMENTAL = 'microsoft_oauth.incremental';

    public function __construct(
        protected ConfigurationRepository $credentials,
        protected ConfigRepository $config,
        protected Session $session,
        protected HttpFactory $http,
        protected ScopeRegistry $scopes,
    ) {
    }

    /**
     * Build the Microsoft authorization URL for a given user.
     *
     * @since 1.0.0
     *
     * @param  int|string  $userId  The user we're connecting a Microsoft account to.
     * @param  array<int, string>  $additionalScopes  Extra scopes to request alongside the baseline.
     */
    public function authorizationUrl( int|string $userId, array $additionalScopes = [] ): string
    {
        $this->session->forget( self::SESSION_INCREMENTAL );

        return $this->buildAuthorizationUrl(
            $userId,
            $this->mergeScopes( $additionalScopes ),
            (string) $this->config->get( 'microsoft-oauth.prompt', 'select_account' ),
        );
    }

    /**
     * Build an incremental-consent authorization URL for a user who already
     * has a {@see MicrosoftConnection} but is missing scopes required by
     * newly-registered dependent services.
     *
     * Returns an {@see IncrementalConsentResult} case instead of a URL when
     * there is nothing to send the user to — `NoConnection` when the user
     * has never connected (caller should route them through the full connect
     * flow) or `AlreadyAuthorized` when every registered scope is already
     * granted. When a URL is returned, a session flag is set so
     * {@see handleCallback()} preserves previously-granted scopes on top of
     * what Microsoft returns in the token response.
     *
     * @since 1.0.0
     */
    public function incrementalAuthorizationUrl( int|string $userId ): string|IncrementalConsentResult
    {
        $connection = MicrosoftConnection::where( 'user_id', $userId )->first();

        if ( null === $connection ) {
            return IncrementalConsentResult::NoConnection;
        }

        $granted = $connection->grantedScopes();
        $missing = $this->scopes->missing( $granted );

        if ( [] === $missing ) {
            return IncrementalConsentResult::AlreadyAuthorized;
        }

        // Request the full union so Microsoft has the complete picture and
        // returns a token valid for every scope the app needs. The consent
        // screen still only asks for the delta — Microsoft skips prompts for
        // scopes the user has already granted. `prompt=consent` forces the
        // consent screen so the user has an opportunity to approve the added
        // scopes even if their tenant would otherwise auto-consent.
        $url = $this->buildAuthorizationUrl(
            $userId,
            $this->mergeScopes( [] ),
            'consent',
        );

        $this->session->put( self::SESSION_INCREMENTAL, true );

        return $url;
    }

    /**
     * Handle the OAuth callback: verify state, exchange the code, and
     * persist the resulting tokens on a {@see MicrosoftConnection}.
     *
     * In direct mode the code goes to Microsoft with the PKCE verifier from
     * the session, and the id_token `tid` is checked against the configured
     * authority. In broker mode the broker's one-time code goes to the
     * broker, which owns the tenant authority. Either way the result is
     * stored on the user's connection.
     *
     * Access and refresh tokens are stored encrypted via Laravel's
     * `encrypted` cast on the model. The returned model is either newly
     * created or updated in place for the connecting user, so downstream
     * code can wire the callback directly into service-specific
     * bootstrapping without another DB round-trip.
     *
     * @since 1.0.0
     */
    public function handleCallback( string $code, string $returnedState ): MicrosoftConnection
    {
        $storedState = $this->session->pull( self::SESSION_STATE );
        $verifier    = $this->session->pull( self::SESSION_VERIFIER );
        $userId      = $this->session->pull( self::SESSION_USER_ID );
        $incremental = (bool) $this->session->pull( self::SESSION_INCREMENTAL, false );
        $usesBroker  = $this->usesBroker();

        MicrosoftClient::verifyState( empty( $storedState ) ? null : (string) $storedState, $returnedState );

        if ( ! $usesBroker && empty( $verifier ) ) {
            throw new OAuthException( __( 'PKCE code verifier missing from session.' ) );
        }

        if ( empty( $userId ) ) {
            throw new OAuthException( __( 'OAuth session missing user context.' ) );
        }

        $tokens = $usesBroker
            ? $this->brokerClient()->exchangeCode( $code )
            : $this->client()->exchangeCode( $code, $this->mergeScopes( [] ), (string) $verifier );

        return $this->persistConnection( $userId, $tokens, $incremental );
    }

    /**
     * Stateless Microsoft client, from explicit credentials or the
     * configured driver.
     *
     * @since 1.1.0
     */
    public function client( ?MicrosoftCredentials $credentials = null ): MicrosoftClient
    {
        return MicrosoftClient::make( $credentials ?? $this->credentials, $this->http, $this->config );
    }

    /**
     * Broker client built from the configured broker credentials.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the broker is not configured.
     */
    public function brokerClient(): BrokerClient
    {
        return BrokerClient::fromConfig( $this->config, $this->http );
    }

    /**
     * Whether the package is in broker client mode.
     *
     * @since 1.1.0
     */
    public function usesBroker(): bool
    {
        return BrokerClient::isEnabled( $this->config );
    }

    /**
     * Whether a license `renew_url` from the broker's return is safe to show
     * the user.
     *
     * Only true in broker mode, for URLs on the broker's own host. The
     * `renew_url` arrives on the return URL's query string, so anyone can
     * forge it; check it here before linking to it.
     *
     * @since 1.1.0
     */
    public function isTrustedRenewUrl( ?string $url ): bool
    {
        if ( ! $this->usesBroker() ) {
            return false;
        }

        try {
            return $this->brokerClient()->isTrustedRenewUrl( $url );
        } catch ( OAuthException ) {
            return false;
        }
    }

    /**
     * Assemble a Microsoft authorization URL and stash the accompanying
     * PKCE / state values in the session.
     *
     * In broker mode this is the signed broker `/authorize` link instead,
     * carrying the scopes through `scopes=`.
     *
     * @since 1.0.0
     *
     * @param  list<string>  $scopes  Deduplicated list of scopes to request.
     */
    protected function buildAuthorizationUrl( int|string $userId, array $scopes, string $prompt ): string
    {
        if ( $this->usesBroker() ) {
            return $this->buildBrokerAuthorizationUrl( $userId, $scopes );
        }

        $client   = $this->client();
        $state    = Str::random( 40 );
        $verifier = MicrosoftClient::generateCodeVerifier();

        // Build first so a misconfiguration throws before the session is touched.
        $url = $client->authorizationUrl( $state, $scopes, [ 'prompt' => $prompt ], $verifier );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->put( self::SESSION_VERIFIER, $verifier );
        $this->session->put( self::SESSION_USER_ID, $userId );

        return $url;
    }

    /**
     * Build the signed broker `/authorize` URL. The broker runs PKCE (and
     * the prompt) with Microsoft itself, so only state and the user are
     * kept in the session.
     *
     * @since 1.1.0
     *
     * @param  list<string>  $scopes  Scopes to request.
     *
     * @throws OAuthException When the broker is not configured.
     */
    protected function buildBrokerAuthorizationUrl( int|string $userId, array $scopes ): string
    {
        $state = Str::random( 40 );
        $url   = $this->brokerClient()->authorizationUrl( $state, $this->brokerReturnUrl(), $scopes );

        $this->session->put( self::SESSION_STATE, $state );
        $this->session->forget( self::SESSION_VERIFIER );
        $this->session->put( self::SESSION_USER_ID, $userId );

        return $url;
    }

    /**
     * The URL the broker sends the browser back to: the configured
     * `microsoft-oauth.broker.return_url`, else the package's callback route.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When neither is available.
     */
    protected function brokerReturnUrl(): string
    {
        $configured = (string) $this->config->get( 'microsoft-oauth.broker.return_url', '' );

        if ( '' !== $configured ) {
            return $configured;
        }

        if ( ! Route::has( 'microsoft.auth.callback' ) ) {
            throw new OAuthException( __( 'Set microsoft-oauth.broker.return_url when the package routes are not registered.' ) );
        }

        return route( 'microsoft.auth.callback' );
    }

    /**
     * Upsert the {@see MicrosoftConnection} for the connecting user.
     *
     * A duplicate-key failure from `save()` means a concurrent callback
     * for the same user just won the race and inserted the row first —
     * fetch that row and re-apply our tokens to it instead of losing
     * the exchange we just performed.
     *
     * @since 1.0.0
     *
     * @param  int|string     $userId       The connecting user.
     * @param  TokenResponse  $tokens       The exchanged token set.
     * @param  bool           $incremental  Whether this was an incremental-consent re-auth.
     */
    protected function persistConnection( int|string $userId, TokenResponse $tokens, bool $incremental = false ): MicrosoftConnection
    {
        $connection = MicrosoftConnection::firstOrNew( [ 'user_id' => $userId ] );

        $this->applyTokens( $connection, $tokens, $incremental );

        try {
            $connection->save();
        } catch ( QueryException $e ) {
            if ( ! $this->isDuplicateKeyException( $e ) ) {
                throw $e;
            }

            $connection = MicrosoftConnection::where( 'user_id', $userId )->firstOrFail();
            $this->applyTokens( $connection, $tokens, $incremental );
            $connection->save();
        }

        return $connection;
    }

    /**
     * Copy the exchanged token set onto the connection.
     *
     * @since 1.0.0
     */
    protected function applyTokens( MicrosoftConnection $connection, TokenResponse $tokens, bool $incremental = false ): void
    {
        $scopes = $this->resolveGrantedScopes( $connection, $tokens );

        // On an incremental-consent re-auth the token response reflects only
        // the scopes the user granted in *this* exchange, but the user's
        // consent on the Microsoft side is cumulative. Union with the
        // previously-recorded scopes so ScopeRegistry::missing() keeps
        // reporting a correct picture after re-auth.
        if ( $incremental ) {
            $scopes = $this->unionScopes( $connection->grantedScopes(), $scopes );
        }

        $connection->microsoft_user_id = $tokens->accountId ?? $connection->microsoft_user_id;
        $connection->email             = $tokens->accountEmail ?? $connection->email;
        $connection->tid               = $tokens->tenantId ?? $connection->tid;
        $connection->access_token      = $tokens->accessToken;
        $connection->token_type        = $tokens->tokenType;
        $connection->scopes            = $scopes;
        $connection->expires_at        = $tokens->expiresAt;
        $connection->status            = MicrosoftConnection::STATUS_CONNECTED;
        $connection->disconnect_reason = null;

        // Microsoft returns a refresh_token on every successful exchange
        // when `offline_access` is granted (unlike Google, which only issues
        // it on first consent). Still guard against a missing value so an
        // unexpected response can't wipe the existing token on file.
        if ( null !== $tokens->refreshToken ) {
            $connection->refresh_token = $tokens->refreshToken;
        }
    }

    /**
     * Scopes to store for an exchanged token set.
     *
     * Reported scopes always win. When none are reported, direct mode keeps
     * its historical fallback to the requested scopes (Microsoft omits
     * `scope` only when it granted what was asked). The broker always
     * reports scopes, so an empty list there means "unknown": keep what the
     * connection already holds rather than claiming every registered scope,
     * which would hide a needed reauthorization.
     *
     * @since 1.1.0
     *
     * @return list<string>
     */
    protected function resolveGrantedScopes( MicrosoftConnection $connection, TokenResponse $tokens ): array
    {
        if ( [] !== $tokens->scopes ) {
            return $tokens->scopes;
        }

        if ( $this->usesBroker() ) {
            return $connection->grantedScopes();
        }

        return $this->mergeScopes( [] );
    }

    /**
     * Detect the driver-specific duplicate-key error raised when a concurrent
     * insert for the same `user_id` hits our unique index first.
     *
     * MySQL uses SQLSTATE 23000 (integrity constraint violation) with vendor
     * code 1062; Postgres uses 23505 (unique_violation); SQLite reports
     * SQLSTATE 23000 with vendor code 19 and "UNIQUE constraint failed" in
     * the message.
     *
     * @since 1.0.0
     */
    protected function isDuplicateKeyException( QueryException $e ): bool
    {
        if ( '23505' === $e->getCode() ) {
            return true;
        }

        if ( '23000' !== $e->getCode() ) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains( $message, '1062' )
            || str_contains( $message, 'UNIQUE constraint failed' )
            || str_contains( $message, 'Duplicate entry' );
    }

    /**
     * Merge caller-supplied scopes with those contributed by the
     * {@see ScopeRegistry} (baseline identity scopes + anything registered
     * via the `ap.microsoft.oauth.scopes` filter or imperatively),
     * deduplicated.
     *
     * @param  array<int, string>  $additional
     *
     * @return list<string>
     */
    protected function mergeScopes( array $additional ): array
    {
        return $this->unionScopes( $this->scopes->all(), $additional );
    }

    /**
     * Deduplicated union of two or more scope lists, preserving order (each
     * list's scopes appear before the next list's, whitespace-only entries
     * are dropped).
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  ...$lists
     *
     * @return list<string>
     */
    protected function unionScopes( array ...$lists ): array
    {
        $merged = [];

        foreach ( $lists as $list ) {
            foreach ( $list as $scope ) {
                $merged[] = (string) $scope;
            }
        }

        $merged = array_map( 'trim', $merged );
        $merged = array_filter( $merged, static fn ( string $s ): bool => '' !== $s );

        return array_values( array_unique( $merged ) );
    }
}
