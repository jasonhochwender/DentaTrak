<?php
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$pdo->exec("ALTER TABLE marketing_sends MODIFY status ENUM('running','sent','skipped_suppressed','failed') NOT NULL");
echo "status enum updated\n";
