<?php
/**
 * IntegrationManager
 *
 * Foundation layer for PMS integrations: loads connections, enforces the
 * practice-isolation boundary, resolves the correct adapter for a
 * connection's provider, and brokers credential access. It does NOT run
 * synchronization itself - SyncEngine and the future worker own that.
 *
 * PRACTICE ISOLATION BOUNDARY:
 *  - Admin-facing lookups MUST go through getConnectionForPractice(), which
 *    requires the connection's practice_id to match the caller's verified
 *    practice context. Never fetch a connection by bare id and then trust
 *    client-supplied practice data.
 *  - Worker-facing code uses listConnectionsDueForSync(); each returned row
 *    carries its own practice_id, which is the ONLY practice context the
 *    worker may use. No request/session input is involved.
 *  - Credentials are loaded per connection id; a credential row can never be
 *    read for a connection outside the caller's practice because the
 *    connection row itself is practice-scoped first.
 *
 * ADAPTER RESOLUTION:
 *  Providers are registered via registerAdapterFactory(). Open Dental is
 *  registered in a later phase; until then getAdapter() throws for unknown
 *  providers, which is the correct fail-closed behavior.
 */

require_once __DIR__ . '/PmsAdapterInterface.php';
require_once __DIR__ . '/CanonicalCase.php';
require_once __DIR__ . '/IntegrationCredentials.php';
require_once __DIR__ . '/IntegrationEvents.php';

class IntegrationManager {

    /** @var array<string, callable> provider => factory(connectionRow): PmsAdapterInterface */
    private static $adapterFactories = [];

    // ----------------------------------------------------------------------
    // Adapter registry
    // ----------------------------------------------------------------------

    /**
     * Register an adapter factory for a provider key (e.g. 'open_dental').
     * The factory receives the connection row and returns a
     * PmsAdapterInterface instance.
     */
    public static function registerAdapterFactory(string $provider, callable $factory): void {
        self::$adapterFactories[strtolower($provider)] = $factory;
    }

    /**
     * Test hook: clear registered factories (used by unit tests so a fake
     * provider registration cannot leak between tests).
     */
    public static function clearAdapterFactories(): void {
        self::$adapterFactories = [];
    }

    /**
     * Resolve the adapter for a connection. Throws when the provider has no
     * registered adapter - deliberately fail-closed.
     */
    public static function getAdapter(array $connection): PmsAdapterInterface {
        $provider = strtolower((string)($connection['provider'] ?? ''));
        if ($provider === '' || !isset(self::$adapterFactories[$provider])) {
            throw new RuntimeException('No adapter registered for this integration provider.');
        }
        $adapter = call_user_func(self::$adapterFactories[$provider], $connection);
        if (!($adapter instanceof PmsAdapterInterface)) {
            throw new RuntimeException('Registered adapter factory did not return a PmsAdapterInterface.');
        }
        return $adapter;
    }

    // ----------------------------------------------------------------------
    // Connection loading (practice-scoped)
    // ----------------------------------------------------------------------

