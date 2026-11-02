<?php
/**
 * Diagnostic: dump the synthetic Medit case detail's structure to map
 * available patient/treatment fields. Synthetic case only (MEDIT TEST).
 */
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/MeditConfig.php';
require_once __DIR__ . '/../api/integrations/medit/MeditClient.php';
require_once __DIR__ . '/../api/integrations/medit/MeditAuthService.php';

global $pdo;
$conn = $pdo->query("SELECT id FROM integration_connections WHERE practice_id=32 AND provider='medit'")->fetch(PDO::FETCH_ASSOC);
$client = new MeditClient($pdo, (int) $conn['id']);
$d = $client->getCase('80e02fc7-bb63-46dd-98f4-f0bc803d2e2a');

function dump($v, $depth = 0, $prefix = '') {
    $pad = str_repeat('  ', $depth);
    if (!is_array($v)) {
        $s = is_string($v) ? (strlen($v) > 60 ? substr($v, 0, 60) . '…' : $v) : json_encode($v);
        echo $pad . $prefix . $s . "\n";
        return;
    }
    if ($depth > 4) { echo $pad . $prefix . '[deep]\n'; return; }
    foreach ($v as $k => $item) {
        if (is_array($item)) {
            echo $pad . $k . ": " . (is_array($item) && array_keys($item) === range(0, count($item) - 1) ? 'list[' . count($item) . ']' : 'obj') . "\n";
            dump($item, $depth + 1);
        } else {
            dump($item, $depth, $k . ' = ');
        }
    }
}
echo "TOP KEYS: " . implode(', ', array_keys($d)) . "\n";
dump($d);
