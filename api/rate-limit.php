<?php
/**
 * Shared database-backed fixed-window rate limiter.
 *
 * Counters live in MySQL so limits hold across Cloud Run instances and
 * cannot be bypassed by concurrent requests or practice switching.
 * Increment is atomic via INSERT ... ON DUPLICATE KEY UPDATE feeding
 * LAST_INSERT_ID(); the returned hit count is unique per request.
 */

function rateLimitEnsureTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_counters (
        bucket VARCHAR(160) NOT NULL,
        window_id BIGINT UNSIGNED NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (bucket, window_id)
    ) ENGINE=InnoDB");
    $done = true;
}

/**
 * Consume one hit for $bucket inside the current fixed window.
 * Returns seconds until the window resets when the limit is exceeded,
 * or null when the request is allowed.
 */
function rateLimitConsume(PDO $pdo, string $bucket, int $limit, int $windowSeconds): ?int {
    rateLimitEnsureTable($pdo);
    $windowId = intdiv(time(), $windowSeconds);

    // Canonical atomic counter: LAST_INSERT_ID() returns the post-increment
    // hit count on both the insert and update paths.
    $stmt = $pdo->prepare(
        "INSERT INTO rate_limit_counters (bucket, window_id, hits)
         VALUES (:b, :w, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)"
    );
    $stmt->execute([':b' => $bucket, ':w' => $windowId]);
    $hits = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    // Opportunistic cleanup of expired windows (~1% of calls).
    if (random_int(1, 100) === 1) {
        try {
            $pdo->exec('DELETE FROM rate_limit_counters WHERE window_id < ' . ($windowId - 2));
        } catch (PDOException $ignored) {}
    }

    if ($hits > $limit) {
        return max(1, ($windowId + 1) * $windowSeconds - time());
    }
    return null;
}
