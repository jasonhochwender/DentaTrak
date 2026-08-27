<?php
/**
 * Generate Dental Practice Demo Data
 *
 * Purpose-built Dev Tools generator that populates the CURRENT practice
 * ($_SESSION['current_practice_id']) with a curated, realistic demo dataset
 * (active + historical cases) for marketing screenshots, demos, training,
 * and general testing. Works for whatever practice is currently selected -
 * it is not tied to any specific practice name.
 *
 * SAFETY: Scoped entirely to the current practice via requireValidPracticeContext()
 * and $currentPracticeId - every write uses that ID, exactly like
 * generate-fake-cases.php. It never creates a practice, and if the current
 * practice already has a meaningful amount of case data it asks for
 * confirmation before adding more (see $significantDataThreshold below).
 *
 * Architecture: follows the same pattern as api/generate-fake-cases.php -
 * writes directly to cases_cache (bypassing Google Drive, which real case
 * creation depends on) via the same saveCaseToCache()/PIIEncryption helpers.
 * Unlike generate-fake-cases.php, this also produces real case_activity_log
 * history (via logCaseActivity(), including its optional backdating param)
 * and real revision_count values (via incrementCaseRevisionCount()), so
 * generated cases behave like normal, lived-in DentaTrak records rather than
 * empty shells.
 *
 * NOTE on case_comments: api/case-comments.php is an HTTP endpoint file with
 * top-level request-routing code that executes immediately on require/include
 * (it reads $_SERVER['REQUEST_METHOD'] and php://input unconditionally). It is
 * NOT safe to require_once from another script - doing so previously caused
 * this generator to be short-circuited by that file's own "Case ID and
 * comment text required" validation error, because it interpreted this
 * generator's own POST body as a comment-creation request. This file
 * therefore does NOT require case-comments.php; it creates the case_comments
 * table and row itself using the exact same schema/columns as that endpoint.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/csrf.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Ensure the case_comments table exists. Duplicated (not required) from
 * api/case-comments.php, which cannot be safely require_once'd from another
 * script - see the file header note above. Schema matches that file exactly.
 */
