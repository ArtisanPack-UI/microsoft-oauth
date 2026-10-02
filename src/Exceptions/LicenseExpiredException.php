<?php

/**
 * Broker license-expired exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Exceptions;

/**
 * Thrown when the OAuth broker refuses a refresh because the site's
 * plugin license has lapsed beyond its grace period (HTTP 402).
 *
 * Unlike a revoked grant, the Microsoft connection itself is still valid:
 * renewing the license at {@see self::getRenewUrl()} restores refreshes
 * without the user having to reconnect.
 *
 * @since 1.1.0
 */
class LicenseExpiredException extends TokenRefreshException
{
}
