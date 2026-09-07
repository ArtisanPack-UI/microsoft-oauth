<?php

/**
 * Thrown when a Microsoft OAuth token cannot be refreshed.
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
 * Thrown when a Microsoft OAuth token cannot be refreshed.
 *
 * @since 1.0.0
 */
class TokenRefreshException extends RuntimeException
{
}
