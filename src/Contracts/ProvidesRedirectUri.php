<?php

/**
 * Optional redirect-URI contract for credential drivers.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Contracts;

/**
 * Implemented by {@see ConfigurationRepository} drivers that store their own
 * redirect URI.
 *
 * Kept separate from {@see ConfigurationRepository} so custom drivers written
 * against 1.0 keep working; drivers that don't implement it use
 * `config('microsoft-oauth.redirect_uri')`.
 *
 * @since 1.1.0
 */
interface ProvidesRedirectUri
{
    /**
     * Get the redirect URI registered with the Entra app.
     *
     * Return null to fall back to `config('microsoft-oauth.redirect_uri')`.
     *
     * @since 1.1.0
     */
    public function getRedirectUri(): ?string;
}
