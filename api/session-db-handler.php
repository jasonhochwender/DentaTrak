<?php
/**
 * PDO-backed session handler for Cloud Run / multi-instance deployments.
 *
 * Stores session data in Cloud SQL so sessions survive request routing.
 * Features:
 * - SHA-256/HMAC of the session id is used as the database key (not the raw id)
 * - Per-session advisory locking via MySQL GET_LOCK()
 * - Bounded 30-second rotation mapping for session_regenerate_id() safety
 * - Expiration filtering on read and efficient GC
 */

/**
 * Thrown when a session lock cannot be acquired within the bounded timeout.
 * This is a transient, retryable condition and must not be treated as logout.
 */
class SessionLockException extends Exception {}

class PdoSessionHandler implements SessionHandlerInterface
{
    private $pdo;
    private $lockName;
    private $lockHeld;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->lockName = null;
        $this->lockHeld = false;
    }

    /**
     * Hash a session identifier for database storage.
     */
    public static function hashId(string $id): string
    {
        $pepper = getenv('ENCRYPTION_KEY') ?: ($_ENV['ENCRYPTION_KEY'] ?? '');
        if (is_array($pepper)) {
            $pepper = '';
        }
        return hash_hmac('sha256', $id, $pepper);
    }

    /**
     * Create required tables if they do not exist. DDL runs at most once per
     * container because of the sentinel file. The sentinel name is versioned
     * so a schema change will force a fresh check on the next deployment.
     */
    public static function ensureTables(PDO $pdo): void
    {
        $ddl = "
            CREATE TABLE IF NOT EXISTS php_sessions (
                session_hash CHAR(64) PRIMARY KEY,
                data MEDIUMBLOB,
                updated_at INT UNSIGNED NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS php_session_rotations (
                old_hash CHAR(64) PRIMARY KEY,
                new_hash CHAR(64) NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_expires_at (expires_at),
                INDEX idx_new_hash (new_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $version = md5($ddl);
        $sentinel = sys_get_temp_dir() . '/dtk_php_sessions_table_' . $version;
        if (file_exists($sentinel)) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS php_sessions (
                session_hash CHAR(64) PRIMARY KEY,
                data MEDIUMBLOB,
                updated_at INT UNSIGNED NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS php_session_rotations (
                old_hash CHAR(64) PRIMARY KEY,
                new_hash CHAR(64) NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_expires_at (expires_at),
                INDEX idx_new_hash (new_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        @touch($sentinel);
    }

    public function open($savePath, $sessionName): bool
    {
        self::ensureTables($this->pdo);
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();
        return true;
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    public function read($id): string
    {
        $hash = self::hashId($id);
        $now = time();

        if (!$this->acquireLock($hash)) {
            return '';
        }

        $stmt = $this->pdo->prepare("
            SELECT data FROM php_sessions
            WHERE session_hash = :hash AND expires_at > :now
        ");
        $stmt->execute(['hash' => $hash, 'now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $value = $row['data'];
            if (is_resource($value)) {
                return (string) stream_get_contents($value);
            }
            return (string) $value;
        }

        // If this id has been rotated to a new one, return the new session data
        $rot = $this->pdo->prepare("
            SELECT new_hash FROM php_session_rotations
            WHERE old_hash = :hash AND expires_at > :now
        ");
        $rot->execute(['hash' => $hash, 'now' => $now]);
        $rotation = $rot->fetch(PDO::FETCH_ASSOC);

        if ($rotation) {
            $newHash = $rotation['new_hash'];
            $this->acquireLock($newHash);
            $stmt = $this->pdo->prepare("
                SELECT data FROM php_sessions
                WHERE session_hash = :hash AND expires_at > :now
            ");
            $stmt->execute(['hash' => $newHash, 'now' => $now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return '';
            }
            $value = $row['data'];
            if (is_resource($value)) {
                return (string) stream_get_contents($value);
            }
            return (string) $value;
        }

        return '';
    }

    /**
     * Internal write to a specific hash without rotation forwarding. Used by
     * markRotated() to seed the new session row while holding both locks.
     */
    private function writeRaw(string $hash, string $data, int $now): bool
    {
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        if ($lifetime <= 0) {
            $lifetime = 5400;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO php_sessions
                (session_hash, data, updated_at, expires_at, created_at)
            VALUES
                (:hash, :data, :updated_at, :expires_at, :created_at)
            ON DUPLICATE KEY UPDATE
                data = VALUES(data),
                updated_at = VALUES(updated_at),
                expires_at = VALUES(expires_at)
        ");

        $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
        $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', $now + $lifetime, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function write($id, $data): bool
    {
        $hash = self::hashId($id);
        $now = time();

        // Avoid creating rows for completely empty anonymous sessions. Under the
        // php_serialize handler an empty session is encoded as 'a:0:{}'; the older
        // php handler encodes it as ''. Any real value (locale, CSRF, user id,
        // pending destination, etc.) produces a non-empty, non-a:0:{} payload and
        // is persisted so legitimate pre-auth and user-state flows keep working.
        if ($data === '' || $data === 'a:0:{}') {
            return true;
        }

        try {
            $this->acquireLock($hash);

            // If this id has been rotated to a newer id, forward the write so the
            // current session stays current. This prevents an in-flight request with
            // an old cookie from forking a stale session.
            $rot = $this->pdo->prepare("
                SELECT new_hash FROM php_session_rotations
                WHERE old_hash = :hash AND expires_at > :now
            ");
            $rot->execute(['hash' => $hash, 'now' => $now]);
            $rotation = $rot->fetch(PDO::FETCH_ASSOC);

            if ($rotation) {
                $hash = $rotation['new_hash'];
                $this->acquireLock($hash);
            }

            $lifetime = (int) ini_get('session.gc_maxlifetime');
            if ($lifetime <= 0) {
                $lifetime = 5400;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO php_sessions
                    (session_hash, data, updated_at, expires_at, created_at)
                VALUES
                    (:hash, :data, :updated_at, :expires_at, :created_at)
                ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    updated_at = VALUES(updated_at),
                    expires_at = VALUES(expires_at)
            ");

            $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
            $stmt->bindValue(':updated_at', $now, PDO::PARAM_INT);
            $stmt->bindValue(':expires_at', $now + $lifetime, PDO::PARAM_INT);
            $stmt->bindValue(':created_at', $now, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (Exception $e) {
            // A write lock timeout is transient. Returning false lets the
            // request finish without a fatal, and the next request will see
            // the session state written by whichever request holds the lock.
            error_log('[PdoSessionHandler] write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy($id): bool
    {
        $hash = self::hashId($id);

        try {
            $this->acquireLock($hash);

            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE session_hash = :hash");
            $stmt->execute(['hash' => $hash]);

            $rot = $this->pdo->prepare("
                DELETE FROM php_session_rotations
                WHERE old_hash = ? OR new_hash = ?
            ");
            $rot->execute([$hash, $hash]);

            return true;
        } catch (Exception $e) {
            error_log('[PdoSessionHandler] destroy failed: ' . $e->getMessage());
            return false;
        } finally {
            $this->releaseLock();
        }
    }

    public function gc($maxlifetime): int
    {
        $now = time();

        $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE expires_at < :now");
        $stmt->execute(['now' => $now]);
        $rows = $stmt->rowCount();

        $rot = $this->pdo->prepare("DELETE FROM php_session_rotations WHERE expires_at < :now");
        $rot->execute(['now' => $now]);

        return $rows + $rot->rowCount();
    }

    /**
     * Mark an old session id as rotated to a new one. The old session row is
     * expired immediately; the 30-second rotation mapping lets in-flight
     * requests with the old cookie continue to read and write the new session
     * data before the mapping is garbage collected.
     */
    public function markRotated(string $oldId, string $newId): bool
    {
        $oldHash = self::hashId($oldId);
        $newHash = self::hashId($newId);
        $now = time();

        try {
            $this->acquireLock($oldHash);

            // Make sure the new session row is written before we expire the old id.
            // session_encode() works because the calling code already has an active
            // session, so an in-flight old request can immediately use the rotation
            // and read the current data from the new row.
            if (function_exists('session_encode')) {
                $data = @session_encode();
                if ($data !== false && $data !== '') {
                    $this->acquireLock($newHash);
                    $this->writeRaw($newHash, $data, $now);
                }
            }

            $rotationLifetime = 30;  // seconds the rotation mapping remains valid

            $stmt = $this->pdo->prepare("
                INSERT INTO php_session_rotations
                    (old_hash, new_hash, expires_at, created_at)
                VALUES
                    (:old_hash, :new_hash, :rotation_expires, :created_at)
                ON DUPLICATE KEY UPDATE
                    new_hash = VALUES(new_hash),
                    expires_at = VALUES(expires_at)
            ");
            $stmt->execute([
                'old_hash' => $oldHash,
                'new_hash' => $newHash,
                'rotation_expires' => $now + $rotationLifetime,
                'created_at' => $now,
            ]);

            // Expire the old session immediately so new requests use the rotation
            // mapping, while in-flight requests that already read the old session
            // continue to work until their request ends.
            $stmt = $this->pdo->prepare("
                UPDATE php_sessions
                SET expires_at = ?
                WHERE session_hash = ? AND expires_at > ?
            ");
            $expiredAt = $now - 1;
            $stmt->execute([$expiredAt, $oldHash, $expiredAt]);

            return true;
        } catch (Exception $e) {
            // Rotation is best-effort; a lock timeout or store failure should
            // not break the user's current request.
            error_log('[PdoSessionHandler] markRotated failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Acquire a MySQL advisory lock for this session, releasing any previous
     * session lock held by the same request. Bounded timeout prevents stalls.
     */
    private function acquireLock(string $hash): bool
    {
        if ($this->lockHeld && $this->lockName === $hash) {
            return true;
        }

        $this->releaseLock();

        $timeout = (int) (getenv('DENTATRAK_SESSION_LOCK_TIMEOUT') ?: 5);
        if ($timeout < 0) {
            $timeout = 5;
        }

        $stmt = $this->pdo->prepare("SELECT GET_LOCK(:name, :timeout)");
        $stmt->execute(['name' => $hash, 'timeout' => $timeout]);
        $result = $stmt->fetchColumn();

        if ($result === '1' || $result === 1 || $result === true) {
            $this->lockName = $hash;
            $this->lockHeld = true;
            return true;
        }

        // Bounded lock timeout is a transient, retryable condition. It must
        // never be treated as an empty or logged-out session.
        throw new SessionLockException('Session lock timeout');
    }

    private function releaseLock(): void
    {
        if (!$this->lockHeld || !$this->lockName) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT RELEASE_LOCK(:name)");
            $stmt->execute(['name' => $this->lockName]);
        } catch (Throwable $e) {
            // Lock is released when the connection closes anyway
        }

        $this->lockName = null;
        $this->lockHeld = false;
    }
}

/**
 * Install the PDO session handler and expose it globally for regenerateSession().
 */
function registerPdoSessionHandler(PDO $pdo): void
{
    $handler = new PdoSessionHandler($pdo);
    $GLOBALS['pdoSessionHandler'] = $handler;
    session_set_save_handler($handler, true);
}
