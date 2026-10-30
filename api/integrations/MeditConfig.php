<?php
/**
 * MeditConfig
 *
 * Central server-side configuration and secrets for the Medit Link
 * integration. The Client ID / Client Secret belong to DentaTrak (the API
 * developer), NOT to any practice.
 *
 *   MEDIT_ENVIRONMENT           - 'stage' (default) or 'production'.
 *                                 Selects the credential pair and the
 *                                 endpoint set. Per the Medit Link OpenAPI
 *                                 guide, auth and resource APIs live on
 *                                 separate hosts:
 *                                   stage-auth: stage-openapi-auth.meditlink.com
 *                                   stage-res:  stage-openapi-resources.meditlink.com
 *   MEDIT_STAGE_CLIENT_ID       - stage Client ID
 *   MEDIT_STAGE_CLIENT_SECRET   - stage Client Secret (Secret Manager)
 *   MEDIT_PROD_CLIENT_ID        - production Client ID (future)
 *   MEDIT_PROD_CLIENT_SECRET    - production Client Secret (future)
 *   MEDIT_AUTH_BASE_URL         - optional auth host override
 *   MEDIT_RESOURCE_BASE_URL     - optional resource host override
 *   MEDIT_ALLOWED_PRACTICES     - comma-separated practice ids permitted to
 *                                 use Medit at all. EMPTY or UNSET permits
 *                                 NO practice - the rollout gate enforced
 *                                 on initiation, callback, refresh,
 *                                 polling, webhook processing, and every
 *                                 download. Server-side only.
 *   MEDIT_WEBHOOK_SIGN_SECRET   - the signSecretKey issued when the webhook
 *                                 endpoint is registered (HMAC-SHA256).
 *                                 UNSET fails closed: the receiver rejects
 *                                 every delivery.
 *   MEDIT_WEBHOOK_SIGNATURE_HEADER - header carrying the HMAC hex digest.
 *                                 The stage guide documents signSecretKey
 *                                 and HMAC-SHA256 but does NOT name the
 *                                 delivery header; defaults to the most
 *                                 likely convention and is overridable
 *                                 here once confirmed.
 *
 * SECURITY CONTRACT (same as DsCoreConfig):
 *  - Secrets loaded exclusively from the server environment.
 *  - Never stored in integration_credentials or any database table, never
 *    returned to the browser, never logged, never placed in URLs,
 *    exceptions, config_json, or audit metadata.
 *  - Callers fail closed: a missing value returns null and the caller
 *    surfaces a safe "server configuration" error.
 */

class MeditConfig {

