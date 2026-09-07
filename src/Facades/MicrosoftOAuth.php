<?php

/**
 * MicrosoftOAuth Facade.
 *
 * Provides static access to the MicrosoftOAuth class.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * MicrosoftOAuth Facade.
 *
 * @see \ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuth
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */
class MicrosoftOAuth extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'microsoft-oauth';
    }
}
