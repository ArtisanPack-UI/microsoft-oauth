<?php

/**
 * CMS Framework Settings driver for app credentials.
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores Microsoft OAuth app credentials in the CMS framework's Settings module.
 *
 * Only registered when `artisanpack-ui/cms-framework` is installed. Delegates
 * reads and writes to `apGetSetting()` / `apUpdateSetting()` (with a matching
 * `apRegisterSetting()` at boot time) so credentials live alongside every other
 * site-level setting the CMS manages. The client secret is encrypted using the
 * framework Encrypter before being written to the Settings table.
 *
 * @since 1.0.0
 */
class CmsSettingsDriver implements ConfigurationRepository
{
    public const KEY_CLIENT_ID     = 'artisanpack_microsoft_oauth_client_id';

    public const KEY_CLIENT_SECRET = 'artisanpack_microsoft_oauth_client_secret';

    public const KEY_TENANT        = 'artisanpack_microsoft_oauth_tenant';

    /**
     * @var array<string, string|null>|null
     */
    protected ?array $cache = null;

    /**
     * Whether the last load() saw a stored client_secret that failed to
     * decrypt. Tracked separately from a legitimately-null secret so
     * confidential-client callers can distinguish "public client, no secret
     * needed" from "secret is corrupt / APP_KEY rotated" and refuse to
     * proceed into an OAuth flow with a phantom empty secret.
     *
     * @since 1.0.0
     */
    protected bool $secretDecryptFailed = false;

    public function __construct( protected Encrypter $encrypter )
    {
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
        // Pass plaintext through. Encryption for the client_secret key is
        // owned by the sanitize callback that MicrosoftOAuthServiceProvider
        // registers with the CMS framework, so both this write path and any
        // operator saving through the Settings UI persist the same ciphertext
        // shape.
        apUpdateSetting( self::KEY_CLIENT_ID, $credentials[ 'client_id' ] ?? null );
        apUpdateSetting( self::KEY_CLIENT_SECRET, $credentials[ 'client_secret' ] ?? null );
        apUpdateSetting( self::KEY_TENANT, $credentials[ 'tenant' ] ?? null );

        $this->cache               = null;
        $this->secretDecryptFailed = false;
    }

    public function isConfigured(): bool
    {
        // Force the cache to populate so `$secretDecryptFailed` reflects the
        // currently-stored row before we consult it. Reading via load()
        // directly (instead of the getters) avoids relying on getter order.
        $this->load();

        if ( $this->secretDecryptFailed ) {
            return false;
        }

        return ! empty( $this->getClientId() )
            && ! empty( $this->getTenant() );
    }

    /**
     * Clear the per-request cache; primarily for tests.
     *
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->cache               = null;
        $this->secretDecryptFailed = false;
    }

    /**
     * @return array<string, string|null>
     */
    protected function load(): array
    {
        if ( null !== $this->cache ) {
            return $this->cache;
        }

        $clientId     = apGetSetting( self::KEY_CLIENT_ID );
        $secretCipher = apGetSetting( self::KEY_CLIENT_SECRET );
        $tenant       = apGetSetting( self::KEY_TENANT );

        $secret                    = null;
        $this->secretDecryptFailed = false;
        if ( ! empty( $secretCipher ) && is_string( $secretCipher ) ) {
            try {
                $secret = $this->encrypter->decryptString( $secretCipher );
            } catch ( Throwable $e ) {
                Log::warning(
                    'artisanpack-ui/microsoft-oauth: failed to decrypt CMS-stored client_secret; treating as unconfigured. Was APP_KEY rotated without re-encrypting the setting?',
                    [ 'exception' => $e::class, 'message' => $e->getMessage() ],
                );
                $secret                    = null;
                $this->secretDecryptFailed = true;
            }
        }

        return $this->cache = [
            'client_id'     => null === $clientId ? null : (string) $clientId,
            'client_secret' => $secret,
            'tenant'        => null === $tenant ? null : (string) $tenant,
        ];
    }
}
