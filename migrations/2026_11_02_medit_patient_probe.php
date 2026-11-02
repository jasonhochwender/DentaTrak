<?php
/** Probe: does a patient resource endpoint expose gender/DOB? Synthetic case 80e02fc7 (patient.code=999). */
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/MeditConfig.php';
require_once __DIR__ . '/../api/integrations/medit/MeditClient.php';
require_once __DIR__ . '/../api/integrations/medit/MeditAuthService.php';

global $pdo;
$conn = $pdo->query("SELECT id FROM integration_connections WHERE practice_id=32 AND provider='medit'")->fetch(PDO::FETCH_ASSOC);
$client = new MeditClient($pdo, (int) $conn['id']);
$m = new ReflectionMethod($client, 'apiGetJson');
$m->setAccessible(true);

$paths = [
    '/v1/patients'            => ['size' => 5],
    '/v1/patients/search'     => ['code' => '999'],
    '/v1/patients/999'        => [],
    '/v1/patients?code=999'   => null, // raw path
];
foreach ($paths as $path => $q) {
    try {
        $real = $q === null ? $path : $path;
        $res = $m->invoke($client, $real, is_array($q) ? $q : [], false);
        echo "$path => OK keys: " . implode(',', array_keys(is_array($res) ? $res : [])) . "\n";
        if (isset($res['content']) || isset($res['items']) || isset($res['patients'])) {
            $list = $res['content'] ?? $res['items'] ?? $res['patients'];
            echo "  first-entry keys: " . implode(',', array_keys($list[0] ?? [])) . "\n";
        } elseif (isset($res['uuid'])) {
            echo "  entry keys: " . implode(',', array_keys($res)) . "\n";
        }
    } catch (Throwable $e) {
        echo "$path => " . get_class($e) . " " . substr($e->getMessage(), 0, 90) . "\n";
    }
}
