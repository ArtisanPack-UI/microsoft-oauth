<?php

/**
 * OAuth error-code carrier.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Exceptions\Concerns;

use Throwable;

/**
 * Lets an exception carry the machine-readable OAuth `error` code
 * (`invalid_grant`, `license_expired`, …) and an optional `renew_url`
 * alongside its human-readable message.
 *
 * @since 1.1.0
 */
trait CarriesOAuthError
{
    /**
     * @since 1.1.0
     *
     * @param  string          $message   Human-readable, translated message.
     * @param  string|null     $error     OAuth error code reported by Microsoft or the broker.
     * @param  string|null     $renewUrl  License renewal URL, set only for `license_expired`.
     * @param  Throwable|null  $previous  Previous exception.
     */
    public function __construct(
        string $message = '',
        protected ?string $error = null,
        protected ?string $renewUrl = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct( $message, 0, $previous );
    }

    /**
     * The OAuth error code, when one was reported.
     *
     * @since 1.1.0
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * The broker's license renewal URL, when one was reported.
     *
     * @since 1.1.0
     */
    public function getRenewUrl(): ?string
    {
        return $this->renewUrl;
    }
}