    /**
     * Environment label -> endpoint bases for this deployment profile.
     * 'auth_base' serves /oauth/* (authorize, token); 'resource_base'
     * serves /v1/* business APIs. Stage hosts come from the OpenAPI
     * guide; production hosts follow the same naming convention and are
     * overridable pending vendor confirmation.
     */
    private const ENDPOINTS = [
        'stage' => [
            'auth_base'     => 'https://stage-openapi-auth.meditlink.com',
            'resource_base' => 'https://stage-openapi-resources.meditlink.com',
        ],
        'production' => [
            'auth_base'     => 'https://openapi-auth.meditlink.com',
            'resource_base' => 'https://openapi-resources.meditlink.com',
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
     * Active Medit environment: 'stage' unless explicitly 'production'.
     * Any unrecognized value resolves to 'stage' so a typo can never
     * point the flow at the production gateway by accident.
     */
    public static function environment(): string {
        $env = strtolower((string)(self::envValue('MEDIT_ENVIRONMENT') ?? 'stage'));
        return $env === 'production' ? 'production' : 'stage';
    }

    /** Active Client ID for the configured environment, or null. */
    public static function clientId(): ?string {
        return self::environment() === 'production'
            ? self::envValue('MEDIT_PROD_CLIENT_ID')
            : self::envValue('MEDIT_STAGE_CLIENT_ID');
    }

    /** Active Client Secret for the configured environment, or null. */
    public static function clientSecret(): ?string {
        return self::environment() === 'production'
            ? self::envValue('MEDIT_PROD_CLIENT_SECRET')
            : self::envValue('MEDIT_STAGE_CLIENT_SECRET');
    }

    /** Base URL for /oauth/* calls (authorize + token + check_token). */
    public static function authBaseUrl(): string {
        $override = self::envValue('MEDIT_AUTH_BASE_URL');
        if ($override !== null) {
            return rtrim($override, '/');
        }
        return self::ENDPOINTS[self::environment()]['auth_base'];
    }

    /** Base URL for /v1/* resource calls. */
    public static function resourceBaseUrl(): string {
        $override = self::envValue('MEDIT_RESOURCE_BASE_URL');
        if ($override !== null) {
            return rtrim($override, '/');
        }
        return self::ENDPOINTS[self::environment()]['resource_base'];
    }

    /**
     * True only when BOTH the Client ID and Secret are configured for the
     * active environment. The connect flow fails closed without them.
     *
     * PRODUCTION FAILS CLOSED: the inferred production host names are not
     * vendor-confirmed. A production environment is only "configured"
     * when the operator has explicitly pinned BOTH endpoint overrides -
     * staging works on the documented defaults alone.
     */
    public static function isConfigured(): bool {
        if (self::clientId() === null || self::clientSecret() === null) {
            return false;
        }
        if (self::environment() === 'production') {
            return self::envValue('MEDIT_AUTH_BASE_URL') !== null
                && self::envValue('MEDIT_RESOURCE_BASE_URL') !== null;
        }
        return true;
    }

    /**
     * Practice ids permitted to use Medit, from MEDIT_ALLOWED_PRACTICES
     * (comma-separated). UNSET or EMPTY returns [] - which permits NO
     * practice. This is the controlled-rollout gate: until an operator
     * deliberately lists staging test practices, MEDIT_CONNECT alone can
     * never reach a real practice.
     *
     * Deliberately consulted NOWHERE but server-side enforcement points:
     * no role, plan, or override argument exists, so super-user/admin
     * status cannot bypass it.
     */
    public static function allowedPracticeIds(): array {
        $raw = self::envValue('MEDIT_ALLOWED_PRACTICES');
        if ($raw === null) {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && ctype_digit($piece)) {
                $ids[] = (int)$piece;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * True only when the allowlist is the explicit wildcard '*' - general
     * availability for every eligible practice. Still requires the value
     * to be deliberately set: UNSET or EMPTY permits NO practice, and no
     * other token ('all', 'true', whitespace variants) counts.
     */
    public static function allowAllPractices(): bool {
        $raw = self::envValue('MEDIT_ALLOWED_PRACTICES');
        return $raw !== null && trim($raw) === '*';
    }

    /** True only when the practice is explicitly allowlisted (or '*'). */
    public static function isPracticeAllowed(int $practiceId): bool {
        return $practiceId > 0
            && (self::allowAllPractices() || in_array($practiceId, self::allowedPracticeIds(), true));
    }

    /**
     * True only when a stored connection was authorized against the
     * ACTIVE environment. config_json.environment is written at connect
     * time; a missing value predates the field and counts as 'stage'.
     * Enforced on status, polling, refresh, webhook processing, and
     * business calls so a stage connection can never be polled,
     * refreshed, or presented as connected under production
     * configuration.
     */
    public static function connectionMatchesEnvironment(?array $connection): bool {
        if (!$connection) {
            return false;
        }
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        $connEnv = strtolower((string)($config['environment'] ?? 'stage'));
        return $connEnv === self::environment();
    }

    /**
     * The HMAC-SHA256 signing secret for webhook payload verification,
     * from MEDIT_WEBHOOK_SIGN_SECRET. UNSET -> the receiver fails closed.
     */
    public static function webhookSignSecret(): ?string {
        return self::envValue('MEDIT_WEBHOOK_SIGN_SECRET');
    }

    /**
     * Header carrying the hex HMAC-SHA256 of the raw request body. The
     * guide documents signSecretKey but not the delivery header name;
     * default follows Medit's documented header convention and is
     * overridable until the exact name is confirmed with the vendor.
     */
    public static function webhookSignatureHeader(): string {
        $override = self::envValue('MEDIT_WEBHOOK_SIGNATURE_HEADER');
        if ($override !== null && preg_match('/^[A-Za-z0-9\-]{2,64}$/', $override)) {
            return strtolower($override);
        }
        return 'x-meditlink-signature';
    }
}
