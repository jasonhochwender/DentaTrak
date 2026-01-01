<?php
/**
 * Generate Fake Cases API Endpoint
 * Bulk-generates fake cases for testing
 * 
 * Access Control:
 * - Always allowed in development environment
 * - In UAT/Production: Only allowed for super users with dev_tools_enabled
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/dev-tools-access.php';

header('Content-Type: application/json');

// Do not expose notices/warnings to the client
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

try {
    if (!isset($_SESSION['user']) && !isset($_SESSION['db_user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.'
        ]);
        exit;
    }

    // Check dev tools access (handles both development and super user in UAT/Prod)
    $userEmail = $_SESSION['user_email'] ?? '';
    if (!canAccessDevTools($appConfig, $userEmail)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Not authorized to generate test cases.'
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $count = isset($input['count']) ? (int)$input['count'] : 0;

    if ($count < 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Count must be at least 1.'
        ]);
        exit;
    }

    if ($count > 500) {
        $count = 500;
    }

    // Get current practice ID
    $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
    
    if (!$currentPracticeId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No practice selected. Please set up a practice first.'
        ]);
        exit;
    }

    // Define test users to create (for Assigned To)
    $testUserEmails = [
        'tech@example.com',
        'lab@example.com', 
        'assistant@example.com',
        'manager@example.com'
    ];
    
    // Define test case labels (NOT assignment labels - these are tags/context)
    $testCaseLabels = [
        'Rush Order',
        'Quality Check',
        'Specialist Review',
        'New Patient',
        'Follow-up',
        'Complex Case',
        'Insurance Pending'
    ];
    
    // Create test users in the database (if they don't exist)
    foreach ($testUserEmails as $email) {
        try {
            // First, check if user exists in users table
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingUser) {
                // Create the user - use first_name and last_name instead of name column
                $nameParts = explode('@', $email);
                $firstName = ucfirst($nameParts[0]);
                $lastName = 'User';
                
                $stmt = $pdo->prepare("INSERT INTO users (email, first_name, last_name, created_at) VALUES (:email, :first_name, :last_name, NOW())");
                $stmt->execute([
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName
                ]);
                $userId = $pdo->lastInsertId();
            } else {
                $userId = $existingUser['id'];
            }
            
            // Check if user is already in practice_users for this practice
            $stmt = $pdo->prepare("SELECT id FROM practice_users WHERE user_id = :user_id AND practice_id = :practice_id");
            $stmt->execute(['user_id' => $userId, 'practice_id' => $currentPracticeId]);
            
            if (!$stmt->fetch()) {
                // Add user to practice_users as a regular user
                $stmt = $pdo->prepare("INSERT INTO practice_users (user_id, practice_id, role, created_at) VALUES (:user_id, :practice_id, 'user', NOW())");
                $stmt->execute(['user_id' => $userId, 'practice_id' => $currentPracticeId]);
            }
        } catch (Exception $e) {
            // Log but continue - don't fail the whole operation
            error_log('Error creating test user ' . $email . ': ' . $e->getMessage());
        }
    }
    
    // Ensure case_labels table exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS case_labels (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                practice_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(7) DEFAULT '#6b7280',
                created_by INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_label_per_practice (practice_id, name),
                INDEX idx_practice_id (practice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS case_label_assignments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id VARCHAR(64) NOT NULL,
                label_id INT UNSIGNED NOT NULL,
                assigned_by INT UNSIGNED NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_case_label (case_id, label_id),
                INDEX idx_case_id (case_id),
                INDEX idx_label_id (label_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('Error creating label tables: ' . $e->getMessage());
    }
    
    // Create test case labels in the database (if they don't exist)
    $labelColors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899'];
    $createdLabelIds = [];
    $userId = $_SESSION['db_user_id'] ?? 1;
    
    foreach ($testCaseLabels as $index => $labelName) {
        try {
            // Check if label already exists for this practice
            $stmt = $pdo->prepare("SELECT id FROM case_labels WHERE practice_id = :practice_id AND name = :name");
            $stmt->execute(['practice_id' => $currentPracticeId, 'name' => $labelName]);
            $existingLabel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingLabel) {
                $createdLabelIds[] = (int)$existingLabel['id'];
            } else {
                // Insert the label with a color
                $color = $labelColors[$index % count($labelColors)];
                $stmt = $pdo->prepare("INSERT INTO case_labels (practice_id, name, color, created_by, created_at) VALUES (:practice_id, :name, :color, :created_by, NOW())");
                $stmt->execute([
                    'practice_id' => $currentPracticeId,
                    'name' => $labelName,
                    'color' => $color,
                    'created_by' => $userId
                ]);
                $createdLabelIds[] = (int)$pdo->lastInsertId();
            }
        } catch (Exception $e) {
            // Log but continue
            error_log('Error creating test case label ' . $labelName . ': ' . $e->getMessage());
        }
    }

    // Option pools matching the main PHP UI dropdowns
    $caseTypes = [
        'Crown',
        'Bridge',
        'Implant',
        'AOX',
        'Denture',
        'Partial',
        'Veneer',
        'Inlay/Onlay',
        'Orthodontic Appliance'
    ];

    $caseTypesRequiringMaterial = [
        'Crown',
        'Bridge',
        'Implant',
        'AOX',
        'Veneer',
        'Inlay/Onlay'
    ];

    $materials = [
        'Zirconia',
        'Lithium Disilicate',
        'PFM',
        'PFZ',
        '3D Printed'
    ];

    $statuses = [
        'Originated',
        'Sent To External Lab',
        'Designed',
        'Manufactured',
        'Received From External Lab',
        'Delivered'
    ];

    $toothShades = ['A1', 'A2', 'A3', 'A3.5', 'B1', 'B2', 'C2', 'D2'];
    
    $genders = ['Male', 'Female'];
    
    // Clinical detail options
    $toothNumbers = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32'];
    $abutmentTypes = ['Stock', 'Custom', 'Ti-Base'];
    $implantSystems = ['Nobel Biocare', 'Straumann', 'Zimmer Biomet', 'Dentsply Sirona', 'BioHorizons', 'Neodent'];
    $platformSizes = ['3.5mm', '4.0mm', '4.5mm', '5.0mm', '5.5mm', '6.0mm'];
    $dentureJaws = ['Upper', 'Lower', 'Both'];
    $dentureTypes = ['Full', 'Partial', 'Immediate', 'Overdenture'];
    $gingivalShades = ['Light Pink', 'Pink', 'Dark Pink', 'Natural'];
    $partialMaterials = ['Chrome Cobalt', 'Acrylic', 'Flexible', 'Titanium'];

    $firstNames = ['John', 'Jane', 'Alex', 'Sam', 'Chris', 'Taylor', 'Jordan', 'Morgan', 'Pat', 'Jamie'];
    $lastNames  = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Taylor'];

    $dentistNames = [
        'Dr. Smith',
        'Dr. Johnson',
        'Dr. Carter',
        'Dr. Nguyen',
        'Dr. Patel',
        'Dr. Lopez',
        'Dr. Thompson',
        'Dr. Kim'
    ];

    // Helper to generate a random Y-m-d date between two timestamps
    $randDate = function (int $startTs, int $endTs): string {
        if ($endTs <= $startTs) {
            return date('Y-m-d', $startTs);
        }
        $ts = random_int($startTs, $endTs);
        return date('Y-m-d', $ts);
    };

    $now = time();
    $currentYear = (int) date('Y', $now);
    $lastYear = $currentYear - 1;

    // Created date: between 2 years ago and now
    $createdStart = strtotime('-2 years', $now);
    $createdEnd   = $now;

    // Due date: between Jan 1 of last year and six months from now
    $dueStart = strtotime($lastYear . '-01-01 00:00:00');
    $dueEnd   = strtotime('+6 months', $now);

    // DOB: at least 2 years in the past, up to ~90 years ago
    $dobMax = strtotime('-2 years', $now);
    $dobMin = strtotime('-90 years', $now);

    if (!$pdo) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection is not configured.'
        ]);
        exit;
    }

    $created = 0;

    for ($i = 0; $i < $count; $i++) {
        $caseType    = $caseTypes[array_rand($caseTypes)];
        $status      = $statuses[array_rand($statuses)];
        $firstName   = $firstNames[array_rand($firstNames)];
        $lastName    = $lastNames[array_rand($lastNames)];
        $dentistName = $dentistNames[array_rand($dentistNames)];
        $dob         = $randDate($dobMin, $dobMax);
        $dueDate     = $randDate($dueStart, $dueEnd);
        $toothShade  = $toothShades[array_rand($toothShades)];

        // Dev-only ID so it is easy to distinguish
        $caseId = 'dev_' . uniqid();

        // Generate random creation date within last 2 years
        $createdTs = random_int($createdStart, $createdEnd);
        $createdDate = date('c', $createdTs);
        // Last update is between creation and now
        $lastUpdateTs = random_int($createdTs, $now);
        $lastUpdateDate = date('c', $lastUpdateTs);
        
        // Generate varied statusChangedAt values for "In Status" testing
        // Create a wide range of time differences from creation to now
        $statusChangedOptions = [
            // Very recent changes (minutes ago) - use more variation
            $now - rand(1, 59) * 60,                    // 1-59 minutes ago
            $now - rand(1, 23) * 60 * 60,               // 1-23 hours ago
            $now - rand(1, 6) * 24 * 60 * 60,           // 1-6 days ago
            $now - rand(7, 14) * 24 * 60 * 60,          // 1-2 weeks ago
            $now - rand(15, 30) * 24 * 60 * 60,         // 2-4 weeks ago
            $now - rand(31, 90) * 24 * 60 * 60,         // 1-3 months ago
            
            // Also include some changes relative to creation date for variety
            $createdTs + rand(1, 59) * 60,              // 1-59 minutes after creation
            $createdTs + rand(1, 23) * 60 * 60,        // 1-23 hours after creation
            $createdTs + rand(1, 7) * 24 * 60 * 60,    // 1-7 days after creation
            $createdTs + rand(8, 30) * 24 * 60 * 60,   // 1-4 weeks after creation
        ];
        
        // Filter out any future dates and ensure statusChangedAt is between creation and now
        $validStatusChangedOptions = array_filter($statusChangedOptions, function($timestamp) use ($createdTs, $now) {
            return $timestamp >= $createdTs && $timestamp <= $now;
        });
        
        // If no valid options (shouldn't happen), use a safe fallback
        if (empty($validStatusChangedOptions)) {
            $validStatusChangedOptions = [$createdTs, $now];
        }
        
        // Shuffle the options for better randomization
        shuffle($validStatusChangedOptions);
        $statusChangedTs = $validStatusChangedOptions[array_rand($validStatusChangedOptions)];
        $statusChangedAt = date('c', $statusChangedTs); // Use ISO 8601 format
        
        // Get practice users for Assigned To (must be a real practice user)
        $assignOptions = [];
        
        try {
            // Get practice users (admin + regular users) - ONLY users, not labels
            $stmt = $pdo->prepare("
                SELECT u.email 
                FROM users u
                JOIN practice_users pu ON u.id = pu.user_id
                WHERE pu.practice_id = :practice_id
            ");
            $stmt->execute(['practice_id' => $currentPracticeId]);
            $assignOptions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log('Error fetching practice users for test case generation: ' . $e->getMessage());
        }
        
        // Fallback if no options found
        if (empty($assignOptions)) {
            $assignOptions = $testUserEmails;
        }
        
        // Assigned To must be a practice user
        $assignedTo = $assignOptions[array_rand($assignOptions)];
        
        // Randomly select 0-3 labels for this case
        $caseLabelsToAssign = [];
        if (!empty($createdLabelIds) && rand(0, 100) > 30) { // 70% chance of having labels
            $numLabels = rand(1, min(3, count($createdLabelIds)));
            $shuffledLabelIds = $createdLabelIds;
            shuffle($shuffledLabelIds);
            $caseLabelsToAssign = array_slice($shuffledLabelIds, 0, $numLabels);
        }
        
        // No attachments for test cases (labels are separate now)
        $attachments = [];
        
        // Generate clinical details based on case type
        $clinicalDetails = [];
        
        switch ($caseType) {
            case 'Crown':
                $clinicalDetails = [
                    'toothNumber' => $toothNumbers[array_rand($toothNumbers)]
                ];
                break;
            case 'Bridge':
                // Pick 2 abutment teeth and 1-2 pontic teeth
                $abutment1 = $toothNumbers[array_rand($toothNumbers)];
                $abutment2 = $toothNumbers[array_rand($toothNumbers)];
                $pontic = $toothNumbers[array_rand($toothNumbers)];
                $clinicalDetails = [
                    'abutmentTeeth' => $abutment1 . ', ' . $abutment2,
                    'ponticTeeth' => $pontic
                ];
                break;
            case 'Implant':
                $clinicalDetails = [
                    'implantToothNumber' => $toothNumbers[array_rand($toothNumbers)],
                    'abutmentType' => $abutmentTypes[array_rand($abutmentTypes)],
                    'implantSystem' => $implantSystems[array_rand($implantSystems)],
                    'platformSize' => $platformSizes[array_rand($platformSizes)],
                    'scanBodyUsed' => rand(0, 1) ? 'Yes' : 'No'
                ];
                break;
            case 'Denture':
                $clinicalDetails = [
                    'dentureJaw' => $dentureJaws[array_rand($dentureJaws)],
                    'dentureType' => $dentureTypes[array_rand($dentureTypes)],
                    'gingivalShade' => $gingivalShades[array_rand($gingivalShades)]
                ];
                break;
            case 'Partial':
                // Pick 2-4 teeth to replace
                $numTeeth = rand(2, 4);
                $teethToReplace = [];
                for ($t = 0; $t < $numTeeth; $t++) {
                    $teethToReplace[] = $toothNumbers[array_rand($toothNumbers)];
                }
                $clinicalDetails = [
                    'partialJaw' => $dentureJaws[array_rand(array_slice($dentureJaws, 0, 2))], // Upper or Lower only
                    'teethToReplace' => implode(', ', array_unique($teethToReplace)),
                    'partialMaterial' => $partialMaterials[array_rand($partialMaterials)],
                    'partialGingivalShade' => $gingivalShades[array_rand($gingivalShades)]
                ];
                break;
            case 'Veneer':
                // Pick 2-6 teeth for veneers
                $numTeeth = rand(2, 6);
                $veneeredTeeth = [];
                for ($t = 0; $t < $numTeeth; $t++) {
                    $veneeredTeeth[] = $toothNumbers[array_rand($toothNumbers)];
                }
                $clinicalDetails = [
                    'veneeredTeeth' => implode(', ', array_unique($veneeredTeeth))
                ];
                break;
            case 'Inlay/Onlay':
                $clinicalDetails = [
                    'toothNumber' => $toothNumbers[array_rand($toothNumbers)],
                    'restorationType' => rand(0, 1) ? 'Inlay' : 'Onlay'
                ];
                break;
        }
        
        $caseData = [
            'id'              => $caseId,
            'driveFolderId'   => null,
            'patientFirstName'=> $firstName,
            'patientLastName' => $lastName,
            'patientDOB'      => $dob,
            'patientGender'   => $genders[array_rand($genders)],
            'dentistName'     => $dentistName,
            'caseType'        => $caseType,
            'toothShade'      => $toothShade,
            'dueDate'         => $dueDate,
            'creationDate'    => $createdDate,
            'lastUpdateDate'  => $lastUpdateDate,
            'status'          => $status,
            'statusChangedAt' => $statusChangedAt,
            'assignedTo'      => $assignedTo,
            'notes'           => 'Auto-generated dev case #' . ($i + 1),
            'revisions'       => [],
            'attachments'     => $attachments,
            'clinicalDetails' => !empty($clinicalDetails) ? $clinicalDetails : null
        ];

        if (in_array($caseType, $caseTypesRequiringMaterial, true)) {
            $caseData['material'] = $materials[array_rand($materials)];
        }

        // Encrypt PII before storing in cache
        $encryptedCaseData = PIIEncryption::encryptCaseData($caseData);
        
        // Store directly in the local cache; no Google Drive I/O in dev generator
        saveCaseToCache($encryptedCaseData);
        
        // Assign labels to the case
        if (!empty($caseLabelsToAssign)) {
            try {
                $labelStmt = $pdo->prepare("
                    INSERT INTO case_label_assignments (case_id, label_id, assigned_by)
                    VALUES (:case_id, :label_id, :assigned_by)
                ");
                
                foreach ($caseLabelsToAssign as $labelId) {
                    try {
                        $labelStmt->execute([
                            'case_id' => $caseId,
                            'label_id' => $labelId,
                            'assigned_by' => $userId
                        ]);
                    } catch (PDOException $e) {
                        // Ignore duplicate or invalid label assignments
                    }
                }
            } catch (Exception $e) {
                error_log('Error assigning labels to test case: ' . $e->getMessage());
            }
        }
        
        $created++;
    }

    if ($created === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No cases were created.'
        ]);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Generated ' . $created . ' test cases.',
        'createdCount' => $created,
        'requested'    => $count
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error while generating test cases: ' . $e->getMessage()
    ]);
}
