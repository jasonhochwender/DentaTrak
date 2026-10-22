<?php
/**
 * DsCoreConfig
 *
 * Central server-side configuration and secrets for the DS Core
 * (Dentsply Sirona) integration. The Client ID / Client Secret belong to
 * DentaTrak (the API developer), NOT to any practice.
 *
 *   DS_CORE_ENVIRONMENT          - 'sandbox' (default) or 'production'.
 *                                  Selects the credential pair and the
 *                                  endpoint set. Per the DS Core API
 *                                  Authentication Manual: the interactive
 *                                  login endpoint is REGIONAL (sandbox EU:
 *                                  api.r2.dscore.com) while the token
 *                                  endpoints (token, token:refresh,
 *                                  token:revoke) are GLOBAL
 *                                  (api.dscore.com) in every environment.
 *   DS_CORE_SANDBOX_CLIENT_ID    - sandbox Client ID (Tyk portal, PMS app)
 *   DS_CORE_SANDBOX_CLIENT_SECRET- sandbox Client Secret
 *   DS_CORE_PROD_CLIENT_ID       - production Client ID
 *   DS_CORE_PROD_CLIENT_SECRET   - production Client Secret
 *   DS_CORE_AUTH_BASE_URL        - optional override for the authorize host
 *                                  (region pinning; default per environment)
 *   DS_CORE_API_BASE_URL         - optional override for the token/refresh
 *                                  base URL (default per environment)
 *
 * SECURITY CONTRACT (same as OpenDentalConfig):
 *  - Secrets loaded exclusively from the server environment
 *    (real env vars / .env / Secret Manager -> Cloud Run env).
 *  - Never stored in integration_credentials or any database table, never
 *    returned to the browser, never logged, never placed in URLs,
 *    exceptions, config_json, or audit metadata.
 *  - Callers fail closed: a missing value returns null and the caller
 *    surfaces a safe "server configuration" error.
 */

class DsCoreConfig {

    /**
     * Environment label -> endpoint bases for this deployment profile.
     * 'auth_base' is the regional interactive-login host (secureLogin).
     * 'api_base' is the global authentication gateway used for token
     * exchange, refresh, and revocation - per the Authentication Manual
     * it is api.dscore.com in every environment; it must NOT default to
     * the regional business host.
     */
    private const ENDPOINTS = [
        // Sandbox EU: login on the regional r2 host, token ops global.
        'sandbox' => [
            'auth_base' => 'https://api.r2.dscore.com',
            'api_base'  => 'https://api.dscore.com',
        ],
        'production' => [
            'auth_base' => 'https://api.dscore.com',
            'api_base'  => 'https://api.dscore.com',
        ],
    ];

    /**
     * Read a central secret/config from the server environment.
     * Returns null when unset/empty. Never throws, never logs.
     */
    private static function envValue(string $name): ?string {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    /**
     * Active DS Core environment: 'sandbox' unless explicitly 'production'.
     * Any unrecognized value resolves to 'sandbox' so a typo can never
     * point the flow at the production gateway by accident.
     */
    public static function environment(): string {
        $env = strtolower((string)(self::envValue('DS_CORE_ENVIRONMENT') ?? 'sandbox'));
        return $env === 'production' ? 'production' : 'sandbox';
    }

    /** Active Client ID for the configured environment, or null. */
    public static function clientId(): ?string {
        return self::environment() === 'production'
            ? self::envValue('DS_CORE_PROD_CLIENT_ID')
            : self::envValue('DS_CORE_SANDBOX_CLIENT_ID');
    }

    /** Active Client Secret for the configured environment, or null. */
    public static function clientSecret(): ?string {
        return self::environment() === 'production'
            ? self::envValue('DS_CORE_PROD_CLIENT_SECRET')
            : self::envValue('DS_CORE_SANDBOX_CLIENT_SECRET');
    }

    /**
     * Base URL for the interactive authorization (secureLogin) endpoint.
     * REGIONAL: sandbox EU is api.r2.dscore.com. The override exists for
     * region pinning (e.g. a production region-specific login host).
     */
    public static function authBaseUrl(): string {
        $override = self::envValue('DS_CORE_AUTH_BASE_URL');
        if ($override !== null) {
            return rtrim($override, '/');
        }
        return self::ENDPOINTS[self::environment()]['auth_base'];
    }

    /**
     * Base URL for token exchange/refresh/revocation calls
     * (/v1beta/auth/token*). GLOBAL: api.dscore.com per the Authentication
     * Manual - intentionally distinct from the regional login host.
     */
    public static function apiBaseUrl(): string {
        $override = self::envValue('DS_CORE_API_BASE_URL');
        if ($override !== null) {
            return rtrim($override, '/');
        }
        return self::ENDPOINTS[self::environment()]['api_base'];
    }

    /**
     * True only when BOTH the Client ID and Secret are configured for the
     * active environment. The connect flow fails closed without them.
     */
    public static function isConfigured(): bool {
        return self::clientId() !== null && self::clientSecret() !== null;
    }
}
