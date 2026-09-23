<?php
/**
 * Case Comments API
 * Handles internal comment threads with @mentions for cases
 * Comments are for discussion/coordination, NOT documentation (that's Notes)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/notification-service.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

if (!isFeatureEnabled('SHOW_COMMENTS')) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

/**
 * Ensure the case_comments table exists
 */
function ensureCaseCommentsTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS case_comments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        user_email VARCHAR(255) NOT NULL,
        comment_text TEXT NOT NULL,
        mentions_json TEXT DEFAULT NULL,
        attachments_json TEXT DEFAULT NULL,
        is_deleted BOOLEAN DEFAULT FALSE,
        deleted_at DATETIME DEFAULT NULL,
        deleted_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case_id (case_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at),
        INDEX idx_is_deleted (is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log('[case_comments] Error creating table: ' . $e->getMessage());
    }

    // Idempotent column add for installs where the table pre-dates comment
    // image attachments.
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM case_comments LIKE 'attachments_json'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE case_comments ADD COLUMN attachments_json TEXT DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log('[case_comments] Error adding attachments_json column: ' . $e->getMessage());
    }

    $initialized = true;
}

// Keep user_notifications bootstrapped with the same columns the rest of the
// notification system expects, in case this endpoint is reached before the
// Phase 1 migration or api/notifications.php has run.
function ensureUserNotificationsTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS user_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        notification_type VARCHAR(50) NOT NULL DEFAULT 'mention',
        case_id VARCHAR(64) DEFAULT NULL,
        comment_id BIGINT UNSIGNED DEFAULT NULL,
        from_user_id BIGINT UNSIGNED NOT NULL,
        from_user_name VARCHAR(255) NOT NULL,
        preview_text VARCHAR(255) DEFAULT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        read_at DATETIME DEFAULT NULL,
        dismissed_at DATETIME DEFAULT NULL,
        metadata_json LONGTEXT,
        event_id BIGINT UNSIGNED DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_is_read (is_read),
        INDEX idx_dismissed_at (dismissed_at),
        INDEX idx_created_at (created_at),
        INDEX idx_case_id (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log('[case_comments] Error creating user_notifications table: ' . $e->getMessage());
        return;
    }

    // If an older table already exists without the notification-service columns,
    // add them idempotently (mirrors the Phase 1 migration).
    $columns = [
        'metadata_json' => "ALTER TABLE user_notifications ADD COLUMN metadata_json LONGTEXT",
        'expires_at'    => "ALTER TABLE user_notifications ADD COLUMN expires_at DATETIME DEFAULT NULL",
        'event_id'      => "ALTER TABLE user_notifications ADD COLUMN event_id BIGINT UNSIGNED DEFAULT NULL",
    ];

    foreach ($columns as $col => $alterSql) {
        try {
            $quotedCol = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM user_notifications LIKE {$quotedCol}");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        } catch (PDOException $e) {
            error_log('[case_comments] Error extending user_notifications: ' . $e->getMessage());
        }
    }

    $initialized = true;
}

// Ensure tables exist
ensureCaseCommentsTable();
ensureUserNotificationsTable();

$method = $_SERVER['REQUEST_METHOD'];

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
// Session user_name is '' for members provisioned with an email-only users
// row - fall back to the account email so comments stay attributable.
$userName = trim((string)($_SESSION['user_name'] ?? '')) !== '' ? $_SESSION['user_name'] : $userEmail;

// Validate CSRF for all state-changing requests.
if ($method === 'POST') {
    $inputForCsrf = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($inputForCsrf['csrf_token'] ?? null);
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token']);
        exit;
    }
    // Reset so the action below can re-read the body.
    $input = $inputForCsrf;
}

/**
 * Resolve submitted mention selections to active, case-authorized user records.
 *
 * The client sends the user IDs selected from autocomplete.  The server is the
 * authority: it looks each submitted ID up against the current practice's active
 * members and the specific case's access rules, excludes the comment author,
 * deduplicates, and records the client-supplied display token in mentions_json.
 *
 * @param array  $mentionData Array of ['user_id' => int, 'mention' => string]
 * @param int    $practiceId
 * @param string $caseId
 * @param int    $authorUserId
 * @return array Resolved mentions with keys: user_id, email, name, mention
 */
