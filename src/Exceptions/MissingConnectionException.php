<?php

/**
 * Missing Microsoft connection exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Exceptions;

/**
 * Thrown when a caller asks for a token or authenticated request for a user
 * that has never connected a Microsoft account.
 *
 * Kept distinct from {@see OAuthException} and {@see TokenRefreshException}
 * so downstream packages can catch it specifically and route the user
 * through the initial connect flow instead of surfacing a generic OAuth
 * error message.
 *
 * @since 1.0.0
 */
class MissingConnectionException extends OAuthException
{
}
