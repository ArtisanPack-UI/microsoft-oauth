<?php

/**
 * Microsoft OAuth controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Http\Controllers;

use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use ArtisanPackUI\MicrosoftOAuth\OAuth\OAuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

/**
 * Handles the connect + callback endpoints for the Microsoft OAuth flow.
 *
 * @since 1.0.0
 */
class MicrosoftAuthController extends Controller
{
    public function __construct( protected OAuthManager $oauth )
    {
    }

    /**
     * Kick off the authorization redirect for the current user.
     *
     * @since 1.0.0
     */
    public function connect( Request $request ): RedirectResponse
    {
        $user = $request->user();

        if ( null === $user ) {
            abort( 401 );
        }

        try {
            $url = $this->oauth->authorizationUrl( $user->getAuthIdentifier() );
        } catch ( OAuthException $e ) {
            return $this->redirectAfterError()->with( 'microsoft.error', $e->getMessage() );
        }

        return redirect()->away( $url );
    }

    /**
     * Handle the OAuth callback from Microsoft.
     *
     * @since 1.0.0
     */
    public function callback( Request $request ): RedirectResponse
    {
        $error = $this->stringQuery( $request, 'error' );

        if ( '' !== $error ) {
            $description = $this->stringQuery( $request, 'error_description' );
            $message     = '' !== $description ? $description : $error;

            return $this->redirectAfterError()->with( 'microsoft.error', $message );
        }

        $code  = $this->stringQuery( $request, 'code' );
        $state = $this->stringQuery( $request, 'state' );

        if ( '' === $code || '' === $state ) {
            return $this->redirectAfterError()->with(
                'microsoft.error',
                __( 'Microsoft callback is missing required code or state parameter.' ),
            );
        }

        try {
            $this->oauth->handleCallback( $code, $state );
        } catch ( OAuthException $e ) {
            return $this->redirectAfterError()->with( 'microsoft.error', $e->getMessage() );
        }

        return $this->redirectAfterConnect()->with( 'microsoft.status', 'connected' );
    }

    protected function redirectAfterConnect(): RedirectResponse
    {
        return $this->resolveRedirect(
            (string) config( 'microsoft-oauth.routes.redirect_after_connect', '/' ),
        );
    }

    protected function redirectAfterError(): RedirectResponse
    {
        return $this->resolveRedirect(
            (string) config( 'microsoft-oauth.routes.redirect_after_error', '/' ),
        );
    }

    /**
     * Fetch a query parameter as a string, guarding against `?key[]=value`
     * shapes that would otherwise stringify to "Array".
     *
     * @since 1.0.0
     */
    protected function stringQuery( Request $request, string $key ): string
    {
        $value = $request->query( $key );

        return is_string( $value ) ? $value : '';
    }

    protected function resolveRedirect( string $target ): RedirectResponse
    {
        if ( Route::has( $target ) ) {
            return redirect()->route( $target );
        }

        return redirect( $target );
    }
}