function resolveSubmittedMentions($mentionData, $practiceId, $caseId, $authorUserId) {
    $resolved = [];

    if (empty($mentionData) || !$practiceId || !$caseId) {
        return $resolved;
    }

    $authorizedUsers = getCaseAuthorizedUsers($practiceId, $caseId);
    if (empty($authorizedUsers)) {
        return $resolved;
    }

    // Index authorized users by user ID for exact, unambiguous lookup.
    $authorizedById = [];
    foreach ($authorizedUsers as $user) {
        $authorizedById[(int)$user['id']] = $user;
    }

    $seenUserIds = [];

    foreach ($mentionData as $entry) {
        $submittedUserId = isset($entry['user_id']) ? (int)$entry['user_id'] : 0;
        $displayToken = isset($entry['mention']) ? trim($entry['mention']) : '';

        if ($submittedUserId <= 0) {
            continue;
        }

        // Do not trust the client: the selected user must be active, a member,
        // and explicitly authorized to access this case.
        if (!isset($authorizedById[$submittedUserId])) {
            continue;
        }

        // Never notify the comment author, even on a self-mention.
        if ($submittedUserId === (int)$authorUserId) {
            continue;
        }

        // Deduplicate multiple tokens for the same user.
        if (isset($seenUserIds[$submittedUserId])) {
            continue;
        }

        $user = $authorizedById[$submittedUserId];
        $firstName = (string)($user['first_name'] ?? '');
        $lastName = (string)($user['last_name'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);

        $resolved[] = [
            'user_id' => $submittedUserId,
            'email' => $user['email'],
            'name' => $fullName ?: $user['email'],
            'mention' => $displayToken ?: ($fullName ? preg_replace('/\s+/', '', $fullName) : ''),
        ];
        $seenUserIds[$submittedUserId] = true;
    }

    return $resolved;
}

/**
 * Verify client-submitted comment image metadata against GCS and normalize it
 * for storage in case_comments.attachments_json.
 *
 * Mirrors processGcsAttachments() but stricter: image extensions only and the
 * storage path must live under this exact case's comments/ folder, so the
 * attachment-content.php case-access check covers every comment image.
 *
 * @param array  $images      Client metadata: storage_path, original_filename, content_type, file_size
 * @param int    $practiceId
 * @param string $caseId
 * @return array ['success' => bool, 'attachments' => array, 'errors' => array]
 */
function processCommentImages($images, $practiceId, $caseId) {
    global $appConfig;

    $result = ['success' => true, 'attachments' => [], 'errors' => []];

    if (!is_array($images) || empty($images)) {
        return $result;
    }

    // Configured V1 cap (appConfig['comments']['max_images']); the composer
    // enforces the same value via window.commentImageMaxCount.
    $maxCount = (int)($appConfig['comments']['max_images'] ?? 6);
    if (count($images) > $maxCount) {
        $result['success'] = false;
        $result['errors'][] = t('api.comments.too_many_images', [
            'max' => $maxCount,
            'count' => count($images),
        ]);
        return $result;
    }

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg'];
    $sizeByType = $appConfig['gcs']['max_file_size_by_type'] ?? [];
    $expectedPrefix = "cases/{$practiceId}/{$caseId}/comments/";

    foreach ($images as $index => $imageInfo) {
        $storagePath  = is_array($imageInfo) ? ($imageInfo['storage_path'] ?? '') : '';
        $originalName = is_array($imageInfo) ? basename((string)($imageInfo['original_filename'] ?? '')) : '';
        $contentType  = is_array($imageInfo) ? ($imageInfo['content_type'] ?? '') : '';
        $fileSize     = is_array($imageInfo) ? (int)($imageInfo['file_size'] ?? 0) : 0;

        if ($storagePath === '' || $originalName === '') {
            $result['errors'][] = t('api.comments.image_missing_fields', ['index' => $index]);
            $result['success'] = false;
            continue;
        }

        // Path must be inside this case's comments/ folder - anything else is
        // either another case's file or a case-attachment path, both rejected.
        if (strpos($storagePath, $expectedPrefix) !== 0 || strpos($storagePath, '..') !== false) {
            $result['errors'][] = t('api.comments.image_invalid_path', ['name' => $originalName]);
            $result['success'] = false;
            continue;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExts)) {
            $result['errors'][] = t('api.comments.image_not_image', ['name' => $originalName]);
            $result['success'] = false;
            continue;
        }

        // Confirm the upload actually exists in GCS with the claimed size/type.
        $verification = verifyGcsUpload($storagePath, $fileSize, $contentType);
        if (!$verification['valid']) {
            $result['errors'][] = t('api.comments.image_verify_failed', [
                'name' => $originalName,
                'detail' => $verification['error'],
            ]);
            $result['success'] = false;
            continue;
        }

        $actualSize = $verification['size'];
        $maxForType = $sizeByType[$ext] ?? ($sizeByType['default'] ?? (100 * 1024 * 1024));
        if ($actualSize > $maxForType) {
            $result['errors'][] = t('api.comments.image_too_large', [
                'name' => $originalName,
                'limit' => round($maxForType / 1024 / 1024),
                'ext' => $ext,
            ]);
            $result['success'] = false;
            continue;
        }

        $result['attachments'][] = [
            'fileName'    => $originalName,
            'fileType'    => $contentType,
            'size'        => $actualSize,
            'storagePath' => $storagePath,
            'storageType' => 'gcs',
        ];
    }

    return $result;
}

