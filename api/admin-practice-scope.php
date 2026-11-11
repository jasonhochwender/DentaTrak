<?php
/**
 * Shared Platform Administration practice-scope helpers.
 *
 * "Hidden" practices are a per-administrator view preference stored in
 * admin_hidden_practices - NOT a practice status, and unrelated to
 * inactive/demo/test classifications. Reporting endpoints default to the
 * non-hidden ("visible") population and may opt into including hidden
 * rows explicitly via an include_hidden parameter.
 */

if (!function_exists('ensureAdminHiddenPracticesSchema')) {
    /**
     * Ensure the admin_hidden_practices table exists.
     */
    function ensureAdminHiddenPracticesSchema() {
        global $pdo;
        static $initialized = false;

        if ($initialized || !$pdo || $pdo->inTransaction()) {
            return;
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_hidden_practices (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_user_id BIGINT UNSIGNED NOT NULL,
                practice_id BIGINT UNSIGNED NOT NULL,
                hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_user_id (admin_user_id),
                INDEX idx_practice_id (practice_id),
                UNIQUE KEY idx_admin_practice (admin_user_id, practice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $initialized = true;
        } catch (PDOException $e) {
            error_log('[admin-practice-scope] Error ensuring admin hidden practices schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getAdminHiddenPracticeIds')) {
    /**
     * Get the practice IDs hidden by the given admin.
     */
    function getAdminHiddenPracticeIds($adminUserId) {
        global $pdo;

        if (!$pdo || !$adminUserId) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT practice_id FROM admin_hidden_practices WHERE admin_user_id = ?");
            $stmt->execute([$adminUserId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log('[admin-practice-scope] Error getting hidden practice IDs: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('hidePracticeForAdmin')) {
    /**
     * Hide a practice from the admin's view.
     */
    function hidePracticeForAdmin($practiceId, $adminUserId) {
        global $pdo;

        if (!$pdo || !$practiceId || !$adminUserId || !is_numeric($practiceId)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO admin_hidden_practices (admin_user_id, practice_id, hidden_at) VALUES (?, ?, NOW())");
            return $stmt->execute([$adminUserId, $practiceId]);
        } catch (PDOException $e) {
            error_log('[admin-practice-scope] Error hiding practice: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('unhidePracticeForAdmin')) {
    /**
     * Unhide a practice from the admin's view.
     */
    function unhidePracticeForAdmin($practiceId, $adminUserId) {
        global $pdo;

        if (!$pdo || !$practiceId || !$adminUserId || !is_numeric($practiceId)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM admin_hidden_practices WHERE admin_user_id = ? AND practice_id = ?");
            return $stmt->execute([$adminUserId, $practiceId]);
        } catch (PDOException $e) {
            error_log('[admin-practice-scope] Error unhiding practice: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('paIncludeHiddenParam')) {
    /**
     * Parse the include_hidden request parameter. Only explicit truthy
     * values widen the scope; anything else keeps the visible default.
     */
    function paIncludeHiddenParam($raw): bool {
        $v = strtolower(trim((string)$raw));
        return $v === '1' || $v === 'true' || $v === 'yes';
    }
}

if (!function_exists('paPracticeScopeSql')) {
    /**
     * Build a SQL WHERE fragment restricting a practice-id expression to
     * the caller's visible (non-hidden) practices.
     *
     * @param string $practiceIdExpr  SQL expression yielding a practice id
     *                                (e.g. 'p.id', 'c.practice_id').
     * @param bool   $includeHidden   When true, returns no restriction.
     * @param int    $adminUserId     Session admin user id.
     * @return array{0:string,1:array} [sqlFragment, params]
     */
    function paPracticeScopeSql(string $practiceIdExpr, bool $includeHidden, $adminUserId): array {
        if ($includeHidden) {
            return ['', []];
        }
        ensureAdminHiddenPracticesSchema();
        return [
            " AND {$practiceIdExpr} NOT IN (SELECT practice_id FROM admin_hidden_practices WHERE admin_user_id = ?)",
            [(int)$adminUserId],
        ];
    }
}

if (!function_exists('paPracticeScopeCounts')) {
    /**
     * Visible/hidden practice counts for the current admin - used for the
     * "Showing X visible practices" scope indicator.
     *
     * @return array{visible:int, hidden:int, total:int}
     */
    function paPracticeScopeCounts($pdo, $adminUserId): array {
        $counts = ['visible' => 0, 'hidden' => 0, 'total' => 0];
        try {
            ensureAdminHiddenPracticesSchema();
            $total = (int)$pdo->query("SELECT COUNT(*) FROM practices")->fetchColumn();
            $hidden = count(getAdminHiddenPracticeIds($adminUserId));
            // Hidden ids may reference deleted practices; clamp to real rows.
            if ($hidden > 0) {
                $ids = getAdminHiddenPracticeIds($adminUserId);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM practices WHERE id IN ($ph)");
                $stmt->execute($ids);
                $hidden = (int)$stmt->fetchColumn();
            }
            $counts['total'] = $total;
            $counts['hidden'] = $hidden;
            $counts['visible'] = max(0, $total - $hidden);
        } catch (Throwable $e) {
            error_log('[admin-practice-scope] scope counts failed: ' . $e->getMessage());
        }
        return $counts;
    }
}

if (!function_exists('paOwnerUserIdsForScope')) {
    /**
     * Distinct owner user ids that own at least one practice inside the
     * requested scope. Used to scope owner-level subscriptions without
     * double-counting shared subscriptions.
     *
     * @return array<int>
     */
    function paOwnerUserIdsForScope($pdo, bool $includeHidden, $adminUserId): array {
        try {
            [$frag, $params] = paPracticeScopeSql('p.id', $includeHidden, $adminUserId);
            $sql = "SELECT DISTINCT pu.user_id
                    FROM practice_users pu
                    JOIN practices p ON p.id = pu.practice_id
                    WHERE pu.is_owner = 1" . $frag;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('[admin-practice-scope] owner scope failed: ' . $e->getMessage());
            return [];
        }
    }
}
