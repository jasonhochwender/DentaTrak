<?php
/**
 * Save Settings API Endpoint
 * Handles saving user preferences to the database
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/csrf.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context for settings that affect practice data
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// SECURITY: Settings is an admin-only surface (practice configuration, users
// & roles, security, data & privacy, assignment-label management, and
// personal preferences are all edited through this one endpoint). Reject
// the entire request up front for non-admins rather than relying solely on
// the per-section role checks further below.
requirePracticeAdmin($currentPracticeId);

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Get JSON data from request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data format']);
    exit;
}

// Workflow Stage Names (Settings > Display & Behavior). Validated FIRST,
// before any field in this request is persisted, using the single
// authoritative validator (normalizeWorkflowStageLabelsForSave() in
// workflow-stages.php) so this endpoint can never store anything that
// helper wouldn't also allow. ATOMIC SAVE POLICY: if any submitted label
// is invalid (unknown status key or over the 40-character limit), the
// ENTIRE request is rejected here - nothing is written to the database,
// not even other, unrelated, otherwise-valid settings fields in the same
// request - so previously-saved values are always preserved on failure.
$workflowStageLabelsInput = isset($data['workflowStageLabels']) && is_array($data['workflowStageLabels'])
    ? $data['workflowStageLabels']
    : [];
$workflowStageLabelsResult = normalizeWorkflowStageLabelsForSave($workflowStageLabelsInput);
if (!$workflowStageLabelsResult['valid']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid workflow stage name(s): ' . implode('; ', $workflowStageLabelsResult['errors']),
        'errors' => $workflowStageLabelsResult['errors'],
        'field' => 'workflowStageLabels'
    ]);
    exit;
}
$workflowStageLabelOverridesToSave = $workflowStageLabelsResult['overrides'];

// Validate data
$theme = isset($data['theme']) ? $data['theme'] : 'light';
if (!in_array($theme, ['light', 'dark'])) {
    $theme = 'light';
}

$allowCardDelete = isset($data['allowCardDelete']) ? (bool)$data['allowCardDelete'] : false;
$highlightPastDue = isset($data['highlightPastDue']) ? (bool)$data['highlightPastDue'] : true;
$pastDueDays = isset($data['pastDueDays']) ? (int)$data['pastDueDays'] : 7;

// New: hide Delivered cases older than N days (0 = show all). Default is 120 days.
$deliveredHideDays = isset($data['deliveredHideDays']) ? (int)$data['deliveredHideDays'] : 120;

// Google Drive backup setting
$googleDriveBackup = isset($data['googleDriveBackup']) ? (bool)$data['googleDriveBackup'] : false;

// Ensure pastDueDays is within valid range
if ($pastDueDays < 1) {
    $pastDueDays = 1;
} elseif ($pastDueDays > 99) {
    $pastDueDays = 99;
}

// Ensure deliveredHideDays is within a sane range (0 = off)
if ($deliveredHideDays < 0) {
    $deliveredHideDays = 0;
} elseif ($deliveredHideDays > 365) {
    $deliveredHideDays = 365;
}

// Practice name handling
$practiceName = isset($data['practiceName']) ? trim($data['practiceName']) : '';

// Logo action handling (e.g. 'update', 'remove', or 'none')
$logoAction = isset($data['logoAction']) ? $data['logoAction'] : 'none';

// Optional logo path when updating logo
$logoPath = isset($data['logoPath']) ? trim($data['logoPath']) : '';

// Handle Admin users
$adminUsers = isset($data['adminUsers']) ? $data['adminUsers'] : [];
$validAdminUsers = [];

foreach ($adminUsers as $email) {
    // Validate each email (accept any valid email, not just Gmail)
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validAdminUsers[] = $email;
    }
}

// Handle regular users
$gmailUsers = isset($data['gmailUsers']) ? $data['gmailUsers'] : [];
$validGmailUsers = [];

foreach ($gmailUsers as $email) {
    // Validate each email (accept any valid email, not just Gmail)
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validGmailUsers[] = $email;
    }
}

// Handle limited visibility users (map of email => boolean)
$limitedVisibilityUsers = isset($data['limitedVisibilityUsers']) && is_array($data['limitedVisibilityUsers']) 
    ? $data['limitedVisibilityUsers'] 
    : [];

// SECURITY: Admin and Assigned Only (limited_visibility) are mutually
// exclusive. The UI enforces this, but a manipulated request could try to
// submit both for the same user - reject the whole request rather than
// silently deciding which one wins, so no invalid combination is ever
// persisted.
$limitedVisibilityLower = [];
foreach ($limitedVisibilityUsers as $limitedEmail => $isLimited) {
    if ($isLimited) {
        $limitedVisibilityLower[strtolower(trim((string)$limitedEmail))] = true;
    }
}
foreach ($validAdminUsers as $adminEmail) {
    if (isset($limitedVisibilityLower[strtolower(trim($adminEmail))])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid role combination: "' . $adminEmail . '" cannot be both Admin and Assigned Only. Please uncheck one and try again.'
        ]);
        exit;
    }
}

// Handle can view analytics users (map of email => boolean, default true)
$canViewAnalyticsUsers = isset($data['canViewAnalyticsUsers']) && is_array($data['canViewAnalyticsUsers']) 
    ? $data['canViewAnalyticsUsers'] 
    : [];

// Handle can edit cases users (map of email => boolean, default true)
$canEditCasesUsers = isset($data['canEditCasesUsers']) && is_array($data['canEditCasesUsers']) 
    ? $data['canEditCasesUsers'] 
    : [];

// Handle assignment labels (free-text, per practice)
$assignmentLabels = isset($data['assignmentLabels']) && is_array($data['assignmentLabels']) ? $data['assignmentLabels'] : [];
$validAssignmentLabels = [];
$seenAssignmentLabels = [];

foreach ($assignmentLabels as $label) {
    if (!is_string($label)) {
        continue;
    }

    $trimmed = trim($label);
    if ($trimmed === '') {
        continue;
    }

    // Limit length to 150 characters to avoid excessively long labels
    if (mb_strlen($trimmed) > 150) {
        $trimmed = mb_substr($trimmed, 0, 150);
    }

    $lower = mb_strtolower($trimmed);
    if (isset($seenAssignmentLabels[$lower])) {
        continue;
    }

    $seenAssignmentLabels[$lower] = true;
    $validAssignmentLabels[] = $trimmed;
}

// Handle the new stable-ID assignment-label payload ({id, label, isLab}).
// Absence of this field (null) means an older/legacy client submitted only
// the plain `assignmentLabels` string array above - handled separately
// below so a stale client can never trigger a destructive delete-all once
// stable IDs exist. Presence of an empty array [] is a valid "no labels"
// state and is NOT treated as absent.
$assignmentLabelsDetailed = null;
if (isset($data['assignmentLabelsDetailed']) && is_array($data['assignmentLabelsDetailed'])) {
    $assignmentLabelsDetailed = [];
    foreach ($data['assignmentLabelsDetailed'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $labelText = isset($item['label']) ? trim((string)$item['label']) : '';
        if ($labelText === '') {
            continue;
        }
        if (mb_strlen($labelText) > 150) {
            $labelText = mb_substr($labelText, 0, 150);
        }
        $assignmentLabelsDetailed[] = [
            'id' => (isset($item['id']) && is_numeric($item['id']) && (int)$item['id'] > 0) ? (int)$item['id'] : null,
            'label' => $labelText,
            'isLab' => !empty($item['isLab']),
        ];
    }
}

// Handle Lab designation for users (map of email => boolean). Only ever
// applied while SHOW_LAB_INSIGHTS is on the server side of the checkbox's
// visibility, but harmless to accept/ignore otherwise since it only ever
// flips a boolean that has no effect on anything unless the flag is on.
$isLabUsers = isset($data['isLabUsers']) && is_array($data['isLabUsers']) ? $data['isLabUsers'] : [];

/**
 * SECURITY: Never silently orphan a case's assignment by deleting a label
 * that's still in use. Given a list of candidate-for-removal label rows,
 * returns the subset that are still assigned to one or more cases.
 */
