<?php

/**
 * Main MicrosoftOAuth class.
 *
 * This is the main class for the Microsoft OAuth package, accessed via the
 * `microsoft_oauth()` helper function or the MicrosoftOAuth facade.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth;

use ArtisanPackUI\MicrosoftOAuth\Broker\BrokerClient;
use ArtisanPackUI\MicrosoftOAuth\Broker\BrokerCredentials;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftClient;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftCredentials;
use ArtisanPackUI\MicrosoftOAuth\OAuth\MicrosoftOAuthManager;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Main MicrosoftOAuth class.
 *
 * Shared Microsoft identity platform (Entra / Azure AD) OAuth2 broker for
 * ArtisanPack UI Microsoft service integrations.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */
class MicrosoftOAuth
{
    /**
     * Return a {@see PendingRequest} pre-configured with a valid bearer token
     * for the given user's Microsoft connection.
     *
     * Facade sugar over {@see MicrosoftOAuthManager::request()} — see that
     * method for the full contract. Prefer injecting {@see MicrosoftOAuthManager}
     * (or the {@see Contracts\TokenProvider}
     * contract, when only a token is needed) in application code; this shortcut
     * exists so `MicrosoftOAuth::request( $userId )->get( … )` works via the
     * facade at call sites where DI would be more ceremony than the call
     * warrants.
     *
     * @since 1.0.0
     */
    public function request( int|string $userId ): PendingRequest
    {
        // Resolve the manager on each call rather than caching it on this
        // singleton — the manager is a scoped binding so the container can
        // rebuild it between Octane requests / queue jobs when the
        // underlying credential row changes. A cached reference here would
        // pin a stale manager past the scoped rebuild.
        return app( MicrosoftOAuthManager::class )->request( $userId );
    }

    /**
     * A stateless Microsoft identity platform client.
     *
     * With no arguments it uses the configured credential driver. Pass
     * explicit credentials to relay for another app, as an OAuth broker
     * does; the client never touches the session or the database.
     *
     * @since 1.1.0
     */
    public function client( ?MicrosoftCredentials $credentials = null ): MicrosoftClient
    {
        return app( OAuthManager::class )->client( $credentials );
    }

    /**
     * A client for the OAuth broker, from explicit or configured credentials.
     *
     * @since 1.1.0
     *
     * @throws Exceptions\OAuthException When no credentials are passed and none are configured.
     */
    public function broker( ?BrokerCredentials $credentials = null ): BrokerClient
    {
        if ( null !== $credentials ) {
            return new BrokerClient( $credentials, app( HttpFactory::class ) );
        }

        return app( OAuthManager::class )->brokerClient();
    }

    /**
     * Whether the package is in broker client mode (`microsoft-oauth.mode` = `broker`).
     *
     * @since 1.1.0
     */
    public function usesBroker(): bool
    {
        return app( OAuthManager::class )->usesBroker();
    }
}
