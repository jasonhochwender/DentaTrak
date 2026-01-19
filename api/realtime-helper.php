<?php
/**
 * Real-time Updates Helper Functions
 * Functions to trigger real-time updates via SSE
 */

/**
 * Trigger a real-time update for specific users
 * @param string $event Event type (case_updated, case_assigned, etc.)
 * @param array $data Data to send to clients
 * @param array $targetUsers Array of user IDs to notify (optional, defaults to all practice users)
 * @param int $practiceId Practice ID (optional, uses current practice if not provided)
 */
function triggerRealtimeUpdate($event, $data, $targetUsers = null, $practiceId = null) {
    global $pdo;
    
    if (!$pdo) {
        return false;
    }
    
    // Get practice ID if not provided
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    
    if (!$practiceId) {
        return false;
    }
    
    // Get connections directory
    $connectionsDir = __DIR__ . '/sse_connections';
    if (!is_dir($connectionsDir)) {
        return false;
    }
    
    // If no specific users provided, get all users in the practice
    if ($targetUsers === null) {
        try {
            $stmt = $pdo->prepare("
                SELECT user_id 
                FROM practice_users 
                WHERE practice_id = :practice_id
            ");
            $stmt->execute(['practice_id' => $practiceId]);
            $targetUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log('Failed to get practice users for realtime update: ' . $e->getMessage());
            return false;
        }
    }
    
    // Send update to each target user
    foreach ($targetUsers as $userId) {
        $updatesFile = $connectionsDir . '/updates_' . $userId . '.json';
        
        // Read existing updates
        $updates = [];
        if (file_exists($updatesFile)) {
            $updates = json_decode(file_get_contents($updatesFile), true) ?: [];
        }
        
        // Add new update
        $updates[] = [
            'event' => $event,
            'data' => $data,
            'timestamp' => time()
        ];
        
        // Keep only last 50 updates to prevent file from growing too large
        if (count($updates) > 50) {
            $updates = array_slice($updates, -50);
        }
        
        // Save updates
        file_put_contents($updatesFile, json_encode($updates));
    }
    
    return true;
}

/**
 * Trigger update when a case is modified
 * @param string $caseId The case ID that was updated
 * @param array $caseData The updated case data
 * @param int $practiceId The practice ID
 */
function triggerCaseUpdate($caseId, $caseData, $practiceId = null) {
    triggerRealtimeUpdate('case_updated', [
        'caseId' => $caseId,
        'caseData' => $caseData,
        'action' => 'updated'
    ], null, $practiceId);
}

/**
 * Trigger update when a case is assigned to a user
 * @param string $caseId The case ID
 * @param string $assignedTo The user email it was assigned to
 * @param int $practiceId The practice ID
 */
function triggerCaseAssignment($caseId, $assignedTo, $practiceId = null) {
    global $pdo;
    
    // Get the user ID for the assigned email
    $assignedUserId = null;
    if ($assignedTo && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $assignedTo]);
            $assignedUserId = $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Failed to get user ID for assignment notification: ' . $e->getMessage());
        }
    }
    
    // Send to all users (so they see the case move) but prioritize the assigned user
    $targetUsers = $assignedUserId ? [$assignedUserId] : null;
    
    triggerRealtimeUpdate('case_assigned', [
        'caseId' => $caseId,
        'assignedTo' => $assignedTo,
        'action' => 'assigned'
    ], $targetUsers, $practiceId);
}

/**
 * Trigger update when a case status changes
 * @param string $caseId The case ID
 * @param string $oldStatus Previous status
 * @param string $newStatus New status
 * @param int $practiceId The practice ID
 */
function triggerCaseStatusChange($caseId, $oldStatus, $newStatus, $practiceId = null) {
    triggerRealtimeUpdate('case_status_changed', [
        'caseId' => $caseId,
        'oldStatus' => $oldStatus,
        'newStatus' => $newStatus,
        'action' => 'status_changed'
    ], null, $practiceId);
}

/**
 * Clean up old SSE connections (run periodically)
 */
function cleanupOldSSEConnections() {
    $connectionsDir = __DIR__ . '/sse_connections';
    if (!is_dir($connectionsDir)) {
        return;
    }
    
    $cutoffTime = time() - 300; // 5 minutes
    $files = glob($connectionsDir . '/*.json');
    
    foreach ($files as $file) {
        if (basename($file) !== 'updates_' && strpos(basename($file), 'updates_') !== 0) {
            // This is a connection file
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['lastPing']) && $data['lastPing'] < $cutoffTime) {
                unlink($file);
            }
        }
    }
}
