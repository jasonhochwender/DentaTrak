<?php
/**
 * AI recommendation persistence + lifecycle store.
 *
 * Tables (lazy-ensured, transaction-safe per repo convention):
 *   ai_recommendation_generations - one row per produced generation
 *     (payload snapshot + data signature + locale + source/stale info)
 *   ai_recommendation_states      - per-(practice, rec_key) lifecycle state
 *     (new|reviewed|dismissed) plus a content snapshot so resolved or
 *     rotated-out recommendations can still be displayed under All/Resolved.
 *
 * rec_key = category + normalized title slug: stable across refreshes for
 * the same underlying issue, changes when the AI materially rewords it.
 * data_signature = md5(analytics payload + locale): a dismissed rec only
 * stays suppressed while the underlying practice data is unchanged.
 */

if (!function_exists('aiRecEnsureSchema')) {
    function aiRecEnsureSchema(PDO $pdo): void {
        static $ensured = false;
        if ($ensured) return;
        // Never run DDL inside a caller's transaction (implicit commit).
        if ($pdo->inTransaction()) return;
        $ensured = true;
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_recommendation_generations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            practice_id INT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL DEFAULT 'en-US',
            provider VARCHAR(20) NOT NULL DEFAULT 'gemini',
            data_signature CHAR(32) NOT NULL,
            payload_json MEDIUMTEXT NOT NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'ai',
            error_code VARCHAR(50) DEFAULT NULL,
            generated_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_practice_locale (practice_id, locale, generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_recommendation_states (
            practice_id INT UNSIGNED NOT NULL,
            rec_key VARCHAR(160) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'new',
            data_signature CHAR(32) DEFAULT NULL,
            snapshot_json TEXT DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (practice_id, rec_key),
            INDEX idx_practice_status (practice_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('aiRecSlug')) {
    function aiRecSlug(string $title): string {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', ' ', $slug);
        $slug = trim(preg_replace('/\s+/', ' ', $slug));
        return substr($slug, 0, 80);
    }
}

if (!function_exists('aiRecKey')) {
    function aiRecKey(string $category, string $title): string {
        return substr($category . '|' . aiRecSlug($title), 0, 160);
    }
}

if (!function_exists('aiRecSignature')) {
    function aiRecSignature(array $analyticsData, string $locale): string {
        return md5(json_encode($analyticsData) . '|' . $locale);
    }
}

if (!function_exists('aiRecNormalize')) {
    /**
     * Normalize raw AI output into stored recommendation records.
     * fact_keys are validated against the deterministic facts we sent;
     * action_target is whitelisted to known board destinations.
     */
    function aiRecNormalize(array $rawRecs, array $validFactKeys): array {
        $out = [];
        $allowedActions = ['overdue', 'appt_risk', 'due_soon', 'unassigned',
            'unclassified', 'lab_ack', 'attention', 'none'];
        foreach ($rawRecs as $rec) {
            if (!isset($rec['title']) || !isset($rec['description'])) continue;
            $title = strip_tags(trim($rec['title']));
            $desc = strip_tags(trim($rec['description']));
            if ($title === '' || $desc === '') continue;
            $category = in_array($rec['category'] ?? '', ['efficiency', 'quality', 'scheduling', 'workload', 'communication'], true)
                ? $rec['category'] : 'efficiency';
            $priority = in_array($rec['priority'] ?? '', ['high', 'medium', 'low'], true)
                ? $rec['priority'] : 'medium';
            $factKeys = [];
            foreach ((array)($rec['fact_keys'] ?? []) as $fk) {
                if (in_array($fk, $validFactKeys, true) && !in_array($fk, $factKeys, true)) {
                    $factKeys[] = $fk;
                }
            }
            $action = in_array($rec['action_target'] ?? '', $allowedActions, true)
                ? $rec['action_target'] : 'none';
            $out[] = [
                'rec_key' => aiRecKey($category, $title),
                'title' => $title,
                'description' => $desc,
                'priority' => $priority,
                'category' => $category,
                'fact_keys' => $factKeys,
                'action_target' => $action,
            ];
            if (count($out) >= 5) break;
        }
        return $out;
    }
}

if (!function_exists('aiRecSaveGeneration')) {
    function aiRecSaveGeneration(PDO $pdo, int $practiceId, string $locale, string $provider,
                                  string $signature, array $payload, string $source, ?string $errorCode): void {
        aiRecEnsureSchema($pdo);
        $stmt = $pdo->prepare("INSERT INTO ai_recommendation_generations
            (practice_id, locale, provider, data_signature, payload_json, source, error_code, generated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$practiceId, $locale, $provider, $signature,
            json_encode($payload, JSON_UNESCAPED_UNICODE), $source, $errorCode]);
    }
}

if (!function_exists('aiRecLatestGeneration')) {
    function aiRecLatestGeneration(PDO $pdo, int $practiceId, string $locale): ?array {
        aiRecEnsureSchema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM ai_recommendation_generations
            WHERE practice_id = ? AND locale = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$practiceId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['payload'] = json_decode($row['payload_json'], true) ?: [];
        return $row;
    }
}

if (!function_exists('aiRecLoadStates')) {
    function aiRecLoadStates(PDO $pdo, int $practiceId): array {
        aiRecEnsureSchema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM ai_recommendation_states WHERE practice_id = ?");
        $stmt->execute([$practiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['snapshot'] = $row['snapshot_json'] ? (json_decode($row['snapshot_json'], true) ?: null) : null;
            $out[$row['rec_key']] = $row;
        }
        return $out;
    }
}

if (!function_exists('aiRecSetState')) {
    function aiRecSetState(PDO $pdo, int $practiceId, string $recKey, string $state,
                           ?int $userId, ?array $snapshot = null): void {
        aiRecEnsureSchema($pdo);
        $allowed = ['new', 'reviewed', 'dismissed', 'resolved'];
        if (!in_array($state, $allowed, true)) return;
        $stmt = $pdo->prepare("INSERT INTO ai_recommendation_states
            (practice_id, rec_key, status, snapshot_json, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status),
                snapshot_json = COALESCE(VALUES(snapshot_json), snapshot_json),
                updated_by = VALUES(updated_by)");
        $stmt->execute([$practiceId, substr($recKey, 0, 160), $state,
            $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null, $userId]);
    }
}

if (!function_exists('aiRecMerge')) {
    /**
     * Merge a generation payload with lifecycle states.
     *
     * Returns ['active' => [...], 'resolved' => [...]] where each item is a
     * recommendation record + 'state' + 'generated_at'.
     *
     * Rules:
     *  - rec in generation: state = stored status, or 'new'. A dismissed rec
     *    whose data signature changed is reopened as 'new' (meaningful change).
     *  - state row absent from generation AND signature changed => 'resolved'
     *    (issue verifiably no longer applies); snapshot supplies content.
     *  - state row absent from generation AND signature unchanged => omitted
     *    (AI simply did not repeat it; not evidence of resolution).
     */
    function aiRecMerge(PDO $pdo, int $practiceId, string $signature, array $payload): array {
        $states = aiRecLoadStates($pdo, $practiceId);
        $active = [];
        $seen = [];

        foreach ($payload as $rec) {
            $key = $rec['rec_key'];
            $seen[$key] = true;
            $st = $states[$key] ?? null;
            $status = $st['status'] ?? 'new';
            if ($status === 'dismissed' && $st && $st['data_signature'] !== $signature) {
                // Underlying data changed since dismissal - reopen.
                $status = 'new';
                aiRecSetState($pdo, $practiceId, $key, 'new', null, $rec);
                if (isset($states[$key])) $states[$key]['status'] = 'new';
            }
            if ($st && $st['data_signature'] !== $signature) {
                // Refresh signature + snapshot so future comparisons are current.
                $upd = $pdo->prepare("UPDATE ai_recommendation_states SET data_signature = ?, snapshot_json = ? WHERE practice_id = ? AND rec_key = ?");
                $upd->execute([$signature, json_encode($rec, JSON_UNESCAPED_UNICODE), $practiceId, $key]);
                $st['data_signature'] = $signature;
                $st['snapshot'] = $rec;
            }
            $rec['state'] = $status;
            $active[] = $rec;
        }

        $resolved = [];
        foreach ($states as $key => $st) {
            if (isset($seen[$key])) continue;
            if ($st['status'] === 'resolved') {
                if ($st['snapshot']) {
                    $snap = $st['snapshot'];
                    $snap['state'] = 'resolved';
                    $resolved[] = $snap;
                }
                continue;
            }
            // Absent from the newest generation + data changed => resolved.
            if ($st['data_signature'] !== null && $st['data_signature'] !== $signature) {
                aiRecSetState($pdo, $practiceId, $key, 'resolved', null);
                if ($st['snapshot']) {
                    $snap = $st['snapshot'];
                    $snap['state'] = 'resolved';
                    $resolved[] = $snap;
                }
            }
        }

        return ['active' => $active, 'resolved' => $resolved];
    }
}
