<?php

/**
 * OAuth token payload value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Support;

use Illuminate\Support\Carbon;

/**
 * Immutable value object returned from a successful code-for-token exchange.
 *
 * Persistence (encrypted storage, refresh scheduling, connection modelling) is
 * intentionally out of scope for the authorization-code flow. Downstream
 * issues (#3) wire this payload into a Connection model.
 *
 * @since 1.0.0
 */
final readonly class TokenPayload
{
    /**
     * @param  array<int, string>  $scopes  Scopes returned by the token endpoint.
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public string $tokenType,
        public array $scopes,
        public ?Carbon $expiresAt,
        public ?string $idToken,
        public ?string $microsoftUserId,
        public ?string $email,
        public int|string $userId,
    ) {
    }
}