function ensureDemoCaseCommentsTable() {
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
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[case_comments] Error creating table: ' . $e->getMessage());
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    // SECURITY: Require valid, authenticated practice membership. All writes
    // below are scoped to this ID - never another practice.
    $currentPracticeId = requireValidPracticeContext();

    // Dev tools access control (super user in UAT/Prod, always allowed in dev)
    $userEmail = $_SESSION['user_email'] ?? '';
    if (!canAccessDevTools($appConfig, $userEmail)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized to generate demo data.']);
        exit;
    }

    requireCsrfToken();

    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection is not configured.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $confirmed = !empty($input['confirmed']);

    // Ensure demo run schema is available for all actions
    ensureDemoGenerationRunsSchema();
    ensureDemoCaseCommentsTable();
    ensureLabAssignmentHistoryTable();
    ensureCasesCacheTable();

    // ------------------------------------------------------------------
    // Dataset size: small, standard (default), large
    // ------------------------------------------------------------------
    $dataset = isset($input['dataset']) && in_array($input['dataset'], ['small', 'standard', 'large'], true)
        ? $input['dataset']
        : 'standard';

    $datasetConfig = [
        'small' => [
            'activeCount' => 15,
            'historicalMonths' => 1,
            'showcaseCount' => 2,
            'monthBaseCount' => [2, 3, 3, 3, 3, 3],
        ],
        'standard' => [
            'activeCount' => 40,
            'historicalMonths' => 3,
            'showcaseCount' => 5,
            'monthBaseCount' => [5, 7, 8, 8, 9, 10],
        ],
        'large' => [
            'activeCount' => 80,
            'historicalMonths' => 6,
            'showcaseCount' => 10,
            'monthBaseCount' => [10, 13, 14, 14, 16, 18],
        ],
    ];
    $activeTarget = $datasetConfig[$dataset]['activeCount'];
    $historicalMonths = $datasetConfig[$dataset]['historicalMonths'];
    $showcaseTarget = $datasetConfig[$dataset]['showcaseCount'];

    // ------------------------------------------------------------------
    // Load the practice's current highlighting thresholds so boundary
    // cases match the user's actual settings.
    // ------------------------------------------------------------------
    $currentUserId = $_SESSION['db_user_id'] ?? 0;
    $prefStmt = $pdo->prepare("SELECT past_due_days, coming_due_days, appointment_risk_days FROM user_preferences WHERE user_id = :uid LIMIT 1");
    $prefStmt->execute(['uid' => $currentUserId]);
    $preferences = $prefStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pastDueDays = (int)($preferences['past_due_days'] ?? 1);
    $comingDueDays = (int)($preferences['coming_due_days'] ?? 5);
    $appointmentRiskDays = (int)($preferences['appointment_risk_days'] ?? 3);

    // Warn if the current practice already has a meaningful amount of case
    // data, so demo records aren't silently dumped into an actively used
    // practice. Kept intentionally simple - no duplicate-detection system,
    // just a count check.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = :pid AND archived = 0");
    $stmt->execute(['pid' => $currentPracticeId]);
    $existingActiveCount = (int)$stmt->fetchColumn();

    $significantDataThreshold = 5;
    if ($existingActiveCount > $significantDataThreshold && !$confirmed) {
        echo json_encode([
            'success' => false,
            'needsConfirmation' => true,
            'existingCaseCount' => $existingActiveCount,
            'message' => "This practice already has {$existingActiveCount} active case(s). "
                . 'This will ADD demo data on top of what exists (nothing will be deleted). Continue anyway?'
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // Create the generation run record
    // ------------------------------------------------------------------
    $runInsertStmt = $pdo->prepare("
        INSERT INTO demo_generation_runs (practice_id, dataset_size, created_by_user_id, created_by_email, status)
        VALUES (:practice_id, :dataset_size, :created_by_user_id, :created_by_email, 'pending')
    ");
    $runInsertStmt->execute([
        'practice_id' => $currentPracticeId,
        'dataset_size' => $dataset,
        'created_by_user_id' => $_SESSION['db_user_id'] ?? 0,
        'created_by_email' => $_SESSION['user_email'] ?? '',
    ]);
    $runId = (int)$pdo->lastInsertId();

    // ------------------------------------------------------------------
    // Resolve practice users (reuse whoever already belongs to the
    // practice; never create fake login accounts).
    // ------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.first_name, u.last_name
        FROM users u
        JOIN practice_users pu ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id
        ORDER BY u.id ASC
    ");
    $stmt->execute(['practice_id' => $currentPracticeId]);
    $practiceUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labDemoEmail = 'lab@dentatrak.com';
    $labUser = null;
    $staffUsers = [];
    foreach ($practiceUsers as $pu) {
        if (strcasecmp($pu['email'], $labDemoEmail) === 0) {
            $labUser = $pu;
        } else {
            $staffUsers[] = $pu;
        }
    }

    // ------------------------------------------------------------------
    // Ensure lab@dentatrak.com is a member of the CURRENT practice. This is
    // a required demo identity, not an optional one. We use the exact same
    // application logic/data relationships as the real "add team member by
    // email" flow (see api/save-settings.php's admin/user provisioning
    // block): if the email has no users row yet, one is created exactly as
    // save-settings.php does (INSERT INTO users ... role='user'); this does
    // NOT create a Google Workspace account or bypass authentication - the
    // real lab@dentatrak.com owner can still sign in with that email later
    // and will seamlessly assume this same pre-provisioned identity and
    // practice membership, the same way any admin-invited teammate does
    // today before their first login.
    // ------------------------------------------------------------------
    if ($labUser === null) {
        try {
            $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute(['email' => $labDemoEmail]);
            $existingLabAccount = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existingLabAccount) {
                $labUserId = $existingLabAccount['id'];
            } else {
                // Same pattern as save-settings.php's team-member provisioning:
                // create a placeholder users row for an email that has not
                // logged in yet.
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, role, is_active, created_at)
                    VALUES (:email, 'user', 1, NOW())
                ");
                $stmt->execute(['email' => $labDemoEmail]);
                $labUserId = $pdo->lastInsertId();
            }

            // Add (or confirm) practice membership: regular 'user' role (never
            // admin), restricted to seeing only cases assigned to them
            // (limited_visibility = 1, the same "Assigned Only" access level
            // already used for restricted staff), and no analytics access -
            // the minimum permissions appropriate for an external lab.
            $stmt = $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, can_view_analytics, can_edit_cases, created_at)
                VALUES (:practice_id, :user_id, 'user', 0, 1, 0, 1, NOW())
            ");
            $stmt->execute([
                'practice_id' => $currentPracticeId,
                'user_id' => $labUserId,
            ]);

            $labUser = [
                'id' => $labUserId,
                'email' => $labDemoEmail,
                'first_name' => $existingLabAccount['first_name'] ?? null,
                'last_name' => $existingLabAccount['last_name'] ?? null,
            ];
        } catch (PDOException $e) {
            error_log('[generate-dental-practice-demo-data] Failed to provision lab@dentatrak.com: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Could not add lab@dentatrak.com to this practice: ' . $e->getMessage()
                    . '. No demo data was generated. Please add lab@dentatrak.com to this practice '
                    . 'manually via Settings > Team, then try again.',
            ]);
            exit;
        }
    }

    // Weighted staff pool so some users naturally get more cases than others.
    $staffWeightedPool = [];
    foreach ($staffUsers as $idx => $u) {
        $weight = max(1, 4 - $idx); // 1st user weight 4, 2nd weight 3, 3rd weight 2, rest weight 1
        for ($w = 0; $w < $weight; $w++) {
            $staffWeightedPool[] = $u['email'];
        }
    }

    $labUserAvailable = ($labUser !== null);
    $staffAvailable = !empty($staffUsers);

    // ------------------------------------------------------------------
    // Weighted pools - sized to the selected dataset. Case types and
    // statuses are drawn from the actual Create Case form values.
    // ------------------------------------------------------------------
    $buildWeightedPool = function ($weights, $count) {
        $pool = [];
        foreach ($weights as $value => $weight) {
            if ($weight > 0) {
                for ($i = 0; $i < $weight; $i++) {
                    $pool[] = $value;
                }
            }
        }
        if (empty($pool) || $count <= 0) {
            return [];
        }
        shuffle($pool);
        $result = [];
        $total = count($pool);
        for ($i = 0; $i < $count; $i++) {
            $result[] = $pool[$i % $total];
            if (($i + 1) % $total === 0) {
                shuffle($pool);
            }
        }
        shuffle($result);
        return $result;
    };

    // Weights intentionally mirror a real dental practice distribution.
    $caseTypeWeights = [
        'Crown' => 8,
        'Bridge' => 4,
        'Veneer' => 3,
        'Inlay/Onlay' => 2,
        'Implant Crown' => 2,
        'Implant Surgical Guide' => 1,
        'Denture' => 2,
        'Partial' => 2,
        'AOX' => 1,
        'Orthodontic Appliance' => 1,
    ];

    $statusWeights = [
        'Originated' => 9,
        'Sent To External Lab' => 6,
        'Designed' => 6,
        'Manufactured' => 5,
        'Received From External Lab' => 5,
    ];

    $dueBucketWeights = [
        'overdue' => 2,
        'very_soon' => 3,
        'this_week' => 5,
        'few_weeks' => 8,
        'distant' => 7,
        'long_overdue' => 1,
    ];

    $revisionWeights = [
        0 => 55,
        1 => 16,
        2 => 8,
        3 => 2,
    ];

    $apptBucketWeights = [
        'none' => 6,
        'far_future' => 4,
        'within_threshold' => 3,
        'today' => 1,
        'past' => 2,
    ];

    $caseTypePool = $buildWeightedPool($caseTypeWeights, $activeTarget);
    $statusPool = $buildWeightedPool($statusWeights, $activeTarget);
    $dueBucketPool = $buildWeightedPool($dueBucketWeights, $activeTarget);
    $revisionPool = $buildWeightedPool($revisionWeights, $activeTarget);
    $apptBucketPool = $buildWeightedPool($apptBucketWeights, $activeTarget);

    $workflowStageOrder = [
        'Originated' => 0,
        'Sent To External Lab' => 1,
        'Designed' => 2,
        'Manufactured' => 3,
        'Received From External Lab' => 4,
        'Delivered' => 5,
    ];
    $stageNames = array_flip($workflowStageOrder);

    $firstNames = ['Emily', 'Michael', 'Olivia', 'Daniel', 'Sophia', 'Matthew', 'Ava', 'Andrew',
        'Isabella', 'Ryan', 'Grace', 'Nathan', 'Chloe', 'Brandon', 'Hannah', 'Tyler',
        'Madison', 'Justin', 'Natalie', 'Kevin'];
    $lastNames = ['Bennett', 'Foster', 'Coleman', 'Reyes', 'Whitfield', 'Sanders', 'Ellison',
        'Marsh', 'Donovan', 'Pierce', 'Callahan', 'Hutchins', 'Vance', 'Sutton',
        'Prescott', 'Doyle', 'Merritt', 'Lindqvist', 'Osei', 'Nakamura'];
    $dentistNames = ['Dr. Rebecca Hale', 'Dr. Thomas Ingram'];
    $genders = ['Male', 'Female'];
    $toothShades = ['A1', 'A2', 'A3', 'A3.5', 'B1', 'B2', 'C2', 'D2'];
    $materials = ['Zirconia', 'Lithium Disilicate', 'PFM', 'PFZ', '3D Printed'];
    $toothNumbers = range(1, 32);

    $notesPool = [
        'Shade confirmed with patient at try-in.',
        'Lab requested a new impression due to margin distortion.',
        'Patient appointment moved to next Thursday.',
        'Waiting on custom abutment from lab.',
        'Contact adjustment requested before seating.',
        'Photos sent to lab for shade matching.',
        'Rush requested - patient has an event coming up.',
        'Patient reported slight sensitivity at last visit, monitoring.',
        'Occlusion checked and adjusted chairside.',
        'Insurance pre-authorization confirmed.',
        'Lab confirmed receipt of impression and scan.',
        'Patient prefers afternoon appointments for the seat visit.',
        'Marginal fit was slightly off on first try-in, remake in progress.',
        'Front desk confirmed patient\'s next cleaning is already scheduled.',
        'Lab flagged a minor discrepancy in the digital scan, reviewing.',
        'Patient requested a lighter shade than originally selected.',
        'Temporary crown replaced after patient reported it loosening.',
        'Case reviewed with the doctor prior to sending to lab.',
        'Delivery delayed slightly due to lab backlog.',
    ];

    $commentPool = [
        'Can we confirm the shade with the patient before this goes out?',
        'Lab called, they need the opposing model rescanned.',
        'Heads up - patient wants to move up their appointment if possible.',
        'This one is a rush, please prioritize when it comes back.',
        'Double check the margin on this one before we send back to lab.',
    ];

    // ------------------------------------------------------------------
    // Helper functions
    // ------------------------------------------------------------------

    /**
     * Pick a realistic staff assignee email for a case. Lab assignment is
     * handled separately/deterministically (see $labCaseIndices below), so
     * this only ever returns a staff member (falling back to the lab user
     * only in the unlikely case the practice has no staff at all).
     */
    $pickAssignee = function ($statusIndex) use ($labUserAvailable, $labUser, $staffAvailable, $staffWeightedPool) {
        if ($staffAvailable) {
            return $staffWeightedPool[array_rand($staffWeightedPool)];
        }
        return $labUserAvailable ? $labUser['email'] : '';
    };

    $joinTeeth = function (array $teeth) {
        $teeth = array_unique(array_map('strval', $teeth));
        sort($teeth, SORT_NUMERIC);
        return implode(', ', $teeth);
    };

    $buildClinicalDetails = function ($caseType) use ($toothNumbers, $joinTeeth) {
        switch ($caseType) {
            case 'Crown':
                return ['toothNumber' => (string)$toothNumbers[array_rand($toothNumbers)]];
            case 'Bridge':
                $abutments = [$toothNumbers[array_rand($toothNumbers)], $toothNumbers[array_rand($toothNumbers)]];
                return [
                    'abutmentTeeth' => $joinTeeth($abutments),
                    'ponticTeeth' => (string)$toothNumbers[array_rand($toothNumbers)],
                ];
            case 'Implant Crown':
                return [
                    'implantToothNumber' => (string)$toothNumbers[array_rand($toothNumbers)],
                    'abutmentType' => ['Custom', 'Ti-Base', 'Zirconia'][array_rand(['Custom', 'Ti-Base', 'Zirconia'])],
                    'implantSystem' => ['Straumann', 'Nobel Biocare', 'Zimmer Biomet', 'Dentsply Sirona', 'BioHorizons'][random_int(0, 4)],
                    'platformSize' => ['3.5mm', '4.0mm', '4.1mm', '4.5mm', '5.0mm'][random_int(0, 4)],
                    'scanBodyUsed' => ['Elos Accurate', 'iTero scan body', 'Straumann CARES scan body', 'Medit scan body'][random_int(0, 3)],
                ];
            case 'Implant Surgical Guide':
                $sites = [$toothNumbers[array_rand($toothNumbers)], $toothNumbers[array_rand($toothNumbers)]];
                return [
                    'implantSites' => $joinTeeth($sites),
                    'implantSystem' => ['Straumann', 'Nobel Biocare', 'Zimmer Biomet', 'Dentsply Sirona', 'BioHorizons'][random_int(0, 4)],
                    'platformSize' => ['3.5mm', '4.0mm', '4.1mm', '4.5mm', '5.0mm'][random_int(0, 4)],
                ];
            case 'Denture':
                $details = [
                    'dentureJaw' => ['Maxillary', 'Mandibular', 'Both'][random_int(0, 2)],
                    'dentureType' => random_int(0, 1) ? 'Immediate' : 'Definitive',
                ];
                if (random_int(1, 100) <= 50) {
                    $details['gingivalShade'] = ['Light Pink', 'Pink', 'Dark Pink', 'Natural'][random_int(0, 3)];
                }
                return $details;
            case 'Partial':
                $numTeeth = random_int(2, 4);
                $teeth = [];
                for ($t = 0; $t < $numTeeth; $t++) {
                    $teeth[] = $toothNumbers[array_rand($toothNumbers)];
                }
                $details = [
                    'partialJaw' => ['Maxillary', 'Mandibular', 'Both'][random_int(0, 2)],
                    'teethToReplace' => $joinTeeth($teeth),
                    'partialMaterial' => ['Cast Metal', 'Valplast Flex Resin', 'Acrylic Base', 'Interim Acrylic'][random_int(0, 3)],
                ];
                if (random_int(1, 100) <= 50) {
                    $details['partialGingivalShade'] = ['Light Pink', 'Pink', 'Dark Pink', 'Natural'][random_int(0, 3)];
                }
                return $details;
            case 'AOX':
                if (random_int(1, 100) <= 50) {
                    return ['gingivalShade' => ['Light Pink', 'Pink', 'Dark Pink', 'Natural'][random_int(0, 3)]];
                }
                return null;
            default: // Veneer, Inlay/Onlay, Orthodontic Appliance - no clinical fields in the real form
                return null;
        }
    };

    $needsMaterial = ['Crown', 'Bridge', 'Implant Crown', 'Veneer', 'Inlay/Onlay'];

    /**
     * Simulate a chronologically-sound status journey for a case and record
     * it via real logCaseActivity() calls (backdated) and
     * incrementCaseRevisionCount() for each backward movement, using the
     * exact same "backward stage order" business rule as
     * update-case-status.php's isBackwardMovement().
     *
     * Returns the number of activity rows written.
     */
    $simulateJourney = function (
        $caseId, $creationTs, $endTs, $finalStatus, $revisionsWanted, $assignedTo, $notes, $isHistorical
    ) use ($workflowStageOrder, $stageNames) {
        $eventsWritten = 0;
        $finalIndex = $workflowStageOrder[$finalStatus];

        // Build a step sequence of stage indices starting at 0 (Originated)
        // and ending at $finalIndex, inserting $revisionsWanted backward
        // steps of exactly 1 stage each along the way (peaking above the
        // final stage when a revision needs to pull it back down again).
        $steps = [0];
        $current = 0;
        $revisionsLeft = $revisionsWanted;

        while ($current !== $finalIndex || $revisionsLeft > 0) {
            if ($revisionsLeft > 0 && $current < 5 && $current >= 1) {
                // Regress one stage back (a real "revision").
                $current -= 1;
                $steps[] = $current;
                $revisionsLeft--;
                // After regressing, move forward again toward final (and
                // possibly past it, if more revisions remain to consume).
                $forwardTarget = min(5, $finalIndex + $revisionsLeft);
                while ($current < $forwardTarget) {
                    $current++;
                    $steps[] = $current;
                }
            } elseif ($current < $finalIndex) {
                $current++;
                $steps[] = $current;
            } elseif ($current > $finalIndex) {
                // Shouldn't normally happen, but guard against infinite loops.
                $current--;
                $steps[] = $current;
            } else {
                // current === finalIndex but we still owe revisions and can't
                // regress further (e.g. finalIndex is 0) - bump forward once
                // so a backward step is possible, then let the loop continue.
                if ($revisionsLeft > 0 && $current === 0) {
                    $current = 1;
                    $steps[] = $current;
                } else {
                    break;
                }
            }
        }

        // Spread timestamps for each step evenly across [creationTs, endTs],
        // then add small jitter so they are not perfectly uniform.
        $totalSteps = count($steps);
        $span = max(1, $endTs - $creationTs);
        $timestamps = [$creationTs];
        for ($i = 1; $i < $totalSteps; $i++) {
            $fraction = $i / max(1, $totalSteps);
            $jitter = random_int(-3600 * 4, 3600 * 4);
            $ts = $creationTs + (int)round($span * $fraction) + $jitter;
            $ts = max($timestamps[$i - 1] + 60, min($ts, $endTs));
            $timestamps[] = $ts;
        }

        // case_created
        logCaseActivity($caseId, 'case_created', null, null, [
            'source' => 'generate-dental-practice-demo-data.php',
            'has_notes' => !empty($notes),
        ], date('Y-m-d H:i:s', $creationTs));
        $eventsWritten++;

        // assignment_set shortly after creation
        if (!empty($assignedTo)) {
            $assignTs = min($endTs, $creationTs + random_int(1800, 14400));
            logCaseActivity($caseId, 'assignment_set', null, null, [
                'assigned_to' => $assignedTo,
                'source' => 'generate-dental-practice-demo-data.php',
            ], date('Y-m-d H:i:s', $assignTs));
            $eventsWritten++;
        }

        // notes_updated shortly after, if notes exist
        if (!empty($notes)) {
            $notesTs = min($endTs, $creationTs + random_int(3600, 28800));
            logCaseActivity($caseId, 'notes_updated', null, null, [
                'length' => strlen($notes),
                'source' => 'generate-dental-practice-demo-data.php',
            ], date('Y-m-d H:i:s', $notesTs));
            $eventsWritten++;
        }

        // Walk the step sequence, logging status_changed / case_regression
        for ($i = 1; $i < $totalSteps; $i++) {
            $fromIdx = $steps[$i - 1];
            $toIdx = $steps[$i];
            $fromStatus = $stageNames[$fromIdx];
            $toStatus = $stageNames[$toIdx];
            $ts = date('Y-m-d H:i:s', $timestamps[$i]);

            if ($toIdx < $fromIdx) {
                $newCount = incrementCaseRevisionCount($caseId);
                logCaseActivity($caseId, 'case_regression', $fromStatus, $toStatus, [
                    'regression_number' => $newCount,
                    'reason' => 'Stage moved backward from ' . $fromStatus . ' to ' . $toStatus,
                    'source' => 'generate-dental-practice-demo-data.php',
                ], $ts);
            } else {
                logCaseActivity($caseId, 'status_changed', $fromStatus, $toStatus, [
                    'source' => 'generate-dental-practice-demo-data.php',
                ], $ts);
            }
            $eventsWritten++;
        }

        // Historical (Delivered + archived) cases get a final auto-archive event.
        if ($isHistorical) {
            $archiveTs = min($endTs, ($timestamps[$totalSteps - 1] ?? $creationTs) + random_int(3600, 3 * 86400));
            logCaseActivity($caseId, 'case_archived_auto', 'Delivered', null, [
                'delivered_hide_days' => 14,
                'source' => 'generate-dental-practice-demo-data.php',
            ], date('Y-m-d H:i:s', $archiveTs));
            $eventsWritten++;
        }

        return $eventsWritten;
    };

    $activityRecordsWritten = 0;
    $commentsWritten = 0;
    $activeCreated = 0;
    $historicalCreated = 0;
    $labActiveCaseCount = 0;
    $labHistoricalCaseCount = 0;
    $now = time();
    $today = strtotime(date('Y-m-d', $now));

    // ------------------------------------------------------------------
    // Mark the demo lab user as a lab so Lab Insights sees the assignment
    // periods as lab-owned rather than generic assignments.
    // ------------------------------------------------------------------
    if ($labUserAvailable) {
        $pdo->prepare("UPDATE practice_users SET is_lab = 1 WHERE practice_id = :practice_id AND user_id = :user_id")
            ->execute(['practice_id' => $currentPracticeId, 'user_id' => $labUser['id']]);
    }
    ensureLabAssignmentHistoryTable();

    $activeCreatedByNames = [];
    foreach ($staffUsers as $u) {
        $activeCreatedByNames[] = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'];
    }

    // ------------------------------------------------------------------
    // 1) ACTIVE CASES
    // ------------------------------------------------------------------

    $dueOffsetForBucket = function ($bucket) {
        switch ($bucket) {
            case 'overdue': return -random_int(1, 10);
            case 'long_overdue': return -random_int(20, 45);
            case 'very_soon': return random_int(0, 2);
            case 'this_week': return random_int(3, 7);
            case 'few_weeks': return random_int(8, 21);
            case 'distant': return random_int(22, 42);
        }
        return 7;
    };

    $apptOffsetForBucket = function ($bucket) use ($appointmentRiskDays) {
        switch ($bucket) {
            case 'none': return null;
            case 'far_future': return random_int(max(1, $appointmentRiskDays + 7), $appointmentRiskDays + 45);
            case 'within_threshold': return $appointmentRiskDays > 0 ? random_int(1, $appointmentRiskDays) : 0;
            case 'today': return 0;
            case 'past': return -random_int(1, 30);
        }
        return null;
    };

    $activeDefs = [];
    for ($i = 0; $i < $activeTarget; $i++) {
        $caseType = $caseTypePool[$i];
        $status = $statusPool[$i];
        $statusIdx = $workflowStageOrder[$status];
        $revisions = $revisionPool[$i];
        $dueBucket = $dueBucketPool[$i];
        $apptBucket = $apptBucketPool[$i];

        $ageDaysMin = [0 => 2, 1 => 5, 2 => 10, 3 => 15, 4 => 20][$statusIdx];
        $ageDaysMax = [0 => 8, 1 => 14, 2 => 20, 3 => 28, 4 => 35][$statusIdx];
        $ageDaysMin += $revisions * 4;
        $ageDaysMax += $revisions * 7;

        $creationTs = $today - (86400 * random_int($ageDaysMin, $ageDaysMax)) - random_int(0, 43200);

        $dueTs = $today + (86400 * $dueOffsetForBucket($dueBucket));
        if ($dueTs <= $creationTs) {
            $dueTs = $creationTs + (86400 * random_int(5, 15));
        }

        $apptOffset = $apptOffsetForBucket($apptBucket);
        $apptTs = $apptOffset !== null ? $today + (86400 * $apptOffset) : null;

        $activeDefs[] = [
            'caseType' => $caseType,
            'status' => $status,
            'statusIdx' => $statusIdx,
            'revisions' => $revisions,
            'creationTs' => $creationTs,
            'dueTs' => $dueTs,
            'apptTs' => $apptTs,
            'notes' => null,
            'assignedTo' => null,
            'showcase' => null,
        ];
    }

    // Pick showcase cases up to the configured target.
    $showcaseIdx = [];
    foreach ($activeDefs as $idx => $def) {
        if ($def['revisions'] >= 3 && !in_array('three_revisions', $showcaseIdx, true)) {
            $showcaseIdx['three_revisions'] = $idx;
        }
    }
    foreach ($activeDefs as $idx => $def) {
        if ($def['dueTs'] < $today && !isset($showcaseIdx['overdue']) && !in_array($idx, $showcaseIdx, true)) {
            $showcaseIdx['overdue'] = $idx;
        }
    }
    foreach ($activeDefs as $idx => $def) {
        if ($def['statusIdx'] >= 1 && $def['statusIdx'] <= 3 && !isset($showcaseIdx['at_lab']) && !in_array($idx, $showcaseIdx, true)) {
            $showcaseIdx['at_lab'] = $idx;
        }
    }
    foreach ($activeDefs as $idx => $def) {
        if ($def['statusIdx'] === 0 && !isset($showcaseIdx['unassigned']) && !in_array($idx, $showcaseIdx, true)) {
            $showcaseIdx['unassigned'] = $idx;
        }
    }
    foreach ($activeDefs as $idx => $def) {
        if ($def['revisions'] >= 1 && $def['statusIdx'] >= 2 && !isset($showcaseIdx['comments']) && !in_array($idx, $showcaseIdx, true)) {
            $showcaseIdx['comments'] = $idx;
        }
    }
    // Appointment-risk showcase
    foreach ($activeDefs as $idx => $def) {
        if ($def['apptTs'] !== null && $def['apptTs'] <= $today + (86400 * $appointmentRiskDays) && !isset($showcaseIdx['appt_risk']) && !in_array($idx, $showcaseIdx, true)) {
            $showcaseIdx['appt_risk'] = $idx;
        }
    }

    $showcaseIndices = array_slice(array_values($showcaseIdx), 0, $showcaseTarget);

    // Active lab cases: external-lab statuses + a few in other stages.
    $labCaseIndices = [];
    if ($labUserAvailable) {
        $labEligibleIndices = [];
        foreach ($activeDefs as $idx => $def) {
            if ($def['statusIdx'] >= 1 && $def['statusIdx'] <= 4) {
                $labEligibleIndices[] = $idx;
            }
        }
        shuffle($labEligibleIndices);

        $labTargetCount = max(3, (int)round($activeTarget * 0.25));
        $labTargetCount = min(count($labEligibleIndices), $labTargetCount);
        $labCaseIndices = array_slice($labEligibleIndices, 0, $labTargetCount);

        if (isset($showcaseIdx['at_lab']) && !in_array($showcaseIdx['at_lab'], $labCaseIndices, true)) {
            $labCaseIndices[] = $showcaseIdx['at_lab'];
        }
        $labCaseIndices = array_values(array_unique($labCaseIndices));
    }

    foreach ($activeDefs as $idx => &$def) {
        $showcaseKey = array_search($idx, $showcaseIdx, true);
        $isShowcase = $showcaseKey !== false && in_array($idx, $showcaseIndices, true);

        if ($showcaseKey === 'unassigned') {
            $def['assignedTo'] = '';
        } elseif ($labUserAvailable && in_array($idx, $labCaseIndices, true)) {
            $def['assignedTo'] = $labUser['email'];
        } else {
            $def['assignedTo'] = $pickAssignee($def['statusIdx']);
        }

        if ($isShowcase || random_int(1, 100) <= 55) {
            $def['notes'] = $notesPool[array_rand($notesPool)];
        } else {
            $def['notes'] = '';
        }

        $def['showcase'] = $showcaseKey !== false ? $showcaseKey : null;
    }
    unset($def);

    $demoCaseCreatorIds = [];
    foreach ($activeDefs as $def) {
        $caseId = 'demo_' . uniqid('', true);
        $firstName = $firstNames[array_rand($firstNames)];
        $lastName = $lastNames[array_rand($lastNames)];
        $dentist = $dentistNames[array_rand($dentistNames)];
        $dobTs = strtotime('-' . random_int(10, 80) . ' years', $now);

        $creator = $staffAvailable ? $staffUsers[array_rand($staffUsers)] : null;
        $createdByUserId = $creator ? (int)$creator['id'] : ($_SESSION['db_user_id'] ?? null);
        $createdByName = $creator ? (trim($creator['first_name'] . ' ' . $creator['last_name']) ?: $creator['email']) : 'Demo Generator';
        $demoCaseCreatorIds[] = $caseId;

        $caseData = [
            'id' => $caseId,
            'driveFolderId' => null,
            'patientFirstName' => $firstName,
            'patientLastName' => $lastName,
            'patientDOB' => date('Y-m-d', $dobTs),
            'patientGender' => $genders[array_rand($genders)],
            'dentistName' => $dentist,
            'caseType' => $def['caseType'],
            'toothShade' => $toothShades[array_rand($toothShades)],
            'dueDate' => date('Y-m-d', $def['dueTs']),
            'patientAppointmentDate' => $def['apptTs'] !== null ? date('Y-m-d', $def['apptTs']) : null,
            'creationDate' => date('c', $def['creationTs']),
            'lastUpdateDate' => date('c', min($now, $def['creationTs'] + random_int(3600, max(3600, $now - $def['creationTs'])))),
            'status' => $def['status'],
            'statusChangedAt' => date('c', min($now, $def['creationTs'] + random_int(3600, max(3600, $now - $def['creationTs'])))),
            'assignedTo' => $def['assignedTo'],
            'notes' => $def['notes'],
            'revisions' => [],
            'attachments' => [],
            'clinicalDetails' => $buildClinicalDetails($def['caseType']),
            'createdByUserId' => $createdByUserId,
            'createdByName' => $createdByName,
            'demoGenerationRunId' => $runId,
            'isDemo' => true,
        ];
        if (in_array($def['caseType'], $needsMaterial, true)) {
            $caseData['material'] = $materials[array_rand($materials)];
        }

        $encrypted = PIIEncryption::encryptCaseData($caseData);
        saveCaseToCache($encrypted);
        $activeCreated++;
        if ($labUserAvailable && strcasecmp($def['assignedTo'], $labUser['email']) === 0) {
            $labActiveCaseCount++;
        }

        $activityRecordsWritten += $simulateJourney(
            $caseId, $def['creationTs'], $now, $def['status'], $def['revisions'],
            $def['assignedTo'], $def['notes'], false
        );

        // Open a backdated lab assignment period for lab-assigned active cases.
        if ($labUserAvailable && strcasecmp($def['assignedTo'], $labUser['email']) === 0) {
            $labStartTs = date('Y-m-d H:i:s', min($def['creationTs'] + 300, $now));
            $pdo->prepare("
                INSERT INTO case_lab_assignment_periods
                    (case_id, practice_id, assignee_type, user_id, label_id, label_text_normalized, assignee_display_name_snapshot, is_lab_snapshot, started_at, ended_at, end_reason, history_quality)
                VALUES
                    (:case_id, :practice_id, 'user', :user_id, NULL, NULL, :display_name, 1, :started_at, NULL, NULL, 'backfilled_unknown_start')
            ")->execute([
                'case_id' => $caseId,
                'practice_id' => $currentPracticeId,
                'user_id' => $labUser['id'],
                'display_name' => $labUser['email'],
                'started_at' => $labStartTs,
            ]);
        }

        // Comment showcase
        if ($def['showcase'] === 'comments' && $staffAvailable) {
            ensureDemoCaseCommentsTable();
            $commentAuthor = $staffUsers[array_rand($staffUsers)];
            $commentTs = date('Y-m-d H:i:s', min($now, $def['creationTs'] + random_int(3600, 172800)));
            $commentText = $commentPool[array_rand($commentPool)];
            $userName = trim(($commentAuthor['first_name'] ?? '') . ' ' . ($commentAuthor['last_name'] ?? ''));
            if ($userName === '') {
                $userName = $commentAuthor['email'];
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO case_comments (case_id, practice_id, user_id, user_name, user_email, comment_text, mentions_json, created_at)
                    VALUES (:case_id, :practice_id, :user_id, :user_name, :user_email, :comment_text, NULL, :created_at)
                ");
                $stmt->execute([
                    'case_id' => $caseId,
                    'practice_id' => $currentPracticeId,
                    'user_id' => $commentAuthor['id'],
                    'user_name' => $userName,
                    'user_email' => $commentAuthor['email'],
                    'comment_text' => $commentText,
                    'created_at' => $commentTs,
                ]);

                logCaseActivity($caseId, 'comment_added', null, null, [
                    'comment_id' => (int)$pdo->lastInsertId(),
                    'source' => 'generate-dental-practice-demo-data.php',
                ], $commentTs);
                $commentsWritten++;
                $activityRecordsWritten++;
            } catch (PDOException $e) {
                error_log('[generate-dental-practice-demo-data] Error creating demo comment: ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // 2) HISTORICAL / COMPLETED CASES (previous months, for Insights)
    // ------------------------------------------------------------------
    $caseTypesHistorical = ['Crown', 'Bridge', 'Implant Crown', 'Implant Surgical Guide',
        'Denture', 'Partial', 'Veneer', 'Inlay/Onlay', 'AOX', 'Orthodontic Appliance'];
    $caseTypeWeights = [8, 4, 2, 1, 2, 2, 3, 2, 1, 1]; // mirrors the active-case mix
    $caseTypeWeightedPool = [];
    foreach ($caseTypesHistorical as $ci => $ct) {
        for ($w = 0; $w < $caseTypeWeights[$ci]; $w++) {
            $caseTypeWeightedPool[] = $ct;
        }
    }

    $turnaroundByType = [
        'Crown' => [7, 14], 'Veneer' => [7, 14], 'Inlay/Onlay' => [6, 12],
        'Bridge' => [12, 22], 'Denture' => [14, 24], 'Partial' => [12, 20],
        'Implant Crown' => [18, 32], 'Implant Surgical Guide' => [16, 28], 'AOX' => [21, 35],
        'Orthodontic Appliance' => [14, 28],
    ];

    $monthBaseBySize = $datasetConfig[$dataset]['monthBaseCount'];
    $busyMonthOffset = random_int(1, max(1, $historicalMonths - 1));

    for ($monthOffset = 0; $monthOffset < $historicalMonths; $monthOffset++) {
        $monthStartTs = strtotime(date('Y-m-01', strtotime("-{$monthOffset} months", $today)));
        $monthEndTs = strtotime('+1 month -1 day', $monthStartTs);
        if ($monthEndTs > $today) {
            $monthEndTs = $today;
        }

        $baseCount = $monthBaseBySize[$monthOffset] ?? random_int(2, 4);
        if ($monthOffset === $busyMonthOffset) {
            $baseCount += random_int(3, 5);
        }

        for ($c = 0; $c < $baseCount; $c++) {
            $caseType = $caseTypeWeightedPool[array_rand($caseTypeWeightedPool)];
            $creationTs = random_int($monthStartTs, max($monthStartTs, $monthEndTs - 86400));

            [$minTurn, $maxTurn] = $turnaroundByType[$caseType];
            $turnaroundDays = random_int($minTurn, $maxTurn);
            if (random_int(1, 100) <= 12) {
                $turnaroundDays += random_int(15, 25); // unusually long outlier
            }

            $archivedTs = min($now, $creationTs + ($turnaroundDays * 86400) + random_int(0, 43200));
            if ($archivedTs <= $creationTs) {
                $archivedTs = $creationTs + 86400;
            }
            $dueTs = $creationTs + (int)round($turnaroundDays * 86400 * 0.9);

            $revisions = 0;
            $roll = random_int(1, 100);
            if ($roll <= 15) {
                $revisions = 1;
            } elseif ($roll <= 20) {
                $revisions = 2;
            }

            if ($labUserAvailable && random_int(1, 100) <= 20) {
                $assignedTo = $labUser['email'];
                $labHistoricalCaseCount++;
            } else {
                $assignedTo = $staffAvailable ? $staffWeightedPool[array_rand($staffWeightedPool)] : ($labUserAvailable ? $labUser['email'] : '');
            }

            $notes = '';
            if (random_int(1, 100) <= 30) {
                $notes = $notesPool[array_rand($notesPool)];
            }

            $caseId = 'demo_' . uniqid('', true);
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $dentist = $dentistNames[array_rand($dentistNames)];
            $dobTs = strtotime('-' . random_int(10, 80) . ' years', $now);

            $creator = $staffAvailable ? $staffUsers[array_rand($staffUsers)] : null;
            $createdByUserId = $creator ? (int)$creator['id'] : ($_SESSION['db_user_id'] ?? null);

            // Some delivered cases have a patient appointment (before or shortly after delivery) to
            // demonstrate that Delivered cases do NOT show Appointment Risk highlighting.
            $apptRoll = random_int(1, 100);
            $apptTs = null;
            if ($apptRoll <= 40) {
                $apptTs = $creationTs + (86400 * random_int(1, $turnaroundDays - 1));
            } elseif ($apptRoll <= 55) {
                $apptTs = $archivedTs + (86400 * random_int(1, 14));
            }

            $caseData = [
                'id' => $caseId,
                'driveFolderId' => null,
                'patientFirstName' => $firstName,
                'patientLastName' => $lastName,
                'patientDOB' => date('Y-m-d', $dobTs),
                'patientGender' => $genders[array_rand($genders)],
                'dentistName' => $dentist,
                'caseType' => $caseType,
                'toothShade' => $toothShades[array_rand($toothShades)],
                'dueDate' => date('Y-m-d', $dueTs),
                'patientAppointmentDate' => $apptTs !== null ? date('Y-m-d', $apptTs) : null,
                'creationDate' => date('c', $creationTs),
                'lastUpdateDate' => date('c', $archivedTs),
                'status' => 'Delivered',
                'statusChangedAt' => date('c', $archivedTs),
                'assignedTo' => $assignedTo,
                'notes' => $notes,
                'revisions' => [],
                'attachments' => [],
                'clinicalDetails' => $buildClinicalDetails($caseType),
                'createdByUserId' => $createdByUserId,
                'demoGenerationRunId' => $runId,
            ];
            if (in_array($caseType, $needsMaterial, true)) {
                $caseData['material'] = $materials[array_rand($materials)];
            }

            $encrypted = PIIEncryption::encryptCaseData($caseData);
            saveCaseToCache($encrypted);

            // Archive it (saveCaseToCache doesn't touch archived/archived_date).
            $archiveStmt = $pdo->prepare("UPDATE cases_cache SET archived = 1, archived_date = :archived_date WHERE case_id = :case_id");
            $archiveStmt->execute([
                'archived_date' => date('Y-m-d H:i:s', $archivedTs),
                'case_id' => $caseId,
            ]);

            $historicalCreated++;

            $activityRecordsWritten += $simulateJourney(
                $caseId, $creationTs, $archivedTs, 'Delivered', $revisions, $assignedTo, $notes, true
            );

            // Backdated, closed lab assignment period for historical lab cases.
            if ($labUserAvailable && strcasecmp($assignedTo, $labUser['email']) === 0) {
                $pdo->prepare("
                    INSERT INTO case_lab_assignment_periods
                        (case_id, practice_id, assignee_type, user_id, label_id, label_text_normalized, assignee_display_name_snapshot, is_lab_snapshot, started_at, ended_at, end_reason, history_quality)
                    VALUES
                        (:case_id, :practice_id, 'user', :user_id, NULL, NULL, :display_name, 1, :started_at, :ended_at, 'delivered', 'backfilled_unknown_start')
                ")->execute([
                    'case_id' => $caseId,
                    'practice_id' => $currentPracticeId,
                    'user_id' => $labUser['id'],
                    'display_name' => $labUser['email'],
                    'started_at' => date('Y-m-d H:i:s', $creationTs + 300),
                    'ended_at' => date('Y-m-d H:i:s', $archivedTs),
                ]);
            }
        }
    }

    // Mark the run complete with counts
    $pdo->prepare("
        UPDATE demo_generation_runs
        SET status = 'complete', active_case_count = :active, historical_case_count = :historical
        WHERE id = :run_id
    ")->execute([
        'active' => $activeCreated,
        'historical' => $historicalCreated,
        'run_id' => $runId,
    ]);

    echo json_encode([
        'success' => true,
        'run_id' => $runId,
        'message' => "Generated {$activeCreated} active case(s) and {$historicalCreated} historical case(s) for the current practice. "
            . "{$labActiveCaseCount} active and {$labHistoricalCaseCount} historical case(s) were associated with lab@dentatrak.com.",
        'activeCasesCreated' => $activeCreated,
        'historicalCasesCreated' => $historicalCreated,
        'activityRecordsWritten' => $activityRecordsWritten,
        'commentsWritten' => $commentsWritten,
        'labUserUsed' => $labUserAvailable,
        'labActiveCasesAssigned' => $labActiveCaseCount,
        'labHistoricalCasesAssigned' => $labHistoricalCaseCount,
        'staffUsersUsed' => count($staffUsers),
    ]);
} catch (Throwable $e) {
    $stage = $activeCreated > 0
        ? ($historicalCreated > 0 ? 'historical' : 'active')
        : 'setup';
    error_log('[generate-dental-practice-demo-data] dataset=' . ($dataset ?? 'unknown') . ' stage=' . $stage . ' error=' . $e->getMessage());

    if (!empty($runId) && $pdo) {
        try {
            $pdo->prepare("
                UPDATE demo_generation_runs
                SET status = 'failed'
                WHERE id = :run_id
            ")->execute(['run_id' => $runId]);
        } catch (PDOException $updateEx) {
            error_log('[generate-dental-practice-demo-data] Error marking run failed: ' . $updateEx->getMessage());
        }
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error while generating demo data: ' . $e->getMessage(),
    ]);
}