    /**
     * Admin-facing: fetch a connection ONLY if it belongs to the given
     * (already-verified) practice. Returns null when the connection does not
     * exist or belongs to a different practice - callers must not
     * distinguish the two cases (no existence oracle across practices).
     */
    public static function getConnectionForPractice(PDO $pdo, int $practiceId, int $connectionId): ?array {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_connections
            WHERE id = :id AND practice_id = :practice_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $connectionId, ':practice_id' => $practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Internal: fetch a connection by id with NO practice check. For
     * server-side orchestration only (workers, SyncEngine) - admin/API
     * paths must use getConnectionForPractice() instead.
     */
    public static function findConnection(PDO $pdo, int $connectionId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM integration_connections WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $connectionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * All connections for a practice, as safe public projections.
     */
    public static function listConnectionsForPractice(PDO $pdo, int $practiceId): array {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_connections
            WHERE practice_id = :practice_id
            ORDER BY provider ASC
        ");
        $stmt->execute([':practice_id' => $practiceId]);
        $connections = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $connections[] = self::toPublicConnection($pdo, $row);
        }
        return $connections;
    }

    /**
     * Worker-facing: connections eligible for a scheduled sync pass. Each
     * row's practice_id is authoritative - the worker derives all practice
     * scoping from it and never accepts practice context from outside.
     */
    public static function listConnectionsDueForSync(PDO $pdo): array {
        $stmt = $pdo->query("
            SELECT * FROM integration_connections
            WHERE sync_enabled = 1 AND status IN ('active', 'error')
            ORDER BY last_sync_at ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ----------------------------------------------------------------------
    // Credentials
    // ----------------------------------------------------------------------

    /**
     * Decrypted credential map for a connection (credential_key => plaintext).
     * SERVER-SIDE ONLY. Callers must be worker/adapter code operating on an
     * already-verified connection row - never expose the result through any
     * response object.
     */
    public static function getConnectionCredentials(PDO $pdo, int $connectionId): array {
        // Legacy hygiene: developer_key was stored per-connection before the
        // Developer Key became DentaTrak's central server secret
        // (OPEN_DENTAL_DEVELOPER_KEY). Stale per-practice copies could diverge
        // or mask a missing server secret, so remove them opportunistically
        // and never hand one to an adapter. Best-effort: a cleanup failure
        // must not break the credential read path.
        if (IntegrationCredentials::has($pdo, $connectionId, 'developer_key')) {
            try {
                IntegrationCredentials::delete($pdo, $connectionId, 'developer_key');
            } catch (Throwable $e) {
                // Leave the row; it is ignored below either way.
            }
        }
        $credentials = IntegrationCredentials::getAll($pdo, $connectionId);
        unset($credentials['developer_key']);
        return $credentials;
    }

    // ----------------------------------------------------------------------
    // Status bookkeeping (used by SyncEngine/orchestration in later phases)
    // ----------------------------------------------------------------------

    /**
     * Record the outcome of a finished sync pass on the connection row.
     * On success: stamps last_sync_at + last_success_at, clears last_error,
     * and revives an 'error'-status connection to 'active'.
     * On failure: stamps last_sync_at, records a truncated error string, and
     * moves an 'active' connection to 'error' (never auto-disables).
     */
    public static function markSyncFinished(PDO $pdo, int $connectionId, bool $success, ?string $error = null): void {
        if ($success) {
            $stmt = $pdo->prepare("
                UPDATE integration_connections
                SET last_sync_at = NOW(),
                    last_success_at = NOW(),
                    last_error = NULL,
                    status = CASE WHEN status = 'error' THEN 'active' ELSE status END
                WHERE id = :id
            ");
            $stmt->execute([':id' => $connectionId]);
            return;
        }

        $safeError = $error !== null ? substr($error, 0, 1000) : null;
        $stmt = $pdo->prepare("
            UPDATE integration_connections
            SET last_sync_at = NOW(),
                last_error = :last_error,
                status = CASE WHEN status = 'active' THEN 'error' ELSE status END
            WHERE id = :id
        ");
        $stmt->execute([':id' => $connectionId, ':last_error' => $safeError]);
    }

    // ----------------------------------------------------------------------
    // Safe projection
    // ----------------------------------------------------------------------

    /**
     * Projection of a connection safe to hand to UI/status consumers.
     * Excludes config_json entirely (non-secret by contract, but its
     * internal keys are an implementation detail until the settings UI is
     * designed) and NEVER includes credential material - only the boolean
     * "are credentials configured".
     */
    public static function toPublicConnection(PDO $pdo, array $connection): array {
        return [
            'id'                      => (int)$connection['id'],
            'practice_id'             => (int)$connection['practice_id'],
            'provider'                => $connection['provider'],
            'status'                  => $connection['status'],
            'sync_enabled'            => (bool)$connection['sync_enabled'],
            'external_account_id'     => $connection['external_account_id'],
            'last_sync_at'            => $connection['last_sync_at'],
            'last_success_at'         => $connection['last_success_at'],
            'last_error'              => $connection['last_error'],
            'credentials_configured'  => IntegrationCredentials::countForConnection($pdo, (int)$connection['id']) > 0,
            'credential_keys'         => IntegrationCredentials::listKeys($pdo, (int)$connection['id']),
            'subscription'            => IntegrationEvents::subscriptionProjection($pdo, $connection),
            // Most recent real case write (create/update) performed by an
            // integration worker for this connection - derived from the
            // realtime case_updates feed, so no-op events, test-connection
            // calls and subscription setup never inflate it.
            'last_case_write_at'      => self::lastCaseWriteAt($pdo, (int)$connection['id']),
            'created_at'              => $connection['created_at'],
            'updated_at'              => $connection['updated_at'],
        ];
    }

    /**
     * Timestamp of the most recent DentaTrak case create/update performed
     * by an integration for this connection, or null if none. NULL-safe:
     * a missing case_updates table (fresh install) yields null, not an
     * error that would break the whole settings projection.
     */
    private static function lastCaseWriteAt(PDO $pdo, int $connectionId): ?string {
        try {
            $stmt = $pdo->prepare("
                SELECT MAX(cu.updated_at)
                FROM case_updates cu
                JOIN integration_entity_mappings m
                  ON m.internal_id = cu.case_id
                 AND m.entity_type = 'case'
                WHERE m.connection_id = :id
                  AND cu.update_type IN ('create', 'update')
                  AND cu.updated_by LIKE 'integration:%'
            ");
            $stmt->execute([':id' => $connectionId]);
            $v = $stmt->fetchColumn();
            return $v === false || $v === null ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }
}
