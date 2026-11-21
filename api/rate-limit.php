<?php
/**
 * Shared database-backed fixed-window rate limiter.
 *
 * Counters live in MySQL so limits hold across Cloud Run instances and
 * cannot be bypassed by concurrent requests or practice switching.
 * Increment is atomic via INSERT ... ON DUPLICATE KEY UPDATE feeding
 * LAST_INSERT_ID(); the returned hit count is unique per request.
 *
 * The rate_limit_counters table is created by deployment migration
 * migrations/2026_12_01_mcp_send_approvals.php - never at request time.
 * If the table is absent the query throws; callers catch and fail open
 * (availability over strictness for a throttle, never a security gate).
 */

/**
 * Consume one hit for $bucket inside the current fixed window.
 * Returns seconds until the window resets when the limit is exceeded,
 * or null when the request is allowed.
 */
function rateLimitConsume(PDO $pdo, string $bucket, int $limit, int $windowSeconds): ?int {
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
