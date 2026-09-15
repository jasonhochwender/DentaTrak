<?php
/**
 * User environment observation
 *
 * Records each authenticated user's most recently seen browser/OS (parsed
 * from the request User-Agent) plus the observation timestamp. Diagnostic
 * data only - never an authorization signal. No IP addresses, location,
 * fingerprints, or browsing history are stored.
 *
 * Writes are session-throttled: at most one DB write when the UA string
 * changes (prompt browser/OS capture) or once per refresh interval,
 * whichever comes first. All failures are swallowed so collection never
 * interrupts login or normal use.
 */

// Refresh interval for the observation timestamp when the environment is
// unchanged. UA changes bypass this and are written immediately.
define('ENV_RECORD_INTERVAL', 6 * 3600);

/**
 * Parse a User-Agent string into coarse browser/OS labels.
 * Returns ['browser' => ?string, 'os' => ?string]; NULL components mean the
 * value could not be determined (rendered as "Unknown").
 */
function parseUserEnvironment(string $ua): array {
    $browser = null;
    $os = null;

    if ($ua !== '') {
        // Order matters: Edge/Opera UAs also contain a Chrome token, and
        // Chrome/Safari tokens coexist on WebKit browsers.
        if (preg_match('/EdgiOS\/(\d+[\.\d]*)/', $ua, $m)) {
            $browser = 'Edge ' . $m[1] . ' (iOS)';
        } elseif (preg_match('/EdgA\/(\d+[\.\d]*)/', $ua, $m)) {
            $browser = 'Edge ' . $m[1] . ' (Android)';
        } elseif (preg_match('/Edg\/(\d+[\.\d]*)/', $ua, $m)) {
            $browser = 'Edge ' . $m[1];
        } elseif (preg_match('/OPR\/(\d+[\.\d]*)/', $ua, $m) || preg_match('/Opera\/(\d+[\.\d]*)/', $ua, $m)) {
            $browser = 'Opera ' . $m[1];
        } elseif (preg_match('/Firefox\/(\d+[\.\d]*)/', $ua, $m)) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('/Chrome\/(\d+[\.\d]*)/', $ua, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('/Version\/(\d+[\.\d]*)\s.*Safari\//', $ua, $m)) {
            $browser = 'Safari ' . $m[1];
        } elseif (preg_match('/MSIE (\d+[\.\d]*)/', $ua, $m)
            || (strpos($ua, 'Trident/') !== false && preg_match('/rv:(\d+[\.\d]*)/', $ua, $m))) {
            $browser = 'Internet Explorer ' . $m[1];
        }

        if (preg_match('/Windows NT (\d+\.\d+)/', $ua, $m)) {
            // NT 10.0 covers both Windows 10 and 11 - do not pick a precise
            // version from ambiguous metadata.
            $os = $m[1] === '10.0' ? 'Windows 10/11'
                : ($m[1] === '6.3' ? 'Windows 8.1'
                : ($m[1] === '6.2' ? 'Windows 8'
                : ($m[1] === '6.1' ? 'Windows 7' : 'Windows NT ' . $m[1])));
        } elseif (preg_match('/CrOS/', $ua)) {
            $os = 'ChromeOS';
        } elseif (preg_match('/(iPhone|iPad|iPod)/', $ua) && preg_match('/OS (\d+[._\d]*) like Mac OS X/', $ua, $m)) {
            $os = 'iOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Android (\d+[\.\d]*)/', $ua, $m)) {
            $os = 'Android ' . $m[1];
        } elseif (preg_match('/Mac OS X (\d+[._\d]*)/', $ua, $m)) {
            $os = 'macOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Linux/', $ua)) {
            $os = 'Linux';
        }
    }

    return [
        'browser' => $browser === null ? null : substr($browser, 0, 100),
        'os' => $os === null ? null : substr($os, 0, 100),
    ];
}

/**
 * Ensure the users environment columns exist (self-healing, idempotent).
 * Runs at most once per request via the static guard.
 */
function ensureUserEnvironmentColumns(PDO $pdo): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $existing = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $defs = [
            'last_env_browser' => 'VARCHAR(100) DEFAULT NULL',
            'last_env_os' => 'VARCHAR(100) DEFAULT NULL',
            'last_env_seen_at' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($defs as $col => $def) {
            if (!in_array($col, $existing)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            }
        }
        $checked = true;
    } catch (PDOException $e) {
        error_log('[user-environment] Error ensuring columns: ' . $e->getMessage());
        $checked = false;
    }
    return $checked;
}

/**
 * Record the current request's environment for the authenticated user when
 * the throttle permits. Identity always comes from the server-side session -
 * never from client-supplied input.
 *
 * Throttle: a DB write happens only when the UA string differs from the one
 * last recorded in this session (promptly captures a browser/OS change), or
 * when ENV_RECORD_INTERVAL has elapsed since the last write (refreshes the
 * observed-at timestamp). Other requests add no DB traffic.
 */
function maybeRecordUserEnvironment(PDO $pdo, int $userId): void {
    try {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaHash = hash('sha256', $ua);

        $lastHash = $_SESSION['env_last_ua_hash'] ?? null;
        $lastWrite = (int)($_SESSION['env_last_write_at'] ?? 0);

        if ($lastHash === $uaHash && (time() - $lastWrite) < ENV_RECORD_INTERVAL) {
            return;
        }

        if (!ensureUserEnvironmentColumns($pdo)) {
            return;
        }

        $env = parseUserEnvironment($ua);
        $stmt = $pdo->prepare("
            UPDATE users
            SET last_env_browser = :browser,
                last_env_os = :os,
                last_env_seen_at = NOW()
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':browser' => $env['browser'],
            ':os' => $env['os'],
            ':user_id' => $userId,
        ]);

        $_SESSION['env_last_ua_hash'] = $uaHash;
        $_SESSION['env_last_write_at'] = time();
    } catch (Throwable $e) {
        error_log('[user-environment] Error recording environment: ' . $e->getMessage());
    }
}
