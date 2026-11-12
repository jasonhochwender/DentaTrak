<?php
/**
 * Data Export v2 API
 *
 * Async practice-data exports processed by api/export-worker.php (Cloud
 * Run Job / CLI). Gated behind the DATA_EXPORT_V2 feature flag - when
 * disabled every action returns 404 so the surface is invisible.
 *
 * Actions:
 *   request       POST - queue a case_data export (format=csv|json)
 *   status        GET  - list the caller's recent exports
 *   download_url  POST - issue a short-lived signed URL for a completed
 *                        export (records issuance; actual GCS downloads
 *                        are evidenced by bucket Data Access audit logs)
 *   download_file GET  - dev-only local-driver artifact proxy; streams
 *                        bytes and PHI-logs the actual download
 *
 * Authorization: authenticated practice member, not a lab collaborator,
 * current Terms accepted, and an UNRESTRICTED practice admin or owner
 * (is_owner=1, or role=admin with limited_visibility=0). Assigned-Only
 * admins are denied - they keep the legacy personal-scoped export.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/export-artifacts.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

function de2Out($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['db_user_id'])) {
    de2Out(401, ['success' => false, 'message' => t('export.authentication_required')]);
}

$practiceId = requireValidPracticeContext();
$userId = (int)$_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';

// Rollout gate: DATA_EXPORT_V2 plus the DATA_EXPORT_V2_PRACTICES
// allowlist, evaluated against the session's server-resolved practice.
// 404 keeps the surface invisible, same as the master flag.
if (!dataExportV2PracticeAllowed($practiceId)) {
    de2Out(404, ['success' => false, 'message' => 'Not found']);
}

requireNotLabCollaborator($practiceId, t('export.external_collaborator_denied'));
requireCurrentTermsAcceptedForApi($userId);

/**
 * Unrestricted admin/owner check: owner, or admin role without
 * limited_visibility. Assigned-Only members never qualify.
 */
