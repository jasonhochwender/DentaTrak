<?php
/**
 * Orphan GCS Upload Cleanup
 * 
 * Deletes GCS objects under "pending_" paths that are older than 24 hours
 * and not linked to any case in the database. This handles the scenario
 * where a user uploads files but never completes case creation.
 * 
 * Run as a scheduled job (Cloud Scheduler -> Cloud Run):
 *   POST /api/cleanup-orphan-uploads.php
 *   Header: X-Cleanup-Key: <secret>
 * 
 * Or run manually via CLI:
 *   php api/cleanup-orphan-uploads.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/gcs-storage.php';

// Allow CLI execution or authenticated HTTP request
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
    
    // Validate cleanup key for HTTP requests (prevents unauthorized access)
    $expectedKey = getEnvVar('CLEANUP_SECRET_KEY');
    $providedKey = $_SERVER['HTTP_X_CLEANUP_KEY'] ?? '';
    
    if ($expectedKey && $providedKey !== $expectedKey) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

$maxAgeHours = 24;
$maxAgeSeconds = $maxAgeHours * 3600;
$cutoffTime = time() - $maxAgeSeconds;

$stats = [
    'scanned' => 0,
    'deleted' => 0,
    'errors' => 0,
    'skipped' => 0,
];

$log = function($msg) use ($isCli) {
    if ($isCli) {
        echo $msg . "\n";
    }
    error_log('[OrphanCleanup] ' . $msg);
};

try {
    $bucket = getGcsBucket();
    
    // List all objects under the cases/ prefix
    $objects = $bucket->objects(['prefix' => 'cases/']);
    
    foreach ($objects as $object) {
        $stats['scanned']++;
        $name = $object->name();
        
        // Only clean up "pending_" paths (files uploaded but case never created)
        if (strpos($name, '/pending_') === false) {
            $stats['skipped']++;
            continue;
        }
        
        try {
            $info = $object->info();
            $createdTime = strtotime($info['timeCreated'] ?? '');
            
            if (!$createdTime || $createdTime >= $cutoffTime) {
                // File is too new, skip
                $stats['skipped']++;
                continue;
            }
            
            // File is older than cutoff and in a pending path — delete it
            $object->delete();
            $stats['deleted']++;
            $log("Deleted orphan: {$name} (created: " . date('Y-m-d H:i:s', $createdTime) . ")");
            
        } catch (Exception $e) {
            $stats['errors']++;
            $log("Error processing {$name}: " . $e->getMessage());
        }
    }
    
    $log("Cleanup complete. Scanned: {$stats['scanned']}, Deleted: {$stats['deleted']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");
    
    if (!$isCli) {
        echo json_encode([
            'success' => true,
            'stats' => $stats,
        ]);
    }

} catch (Exception $e) {
    $log("Cleanup failed: " . $e->getMessage());
    
    if (!$isCli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Cleanup failed: ' . $e->getMessage()]);
    } else {
        exit(1);
    }
}
