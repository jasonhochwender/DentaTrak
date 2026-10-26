<?php
/**
 * Incoming Scans permission migration
 *
 * Adds practice_users.can_view_incoming_scans - a per-membership grant
 * controlling access to the practice-wide Incoming Scans inbox:
 *   - practice admins get effective access automatically (computed; the
 *     column stays 0 and is never required for them)
 *   - regular members default OFF (0) until a practice admin grants it
 *   - lab collaborators / assigned-only (limited_visibility) users can
 *     never hold the grant - role transitions clear it
 *
 * Idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

function runIncomingScansPermissionMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS practice_users_scan_col_probe (id INT)");
        $pdo->exec("DROP TABLE IF EXISTS practice_users_scan_col_probe");
        $col = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'can_view_incoming_scans'");
        if (!$col->fetch()) {
            $pdo->exec("
                ALTER TABLE practice_users
                ADD COLUMN can_view_incoming_scans TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Incoming Scans inbox permission (admins implied; lab/assigned-only cannot hold)'
            ");
            $performed[] = 'Added practice_users.can_view_incoming_scans';
        }
        // Role-consistency sweep: lab and limited-visibility members can
        // never hold the grant.
        $pdo->exec("
            UPDATE practice_users
            SET can_view_incoming_scans = 0
            WHERE is_lab = 1 OR limited_visibility = 1
        ");
        $performed[] = 'Cleared grants for lab/assigned-only members';
    } catch (Throwable $e) {
        $errors[] = 'can_view_incoming_scans: ' . $e->getMessage();
    }

    return ['success' => empty($errors), 'performed' => $performed, 'errors' => $errors];
}
