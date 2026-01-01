<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/user-manager.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is an admin
$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

// For security, only allow admin users to run this script
if (!$isAdmin) {
    // For initial setup, allow if no users exist
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $userCount = $stmt->fetchColumn();
        if ($userCount > 0) {
            die("You must be an admin user to access this page.");
        }
    } catch (PDOException $e) {
        // If table doesn't exist yet, allow setup
        if (strpos($e->getMessage(), "Table 'dental_case_tracker.users' doesn't exist") === false) {
            die("Database error: " . $e->getMessage());
        }
    }
}

$message = '';
$success = false;

// Run setup when button is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); // Capture any output
    require_once __DIR__ . '/api/setup-practice-tables.php';
    $output = ob_get_clean();
    
    if (strpos($output, 'successfully') !== false) {
        $success = true;
        $message = "Database tables created successfully!";
    } else {
        $message = "Error: " . $output;
    }
}

// Check for tables that already exist
$existingTables = [];
try {
    $tables = ['practices', 'practice_users'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
        $stmt->execute(['table' => $table]);
        if ($stmt->fetchColumn()) {
            $existingTables[] = $table;
        }
    }
} catch (Exception $e) {
    // Ignore errors here
}

// Function to get user ID column type
function getUserIdType($pdo) {
    try {
        $stmt = $pdo->query("DESCRIBE users");
        $usersColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($usersColumns as $column) {
            if ($column['Field'] === 'id') {
                return $column['Type'];
            }
        }
    } catch (Exception $e) {
        // Table doesn't exist or other error
    }
    return 'unknown';
}

$userIdType = getUserIdType($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
        }
        h1 {
            color: #333;
        }
        .success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .error {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .warning {
            background-color: #fff3cd;
            border-color: #ffeeba;
            color: #856404;
            padding: 10px;
            border-radius: 4px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <h1>Database Setup</h1>
    
    <?php if ($message): ?>
        <div class="<?php echo $success ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <div class="container">
        <h2>Database Status</h2>
        <table>
            <tr>
                <th>Component</th>
                <th>Status</th>
            </tr>
            <tr>
                <td>Database Connection</td>
                <td><?php echo isset($pdo) ? 'Connected' : 'Not Connected'; ?></td>
            </tr>
            <tr>
                <td>Users Table ID Type</td>
                <td><?php echo htmlspecialchars($userIdType); ?></td>
            </tr>
            <tr>
                <td>Practice Tables</td>
                <td>
                    <?php if (empty($existingTables)): ?>
                        Not installed
                    <?php else: ?>
                        Installed: <?php echo htmlspecialchars(implode(', ', $existingTables)); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <?php if (count($existingTables) === 2): ?>
            <div class="success">
                Practice tables already exist. You're all set!
                <p><a href="practice-setup.php">Continue to Practice Setup</a></p>
            </div>
        <?php else: ?>
            <?php if (!empty($existingTables)): ?>
                <div class="warning">
                    Some practice tables exist but not all. This may indicate a partial or failed setup.
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <p>Click the button below to set up the practice tables:</p>
                <button type="submit">Create Practice Tables</button>
            </form>
        <?php endif; ?>
    </div>
    
    <p><a href="index.php">Back to login page</a></p>
</body>
</html>
