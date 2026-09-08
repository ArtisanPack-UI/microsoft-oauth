<?php

/**
 * Incremental consent result marker.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

/**
 * Non-URL outcomes of {@see OAuthManager::incrementalAuthorizationUrl()}.
 *
 * The manager returns an authorization URL when there are missing scopes
 * to consent to, and one of these cases otherwise so the caller can
 * distinguish "no connection to build on" (send the user through the full
 * connect flow) from "the connection is already complete" (nothing to do).
 *
 * @since 1.0.0
 */
enum IncrementalConsentResult
{
    case NoConnection;

    case AlreadyAuthorized;
}
