<?php
/**
 * Platform announcements.
 *
 * - announcements: platform-admin-authored notices shown below the app
 *   header during a scheduled UTC window to a targeted audience
 *   ('all' users, or 'fabg' = members of practices flagged
 *   practices.fabg_pilot). Content is plain text only; rendering escapes
 *   it, so no HTML is stored or executed.
 * - announcement_dismissals: durable per-user dismissal. One row per
 *   (announcement_id, user_id); survives refresh, logout/login, and
 *   devices because it is keyed by the authenticated user id.
 *
 * Idempotent: safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NULL,
    message TEXT NOT NULL,
    type ENUM('info','warning','critical','success') NOT NULL DEFAULT 'info',
    audience VARCHAR(20) NOT NULL DEFAULT 'all',
    display_start_utc DATETIME NOT NULL,
    display_end_utc DATETIME NOT NULL,
    display_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    published TINYINT(1) NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX ix_ann_window (published, enabled, display_start_utc, display_end_utc),
    INDEX ix_ann_audience (audience)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "announcements ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ann_dismissal (announcement_id, user_id),
    INDEX ix_ann_dismiss_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "announcement_dismissals ready\n";

echo "done\n";
