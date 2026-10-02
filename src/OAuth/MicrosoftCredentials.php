<?php

/**
 * Microsoft OAuth app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use ArtisanPackUI\MicrosoftOAuth\Contracts\ProvidesRedirectUri;

/**
 * Immutable set of Microsoft identity platform app credentials.
 *
 * Lets callers build a {@see MicrosoftClient} from credentials supplied at
 * runtime (for example an OAuth broker reading them from its own admin
 * settings) instead of only the bound {@see ConfigurationRepository}.
 *
 * @since 1.1.0
 */
final class MicrosoftCredentials
{
    /**
     * @since 1.1.0
     *
     * @param  string       $clientId      Application (client) ID.
     * @param  string|null  $clientSecret  Client secret. Public clients (SPA / native) leave it null and rely on PKCE.
     * @param  string|null  $tenant        `common`, `organizations`, `consumers`, a tenant GUID or a verified domain. Null means `common`.
     * @param  string|null  $redirectUri   Redirect URI registered with the app. Only the consent URL and code exchange need it.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $clientSecret = null,
        public readonly ?string $tenant = null,
        public readonly ?string $redirectUri = null,
    ) {
    }

    /**
     * Build credentials from a configuration repository driver.
     *
     * @since 1.1.0
     *
     * @param  ConfigurationRepository  $repository           The credential driver to read.
     * @param  string|null              $fallbackRedirectUri  Redirect URI to use when the driver has none (or does not implement {@see ProvidesRedirectUri}).
     */
    public static function fromRepository( ConfigurationRepository $repository, ?string $fallbackRedirectUri = null ): self
    {
        return new self(
            (string) ( $repository->getClientId() ?? '' ),
            self::nullIfEmpty( $repository->getClientSecret() ),
            self::nullIfEmpty( $repository->getTenant() ),
            self::nullIfEmpty( $repository instanceof ProvidesRedirectUri ? $repository->getRedirectUri() : null )
                ?? self::nullIfEmpty( $fallbackRedirectUri ),
        );
    }

    /**
     * Normalize an empty string to null.
     *
     * @since 1.1.0
     */
    private static function nullIfEmpty( ?string $value ): ?string
    {
        return null === $value || '' === trim( $value ) ? null : $value;
    }
}
