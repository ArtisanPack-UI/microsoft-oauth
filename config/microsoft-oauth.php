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
    | Application (Client) Credentials
    |--------------------------------------------------------------------------
    |
    | The `client_id` and `client_secret` from your Entra / Azure AD app
    | registration. Public clients (SPA / native) may omit `client_secret` and
    | rely on PKCE alone. Confidential clients (web apps) should always provide
    | the secret. When the `database` driver is active, these values are read
    | from the `microsoft_oauth_configurations` table instead.
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
    | Which Microsoft tenant this app authorizes against. Use `common` for
    | any account (work, school, personal), `organizations` for work/school
    | only, `consumers` for personal Microsoft accounts only, or a specific
    | tenant GUID / verified domain for a single-tenant app. Multi-tenant
    | configuration is elaborated in issue #9.
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
