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

    /**
     * Deterministic key for the one-and-only credential row. A unique index
     * on the column keeps concurrent initial saves from racing in a duplicate.
     *
     * @since 1.0.0
     */
    protected const SINGLETON_KEY = 'default';

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

    /**
     * @since 1.1.0
     */
    public function getRedirectUri(): ?string
    {
        return $this->load()[ 'redirect_uri' ] ?? null;
    }

    public function save( array $credentials ): void
    {
        $now = now();

        $row = [
            'singleton_key' => self::SINGLETON_KEY,
            'client_id'     => $credentials[ 'client_id' ] ?? null,
            'client_secret' => isset( $credentials[ 'client_secret' ] ) && '' !== $credentials[ 'client_secret' ]
                ? $this->encrypter->encryptString( (string) $credentials[ 'client_secret' ] )
                : null,
            'tenant'        => $credentials[ 'tenant' ] ?? null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $update = [ 'client_id', 'client_secret', 'tenant', 'updated_at' ];

        // Only touch `redirect_uri` when the caller passes it, so 1.0 callers
        // keep working (and keep the stored value) without knowing the key.
        if ( array_key_exists( 'redirect_uri', $credentials ) ) {
            $row[ 'redirect_uri' ] = $this->stringOrNull( $credentials[ 'redirect_uri' ] );
            $update[]              = 'redirect_uri';
        }

        // Atomic upsert against the unique `singleton_key`. Compiles to a
        // single INSERT … ON CONFLICT / ON DUPLICATE KEY UPDATE, so two
        // concurrent initial saves cannot race in a duplicate row, and
        // `created_at` is preserved across updates.
        $this->connection->table( $this->table )->upsert( [ $row ], [ 'singleton_key' ], $update );

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

        $row = $this->connection->table( $this->table )
            ->where( 'singleton_key', self::SINGLETON_KEY )
            ->first();

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
            'redirect_uri'  => $this->stringOrNull( $row->redirect_uri ?? null ),
        ];
    }

    /**
     * Normalize a stored or submitted value to a trimmed string, or null.
     *
     * @since 1.1.0
     */
    protected function stringOrNull( mixed $value ): ?string
    {
        if ( null === $value ) {
            return null;
        }

        $string = trim( (string) $value );

        return '' === $string ? null : $string;
    }
}
