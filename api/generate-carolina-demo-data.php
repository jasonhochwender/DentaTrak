<?php
/**
 * Generate Carolina Family Dental Demo Data
 *
 * Purpose-built Dev Tools generator that populates the CURRENT practice with a
 * curated, realistic demo dataset (active + historical cases) for marketing
 * screenshots, demos, training, and general testing.
 *
 * SAFETY: This endpoint refuses to run unless the current practice's name is
 * EXACTLY "Carolina Family Dental". It never creates the practice itself, and
 * it never touches any other practice's data (every write is scoped to
 * $currentPracticeId, exactly like generate-fake-cases.php).
 *
 * Architecture: follows the same pattern as api/generate-fake-cases.php -
 * writes directly to cases_cache (bypassing Google Drive, which real case
 * creation depends on) via the same saveCaseToCache()/PIIEncryption helpers.
 * Unlike generate-fake-cases.php, this also produces real case_activity_log
 * history (via logCaseActivity(), including its optional backdating param)
 * and real revision_count values (via incrementCaseRevisionCount()), so
 * generated cases behave like normal, lived-in DentaTrak records rather than
 * empty shells.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/case-comments.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/csrf.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    // SECURITY: Require valid, authenticated practice membership (never a
    // fallback / never another practice).
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

    // SAFETY GATE: the practice name must be EXACTLY "Carolina Family Dental".
    $stmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
    $stmt->execute(['id' => $currentPracticeId]);
    $practiceName = $stmt->fetchColumn();

    if ($practiceName === false || trim((string)$practiceName) !== 'Carolina Family Dental') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This generator only runs for the practice "Carolina Family Dental". '
                . 'The current practice is "' . ($practiceName !== false ? $practiceName : 'unknown') . '". No data was generated.'
        ]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $confirmed = !empty($input['confirmed']);

    // Warn (once) if the practice already has a meaningful amount of case data,
    // per the "do not silently pile onto existing data" requirement. Kept
    // intentionally simple - no duplicate-detection system, just a count check.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = :pid AND archived = 0");
    $stmt->execute(['pid' => $currentPracticeId]);
    $existingActiveCount = (int)$stmt->fetchColumn();

    $significantDataThreshold = 5;
    if ($existingActiveCount > $significantDataThreshold && !$confirmed) {
        echo json_encode([
            'success' => false,
            'needsConfirmation' => true,
            'existingCaseCount' => $existingActiveCount,
            'message' => "Carolina Family Dental already has {$existingActiveCount} active case(s). "
                . 'This will ADD demo data on top of what exists (nothing will be deleted). Continue anyway?'
        ]);
        exit;
    }

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

    $labUser = null;
    $staffUsers = [];
    foreach ($practiceUsers as $pu) {
        if (strcasecmp($pu['email'], 'lab@dentatrak.com') === 0) {
            $labUser = $pu;
        } else {
            $staffUsers[] = $pu;
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
    // Reference pools - real case types / statuses / clinical fields taken
    // directly from the actual "Create Case" form (main.php), not guessed.
    // ------------------------------------------------------------------
    $caseTypePool25 = array_merge(
        array_fill(0, 8, 'Crown'),
        array_fill(0, 4, 'Bridge'),
        array_fill(0, 2, 'Implant Crown'),
        array_fill(0, 1, 'Implant Surgical Guide'),
        array_fill(0, 2, 'Denture'),
        array_fill(0, 2, 'Partial'),
        array_fill(0, 3, 'Veneer'),
        array_fill(0, 2, 'Inlay/Onlay'),
        array_fill(0, 1, 'AOX')
    ); // 25
    shuffle($caseTypePool25);

    $statusPool25 = array_merge(
        array_fill(0, 7, 'Originated'),
        array_fill(0, 5, 'Sent To External Lab'),
        array_fill(0, 5, 'Designed'),
        array_fill(0, 4, 'Manufactured'),
        array_fill(0, 4, 'Received From External Lab')
    ); // 25
    shuffle($statusPool25);

    $dueBucketPool25 = array_merge(
        array_fill(0, 2, 'overdue'),
        array_fill(0, 3, 'very_soon'),
        array_fill(0, 6, 'this_week'),
        array_fill(0, 9, 'few_weeks'),
        array_fill(0, 5, 'distant')
    ); // 25
    shuffle($dueBucketPool25);

    $revisionPool25 = array_merge(
        array_fill(0, 17, 0),
        array_fill(0, 4, 1),
        array_fill(0, 3, 2),
        array_fill(0, 1, 3)
    ); // 25
    shuffle($revisionPool25);

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

    /** Pick a realistic assignee email for a case at a given workflow stage. */
    $pickAssignee = function ($statusIndex) use ($labUserAvailable, $labUser, $staffAvailable, $staffWeightedPool) {
        $atLab = ($statusIndex >= 1 && $statusIndex <= 3); // Sent / Designed / Manufactured
        if ($atLab && $labUserAvailable && random_int(1, 100) <= 70) {
            return $labUser['email'];
        }
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
                    'dentureJaw' => random_int(0, 1) ? 'Maxillary' : 'Mandibular',
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
                    'partialJaw' => random_int(0, 1) ? 'Maxillary' : 'Mandibular',
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
            'source' => 'generate-carolina-demo-data.php',
            'has_notes' => !empty($notes),
        ], date('Y-m-d H:i:s', $creationTs));
        $eventsWritten++;

        // assignment_set shortly after creation
        if (!empty($assignedTo)) {
            $assignTs = min($endTs, $creationTs + random_int(1800, 14400));
            logCaseActivity($caseId, 'assignment_set', null, null, [
                'assigned_to' => $assignedTo,
                'source' => 'generate-carolina-demo-data.php',
            ], date('Y-m-d H:i:s', $assignTs));
            $eventsWritten++;
        }

        // notes_updated shortly after, if notes exist
        if (!empty($notes)) {
            $notesTs = min($endTs, $creationTs + random_int(3600, 28800));
            logCaseActivity($caseId, 'notes_updated', null, null, [
                'length' => strlen($notes),
                'source' => 'generate-carolina-demo-data.php',
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
                    'source' => 'generate-carolina-demo-data.php',
                ], $ts);
            } else {
                logCaseActivity($caseId, 'status_changed', $fromStatus, $toStatus, [
                    'source' => 'generate-carolina-demo-data.php',
                ], $ts);
            }
            $eventsWritten++;
        }

        // Historical (Delivered + archived) cases get a final auto-archive event.
        if ($isHistorical) {
            $archiveTs = min($endTs, ($timestamps[$totalSteps - 1] ?? $creationTs) + random_int(3600, 3 * 86400));
            logCaseActivity($caseId, 'case_archived_auto', 'Delivered', null, [
                'delivered_hide_days' => 14,
                'source' => 'generate-carolina-demo-data.php',
            ], date('Y-m-d H:i:s', $archiveTs));
            $eventsWritten++;
        }

        return $eventsWritten;
    };

    $activityRecordsWritten = 0;
    $commentsWritten = 0;
    $activeCreated = 0;
    $historicalCreated = 0;
    $now = time();
    $today = strtotime(date('Y-m-d', $now));

    // ------------------------------------------------------------------
    // 1) ACTIVE CASES (25)
    // ------------------------------------------------------------------

    // Due date offsets (in days from today) per bucket.
    $dueOffsetForBucket = function ($bucket) {
        switch ($bucket) {
            case 'overdue': return -random_int(1, 10);
            case 'very_soon': return random_int(0, 2);
            case 'this_week': return random_int(3, 7);
            case 'few_weeks': return random_int(8, 21);
            case 'distant': return random_int(22, 42);
        }
        return 7;
    };

    // Build the 25 base case definitions first so we can reliably pick
    // showcase cases from the guaranteed pool distribution above.
    $activeDefs = [];
    for ($i = 0; $i < 25; $i++) {
        $caseType = $caseTypePool25[$i];
        $status = $statusPool25[$i];
        $statusIdx = $workflowStageOrder[$status];
        $revisions = $revisionPool25[$i];
        $dueBucket = $dueBucketPool25[$i];

        // Cases further along the workflow were created further in the past.
        $ageDaysMin = [0 => 2, 1 => 5, 2 => 10, 3 => 15, 4 => 20][$statusIdx];
        $ageDaysMax = [0 => 8, 1 => 14, 2 => 20, 3 => 28, 4 => 35][$statusIdx];
        // Revisions add real elapsed time (each regression/forward cycle takes days).
        $ageDaysMin += $revisions * 4;
        $ageDaysMax += $revisions * 7;

        $creationTs = $today - (86400 * random_int($ageDaysMin, $ageDaysMax)) - random_int(0, 43200);

        $dueTs = $today + (86400 * $dueOffsetForBucket($dueBucket));
        if ($dueTs <= $creationTs) {
            $dueTs = $creationTs + (86400 * random_int(5, 15));
        }

        $activeDefs[] = [
            'caseType' => $caseType,
            'status' => $status,
            'statusIdx' => $statusIdx,
            'revisions' => $revisions,
            'creationTs' => $creationTs,
            'dueTs' => $dueTs,
            'notes' => null,
            'assignedTo' => null,
            'showcase' => null,
        ];
    }

    // Pick 5 showcase cases from the pool (guaranteed to exist given the
    // fixed bucket distributions above).
    $showcaseIdx = [];
    foreach ($activeDefs as $idx => $def) {
        if ($def['revisions'] === 3 && !in_array('three_revisions', $showcaseIdx, true)) {
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

    $showcaseIndices = array_values($showcaseIdx);

    foreach ($activeDefs as $idx => &$def) {
        $isShowcase = in_array($idx, $showcaseIndices, true);
        $showcaseKey = array_search($idx, $showcaseIdx, true);

        // Assignment
        if ($showcaseKey === 'unassigned') {
            $def['assignedTo'] = '';
        } elseif ($showcaseKey === 'at_lab' && $labUserAvailable) {
            $def['assignedTo'] = $labUser['email'];
        } else {
            $def['assignedTo'] = $pickAssignee($def['statusIdx']);
        }

        // Notes: all showcase cases get notes; ~55% of the rest do too.
        if ($isShowcase || random_int(1, 100) <= 55) {
            $def['notes'] = $notesPool[array_rand($notesPool)];
        } else {
            $def['notes'] = '';
        }

        $def['showcase'] = $showcaseKey !== false ? $showcaseKey : null;
    }
    unset($def);

    foreach ($activeDefs as $def) {
        $caseId = 'demo_' . uniqid('', true);
        $firstName = $firstNames[array_rand($firstNames)];
        $lastName = $lastNames[array_rand($lastNames)];
        $dentist = $dentistNames[array_rand($dentistNames)];
        $dobTs = strtotime('-' . random_int(10, 80) . ' years', $now);

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
            'creationDate' => date('c', $def['creationTs']),
            'lastUpdateDate' => date('c', min($now, $def['creationTs'] + random_int(3600, max(3600, $now - $def['creationTs'])))),
            'status' => $def['status'],
            'statusChangedAt' => date('c', min($now, $def['creationTs'] + random_int(3600, max(3600, $now - $def['creationTs'])))),
            'assignedTo' => $def['assignedTo'],
            'notes' => $def['notes'],
            'revisions' => [],
            'attachments' => [],
            'clinicalDetails' => $buildClinicalDetails($def['caseType']),
        ];
        if (in_array($def['caseType'], $needsMaterial, true)) {
            $caseData['material'] = $materials[array_rand($materials)];
        }

        $encrypted = PIIEncryption::encryptCaseData($caseData);
        saveCaseToCache($encrypted);
        $activeCreated++;

        $activityRecordsWritten += $simulateJourney(
            $caseId, $def['creationTs'], $now, $def['status'], $def['revisions'],
            $def['assignedTo'], $def['notes'], false
        );

        // A couple of showcase cases also get a short discussion thread to
        // demonstrate the Comments feature.
        if ($def['showcase'] === 'comments' && $staffAvailable) {
            ensureCaseCommentsTable();
            $commentAuthor = $staffUsers[array_rand($staffUsers)];
            $commentTs = date('Y-m-d H:i:s', min($now, $def['creationTs'] + random_int(3600, 172800)));
            $stmt = $pdo->prepare("
                INSERT INTO case_comments (case_id, practice_id, user_id, user_name, user_email, comment_text, created_at)
                VALUES (:case_id, :practice_id, :user_id, :user_name, :user_email, :comment_text, :created_at)
            ");
            $userName = trim(($commentAuthor['first_name'] ?? '') . ' ' . ($commentAuthor['last_name'] ?? ''));
            $stmt->execute([
                'case_id' => $caseId,
                'practice_id' => $currentPracticeId,
                'user_id' => $commentAuthor['id'],
                'user_name' => $userName !== '' ? $userName : $commentAuthor['email'],
                'user_email' => $commentAuthor['email'],
                'comment_text' => $commentPool[array_rand($commentPool)],
                'created_at' => $commentTs,
            ]);
            logCaseActivity($caseId, 'comment_added', null, null, [
                'source' => 'generate-carolina-demo-data.php',
            ], $commentTs);
            $commentsWritten++;
            $activityRecordsWritten++;
        }
    }

    // ------------------------------------------------------------------
    // 2) HISTORICAL / COMPLETED CASES (previous 6 months, for Insights)
    // ------------------------------------------------------------------
    $caseTypesHistorical = ['Crown', 'Bridge', 'Implant Crown', 'Implant Surgical Guide',
        'Denture', 'Partial', 'Veneer', 'Inlay/Onlay', 'AOX'];
    $caseTypeWeights = [8, 4, 2, 1, 2, 2, 3, 2, 1]; // mirrors the active-case mix
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
    ];

    // One randomly-chosen "busy" month gets a volume bump.
    $busyMonthOffset = random_int(1, 5);

    for ($monthOffset = 0; $monthOffset <= 5; $monthOffset++) {
        $monthStartTs = strtotime(date('Y-m-01', strtotime("-{$monthOffset} months", $today)));
        $monthEndTs = strtotime('+1 month -1 day', $monthStartTs);
        if ($monthEndTs > $today) {
            $monthEndTs = $today; // current partial month
        }

        $baseCount = ($monthOffset === 0)
            ? random_int(3, 6) // partial current month
            : random_int(8, 13);
        if ($monthOffset === $busyMonthOffset) {
            $baseCount += random_int(4, 6);
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

            $statusIdxForAssignment = 5; // Delivered
            $assignedTo = $staffAvailable ? $staffWeightedPool[array_rand($staffWeightedPool)] : ($labUserAvailable ? $labUser['email'] : '');

            $notes = '';
            if (random_int(1, 100) <= 30) {
                $notes = $notesPool[array_rand($notesPool)];
            }

            $caseId = 'demo_' . uniqid('', true);
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $dentist = $dentistNames[array_rand($dentistNames)];
            $dobTs = strtotime('-' . random_int(10, 80) . ' years', $now);

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
                'creationDate' => date('c', $creationTs),
                'lastUpdateDate' => date('c', $archivedTs),
                'status' => 'Delivered',
                'statusChangedAt' => date('c', $archivedTs),
                'assignedTo' => $assignedTo,
                'notes' => $notes,
                'revisions' => [],
                'attachments' => [],
                'clinicalDetails' => $buildClinicalDetails($caseType),
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
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Generated {$activeCreated} active case(s) and {$historicalCreated} historical case(s) for Carolina Family Dental.",
        'activeCasesCreated' => $activeCreated,
        'historicalCasesCreated' => $historicalCreated,
        'activityRecordsWritten' => $activityRecordsWritten,
        'commentsWritten' => $commentsWritten,
        'labUserUsed' => $labUserAvailable,
        'staffUsersUsed' => count($staffUsers),
    ]);
} catch (Throwable $e) {
    error_log('[generate-carolina-demo-data] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error while generating demo data: ' . $e->getMessage(),
    ]);
}
