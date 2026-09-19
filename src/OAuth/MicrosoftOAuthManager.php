<?php

/**
 * Consumer-facing Microsoft OAuth manager.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Consumer-facing entry point for downstream ArtisanPack UI Microsoft
 * integrations (starting with `artisanpack-ui/bing-places`).
 *
 * Wraps token acquisition and the Laravel HTTP client so consumer packages
 * never touch OAuth internals — the {@see request()} method hands back a
 * {@see PendingRequest} that already carries a valid `Authorization: Bearer …`
 * header for the given user's Microsoft connection, so the caller only has to
 * chain the actual API call:
 *
 * ```
 * $response = $manager->request( $userId )
 *     ->acceptJson()
 *     ->get( 'https://graph.microsoft.com/v1.0/me' );
 * ```
 *
 * Distinct from {@see OAuthManager}, which drives the authorization-code
 * flow (login / callback / consent) — this manager is only for calling
 * Microsoft APIs after a connection is already on file.
 *
 * @since 1.0.0
 */
class MicrosoftOAuthManager
{
    public function __construct(
        protected TokenProvider $tokens,
        protected HttpFactory $http,
    ) {
    }

    /**
     * Return a {@see PendingRequest} pre-configured with a valid bearer token
     * for the given user's Microsoft connection.
     *
     * The returned client is otherwise unconfigured — callers add their own
     * base URL, headers, timeout, retry policy, etc. by chaining onto it,
     * then call the terminal HTTP method (`get()`, `post()`, `patch()`, …)
     * to fire the request.
     *
     * @since 1.0.0
     *
     * @param  int|string  $userId  The application user id whose Microsoft
     *                              connection the token is drawn from.
     *
     * @throws \ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException
     *         When the user has no Microsoft connection on file.
     * @throws \ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException
     *         When a connection exists but its token cannot be refreshed.
     */
    public function request( int|string $userId ): PendingRequest
    {
        return $this->http->withToken( $this->tokens->accessTokenFor( $userId ) );
    }
}