/**
 * Decode a comment's attachments_json into the response shape.
 */
function decodeCommentImages($attachmentsJson) {
    if (empty($attachmentsJson)) {
        return [];
    }
    $decoded = json_decode($attachmentsJson, true);
    return is_array($decoded) ? $decoded : [];
}

if ($method === 'GET') {
    // Get comments for a case
    $caseId = $_GET['case_id'] ?? null;

    if (!$caseId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Case ID required']);
        exit;
    }

    // SECURITY: Assigned Only users may only view comments on cases assigned to them.
    requireCaseAccess($caseId, $currentPracticeId);

    try {
        $stmt = $pdo->prepare("
            SELECT id, case_id, user_id, user_name, user_email, comment_text, 
                   mentions_json, attachments_json, is_deleted, created_at,
                   UNIX_TIMESTAMP(created_at) AS created_ts
            FROM case_comments
            WHERE case_id = :case_id 
            AND practice_id = :practice_id
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            'case_id' => $caseId,
            'practice_id' => $currentPracticeId
        ]);

        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format comments for response
        $formattedComments = array_map(function($comment) {
            return [
                'id' => (int)$comment['id'],
                'case_id' => $comment['case_id'],
                'user_id' => (int)$comment['user_id'],
                'user_name' => $comment['user_name'],
                'user_email' => $comment['user_email'],
                'text' => $comment['is_deleted'] ? '[Comment removed]' : $comment['comment_text'],
                'mentions' => $comment['mentions_json'] ? json_decode($comment['mentions_json'], true) : [],
                // Deleted comments mask their images the same way their text
                // is masked, so removed content stays removed in the UI.
                'images' => $comment['is_deleted'] ? [] : decodeCommentImages($comment['attachments_json']),
                'is_deleted' => (bool)$comment['is_deleted'],
                // Emit ISO-8601 UTC: the stored DATETIME carries no timezone, so
                // UNIX_TIMESTAMP() interprets it in the DB session timezone and
                // returns the real epoch. A bare 'Y-m-d H:i:s' string would be
                // mis-parsed by browsers as browser-local time.
                'created_at' => isset($comment['created_ts']) && $comment['created_ts'] !== null
                    ? gmdate('c', (int)$comment['created_ts'])
                    : null
            ];
        }, $comments);

        echo json_encode([
            'success' => true,
            'comments' => $formattedComments
        ]);

    } catch (PDOException $e) {
        error_log('[case_comments] Error fetching comments: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching comments']);
    }

} elseif ($method === 'POST') {
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        // Create a new comment
        $caseId = $input['case_id'] ?? null;
        $commentText = trim($input['text'] ?? '');
        $submittedImages = $input['images'] ?? [];

        // A comment must carry text, images, or both - never neither.
        if (!$caseId || ($commentText === '' && empty($submittedImages))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => t('api.comments.text_or_images_required')]);
            exit;
        }

        // SECURITY: Assigned Only users may only comment on cases assigned to them.
        requireCaseAccess($caseId, $currentPracticeId);

        // Resolve submitted mentions against active, case-authorized users.
        $submittedMentions = $input['mentions'] ?? [];
        $resolvedMentions = resolveSubmittedMentions($submittedMentions, $currentPracticeId, $caseId, $userId);

        // Verify submitted image uploads exist in this case's comments/ folder
        // with the claimed size/type before linking them to the comment.
        $processedImages = processCommentImages($submittedImages, $currentPracticeId, $caseId);
        if (!$processedImages['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => t('api.comments.images_invalid', ['details' => implode('; ', $processedImages['errors'])])
            ]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO case_comments 
                (case_id, practice_id, user_id, user_name, user_email, comment_text, mentions_json, attachments_json)
                VALUES (:case_id, :practice_id, :user_id, :user_name, :user_email, :comment_text, :mentions_json, :attachments_json)
            ");
            $stmt->execute([
                'case_id' => $caseId,
                'practice_id' => $currentPracticeId,
                'user_id' => $userId,
                'user_name' => $userName,
                'user_email' => $userEmail,
                'comment_text' => $commentText,
                'mentions_json' => !empty($resolvedMentions) ? json_encode($resolvedMentions) : null,
                'attachments_json' => !empty($processedImages['attachments']) ? json_encode($processedImages['attachments']) : null
            ]);

            $commentId = $pdo->lastInsertId();

            // Create in-app and email notifications for mentioned users.
            // This reuses the existing notification-service queue/worker path and
            // respects the SHOW_NOTIFICATIONS master flag.
            if (!empty($resolvedMentions)) {
                emitMentionNotificationEvent(
                    $currentPracticeId,
                    $caseId,
                    $userId,
                    $userName,
                    $commentId,
                    $resolvedMentions
                );
            }

            // Log to case activity
            ensureCaseActivityLogTable();
            logCaseActivity($caseId, 'comment_added', null, null, [
                'comment_id' => (int)$commentId,
                'has_mentions' => !empty($resolvedMentions),
                'mention_count' => count($resolvedMentions),
                'image_count' => count($processedImages['attachments'])
            ]);

            // Reset review status when a different user adds a comment or @mention.
            if (function_exists('resetCaseReviewIfDifferentUser')) {
                $wasReset = resetCaseReviewIfDifferentUser($caseId, $currentPracticeId, $userId);
                if ($wasReset) {
                    logCaseActivity(
                        $caseId,
                        'review_status_changed',
                        'reviewed',
                        'needs_review',
                        [
                            'review_status' => 'needs_review',
                            'review_status_changed_by_user_id' => $userId,
                            'source' => 'case-comments.php',
                            'reason' => !empty($resolvedMentions) ? 'mention_added' : 'comment_added',
                        ]
                    );
                }
            }

            // A comment is case activity: bump the case's Updated timestamp so
            // Updated sorting reflects it. The editable fields are unchanged,
            // so the optimistic-lock version is intentionally left alone.
            try {
                $pdo->prepare("UPDATE cases_cache SET last_update_date = :lud WHERE case_id = :cid")
                    ->execute(['lud' => date('c'), 'cid' => $caseId]);
            } catch (PDOException $e) {
                error_log('[case_comments] Failed to bump last_update_date: ' . $e->getMessage());
            }

            // Read back the stored timestamp so the response reports the value
            // MySQL actually wrote (CURRENT_TIMESTAMP uses the DB session
            // timezone, which can differ from PHP's).
            $createdTs = null;
            try {
                $tsStmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(created_at) FROM case_comments WHERE id = :id");
                $tsStmt->execute(['id' => $commentId]);
                $createdTs = $tsStmt->fetchColumn();
            } catch (PDOException $e) {
                error_log('[case_comments] Failed to read back created_at: ' . $e->getMessage());
            }

            // Notify other clients that the case changed
            if (function_exists('recordCaseUpdate')) {
                recordCaseUpdate($caseId, 'update');
            }

            echo json_encode([
                'success' => true,
                'comment' => [
                    'id' => (int)$commentId,
                    'case_id' => $caseId,
                    'user_id' => (int)$userId,
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'text' => $commentText,
                    'mentions' => $resolvedMentions,
                    'images' => $processedImages['attachments'],
                    'is_deleted' => false,
                    'created_at' => $createdTs !== null && $createdTs !== false
                        ? gmdate('c', (int)$createdTs)
                        : null
                ]
            ]);

        } catch (PDOException $e) {
            error_log('[case_comments] Error creating comment: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error creating comment']);
        }

    } elseif ($action === 'delete') {
        // Soft delete a comment (admin only)
        $commentId = $input['comment_id'] ?? null;

        if (!$commentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Comment ID required']);
            exit;
        }

        try {
            // Get comment info for audit and access verification
            $stmt = $pdo->prepare("
                SELECT case_id, user_name FROM case_comments 
                WHERE id = :id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'id' => $commentId,
                'practice_id' => $currentPracticeId
            ]);
            $comment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$comment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Comment not found']);
                exit;
            }

            // SECURITY: Verify the requesting user can access the comment's case
            // in addition to the admin role requirement.
            requireCaseAccess($comment['case_id'], $currentPracticeId);

            // Check if user is admin
            $stmt = $pdo->prepare("
                SELECT role FROM practice_users 
                WHERE user_id = :user_id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'user_id' => $userId,
                'practice_id' => $currentPracticeId
            ]);
            $userRole = $stmt->fetchColumn();

            if ($userRole !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only admins can delete comments']);
                exit;
            }

            // Soft delete
            $stmt = $pdo->prepare("
                UPDATE case_comments 
                SET is_deleted = TRUE, deleted_at = NOW(), deleted_by = :deleted_by
                WHERE id = :id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'id' => $commentId,
                'practice_id' => $currentPracticeId,
                'deleted_by' => $userId
            ]);

            // Log deletion
            ensureCaseActivityLogTable();
            logCaseActivity($comment['case_id'], 'comment_deleted', null, null, [
                'comment_id' => (int)$commentId,
                'original_author' => $comment['user_name']
            ]);

            echo json_encode(['success' => true]);

        } catch (PDOException $e) {
            error_log('[case_comments] Error deleting comment: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error deleting comment']);
        }
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
