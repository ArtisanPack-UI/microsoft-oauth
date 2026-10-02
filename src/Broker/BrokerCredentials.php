<?php

/**
 * OAuth broker site credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Broker;

use ArtisanPackUI\Hooks\Facades\Filter;
use ArtisanPackUI\MicrosoftOAuth\Exceptions\OAuthException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Immutable credentials a site uses to talk to an OAuth broker.
 *
 * A site in broker mode never holds a Microsoft client secret — only the
 * broker's base URL, its own `site_id` and its site secret.
 *
 * @since 1.1.0
 */
final class BrokerCredentials
{
    /**
     * @since 1.1.0
     *
     * @param  string  $url         Broker base URL, without a trailing slash.
     * @param  string  $siteId      This site's ID at the broker.
     * @param  string  $siteSecret  This site's secret (`{id}|{plain}`), sent as the bearer token.
     *
     * @throws OAuthException When the broker URL is not HTTPS (or HTTP on a local development host).
     */
    public function __construct(
        public readonly string $url,
        public readonly string $siteId,
        public readonly string $siteSecret,
    ) {
        if ( ! self::isSecureUrl( $url ) ) {
            throw new OAuthException(
                __( 'The Microsoft OAuth broker URL must use HTTPS; plain HTTP is only allowed for local development hosts.' ),
            );
        }
    }

    /**
     * Whether a broker URL is safe to send the site secret to.
     *
     * The site secret travels as a bearer token, so the broker must be
     * reached over HTTPS. Plain HTTP is only accepted for local development
     * hosts: `localhost`, `*.localhost`, `*.test` and loopback IPs.
     *
     * @since 1.1.0
     */
    public static function isSecureUrl( string $url ): bool
    {
        $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
        $host   = strtolower( trim( (string) parse_url( $url, PHP_URL_HOST ), '[]' ) );

        if ( '' === $host ) {
            return false;
        }

        if ( 'https' === $scheme ) {
            return true;
        }

        if ( 'http' !== $scheme ) {
            return false;
        }

        if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' ) ) {
            return true;
        }

        return '::1' === $host || str_starts_with( $host, '127.' ) && false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
    }

    /**
     * Resolve broker credentials from `config('microsoft-oauth.broker')`.
     *
     * The values pass through the `ap.microsoft.oauth.broker.credentials` filter, so
     * a host such as a CMS can supply them from its own settings store.
     * Returns null when any of the three values is missing.
     *
     * @since 1.1.0
     *
     * @throws OAuthException When the configured broker URL is not secure.
     */
    public static function fromConfig( ConfigRepository $config ): ?self
    {
        $values = Filter::apply( 'ap.microsoft.oauth.broker.credentials', [
            'url'         => $config->get( 'microsoft-oauth.broker.url' ),
            'site_id'     => $config->get( 'microsoft-oauth.broker.site_id' ),
            'site_secret' => $config->get( 'microsoft-oauth.broker.site_secret' ),
        ] );

        if ( ! is_array( $values ) ) {
            return null;
        }

        $url        = rtrim( trim( (string) ( $values['url'] ?? '' ) ), '/' );
        $siteId     = trim( (string) ( $values['site_id'] ?? '' ) );
        $siteSecret = trim( (string) ( $values['site_secret'] ?? '' ) );

        if ( '' === $url || '' === $siteId || '' === $siteSecret ) {
            return null;
        }

        return new self( $url, $siteId, $siteSecret );
    }

    /**
     * The HMAC key used to sign `/authorize` links.
     *
     * The broker keys signatures with the SHA-256 of the plain part of the
     * site secret (everything after the `|`). A secret without a `|` is
     * treated as all plain part.
     *
     * @since 1.1.0
     */
    public function signingKey(): string
    {
        $separator = strpos( $this->siteSecret, '|' );
        $plain     = false === $separator ? $this->siteSecret : substr( $this->siteSecret, $separator + 1 );

        return hash( 'sha256', $plain );
    }
}
