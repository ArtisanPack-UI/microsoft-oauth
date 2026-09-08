<?php

/**
 * TokenProvider contract.
 *
 * Contract downstream packages depend on to obtain a valid Microsoft OAuth
 * access token for a given user, without knowing anything about the OAuth
 * flow, token refresh mechanics, or the underlying connection model.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Contracts;

use ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\TokenRefreshException;

/**
 * TokenProvider contract.
 *
 * The default binding resolves the current {@see \ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection}
 * for the given user id and returns a currently-valid access token, refreshing
 * transparently when the stored one is expired. Downstream packages should
 * type-hint this contract rather than the concrete implementation so tests can
 * bind a stub.
 *
 * @since 1.0.0
 */
interface TokenProvider
{
    /**
     * Return a valid Microsoft OAuth access token for the given user.
     *
     * Implementations MUST refresh the token if the stored one is expired,
     * and MUST return a non-empty bearer token suitable for use verbatim in
     * an `Authorization: Bearer …` header.
     *
     * @since 1.0.0
     *
     * @param  int|string  $userId  The application user id whose Microsoft
     *                              connection the token is drawn from.
     *
     * @throws MissingConnectionException When the user has no Microsoft
     *                                    connection on file at all — the
     *                                    caller should route them through
     *                                    the initial connect flow.
     * @throws TokenRefreshException      When a connection exists but its
     *                                    token cannot be refreshed into a
     *                                    usable value.
     */
    public function accessTokenFor( int|string $userId ): string;
}
