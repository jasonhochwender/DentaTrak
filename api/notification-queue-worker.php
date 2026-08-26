<?php
/**
 * Notification Queue Worker
 *
 * Processes pending notification_email_queue rows asynchronously.
 * Designed for Cloud Scheduler invoking a Cloud Run endpoint.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/notification-preferences.php';
require_once __DIR__ . '/notification-email-renderer.php';
require_once __DIR__ . '/email-sender.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$workerToken = getEnvVar('QUEUE_WORKER_TOKEN');
$isTestMode = getEnvVar('DENTATRAK_TEST_MODE') === 'true' || ($appConfig['current_environment'] ?? 'production') !== 'production';
if (!$workerToken && $isTestMode) {
    $workerToken = 'dentatrak-test-worker-token';
}
$submittedToken = '';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($authHeader, 'Bearer ') === 0) {
    $submittedToken = substr($authHeader, 7);
}

if (!$submittedToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!$workerToken || !hash_equals($workerToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
}

// Master gating: when SHOW_NOTIFICATIONS is off, the worker must not deliver
// structured case-notification emails, even if queue rows already exist.
if (!isFeatureEnabled('SHOW_NOTIFICATIONS')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Structured notifications are disabled']);
    exit;
}

$BATCH_SIZE = (int)(getEnvVar('NOTIFICATION_WORKER_BATCH_SIZE') ?? 25);
$BATCH_SIZE = max(1, min(1000, $BATCH_SIZE));
$STALE_MINUTES = 10;
$RETRY_MINUTES = [1, 5];
$MAX_RETRIES = 3;

$totals = [
    'processed' => 0,
    'sent' => 0,
    'failed' => 0,
    'cancelled' => 0,
];

$baseUrl = $appConfig['app_base_url'] ?? 'http://localhost/DentaTrak';

try {
    $pdo->beginTransaction();

    // 1. Recover stale processing rows
    $staleInterval = (int)$STALE_MINUTES;
    $recoverStmt = $pdo->exec("
        UPDATE notification_email_queue
        SET status = 'pending', locked_at = NULL
        WHERE status = 'processing'
          AND locked_at < DATE_SUB(NOW(), INTERVAL {$staleInterval} MINUTE)
    ");

    // 2. Atomically claim a batch of pending rows
    $claimSelect = $pdo->prepare("
        SELECT id
        FROM notification_email_queue
        WHERE status = 'pending'
          AND scheduled_at <= NOW()
        ORDER BY id ASC
        LIMIT :limit
        FOR UPDATE
    ");
    $claimSelect->bindValue(':limit', (int)$BATCH_SIZE, PDO::PARAM_INT);
    $claimSelect->execute();
    $ids = $claimSelect->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ids)) {
        $pdo->commit();
        echo json_encode(['success' => true, 'totals' => $totals]);
        exit;
    }

    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $claimUpdate = $pdo->prepare("
        UPDATE notification_email_queue
        SET status = 'processing', locked_at = NOW()
        WHERE id IN ($inClause)
    ");
    $claimUpdate->execute($ids);

    $pdo->commit();

    // 3. Process each claimed row outside the main transaction
    $rowStmt = $pdo->prepare("
        SELECT q.*, u.email, u.is_active AS user_active, n.case_id, n.user_id AS notification_user_id, n.practice_id AS notification_practice_id
        FROM notification_email_queue q
        JOIN users u ON u.id = q.user_id
        LEFT JOIN user_notifications n ON n.id = q.notification_id
        WHERE q.id = :id
        LIMIT 1
    ");

    foreach ($ids as $id) {
        $rowStmt->execute(['id' => (int)$id]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'processing') {
            continue;
        }

        $totals['processed']++;

        try {
            // Resolve the current locale at send time
            $row['locale'] = resolveEmailLocale((int)$row['user_id'], (int)$row['practice_id'], $row['locale']);

            // Cancellation checks
            $cancelReason = null;

            if (empty($row['user_active'])) {
                $cancelReason = 'user_removed_or_inactive';
            } elseif (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $cancelReason = 'invalid_email';
            } elseif (empty($row['case_id'])) {
                $cancelReason = 'notification_or_case_missing';
            } elseif ((int)$row['user_id'] !== (int)$row['notification_user_id'] || (int)$row['practice_id'] !== (int)$row['notification_practice_id']) {
                $cancelReason = 'notification_ownership_mismatch';
            } else {
                // Active membership
                $memberStmt = $pdo->prepare("
                    SELECT 1
                    FROM practice_users pu
                    JOIN users u ON u.id = pu.user_id
                    JOIN practices p ON p.id = pu.practice_id
                    WHERE pu.user_id = :user_id
                      AND pu.practice_id = :practice_id
                      AND u.is_active = 1
                      AND (p.is_active = 1 OR p.is_active IS NULL)
                    LIMIT 1
                ");
                $memberStmt->execute(['user_id' => (int)$row['user_id'], 'practice_id' => (int)$row['practice_id']]);
                if (!$memberStmt->fetchColumn()) {
                    $cancelReason = 'practice_membership_revoked';
                }
            }

            // Current case access and existence
            if (!$cancelReason) {
                $caseStmt = $pdo->prepare("
                    SELECT *
                    FROM cases_cache
                    WHERE case_id = :case_id AND practice_id = :practice_id
                    LIMIT 1
                ");
                $caseStmt->execute(['case_id' => $row['case_id'], 'practice_id' => (int)$row['practice_id']]);
                $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
                if (!$case) {
                    $cancelReason = 'case_deleted';
                } elseif (!canUserAccessCase($case, (int)$row['practice_id'])) {
                    $cancelReason = 'access_revoked';
                }
            }

            // Current direct assignment or Assignment Label mapping
            if (!$cancelReason && !empty($case)) {
                $assignedTo = strtolower(trim((string)($case['assigned_to'] ?? '')));
                $userEmail = strtolower(trim((string)$row['email']));

                if ($assignedTo === '') {
                    $cancelReason = 'case_unassigned';
                } elseif ($assignedTo !== $userEmail) {
                    // The case is assigned to a label. Verify the recipient is still mapped to it.
                    $labelMapStmt = $pdo->prepare("
                        SELECT 1
                        FROM practice_assignment_labels l
                        JOIN practice_assignment_label_recipients r ON r.label_id = l.id
                        WHERE l.practice_id = :practice_id
                          AND LOWER(TRIM(l.label)) = :label
                          AND r.user_id = :user_id
                        LIMIT 1
                    ");
                    $labelMapStmt->execute([
                        'practice_id' => (int)$row['practice_id'],
                        'label' => $assignedTo,
                        'user_id' => (int)$row['user_id'],
                    ]);
                    if (!$labelMapStmt->fetchColumn()) {
                        $cancelReason = 'assignment_or_label_revoked';
                    }
                }
            }

            // Current preferences
            if (!$cancelReason) {
                $categories = json_decode($row['category_keys_json'] ?? '[]', true) ?: [];
                if (!userWantsEmailNotification($pdo, (int)$row['user_id'], (int)$row['practice_id'], $categories)) {
                    $cancelReason = 'email_preferences_disabled';
                }
            }

            if ($cancelReason) {
                $pdo->prepare("
                    UPDATE notification_email_queue
                    SET status = 'cancelled', error_message = :reason, locked_at = NULL
                    WHERE id = :id
                ")?->execute(['reason' => 'cancelled:' . $cancelReason, 'id' => (int)$id]);
                $totals['cancelled']++;
                continue;
            }

            // Render and send
            $templateData = json_decode($row['template_data_json'] ?? '{}', true) ?: [];
            $email = renderNotificationEmail(
                $row['locale'],
                $row['subject_key'],
                $row['body_key'],
                $templateData,
                (int)$row['notification_id'],
                $baseUrl
            );

            $sendResult = sendAppEmail($row['email'], $email['subject'], $email['html'], $email['text']);

            if (!empty($sendResult['success'])) {
                $pdo->prepare("
                    UPDATE notification_email_queue
                    SET status = 'sent', sent_at = NOW(), locked_at = NULL, error_message = NULL
                    WHERE id = :id
                ")?->execute(['id' => (int)$id]);
                $totals['sent']++;
            } else {
                $newRetry = (int)$row['retry_count'] + 1;
                if ($newRetry >= $MAX_RETRIES) {
                    $error = $sendResult['error'] ?? 'unknown_send_error';
                    $pdo->prepare("
                        UPDATE notification_email_queue
                        SET status = 'failed', retry_count = :retry, error_message = :error, locked_at = NULL
                        WHERE id = :id
                    ")?->execute([
                        'retry' => $newRetry,
                        'error' => 'failed:' . _sanitizeError($error),
                        'id' => (int)$id,
                    ]);
                    $totals['failed']++;
                } else {
                    $delay = (int)($RETRY_MINUTES[$newRetry - 1] ?? end($RETRY_MINUTES));
                    $pdo->prepare("
                        UPDATE notification_email_queue
                        SET status = 'pending', retry_count = :retry, scheduled_at = DATE_ADD(NOW(), INTERVAL {$delay} MINUTE), locked_at = NULL, error_message = :error
                        WHERE id = :id
                    ")?->execute([
                        'retry' => $newRetry,
                        'error' => 'retry:' . _sanitizeError($sendResult['error'] ?? 'unknown'),
                        'id' => (int)$id,
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('[notification-queue-worker] Row ' . $id . ' error: ' . $e->getMessage());
            $newRetry = (int)($row['retry_count'] ?? 0) + 1;
            if ($newRetry >= $MAX_RETRIES) {
                $pdo->prepare("
                    UPDATE notification_email_queue
                    SET status = 'failed', retry_count = :retry, error_message = :error, locked_at = NULL
                    WHERE id = :id
                ")?->execute([
                    'retry' => $newRetry,
                    'error' => 'failed:worker_exception',
                    'id' => (int)$id,
                ]);
                $totals['failed']++;
            } else {
                $delay = (int)($RETRY_MINUTES[$newRetry - 1] ?? end($RETRY_MINUTES));
                $pdo->prepare("
                    UPDATE notification_email_queue
                    SET status = 'pending', retry_count = :retry, scheduled_at = DATE_ADD(NOW(), INTERVAL {$delay} MINUTE), locked_at = NULL, error_message = :error
                    WHERE id = :id
                ")?->execute([
                    'retry' => $newRetry,
                    'error' => 'retry:worker_exception',
                    'id' => (int)$id,
                ]);
            }
        }
    }

    echo json_encode(['success' => true, 'totals' => $totals]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[notification-queue-worker] Batch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Batch processing failed']);
}

/**
 * Sanitize an error string for queue storage. Strips newlines and truncates.
 */
function _sanitizeError($error) {
    $safe = preg_replace('/[\r\n]/', ' ', (string)$error);
    return substr($safe, 0, 250);
}
