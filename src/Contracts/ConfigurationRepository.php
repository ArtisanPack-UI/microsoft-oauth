<?php

/**
 * Configuration repository contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage MicrosoftOAuth
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\MicrosoftOAuth\Contracts;

/**
 * Contract for app credential storage drivers.
 *
 * Implementations back either config/env files or the database.
 * OAuth tokens are NOT stored here — see the connection model.
 *
 * @since 1.0.0
 */
interface ConfigurationRepository
{
    /**
     * Get the OAuth client (application) ID.
     *
     * @since 1.0.0
     */
    public function getClientId(): ?string;

    /**
     * Get the OAuth client secret.
     *
     * Public clients (SPA / native) may leave this null and rely on PKCE
     * alone; confidential clients (web apps) must provide a secret.
     *
     * @since 1.0.0
     */
    public function getClientSecret(): ?string;

    /**
     * Get the Microsoft tenant identifier.
     *
     * One of `common`, `organizations`, `consumers`, a tenant GUID, or a
     * verified domain. Defaults to `common` when nothing is configured.
     *
     * @since 1.0.0
     */
    public function getTenant(): ?string;

    /**
     * Persist a full credential set.
     *
     * Drivers that are read-only (like the config driver) may throw
     * a RuntimeException.
     *
     * @since 1.0.0
     *
     * @param  array<string, string|null>  $credentials  Keys: client_id, client_secret, tenant.
     */
    public function save( array $credentials ): void;

    /**
     * Whether the repository has a usable credential set.
     *
     * A `client_id` and a `tenant` are the minimum; `client_secret` is
     * required only for confidential clients, so its presence is not
     * checked here.
     *
     * @since 1.0.0
     */
    public function isConfigured(): bool;
}
