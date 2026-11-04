<?php
/** Global per-client Medit API throttle slots. Idempotent. */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$pdo->exec("CREATE TABLE IF NOT EXISTS medit_api_throttle (
    scope_key VARCHAR(80) NOT NULL,
    next_at DATETIME(6) NOT NULL,
    PRIMARY KEY (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['search', 'other'] as $k) {
    $pdo->exec("INSERT IGNORE INTO medit_api_throttle (scope_key, next_at) VALUES ('$k', NOW(6))");
}
echo "medit_api_throttle ready\n";
