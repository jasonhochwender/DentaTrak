<?php
/**
 * GCS Attachments Processor
 * 
 * Processes GCS file metadata submitted with case creation/update.
 * Verifies that uploaded files actually exist in the bucket and
 * converts them into the standard attachment format used by the app.
 * 
 * This replaces the old direct $_FILES upload processing for case attachments.
 */

require_once __DIR__ . '/gcs-storage.php';

/**
 * Process GCS file metadata from the frontend and verify uploads
 * 
 * The frontend sends an array of objects like:
 * [
 *   {
 *     "storage_path": "cases/1/pending_5_20260302/intraoralScans/abc123-scan.stl",
 *     "original_filename": "scan.stl",
 *     "content_type": "application/octet-stream",
 *     "file_size": 52428800,
 *     "upload_type": "intraoralScans"
 *   }
 * ]
 * 
 * @param string $gcsFilesJson JSON string of GCS file metadata from frontend
 * @param string $practiceId   The practice ID (for path validation)
 * @return array ['success' => bool, 'attachments' => array, 'errors' => array]
 */
function processGcsAttachments($gcsFilesJson, $practiceId) {
    global $appConfig;
    
    $result = [
        'success' => true,
        'attachments' => [],
        'errors' => [],
    ];
    
    if (empty($gcsFilesJson)) {
        return $result;
    }
    
    $gcsFiles = json_decode($gcsFilesJson, true);
    if (!is_array($gcsFiles) || empty($gcsFiles)) {
        return $result;
    }
    
    // --- Aggregate limits ---
    $maxTotalSize = $appConfig['gcs']['max_total_size'] ?? (1024 * 1024 * 1024); // 1 GB
    $maxFileCount = $appConfig['gcs']['max_file_count'] ?? 15;
    $sizeByType   = $appConfig['gcs']['max_file_size_by_type'] ?? [];
    $allowedMimeTypes = $appConfig['gcs']['allowed_mime_types'] ?? [];
    
    // Enforce file count
    if (count($gcsFiles) > $maxFileCount) {
        $result['success'] = false;
        $result['errors'][] = "Maximum {$maxFileCount} files per case. You submitted " . count($gcsFiles) . ".";
        return $result;
    }
    
    $runningTotalSize = 0;
    
    foreach ($gcsFiles as $index => $fileInfo) {
        $storagePath   = $fileInfo['storage_path'] ?? '';
        $originalName  = $fileInfo['original_filename'] ?? '';
        $contentType   = $fileInfo['content_type'] ?? '';
        $fileSize      = (int)($fileInfo['file_size'] ?? 0);
        $uploadType    = $fileInfo['upload_type'] ?? 'photos';
        
        // Basic validation
        if (empty($storagePath) || empty($originalName)) {
            $result['errors'][] = "File #{$index}: missing storage path or filename";
            $result['success'] = false;
            continue;
        }
        
        // Validate storage path belongs to this practice
        $expectedPrefix = "cases/{$practiceId}/";
        if (strpos($storagePath, $expectedPrefix) !== 0) {
            $result['errors'][] = "{$originalName}: invalid storage path (access denied)";
            $result['success'] = false;
            continue;
        }
        
        // Verify file exists in GCS and matches expectations
        $verification = verifyGcsUpload($storagePath, $fileSize, $contentType);
        
        if (!$verification['valid']) {
            $result['errors'][] = "{$originalName}: {$verification['error']}";
            $result['success'] = false;
            continue;
        }
        
        $actualSize = $verification['size'];
        
        // --- Type-specific size enforcement (server-side, do NOT trust frontend) ---
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $maxForType = $sizeByType[$ext] ?? ($sizeByType['default'] ?? (100 * 1024 * 1024));
        if ($actualSize > $maxForType) {
            $limitMB = round($maxForType / 1024 / 1024);
            $actualMB = round($actualSize / 1024 / 1024, 1);
            $result['errors'][] = "{$originalName}: exceeds {$limitMB}MB limit for .{$ext} files ({$actualMB}MB)";
            $result['success'] = false;
            continue;
        }
        
        // --- Accumulate total ---
        $runningTotalSize += $actualSize;
        
        // Build standard attachment object (compatible with existing case data format)
        $result['attachments'][] = [
            'id' => uniqid(),
            'type' => ucfirst($uploadType),
            'fileName' => $originalName,
            'name' => $originalName,
            'fileType' => $contentType,
            'mimeType' => $contentType,
            'size' => $actualSize,
            'storagePath' => $storagePath,
            'storageType' => 'gcs',
            'uploadedAt' => date('c'),
        ];
    }
    
    // --- Enforce total size across all files ---
    if ($runningTotalSize > $maxTotalSize) {
        $result['success'] = false;
        $totalMB = round($runningTotalSize / 1024 / 1024, 1);
        $limitMB = round($maxTotalSize / 1024 / 1024);
        $result['errors'][] = "Total upload size ({$totalMB}MB) exceeds the {$limitMB}MB limit.";
    }
    
    return $result;
}

/**
 * Move GCS files from a pending path to a final case path
 * Used when a "new" case gets its final case ID after creation.
 * 
 * @param array  $attachments Array of attachment objects with storagePath
 * @param string $practiceId  Practice ID
 * @param string $caseId      The final case ID
 * @return array Updated attachments with new storage paths
 */
function finalizeGcsAttachmentPaths($attachments, $practiceId, $caseId) {
    $updated = [];
    $bucket = getGcsBucket();
    
    foreach ($attachments as $attachment) {
        if (($attachment['storageType'] ?? '') !== 'gcs') {
            $updated[] = $attachment;
            continue;
        }
        
        $currentPath = $attachment['storagePath'] ?? '';
        
        // Check if path contains "pending_" prefix that needs updating
        if (strpos($currentPath, '/pending_') !== false) {
            // Build new path with actual case ID
            $uploadType = strtolower($attachment['type'] ?? 'photos');
            // Map display type back to upload type
            $typeMap = [
                'photos' => 'photos',
                'intraoralscans' => 'intraoralScans',
                'facialscans' => 'facialScans',
                'photogrammetry' => 'photogrammetry',
                'completeddesigns' => 'completedDesigns',
            ];
            $uploadType = $typeMap[$uploadType] ?? $uploadType;
            
            // Extract the UUID-filename portion from the current path
            $pathParts = explode('/', $currentPath);
            $fileName = end($pathParts);
            
            $newPath = "cases/{$practiceId}/{$caseId}/{$uploadType}/{$fileName}";
            
            try {
                $sourceObject = $bucket->object($currentPath);
                if ($sourceObject->exists()) {
                    $sourceObject->copy($bucket, ['name' => $newPath]);
                    $sourceObject->delete();
                    $attachment['storagePath'] = $newPath;
                }
            } catch (Exception $e) {
                error_log("[GCS] Failed to move {$currentPath} to {$newPath}: " . $e->getMessage());
                // Keep the original path if move fails - file is still accessible
            }
        }
        
        $updated[] = $attachment;
    }
    
    return $updated;
}

/**
 * Delete GCS attachments for a case
 * 
 * @param array $attachments Array of attachment objects
 * @return int Number of files deleted
 */
function deleteGcsAttachments($attachments) {
    $deleted = 0;
    
    foreach ($attachments as $attachment) {
        if (($attachment['storageType'] ?? '') === 'gcs' && !empty($attachment['storagePath'])) {
            if (deleteGcsObject($attachment['storagePath'])) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}
