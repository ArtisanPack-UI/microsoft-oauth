<?php

/**
 * Microsoft OAuth package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | Configuration Repository Driver
    |--------------------------------------------------------------------------
    |
    | Which storage driver backs the app credentials (client_id,
    | client_secret, tenant). `config` reads the values below from
    | config/env files and is read-only. `database` reads and writes them
    | to the `microsoft_oauth_configurations` table, with the client secret
    | encrypted at rest via Laravel's Encrypter. `cms` bridges to the
    | artisanpack-ui/cms-framework Settings module for admin-editable
    | credentials (only available when the CMS framework is installed).
    |
    */

    'driver'        => env( 'MICROSOFT_OAUTH_DRIVER', 'config' ),

    /*
    |--------------------------------------------------------------------------
    | OAuth Mode
    |--------------------------------------------------------------------------
    |
    | `direct` (the default) talks to Microsoft with this app's own client ID,
    | secret and tenant, as configured below. `broker` runs connect, callback
    | and refresh through an OAuth broker instead, so the site never holds a
    | Microsoft client secret — only the broker settings below. The tenant
    | authority is then the broker's concern.
    |
    */

    'mode'          => env( 'MICROSOFT_OAUTH_MODE', 'direct' ),

    /*
    |--------------------------------------------------------------------------
    | OAuth Broker
    |--------------------------------------------------------------------------
    |
    | Used when `mode` is `broker`. `url` must be HTTPS (plain HTTP is only
    | accepted for local development hosts). `return_url` defaults to the
    | package's callback route and must be on the URL the site registered
    | with the broker. Hosts can supply url / site_id / site_secret at
    | runtime via the `ap.microsoft.oauth.broker.credentials` filter instead.
    |
    */

    'broker'        => [
        'url'         => env( 'MICROSOFT_OAUTH_BROKER_URL' ),
        'site_id'     => env( 'MICROSOFT_OAUTH_BROKER_SITE_ID' ),
        'site_secret' => env( 'MICROSOFT_OAUTH_BROKER_SITE_SECRET' ),
        'return_url'  => env( 'MICROSOFT_OAUTH_BROKER_RETURN_URL' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application (Client) Credentials
    |--------------------------------------------------------------------------
    |
    | The `client_id` and `client_secret` from your Entra / Azure AD app
    | registration. Public clients (SPA / native) may omit `client_secret` and
    | rely on PKCE alone. Confidential clients (web apps) should always provide
    | the secret. When the `database` or `cms` driver is active, these values
    | are read from that store instead; `redirect_uri` falls back to the value
    | here when the store has none.
    |
    */

    'client_id'     => env( 'MICROSOFT_OAUTH_CLIENT_ID' ),

    'client_secret' => env( 'MICROSOFT_OAUTH_CLIENT_SECRET' ),

    'redirect_uri'  => env( 'MICROSOFT_OAUTH_REDIRECT_URI' ),

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    |
    | Which Microsoft tenant this app authorizes against. Accepted values:
    |
    | - `common`        : any Microsoft account (work, school, personal).
    | - `organizations` : work / school accounts only. Personal Microsoft
    |                     accounts are rejected on callback.
    | - `consumers`     : personal Microsoft accounts only. Work / school
    |                     accounts are rejected on callback.
    | - A tenant GUID   : single-tenant app; tokens whose `tid` does not
    |                     match this GUID are rejected on callback.
    | - A verified domain (e.g. `contoso.onmicrosoft.com`, `contoso.com`):
    |                     single-tenant app; Microsoft resolves the domain
    |                     to a specific tenant.
    |
    | The configured value is validated at OAuth-flow time; misconfigured
    | tenants throw an OAuthException rather than silently defaulting.
    |
    */

    'tenant'        => env( 'MICROSOFT_OAUTH_TENANT', 'common' ),

    /*
    |--------------------------------------------------------------------------
    | Prompt Behavior
    |--------------------------------------------------------------------------
    |
    | Passed through to Microsoft's `prompt` parameter. Common values:
    | `login`, `none`, `consent`, `select_account`. Defaults to
    | `select_account` so users can pick which Microsoft account to connect.
    |
    */

    'prompt'        => env( 'MICROSOFT_OAUTH_PROMPT', 'select_account' ),

    /*
    |--------------------------------------------------------------------------
    | Route Behavior
    |--------------------------------------------------------------------------
    |
    | Where to redirect the user after a successful connect or a flow error.
    | Values may be either a named route or an absolute/relative URL.
    |
    */

    'routes'        => [
        'redirect_after_connect' => env( 'MICROSOFT_OAUTH_REDIRECT_AFTER_CONNECT', '/' ),
        'redirect_after_error'   => env( 'MICROSOFT_OAUTH_REDIRECT_AFTER_ERROR', '/' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class name of the application's User model. Used by the
    | MicrosoftConnection Eloquent `belongsTo` relationship so the connection
    | knows how to hydrate its owning user.
    |
    */

    'user_model'    => env( 'MICROSOFT_OAUTH_USER_MODEL', 'App\\Models\\User' ),

];