function checkAssignmentLabelsInUse(PDO $pdo, $practiceId, array $removedRows) {
    if (empty($removedRows)) {
        return [];
    }
    $blocked = [];
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM cases_cache
        WHERE practice_id = :practice_id AND LOWER(assigned_to) = :label
    ");
    foreach ($removedRows as $row) {
        $countStmt->execute([
            'practice_id' => $practiceId,
            'label' => mb_strtolower(trim($row['label']))
        ]);
        $caseCount = (int)$countStmt->fetchColumn();
        if ($caseCount > 0) {
            $blocked[] = ['label' => $row['label'], 'count' => $caseCount];
        }
    }
    return $blocked;
}

/**
 * Emit the standard "labels still in use" error response and exit.
 */
function respondAssignmentLabelsInUse(array $blockedLabels) {
    $parts = [];
    foreach ($blockedLabels as $blocked) {
        $parts[] = '"' . $blocked['label'] . '" (' . $blocked['count'] . ' case' . ($blocked['count'] === 1 ? '' : 's') . ')';
    }
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Cannot delete label(s) still in use: ' . implode(', ', $parts) . '. Please reassign those cases first.'
    ]);
    exit;
}

try {
    ensureUserPreferencesSchema();

    // Lab Insights foundation: self-healing migration for is_lab columns.
    // Safe to run unconditionally; has no visible effect while
    // SHOW_LAB_INSIGHTS is off.
    ensureLabDesignationColumns();

    // CRITICAL: must run here, before any beginTransaction() below. This
    // is a CREATE TABLE (DDL), and DDL causes an implicit commit in
    // MySQL/InnoDB. If left to the lazy static-guarded call inside
    // initializeOpenLabPeriodsForEntity()/recordLabAssignmentChange() and
    // first triggered while a transaction is already open (e.g. a Lab
    // checkbox toggled on for the first time ever), it silently ends that
    // transaction early, and the later explicit $pdo->commit() then fails
    // with "There is no active transaction". Calling it once, up front,
    // makes every later call a no-op (guarded by its own static flag).
    ensureLabAssignmentHistoryTable();

    // Ensure google_drive_backup column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'google_drive_backup'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN google_drive_backup TINYINT(1) DEFAULT 0");
    }

    // First, update user preferences
    $stmt = $pdo->prepare("
        INSERT INTO user_preferences (
            user_id, theme, allow_card_delete, highlight_past_due, past_due_days, delivered_hide_days, google_drive_backup
        ) VALUES (
            :user_id, :theme, :allow_card_delete, :highlight_past_due, :past_due_days, :delivered_hide_days, :google_drive_backup
        ) ON DUPLICATE KEY UPDATE
            theme = VALUES(theme),
            allow_card_delete = VALUES(allow_card_delete),
            highlight_past_due = VALUES(highlight_past_due),
            past_due_days = VALUES(past_due_days),
            delivered_hide_days = VALUES(delivered_hide_days),
            google_drive_backup = VALUES(google_drive_backup)
    ");
    
    $result = $stmt->execute([
        'user_id' => $userId,
        'theme' => $theme,
        'allow_card_delete' => $allowCardDelete ? 1 : 0,
        'highlight_past_due' => $highlightPastDue ? 1 : 0,
        'past_due_days' => $pastDueDays,
        'delivered_hide_days' => $deliveredHideDays,
        'google_drive_backup' => $googleDriveBackup ? 1 : 0
    ]);
    
    // Update practice settings (name and logo) if user is an admin of the practice
    // and allow any user who belongs to the practice to update assignment labels
    if (isset($_SESSION['current_practice_id'])) {
        $currentPracticeId = $_SESSION['current_practice_id'];
        
        // Check if user belongs to this practice and get their role
        $stmt = $pdo->prepare("SELECT role FROM practice_users WHERE practice_id = :practice_id AND user_id = :user_id");
        $stmt->execute([
            'practice_id' => $currentPracticeId,
            'user_id' => $userId
        ]);
        $userRole = $stmt->fetchColumn();
        $isPracticeMember = !empty($userRole);
        
        if ($userRole === 'admin') {
            // Handle display name update (separate from legal name which is immutable)
            $displayName = isset($data['displayName']) ? trim($data['displayName']) : '';
            
            if (!empty($displayName)) {
                // Update display_name (editable) and practice_name (legacy field for UI)
                // Note: legal_name is IMMUTABLE and cannot be changed after BAA acceptance
                $stmt = $pdo->prepare("UPDATE practices SET display_name = :display_name, practice_name = :practice_name WHERE id = :id");
                $stmt->execute([
                    'display_name' => $displayName,
                    'practice_name' => $displayName,
                    'id' => $currentPracticeId
                ]);
                
                // Update session with new display name
                $_SESSION['practice_name'] = $displayName;
                
                userLog("Updated display name for practice {$currentPracticeId} to '{$displayName}'", false);
            }
            // Legacy support: also check practiceName for backwards compatibility
            elseif (!empty($practiceName)) {
                $stmt = $pdo->prepare("UPDATE practices SET display_name = :display_name, practice_name = :practice_name WHERE id = :id");
                $stmt->execute([
                    'display_name' => $practiceName,
                    'practice_name' => $practiceName,
                    'id' => $currentPracticeId
                ]);
                
                // Update session with new practice name
                $_SESSION['practice_name'] = $practiceName;
                
                userLog("Updated display name for practice {$currentPracticeId} to '{$practiceName}'", false);
            }

            // Handle logo removal if requested
            if ($logoAction === 'remove') {
                // Get current logo path
                $stmt = $pdo->prepare("SELECT logo_path FROM practices WHERE id = :practice_id");
                $stmt->execute(['practice_id' => $currentPracticeId]);
                $currentLogoPath = $stmt->fetchColumn();

                // Clear logo_path in DB
                $stmt = $pdo->prepare("UPDATE practices SET logo_path = NULL WHERE id = :practice_id");
                $stmt->execute(['practice_id' => $currentPracticeId]);

                // Delete logo file if it exists on disk
                if ($currentLogoPath) {
                    $fullPath = __DIR__ . '/../' . $currentLogoPath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }

                userLog("Removed logo for practice {$currentPracticeId}", false);
            }
            // Handle logo update if requested and a path was provided
            elseif ($logoAction === 'update' && $logoPath !== '') {
                $stmt = $pdo->prepare("UPDATE practices SET logo_path = :logo_path WHERE id = :practice_id");
                $stmt->execute([
                    'logo_path' => $logoPath,
                    'practice_id' => $currentPracticeId
                ]);

                userLog("Updated logo for practice {$currentPracticeId} to '{$logoPath}'", false);
            }

            // Workflow Stage Names - already validated atomically above
            // (before any writes in this request) via
            // normalizeWorkflowStageLabelsForSave(). Persist only the
            // overrides that differ from the default label; an empty
            // result clears the column back to NULL (all defaults).
            ensureWorkflowStageLabelsColumn();
            $workflowStageLabelsJson = empty($workflowStageLabelOverridesToSave)
                ? null
                : json_encode($workflowStageLabelOverridesToSave);
            $stmt = $pdo->prepare("UPDATE practices SET workflow_stage_labels = :labels WHERE id = :practice_id");
            $stmt->execute([
                'labels' => $workflowStageLabelsJson,
                'practice_id' => $currentPracticeId
            ]);

            userLog("Updated workflow stage labels for practice {$currentPracticeId}", false);
        } else {
            // Not an admin; log any attempted changes to practice name or logo
            if (!empty($practiceName) || $logoAction === 'remove') {
                userLog("User {$userId} attempted to update practice name or logo but is not an admin", true);
            }
        }

        // Update assignment labels for this practice. Management
        // (create/rename/delete) is restricted to Practice Administrators -
        // there is no separate can_add_labels permission. Non-admins can
        // still view/use labels (loaded via get-settings.php for the
        // assignment dropdown); they just can't change the label set.
        //
        // IMPORTANT: practice_assignment_labels.id must be preserved across
        // saves - it is the stable Lab identity used by
        // case_lab_assignment_periods (see lab-assignment-history.php).
        // Nothing below may delete-all/reinsert every row for a practice;
        // only genuinely removed labels are ever deleted.
        $showLabInsightsFlag = isFeatureEnabled('SHOW_LAB_INSIGHTS');

        if ($userRole === 'admin') {
            try {
                if ($assignmentLabelsDetailed === null) {
                    // Legacy client: only the plain string array was submitted.
                    if ($showLabInsightsFlag && !empty($validAssignmentLabels)) {
                        // Once Lab Insights is enabled, a stale/legacy client
                        // attempting to change labels without stable IDs could
                        // corrupt Lab identity (e.g. mis-detect a rename as a
                        // delete+add). Reject safely instead of guessing.
                        http_response_code(409);
                        echo json_encode([
                            'success' => false,
                            'reload_required' => true,
                            'message' => 'Please reload the page before making changes to Assignment Labels.'
                        ]);
                        exit;
                    }

                    // Reconcile by case-insensitive text, preserving id/is_lab
                    // for every label whose text is unchanged. Never
                    // delete-all/reinsert.
                    $existingStmt = $pdo->prepare("SELECT id, label, is_lab FROM practice_assignment_labels WHERE practice_id = :practice_id");
                    $existingStmt->execute(['practice_id' => $currentPracticeId]);
                    $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

                    $existingByNormalized = [];
                    foreach ($existingRows as $row) {
                        $existingByNormalized[mb_strtolower(trim($row['label']))] = $row;
                    }

                    $submittedNormalized = [];
                    foreach ($validAssignmentLabels as $label) {
                        $submittedNormalized[] = mb_strtolower(trim($label));
                    }

                    $removedRows = [];
                    foreach ($existingRows as $row) {
                        if (!in_array(mb_strtolower(trim($row['label'])), $submittedNormalized, true)) {
                            $removedRows[] = $row;
                        }
                    }

                    $blockedLabels = checkAssignmentLabelsInUse($pdo, $currentPracticeId, $removedRows);
                    if (!empty($blockedLabels)) {
                        respondAssignmentLabelsInUse($blockedLabels);
                    }

                    $pdo->beginTransaction();
                    try {
                        $sortOrder = 0;
                        foreach ($validAssignmentLabels as $label) {
                            $norm = mb_strtolower(trim($label));
                            if (isset($existingByNormalized[$norm])) {
                                $row = $existingByNormalized[$norm];
                                $stmt = $pdo->prepare("UPDATE practice_assignment_labels SET label = :label, sort_order = :sort_order WHERE id = :id AND practice_id = :practice_id");
                                $stmt->execute([
                                    'label' => $label,
                                    'sort_order' => $sortOrder,
                                    'id' => $row['id'],
                                    'practice_id' => $currentPracticeId
                                ]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO practice_assignment_labels (practice_id, label, sort_order, is_lab) VALUES (:practice_id, :label, :sort_order, 0)");
                                $stmt->execute([
                                    'practice_id' => $currentPracticeId,
                                    'label' => $label,
                                    'sort_order' => $sortOrder
                                ]);
                            }
                            $sortOrder++;
                        }

                        foreach ($removedRows as $row) {
                            $stmt = $pdo->prepare("DELETE FROM practice_assignment_labels WHERE id = :id AND practice_id = :practice_id");
                            $stmt->execute(['id' => $row['id'], 'practice_id' => $currentPracticeId]);
                            // Legacy payload can't distinguish "designation
                            // removed" from "label deleted" - if it was ever
                            // Lab-flagged, close any open periods either way.
                            if (!empty($row['is_lab'])) {
                                closeOpenLabPeriodsForEntity($currentPracticeId, 'label', (int)$row['id'], 'lab_designation_removed');
                            }
                        }

                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    userLog("Updated assignment labels (legacy payload) for practice {$currentPracticeId} (" . count($validAssignmentLabels) . " labels) by user {$userId}", false);
                } else {
                    // Stable-ID payload: authoritative duplicate validation,
                    // ID-based update/insert/delete, rename propagation, and
                    // Lab-designation lifecycle - all in one transaction.

                    // Authoritative server-side duplicate check, by submitted
                    // array index (never by id, since two new labels both
                    // have id = null and must still be caught).
                    $dupErrors = [];
                    foreach ($assignmentLabelsDetailed as $i => $item) {
                        $normI = mb_strtolower($item['label']);
                        foreach ($assignmentLabelsDetailed as $j => $other) {
                            if ($i === $j) {
                                continue;
                            }
                            if (mb_strtolower($other['label']) === $normI) {
                                $dupErrors[$item['label']] = true;
                            }
                        }
                    }
                    if (!empty($dupErrors)) {
                        http_response_code(409);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Duplicate label(s): ' . implode(', ', array_keys($dupErrors))
                        ]);
                        exit;
                    }

                    $existingStmt = $pdo->prepare("SELECT id, label, is_lab FROM practice_assignment_labels WHERE practice_id = :practice_id");
                    $existingStmt->execute(['practice_id' => $currentPracticeId]);
                    $existingRowsById = [];
                    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $existingRowsById[(int)$row['id']] = $row;
                    }

                    // SECURITY / DATA INTEGRITY: a non-null id must be an
                    // existing row for THIS practice. Silently falling back
                    // to "insert as new" for an unmatched id (e.g. one that
                    // belongs to another practice, or one that's simply
                    // stale) would violate stable-ID semantics - the client
                    // believes it's updating a specific row, not creating a
                    // duplicate. Reject the whole save instead of guessing.
                    // The response is intentionally identical whether the id
                    // belongs to another practice or doesn't exist at all,
                    // so this can never be used to probe for the existence
                    // of another practice's label.
                    foreach ($assignmentLabelsDetailed as $item) {
                        if ($item['id'] !== null && !isset($existingRowsById[$item['id']])) {
                            http_response_code(409);
                            echo json_encode([
                                'success' => false,
                                'reload_required' => true,
                                'message' => 'Assignment Label data is out of date. Please reload Settings and try again.'
                            ]);
                            exit;
                        }
                    }

                    $submittedIds = [];
                    foreach ($assignmentLabelsDetailed as $item) {
                        if ($item['id'] !== null) {
                            $submittedIds[$item['id']] = true;
                        }
                    }

                    $removedRows = [];
                    foreach ($existingRowsById as $id => $row) {
                        if (!isset($submittedIds[$id])) {
                            $removedRows[] = $row;
                        }
                    }

                    $blockedLabels = checkAssignmentLabelsInUse($pdo, $currentPracticeId, $removedRows);
                    if (!empty($blockedLabels)) {
                        respondAssignmentLabelsInUse($blockedLabels);
                    }

                    $pdo->beginTransaction();
                    try {
                        $sortOrder = 0;
                        foreach ($assignmentLabelsDetailed as $item) {
                            $labelText = $item['label'];
                            $newIsLab = !empty($item['isLab']) ? 1 : 0;
                            $id = $item['id'];

                            if ($id !== null && isset($existingRowsById[$id])) {
                                $oldRow = $existingRowsById[$id];
                                $oldLabel = $oldRow['label'];
                                $oldIsLab = (int)$oldRow['is_lab'];

                                $stmt = $pdo->prepare("UPDATE practice_assignment_labels SET label = :label, sort_order = :sort_order, is_lab = :is_lab WHERE id = :id AND practice_id = :practice_id");
                                $stmt->execute([
                                    'label' => $labelText,
                                    'sort_order' => $sortOrder,
                                    'is_lab' => $newIsLab,
                                    'id' => $id,
                                    'practice_id' => $currentPracticeId
                                ]);

                                // Rename propagation: same id, different text.
                                // Pure text substitution on currently-assigned
                                // cases - NOT routed through
                                // recordLabAssignmentChange(), so it never
                                // opens/closes a lab period or counts as a
                                // transfer. Historical snapshots are untouched.
                                if (mb_strtolower(trim($oldLabel)) !== mb_strtolower($labelText)) {
                                    $propStmt = $pdo->prepare("
                                        UPDATE cases_cache
                                        SET assigned_to = :new_label
                                        WHERE practice_id = :practice_id
                                          AND LOWER(TRIM(assigned_to)) = LOWER(TRIM(:old_label))
                                    ");
                                    $propStmt->execute([
                                        'new_label' => $labelText,
                                        'practice_id' => $currentPracticeId,
                                        'old_label' => $oldLabel
                                    ]);
                                }

                                // Lab designation lifecycle.
                                if ($oldIsLab === 0 && $newIsLab === 1) {
                                    initializeOpenLabPeriodsForEntity($currentPracticeId, 'label', $id, $labelText);
                                } elseif ($oldIsLab === 1 && $newIsLab === 0) {
                                    closeOpenLabPeriodsForEntity($currentPracticeId, 'label', $id, 'lab_designation_removed');
                                }
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO practice_assignment_labels (practice_id, label, sort_order, is_lab) VALUES (:practice_id, :label, :sort_order, :is_lab)");
                                $stmt->execute([
                                    'practice_id' => $currentPracticeId,
                                    'label' => $labelText,
                                    'sort_order' => $sortOrder,
                                    'is_lab' => $newIsLab
                                ]);
                                $newId = (int)$pdo->lastInsertId();
                                if ($newIsLab === 1) {
                                    initializeOpenLabPeriodsForEntity($currentPracticeId, 'label', $newId, $labelText);
                                }
                            }
                            $sortOrder++;
                        }

                        foreach ($removedRows as $row) {
                            $stmt = $pdo->prepare("DELETE FROM practice_assignment_labels WHERE id = :id AND practice_id = :practice_id");
                            $stmt->execute(['id' => $row['id'], 'practice_id' => $currentPracticeId]);
                            if (!empty($row['is_lab'])) {
                                closeOpenLabPeriodsForEntity($currentPracticeId, 'label', (int)$row['id'], 'lab_designation_removed');
                            }
                        }

                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    userLog("Updated assignment labels (detailed payload) for practice {$currentPracticeId} (" . count($assignmentLabelsDetailed) . " labels) by user {$userId}", false);
                }
            } catch (PDOException $e) {
                userLog("Error updating assignment labels for practice {$currentPracticeId} by user {$userId}: " . $e->getMessage(), true);
            }
        } else {
            if (!empty($validAssignmentLabels) || !empty($assignmentLabelsDetailed)) {
                userLog("User {$userId} attempted to update assignment labels but is not an admin of practice {$currentPracticeId}", true);
            }
        }
    }
    
    // Get current practice ID
    $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
    if (!$currentPracticeId) {
        throw new Exception('No practice selected');
    }
    
    if ($userRole === 'admin') {
        // Get practice creator ID
        $stmt = $pdo->prepare("SELECT created_by FROM practices WHERE id = :practice_id");
        $stmt->execute(['practice_id' => $currentPracticeId]);
        $practiceCreatorId = $stmt->fetchColumn();
        
        // Don't allow modifying the practice creator's role
        // Get creator email to preserve in admin list
        $creatorEmail = null;
        if ($practiceCreatorId) {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id");
            $stmt->execute(['user_id' => $practiceCreatorId]);
            $creatorEmail = $stmt->fetchColumn();
            
            // Make sure creator is in admin list
            if ($creatorEmail && !in_array($creatorEmail, $validAdminUsers)) {
                // Add creator to beginning of admin list
                array_unshift($validAdminUsers, $creatorEmail);
            }
        }
        
        // Begin transaction to ensure consistency
        $pdo->beginTransaction();
        
        try {
            // Lab Insights foundation: capture each user's CURRENT is_lab
            // value before the delete/reinsert below, so a genuine 0->1 or
            // 1->0 transition can be detected afterward (practice_users.id
            // is not stable across this delete/reinsert, but user_id is -
            // this map is keyed by user_id, not by practice_users.id).
            $oldIsLabByUserId = [];
            $oldIsLabStmt = $pdo->prepare("SELECT user_id, is_lab FROM practice_users WHERE practice_id = :practice_id");
            $oldIsLabStmt->execute(['practice_id' => $currentPracticeId]);
            foreach ($oldIsLabStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $oldIsLabByUserId[(int)$row['user_id']] = (int)$row['is_lab'];
            }

            // First, remove all non-creator users from the practice
            $stmt = $pdo->prepare("
                DELETE FROM practice_users 
                WHERE practice_id = :practice_id AND user_id != :creator_id
            ");
            $stmt->execute([
                'practice_id' => $currentPracticeId,
                'creator_id' => $practiceCreatorId ?? 0
            ]);
            
            userLog("Removed all non-creator users from practice {$currentPracticeId}", false);

            // The practice creator's row is never deleted/reinserted above
            // (loops below skip the creator entirely), so handle their Lab
            // designation as a direct UPDATE, still inside this transaction.
            if ($practiceCreatorId && $creatorEmail) {
                $creatorNewIsLab = isset($isLabUsers[$creatorEmail]) && $isLabUsers[$creatorEmail] ? 1 : 0;
                $creatorOldIsLab = $oldIsLabByUserId[(int)$practiceCreatorId] ?? 0;
                if ($creatorOldIsLab !== $creatorNewIsLab) {
                    $stmt = $pdo->prepare("UPDATE practice_users SET is_lab = :is_lab WHERE practice_id = :practice_id AND user_id = :user_id");
                    $stmt->execute([
                        'is_lab' => $creatorNewIsLab,
                        'practice_id' => $currentPracticeId,
                        'user_id' => $practiceCreatorId
                    ]);
                    if ($creatorOldIsLab === 0 && $creatorNewIsLab === 1) {
                        initializeOpenLabPeriodsForEntity($currentPracticeId, 'user', (int)$practiceCreatorId, $creatorEmail);
                    } elseif ($creatorOldIsLab === 1 && $creatorNewIsLab === 0) {
                        closeOpenLabPeriodsForEntity($currentPracticeId, 'user', (int)$practiceCreatorId, 'lab_designation_removed');
                    }
                }
            }
            
            // Process admin users
            userLog("Processing admin users: " . count($validAdminUsers) . " - " . implode(", ", $validAdminUsers), false);
            
            foreach ($validAdminUsers as $email) {
                // Skip if it's the creator (already in the database)
                if ($email === $creatorEmail) continue;
                
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                $userId = $stmt->fetchColumn();
                
                if (!$userId) {
                    // Create user if they don't exist
                    $stmt = $pdo->prepare("
                        INSERT INTO users (email, role, is_active, created_at)
                        VALUES (:email, 'user', 1, NOW())
                    ");
                    $stmt->execute(['email' => $email]);
                    $userId = $pdo->lastInsertId();
                }
                
                // Check permissions for this user (default: limited=false, analytics=true, edit=true)
                $hasLimitedVisibility = isset($limitedVisibilityUsers[$email]) && $limitedVisibilityUsers[$email] ? 1 : 0;
                $canViewAnalytics = isset($canViewAnalyticsUsers[$email]) ? ($canViewAnalyticsUsers[$email] ? 1 : 0) : 1;
                $canEditCases = isset($canEditCasesUsers[$email]) ? ($canEditCasesUsers[$email] ? 1 : 0) : 1;
                $newIsLab = isset($isLabUsers[$email]) && $isLabUsers[$email] ? 1 : 0;
                
                // Add user to practice as admin
                $stmt = $pdo->prepare("
                    INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, can_view_analytics, can_edit_cases, is_lab, created_at)
                    VALUES (:practice_id, :user_id, 'admin', 0, :limited_visibility, :can_view_analytics, :can_edit_cases, :is_lab, NOW())
                ");
                $stmt->execute([
                    'practice_id' => $currentPracticeId,
                    'user_id' => $userId,
                    'limited_visibility' => $hasLimitedVisibility,
                    'can_view_analytics' => $canViewAnalytics,
                    'can_edit_cases' => $canEditCases,
                    'is_lab' => $newIsLab
                ]);

                $oldIsLab = $oldIsLabByUserId[(int)$userId] ?? 0;
                if ($oldIsLab === 0 && $newIsLab === 1) {
                    initializeOpenLabPeriodsForEntity($currentPracticeId, 'user', (int)$userId, $email);
                } elseif ($oldIsLab === 1 && $newIsLab === 0) {
                    closeOpenLabPeriodsForEntity($currentPracticeId, 'user', (int)$userId, 'lab_designation_removed');
                }
            }
            
            // Process regular users
            userLog("Processing regular users: " . count($validGmailUsers) . " - " . implode(", ", $validGmailUsers), false);
            
            foreach ($validGmailUsers as $email) {
                // Skip if user is already an admin
                if (in_array($email, $validAdminUsers)) continue;
                
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                $userId = $stmt->fetchColumn();
                
                if (!$userId) {
                    // Create user if they don't exist
                    $stmt = $pdo->prepare("
                        INSERT INTO users (email, role, is_active, created_at)
                        VALUES (:email, 'user', 1, NOW())
                    ");
                    $stmt->execute(['email' => $email]);
                    $userId = $pdo->lastInsertId();
                }
                
                // Check permissions for this user (default: limited=false, analytics=true, edit=true)
                $hasLimitedVisibility = isset($limitedVisibilityUsers[$email]) && $limitedVisibilityUsers[$email] ? 1 : 0;
                $canViewAnalytics = isset($canViewAnalyticsUsers[$email]) ? ($canViewAnalyticsUsers[$email] ? 1 : 0) : 1;
                $canEditCases = isset($canEditCasesUsers[$email]) ? ($canEditCasesUsers[$email] ? 1 : 0) : 1;
                $newIsLab = isset($isLabUsers[$email]) && $isLabUsers[$email] ? 1 : 0;
                
                // Add user to practice as regular user
                $stmt = $pdo->prepare("
                    INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, can_view_analytics, can_edit_cases, is_lab, created_at)
                    VALUES (:practice_id, :user_id, 'user', 0, :limited_visibility, :can_view_analytics, :can_edit_cases, :is_lab, NOW())
                ");
                $stmt->execute([
                    'practice_id' => $currentPracticeId,
                    'user_id' => $userId,
                    'limited_visibility' => $hasLimitedVisibility,
                    'can_view_analytics' => $canViewAnalytics,
                    'can_edit_cases' => $canEditCases,
                    'is_lab' => $newIsLab
                ]);

                $oldIsLab = $oldIsLabByUserId[(int)$userId] ?? 0;
                if ($oldIsLab === 0 && $newIsLab === 1) {
                    initializeOpenLabPeriodsForEntity($currentPracticeId, 'user', (int)$userId, $email);
                } elseif ($oldIsLab === 1 && $newIsLab === 0) {
                    closeOpenLabPeriodsForEntity($currentPracticeId, 'user', (int)$userId, 'lab_designation_removed');
                }
            }
            
            $pdo->commit();
            userLog("Successfully updated practice users for practice {$currentPracticeId}", false);

            foreach ($validAdminUsers as $email) {
                try {
                    sharePracticeRootWithEmail($currentPracticeId, $email, 'writer');
                } catch (Exception $e) {
                }
            }

            foreach ($validGmailUsers as $email) {
                if (in_array($email, $validAdminUsers)) {
                    continue;
                }

                try {
                    sharePracticeRootWithEmail($currentPracticeId, $email, 'writer');
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
            // Roll back transaction on error
            $pdo->rollBack();
            userLog("Error updating practice users: " . $e->getMessage(), true);
        }
    } else {
        if (!empty($validAdminUsers) || !empty($validGmailUsers)) {
            userLog("User {$userId} attempted to modify practice users but is not an admin of practice {$currentPracticeId}", true);
        }
    }
    
    // Update session data
    $_SESSION['user_preferences'] = [
        'theme' => $theme,
        'allow_card_delete' => $allowCardDelete,
        'highlight_past_due' => $highlightPastDue,
        'past_due_days' => $pastDueDays,
        'delivered_hide_days' => $deliveredHideDays,
        'google_drive_backup' => $googleDriveBackup
    ];
    
    // Log the activity
    logUserActivity($userId, 'update_settings', 'User updated preferences');

    // Return the authoritative, post-commit assignment-label state so the
    // client can refresh window.assignmentLabelsMeta with real database IDs
    // immediately - without this, a newly-added label would keep id=null
    // client-side until Settings is reopened, and a second save (before
    // reopening) could re-insert it as a duplicate instead of updating it.
    $freshLabelsStmt = $pdo->prepare("SELECT id, label, is_lab FROM practice_assignment_labels WHERE practice_id = :practice_id ORDER BY sort_order ASC, id ASC");
    $freshLabelsStmt->execute(['practice_id' => $currentPracticeId]);
    $freshAssignmentLabelsDetailed = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'label' => $row['label'],
            'isLab' => (bool)$row['is_lab']
        ];
    }, $freshLabelsStmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully',
        'assignmentLabelsDetailed' => $freshAssignmentLabelsDetailed,
        // Fully-resolved six-entry map reflecting exactly what was just
        // persisted (normalized: trimmed, blanks removed) - the client
        // uses this to update window.workflowStageLabels, the Kanban
        // headers, and the status dropdown immediately, with no reload.
        'workflowStageLabels' => getResolvedWorkflowStageLabels($workflowStageLabelOverridesToSave)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while saving settings'
    ]);
    
    userLog("Error saving user settings: " . $e->getMessage(), true);
}
