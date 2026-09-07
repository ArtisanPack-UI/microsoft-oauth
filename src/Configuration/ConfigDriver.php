<?php

/**
 * Config-file driver for app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Configuration;

use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Reads Microsoft OAuth app credentials from the Laravel config repository.
 *
 * This driver is read-only; the values are managed via config/env files.
 *
 * @since 1.0.0
 */
class ConfigDriver implements ConfigurationRepository
{
    public function __construct( protected ConfigRepository $config )
    {
    }

    public function getClientId(): ?string
    {
        return $this->stringOrNull( $this->config->get( 'microsoft-oauth.client_id' ) );
    }

    public function getClientSecret(): ?string
    {
        return $this->stringOrNull( $this->config->get( 'microsoft-oauth.client_secret' ) );
    }

    public function getTenant(): ?string
    {
        return $this->stringOrNull( $this->config->get( 'microsoft-oauth.tenant' ) );
    }

    public function save( array $credentials ): void
    {
        throw new RuntimeException(
            'The config driver is read-only. Switch to the database driver to persist credentials.',
        );
    }

    public function isConfigured(): bool
    {
        return ! empty( $this->getClientId() )
            && ! empty( $this->getTenant() );
    }

    protected function stringOrNull( mixed $value ): ?string
    {
        if ( null === $value ) {
            return null;
        }

        $string = trim( (string) $value );

        return '' === $string ? null : $string;
    }
}
