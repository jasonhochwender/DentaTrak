<?php
/**
 * OpenDentalConfig
 *
 * Central server-side secrets for the Open Dental integration. These keys
 * belong to DentaTrak (the API developer), NOT to any practice:
 *
 *   OPEN_DENTAL_DEVELOPER_KEY        - DentaTrak's Developer Key, combined
 *                                      with each practice's Customer Key at
 *                                      request time (ODFHIR dev/customer).
 *   OPEN_DENTAL_DEVELOPER_PORTAL_KEY - DentaTrak's Developer Portal Key, used
 *                                      ONLY for Developer Portal API calls
 *                                      (/apikeys customer-key lifecycle).
 *
 * SECURITY CONTRACT:
 *  - Loaded exclusively from server environment (.env / real env vars).
 *  - Never stored in integration_credentials or any database table.
 *  - Never returned to the browser, never logged, never placed in URLs,
 *    exceptions, config_json, or audit metadata.
 *  - Callers fail closed: a missing value returns null and the caller
 *    surfaces a safe "server configuration" error - never the variable name
 *    or any raw configuration detail to end users.
 */

class OpenDentalConfig {

    /**
     * Read a central secret from the server environment.
     * Returns null when unset/empty. Never throws, never logs.
     */
    private static function envSecret(string $name): ?string {
        // getenv() first: a real environment variable is authoritative.
        // It is only absent when getenv returns false; a defined-but-empty
        // value is an explicit "disabled" and must fail closed rather than
        // silently falling through to $_ENV (which .env may have populated).
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    /** DentaTrak's Open Dental Developer Key, or null when not configured. */
    public static function developerKey(): ?string {
        return self::envSecret('OPEN_DENTAL_DEVELOPER_KEY');
    }

    /** DentaTrak's Developer Portal Key, or null when not configured. */
    public static function developerPortalKey(): ?string {
        return self::envSecret('OPEN_DENTAL_DEVELOPER_PORTAL_KEY');
    }

    /**
     * Presence checks for safe status reporting ("configured: yes/no")
     * without exposing whether a specific value exists beyond a boolean.
     */
    public static function hasDeveloperKey(): bool {
        return self::developerKey() !== null;
    }

    public static function hasDeveloperPortalKey(): bool {
        return self::developerPortalKey() !== null;
    }
}