function de2IsUnrestrictedAdminOrOwner(PDO $pdo, int $practiceId, int $userId): bool {
    $stmt = $pdo->prepare("
        SELECT is_owner, role, COALESCE(limited_visibility,0) AS lv
        FROM practice_users
        WHERE practice_id = :pid AND user_id = :uid
        LIMIT 1
    ");
    $stmt->execute(['pid' => $practiceId, 'uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['is_owner'] === 1) return true;
    return $row['role'] === 'admin' && (int)$row['lv'] === 0;
}

function de2EnsureSchema(): void {
    global $pdo;
    require_once __DIR__ . '/../migrations/2026_11_13_data_exports_v2.php';
    require_once __DIR__ . '/../migrations/2026_11_20_data_exports_v2_attempts.php';
    runDataExportsV2Migration($pdo);
    runDataExportsV2AttemptsMigration($pdo);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'request';

switch ($action) {
    case 'request':
        if ($method !== 'POST') de2Out(405, ['success' => false, 'message' => t('export.method_not_allowed')]);
        requireCsrfToken();
        de2HandleRequest($pdo, $userId, $practiceId, $userEmail);
        break;
    case 'status':
        de2HandleStatus($pdo, $userId, $practiceId);
        break;
    case 'download_url':
        if ($method !== 'POST') de2Out(405, ['success' => false, 'message' => t('export.method_not_allowed')]);
        requireCsrfToken();
        de2HandleDownloadUrl($pdo, $userId, $practiceId);
        break;
    case 'download_file':
        de2HandleDownloadFile($pdo, $userId, $practiceId);
        break;
    default:
        de2Out(400, ['success' => false, 'message' => t('export.invalid_action')]);
}

function de2HandleRequest(PDO $pdo, int $userId, int $practiceId, string $userEmail): void {
    if (!de2IsUnrestrictedAdminOrOwner($pdo, $practiceId, $userId)) {
        de2Out(403, ['success' => false, 'message' => t('export.admin_only')]);
    }
    de2EnsureSchema();

    // One active job per user.
    $stmt = $pdo->prepare("SELECT id, status FROM data_exports
        WHERE user_id = :u AND status IN ('queued','processing') LIMIT 1");
    $stmt->execute(['u' => $userId]);
    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        de2Out(200, ['success' => false, 'message' => t('export.in_progress'),
                     'exportId' => (int)$existing['id'], 'status' => $existing['status']]);
    }

    // Daily cap on v2 exports per practice (cost guard).
    $cap = (int)(getEnvVar('DATA_EXPORT_DAILY_CAP') ?? 10);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_exports
        WHERE practice_id = :p AND export_type <> 'legacy_json'
          AND created_at >= CURDATE()");
    $stmt->execute(['p' => $practiceId]);
    if ((int)$stmt->fetchColumn() >= $cap) {
        de2Out(429, ['success' => false, 'message' => 'Daily export limit reached']);
    }

    $type = ($_POST['type'] ?? 'case_data') === 'practice_archive' ? 'practice_archive' : 'case_data';
    $format = $type === 'practice_archive' ? 'zip'
        : (($_POST['format'] ?? 'csv') === 'json' ? 'json' : 'csv');
    $stmt = $pdo->prepare("INSERT INTO data_exports
        (user_id, practice_id, export_type, format, status, created_at)
        VALUES (:u, :p, :t, :f, 'queued', NOW())");
    $stmt->execute(['u' => $userId, 'p' => $practiceId, 't' => $type, 'f' => $format]);
    $exportId = (int)$pdo->lastInsertId();

    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'data_export_v2_requested', "Case data export queued (ID: {$exportId}, format: {$format})");
    }

    // Local development: run the worker inline so the UI works without a
    // separate job runner. Production always goes through the job queue.
    $isDev = ($appConfig['current_environment'] ?? '') === 'development';
    if ($isDev) {
        require_once __DIR__ . '/export-worker.php';
        exportWorkerProcessOne($pdo, $exportId, 'dev-inline');
        $row = $pdo->query("SELECT status FROM data_exports WHERE id = {$exportId}")->fetch(PDO::FETCH_ASSOC);
        $status = $row['status'] ?? 'failed';
    } else {
        $status = 'queued';
        // Best-effort wake of the Cloud Run Job; the scheduled drain is the
        // guaranteed path if this call fails.
        try { exportWorkerKickJob($exportId); } catch (Throwable $e) { /* queued regardless */ }
    }

    echo json_encode(['success' => true, 'exportId' => $exportId, 'status' => $status]);
}

function de2HandleStatus(PDO $pdo, int $userId, int $practiceId): void {
    de2EnsureSchema();
    $stmt = $pdo->prepare("SELECT id, export_type, format, status, progress,
        file_size, gcs_object, expires_at, created_at, completed_at, error_message,
        COALESCE(JSON_LENGTH(JSON_EXTRACT(manifest_json, '$.warnings')), 0) AS archive_warnings
        FROM data_exports WHERE user_id = :u AND practice_id = :p ORDER BY created_at DESC LIMIT 10");
    $stmt->execute(['u' => $userId, 'p' => $practiceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Effective status: a completed export past expires_at is expired even
    // before the worker sweep persists the transition, so the UI never
    // shows a stale "Ready" with an active Download button.
    $now = time();
    foreach ($rows as &$r) {
        if ($r['status'] === 'completed' && !empty($r['expires_at'])
            && strtotime($r['expires_at']) < $now) {
            $r['status'] = 'expired';
        }
    }
    unset($r);
    echo json_encode(['success' => true, 'exports' => $rows]);
}

function de2HandleDownloadUrl(PDO $pdo, int $userId, int $practiceId): void {
    if (!de2IsUnrestrictedAdminOrOwner($pdo, $practiceId, $userId)) {
        de2Out(403, ['success' => false, 'message' => t('export.admin_only')]);
    }
    de2EnsureSchema();
    $exportId = (int)($_POST['export_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM data_exports WHERE id = :id AND user_id = :u");
    $stmt->execute(['id' => $exportId, 'u' => $userId]);
    $export = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$export || $export['status'] !== 'completed') {
        de2Out(404, ['success' => false, 'message' => t('export.not_found_or_expired')]);
    }
    // Re-check the allowlist against the practice that OWNS the export -
    // switching to an allowlisted practice cannot unlock another
    // practice's artifacts.
    if (!dataExportV2PracticeAllowed((int)$export['practice_id'])) {
        de2Out(404, ['success' => false, 'message' => t('export.not_found_or_expired')]);
    }
    if ($export['expires_at'] && strtotime($export['expires_at']) < time()) {
        $pdo->prepare("UPDATE data_exports SET status='expired' WHERE id=:id")->execute(['id' => $exportId]);
        de2Out(410, ['success' => false, 'message' => t('export.expired')]);
    }

    // Resolve artifact location (new gcs_object column, or file_path which
    // is either 'gcs:<object>' for legacy GCS artifacts or a local name).
    $ext = ['csv' => 'csv', 'json' => 'json', 'zip' => 'zip'][$export['format'] ?? 'csv'] ?? 'csv';
    $fp = (string)($export['file_path'] ?? '');
    $isGcs = !empty($export['gcs_object']) || str_starts_with($fp, 'gcs:');
    $ref = $export['gcs_object'] ?: (str_starts_with($fp, 'gcs:') ? substr($fp, 4) : $fp);
    $artifact = [
        'id' => $exportId,
        'driver' => $isGcs ? 'gcs' : 'local',
        'ref' => $ref,
        'filename' => 'dentatrak-' . (($export['export_type'] ?? '') === 'practice_archive' ? 'practice-archive' : 'case-export')
            . '-' . $exportId . '.' . $ext,
    ];
    if ($artifact['driver'] === 'gcs') {
        $artifact['bucket'] = $export['gcs_bucket'];
    }

    $url = exportArtifactSignedUrl($artifact, 900);
    if (!$url) {
        de2Out(500, ['success' => false, 'message' => 'Could not issue download URL']);
    }

    // Authorization-to-download evidence (NOT proof the file was fetched -
    // that is covered by GCS Data Access audit logs / the app proxy log).
    $pdo->prepare("UPDATE data_exports SET url_issued_at = NOW(), url_issued_count = url_issued_count + 1 WHERE id = :id")
        ->execute(['id' => $exportId]);
    logPHIAccess(PHI_ACTION_EXPORT_URL_ISSUED, null, ['export_id' => $exportId, 'format' => $export['format']],
        'practice_export', (string)$exportId, $practiceId);

    echo json_encode(['success' => true, 'url' => $url, 'expires_in' => 900]);
}

function de2HandleDownloadFile(PDO $pdo, int $userId, int $practiceId): void {
    // Dev-driver proxy only: when artifacts live on local disk there is no
    // signed URL, so stream through the app with the same auth checks.
    if (exportArtifactDriver() === 'gcs') {
        de2Out(404, ['success' => false, 'message' => 'Not found']);
    }
    $exportId = (int)($_GET['export_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM data_exports WHERE id = :id AND user_id = :u AND status='completed'");
    $stmt->execute(['id' => $exportId, 'u' => $userId]);
    $export = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$export || !$export['file_path']) {
        de2Out(404, ['success' => false, 'message' => t('export.not_found_or_expired')]);
    }
    if (!dataExportV2PracticeAllowed((int)$export['practice_id'])) {
        de2Out(404, ['success' => false, 'message' => t('export.not_found_or_expired')]);
    }
    if ($export['expires_at'] && strtotime($export['expires_at']) < time()) {
        $pdo->prepare("UPDATE data_exports SET status='expired' WHERE id=:id")->execute(['id' => $exportId]);
        de2Out(410, ['success' => false, 'message' => t('export.expired')]);
    }
    // Legacy local artifacts live under exports/, v2 under exports-v2/.
    $dir = (($export['export_type'] ?? '') === 'legacy_json') ? 'exports' : 'exports-v2';
    $path = __DIR__ . '/../' . $dir . '/' . basename($export['file_path']);
    if (!is_file($path)) {
        de2Out(404, ['success' => false, 'message' => t('export.file_not_found')]);
    }
    logPHIAccess(PHI_ACTION_DATA_EXPORT_DOWNLOAD, null, ['export_id' => $exportId],
        'practice_export', (string)$exportId, $practiceId);
    $mime = ['csv' => 'text/csv', 'json' => 'application/json', 'zip' => 'application/zip'][$export['format'] ?? ''] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="dentatrak-case-export-' . $exportId . '.' . $export['format'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
