<?php

/**
 * Microsoft identity platform tenant mode.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\OAuth;

/**
 * Categorizes a configured `tenant` value into one of the four Microsoft
 * identity platform authority modes.
 *
 * `Common`, `Organizations`, and `Consumers` are the three multi-tenant
 * authorities Microsoft exposes; `Tenant` is the single-tenant mode used
 * when the configured value is a specific tenant GUID or a verified
 * domain (e.g. `contoso.onmicrosoft.com`).
 *
 * @since 1.0.0
 */
enum TenantMode: string
{
    case Common        = 'common';

    case Organizations = 'organizations';

    case Consumers     = 'consumers';

    case Tenant        = 'tenant';
}
