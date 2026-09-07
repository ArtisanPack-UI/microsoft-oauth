<?php

/**
 * Database driver for app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Configuration;

use ArtisanPackUI\MicrosoftOAuth\Contracts\ConfigurationRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores Microsoft OAuth app credentials in a database table.
 *
 * The client_secret column is stored encrypted using the framework Encrypter.
 * Values are cached per-request after first read.
 *
 * @since 1.0.0
 */
class DatabaseDriver implements ConfigurationRepository
{
    protected string $table = 'microsoft_oauth_configurations';

    /**
     * @var array<string, string|null>|null
     */
    protected ?array $cache = null;

    public function __construct(
        protected ConnectionInterface $connection,
        protected Encrypter $encrypter,
    ) {
    }

    public function getClientId(): ?string
    {
        return $this->load()[ 'client_id' ] ?? null;
    }

    public function getClientSecret(): ?string
    {
        return $this->load()[ 'client_secret' ] ?? null;
    }

    public function getTenant(): ?string
    {
        return $this->load()[ 'tenant' ] ?? null;
    }

    public function save( array $credentials ): void
    {
        $row = [
            'client_id'     => $credentials[ 'client_id' ] ?? null,
            'client_secret' => isset( $credentials[ 'client_secret' ] ) && '' !== $credentials[ 'client_secret' ]
                ? $this->encrypter->encryptString( (string) $credentials[ 'client_secret' ] )
                : null,
            'tenant'        => $credentials[ 'tenant' ] ?? null,
            'updated_at'    => now(),
        ];

        $existing = $this->connection->table( $this->table )->first();

        if ( $existing ) {
            $this->connection->table( $this->table )
                ->where( 'id', $existing->id )
                ->update( $row );
        } else {
            $row[ 'created_at' ] = now();
            $this->connection->table( $this->table )->insert( $row );
        }

        $this->cache = null;
    }

    public function isConfigured(): bool
    {
        return ! empty( $this->getClientId() )
            && ! empty( $this->getTenant() );
    }

    /**
     * @return array<string, string|null>
     */
    protected function load(): array
    {
        if ( null !== $this->cache ) {
            return $this->cache;
        }

        $row = $this->connection->table( $this->table )->first();

        if ( ! $row ) {
            return $this->cache = [];
        }

        $secret = null;
        if ( ! empty( $row->client_secret ) ) {
            try {
                $secret = $this->encrypter->decryptString( $row->client_secret );
            } catch ( Throwable $e ) {
                Log::warning(
                    'artisanpack-ui/microsoft-oauth: failed to decrypt stored client_secret; treating as unconfigured. Was APP_KEY rotated without re-encrypting the row?',
                    [ 'exception' => $e::class, 'message' => $e->getMessage() ],
                );
                $secret = null;
            }
        }

        return $this->cache = [
            'client_id'     => $row->client_id ?? null,
            'client_secret' => $secret,
            'tenant'        => $row->tenant ?? null,
        ];
    }
}
