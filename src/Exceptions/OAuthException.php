<?php

/**
 * OAuth exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Exceptions;

use RuntimeException;

/**
 * Thrown when the Microsoft OAuth authorization flow fails.
 *
 * @since 1.0.0
 */
class OAuthException extends RuntimeException
{
}
