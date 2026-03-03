<?php
/**
 * Google Cloud Storage Helper
 * 
 * Provides GCS client initialization and utility functions for
 * generating signed upload/download URLs and verifying uploaded objects.
 * 
 * On Cloud Run: Uses Application Default Credentials (ADC) automatically.
 * On Local Dev: Uses service account key file specified in GCS_KEY_FILE env var.
 */

require_once __DIR__ . '/appConfig.php';

use Google\Cloud\Storage\StorageClient;

/**
 * Get a configured GCS StorageClient instance
 * @return StorageClient
 */
function getGcsClient() {
    global $appConfig;

    $options = [];

    // Local dev: use explicit key file if configured
    $keyFile = $appConfig['gcs']['key_file'] ?? null;
    if ($keyFile && file_exists($keyFile)) {
        $options['keyFilePath'] = $keyFile;
    }
    // Cloud Run: ADC is used automatically when no keyFile is provided

    return new StorageClient($options);
}

/**
 * Get the configured GCS bucket
 * @return \Google\Cloud\Storage\Bucket
 */
function getGcsBucket() {
    global $appConfig;
    static $loggedBucket = false;

    $bucketName = $appConfig['gcs']['bucket_name'] ?? '';
    if (empty($bucketName)) {
        $msg = 'GCS_BUCKET_NAME environment variable is not set. File uploads require a configured bucket.';
        error_log('[GCS] FATAL: ' . $msg);
        throw new RuntimeException($msg);
    }

    if (!$loggedBucket) {
        error_log('[GCS] Using bucket: ' . $bucketName);
        $loggedBucket = true;
    }

    $client = getGcsClient();
    return $client->bucket($bucketName);
}

/**
 * Generate a signed PUT URL for direct browser upload to GCS
 *
 * @param string $objectPath  The full object path in the bucket (e.g., cases/{case_id}/{uuid}-file.stl)
 * @param string $contentType The MIME type the client will upload
 * @param int    $expiry      URL expiry in seconds (default from config)
 * @return array ['signed_url' => string, 'storage_path' => string, 'expires_at' => string]
 */
function generateSignedUploadUrl($objectPath, $contentType, $expiry = null) {
    global $appConfig;

    $expiry = $expiry ?? ($appConfig['gcs']['signed_url_expiry'] ?? 900);
    $bucket = getGcsBucket();
    $object = $bucket->object($objectPath);

    $url = $object->signedUrl(
        new \DateTime('+' . $expiry . ' seconds'),
        [
            'method' => 'PUT',
            'contentType' => $contentType,
            'version' => 'v4',
        ]
    );

    return [
        'signed_url'   => $url,
        'storage_path' => $objectPath,
        'expires_at'   => date('c', time() + $expiry),
    ];
}

/**
 * Generate a signed GET URL for secure file download
 *
 * @param string $objectPath The full object path in the bucket
 * @param int    $expiry     URL expiry in seconds (default from config)
 * @return string The signed download URL
 */
function generateSignedDownloadUrl($objectPath, $expiry = null) {
    global $appConfig;

    $expiry = $expiry ?? ($appConfig['gcs']['download_url_expiry'] ?? 300);
    $bucket = getGcsBucket();
    $object = $bucket->object($objectPath);

    return $object->signedUrl(
        new \DateTime('+' . $expiry . ' seconds'),
        [
            'method' => 'GET',
            'version' => 'v4',
        ]
    );
}

/**
 * Verify that an uploaded object exists in GCS and matches expectations
 *
 * @param string $objectPath    The expected object path
 * @param int    $expectedSize  The expected file size in bytes (0 to skip check)
 * @param string $expectedType  The expected MIME type (empty to skip check)
 * @return array ['valid' => bool, 'error' => string|null, 'size' => int, 'content_type' => string]
 */
