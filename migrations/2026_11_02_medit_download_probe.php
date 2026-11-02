<?php
/**
 * One-off diagnostic: compare ?type=stl vs original download for the
 * ATTACHED_DATA file on case 80e02fc7 (practice 32). Prints descriptor
 * item names/sizes/hash encoding + content magic bytes only - never
 * URLs, tokens, or patient data.
 */
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/MeditConfig.php';
require_once __DIR__ . '/../api/integrations/medit/MeditClient.php';
require_once __DIR__ . '/../api/integrations/medit/MeditAuthService.php';
require_once __DIR__ . '/../api/integrations/medit/MeditScanConnector.php';

global $pdo;
$CASE = '80e02fc7-bb63-46dd-98f4-f0bc803d2e2a';
$conn = $pdo->query("SELECT id FROM integration_connections WHERE practice_id=32 AND provider='medit'")->fetch(PDO::FETCH_ASSOC);
$client = new MeditClient($pdo, (int) $conn['id']);
$detail = $client->getCase($CASE);
$f = $detail['files'][0] ?? [];
$fileUuid = $f['uuid'] ?? '';
echo "file: {$f['fileType']} {$f['name']} uuid=" . substr($fileUuid, 0, 8) . "…\n";

foreach (['stl' => 'stl', 'orig' => null] as $label => $type) {
    try {
        $d = $client->getFileDownload($fileUuid, $type);
        echo "[$label] downloadFileName=" . ($d['downloadFileName'] ?? '?') . "\n";
        foreach (($d['items'] ?? []) as $it) {
            $hv = (string) ($it['hashValue'] ?? '');
            $enc = preg_match('/^[0-9a-f]{32}$/i', $hv) ? 'hex' : (base64_decode($hv, true) !== false ? 'b64' : '?');
            echo "  item: {$it['name']} size={$it['size']} alg=" . ($it['hashAlgorithm'] ?? '?') . " hash_enc=$enc hash=$hv\n";
        }
        $url = $d['url'] ?? '';
        if ($url) {
            $body = @file_get_contents($url);
            $is7z = substr($body, 0, 6) === hex2bin('377abcaf271c');
            echo "  body: " . strlen($body) . "B magic=" . bin2hex(substr($body, 0, 6)) . ($is7z ? ' (7z)' : '') . "\n";
            if ($is7z) {
                $tmp = tempnam(sys_get_temp_dir(), 'dz') . '.7z';
                file_put_contents($tmp, $body);
                $out = sys_get_temp_dir() . '/dzx_' . uniqid();
                mkdir($out);
                exec('7z x ' . escapeshellarg($tmp) . ' -o' . escapeshellarg($out) . ' -y 2>&1');
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $e) {
                    if ($e->isFile()) {
                        $c = file_get_contents($e->getPathname());
                        echo "  entry: {$e->getFilename()} size=" . strlen($c) . " md5=" . md5($c) . "\n";
                    }
                }
            }
        }
    } catch (Throwable $e) {
        echo "[$label] ERR: " . get_class($e) . " " . $e->getMessage() . "\n";
    }
}
