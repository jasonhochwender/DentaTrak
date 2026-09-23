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
        
        $isPendingPath = strpos($name, '/pending_') !== false;
        // Comment images upload straight into the case's comments/ folder; a
        // file there that no comment row references was orphaned by a failed
        // comment submission and is safe to sweep after the same age cutoff.
        $isCommentPath = strpos($name, '/comments/') !== false;

        if (!$isPendingPath && !$isCommentPath) {
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

            if ($isCommentPath) {
                // Skip files still referenced by a comment's attachments_json.
                $pathParts = explode('/', $name);
                $commentPracticeId = (int)($pathParts[1] ?? 0);
                $referenced = false;
                try {
                    global $pdo;
                    if ($pdo && $commentPracticeId > 0) {
                        $likePath = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $name) . '%';
                        $refStmt = $pdo->prepare(
                            "SELECT 1 FROM case_comments
                             WHERE practice_id = :pid AND attachments_json LIKE :path ESCAPE '\\\\'
                             LIMIT 1"
                        );
                        $refStmt->execute(['pid' => $commentPracticeId, 'path' => $likePath]);
                        $referenced = (bool)$refStmt->fetchColumn();
                    }
                } catch (PDOException $e) {
                    // Table missing or query failed - leave the file in place.
                    $stats['skipped']++;
                    continue;
                }
                if ($referenced) {
                    $stats['skipped']++;
                    continue;
                }
            }

            // File is older than cutoff and unlinked — delete it
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