function verifyGcsUpload($objectPath, $expectedSize = 0, $expectedType = '') {
    global $appConfig;

    try {
        $bucket = getGcsBucket();
        $object = $bucket->object($objectPath);

        if (!$object->exists()) {
            return ['valid' => false, 'error' => 'File not found in storage', 'size' => 0, 'content_type' => ''];
        }

        $info = $object->info();
        $actualSize = (int)($info['size'] ?? 0);
        $actualType = $info['contentType'] ?? '';

        // Validate file size if expected
        if ($expectedSize > 0 && $actualSize !== $expectedSize) {
            return [
                'valid' => false,
                'error' => "File size mismatch: expected {$expectedSize} bytes, got {$actualSize} bytes",
                'size' => $actualSize,
                'content_type' => $actualType,
            ];
        }

        // Type-specific size limits are enforced in processGcsAttachments()
        // which has access to the filename/extension. Here we only apply
        // the absolute ceiling (max_file_size) as a safety net.
        $absoluteMax = $appConfig['gcs']['max_file_size'] ?? (250 * 1024 * 1024);
        if ($actualSize > $absoluteMax) {
            return [
                'valid' => false,
                'error' => 'File exceeds maximum allowed size of ' . round($absoluteMax / 1024 / 1024) . 'MB',
                'size' => $actualSize,
                'content_type' => $actualType,
            ];
        }

        // Validate MIME type if expected
        if ($expectedType && $actualType !== $expectedType) {
            // Allow application/octet-stream as fallback for binary files
            $allowedTypes = $appConfig['gcs']['allowed_mime_types'] ?? [];
            if (!in_array($actualType, $allowedTypes)) {
                return [
                    'valid' => false,
                    'error' => "Invalid file type: {$actualType}",
                    'size' => $actualSize,
                    'content_type' => $actualType,
                ];
            }
        }

        // Validate path prefix matches expected pattern
        if (!preg_match('#^cases/[^/]+/[a-f0-9\-]+-#', $objectPath)) {
            return [
                'valid' => false,
                'error' => 'Invalid storage path format',
                'size' => $actualSize,
                'content_type' => $actualType,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'size' => $actualSize,
            'content_type' => $actualType,
        ];

    } catch (Exception $e) {
        error_log('[GCS] Verification error: ' . $e->getMessage());
        return ['valid' => false, 'error' => 'Storage verification failed: ' . $e->getMessage(), 'size' => 0, 'content_type' => ''];
    }
}

/**
 * Delete an object from GCS
 *
 * @param string $objectPath The object path to delete
 * @return bool True if deleted or didn't exist
 */
function deleteGcsObject($objectPath) {
    try {
        $bucket = getGcsBucket();
        $object = $bucket->object($objectPath);
        if ($object->exists()) {
            $object->delete();
        }
        return true;
    } catch (Exception $e) {
        error_log('[GCS] Delete error for ' . $objectPath . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * List objects with a given prefix (for cleanup)
 *
 * @param string $prefix The path prefix to search
 * @return array Array of object paths
 */
function listGcsObjects($prefix) {
    try {
        $bucket = getGcsBucket();
        $objects = $bucket->objects(['prefix' => $prefix]);
        $paths = [];
        foreach ($objects as $object) {
            $paths[] = $object->name();
        }
        return $paths;
    } catch (Exception $e) {
        error_log('[GCS] List error for prefix ' . $prefix . ': ' . $e->getMessage());
        return [];
    }
}

/**
 * Sanitize a filename for use in GCS object paths
 *
 * @param string $filename The original filename
 * @return string Sanitized filename safe for GCS paths
 */
function sanitizeGcsFilename($filename) {
    // Remove path traversal characters
    $filename = basename($filename);
    // Replace spaces and special chars with underscores
    $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
    // Collapse multiple underscores
    $filename = preg_replace('/_+/', '_', $filename);
    // Limit length
    if (strlen($filename) > 200) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = substr(pathinfo($filename, PATHINFO_FILENAME), 0, 190);
        $filename = $name . '.' . $ext;
    }
    return $filename;
}
