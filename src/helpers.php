<?php

/**
 * MicrosoftOAuth package helper functions.
 *
 * Global helper functions for the Microsoft OAuth package. Add package-wide
 * helpers here (OAuth flow shortcuts, scope constants, etc.).
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

use ArtisanPackUI\MicrosoftOAuth\MicrosoftOAuth;

if ( ! function_exists( 'microsoft_oauth' ) ) {
    /**
     * Get the MicrosoftOAuth instance.
     *
     * @since 1.0.0
     *
     * @return MicrosoftOAuth
     */
    function microsoft_oauth(): MicrosoftOAuth
    {
        return app( 'microsoft-oauth' );
    }
}

// Add your custom helper functions below
