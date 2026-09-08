<?php

/**
 * Default TokenProvider implementation.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Tokens;

use ArtisanPackUI\MicrosoftOAuth\Contracts\TokenProvider;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\MissingConnectionException;
use ArtisanPackUI\MicrosoftOAuth\Models\MicrosoftConnection;

/**
 * The default binding for {@see TokenProvider}.
 *
 * Resolves the {@see MicrosoftConnection} for the given user id and asks the
 * {@see TokenManager} for a currently-valid access token, refreshing behind
 * the scenes when the stored one has expired. Consumers should type-hint the
 * {@see TokenProvider} contract; this concrete class is only bound in the
 * container so tests and hosts can swap it out.
 *
 * @since 1.0.0
 */
class DefaultTokenProvider implements TokenProvider
{
    public function __construct(
        protected TokenManager $tokens,
    ) {
    }

    /**
     * @since 1.0.0
     */
    public function accessTokenFor( int|string $userId ): string
    {
        $connection = MicrosoftConnection::query()->where( 'user_id', $userId )->first();

        if ( ! $connection instanceof MicrosoftConnection ) {
            throw new MissingConnectionException( __(
                'No Microsoft connection on file for user :user.',
                [ 'user' => (string) $userId ],
            ) );
        }

        return $this->tokens->getValidAccessToken( $connection );
    }
}
