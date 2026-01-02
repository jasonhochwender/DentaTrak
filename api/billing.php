<?php
// Billing API endpoint
require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

// Do not show errors in the browser for this endpoint
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/feature-flags.php';

try {
    // SECURITY: Require valid practice context
    $currentPracticeId = requireValidPracticeContext();
    $userId = $_SESSION['db_user_id'];
    
    // Check if billing feature is disabled - if so, everyone is on Control plan
    $billingEnabled = isFeatureEnabled('SHOW_BILLING');
    
    // Get user's billing tier, case count, and created_at for trial calculation
    $stmt = $pdo->prepare("SELECT billing_tier, case_count, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    // If billing is disabled, treat everyone as Control plan
    $effectiveTier = $billingEnabled ? $user['billing_tier'] : 'control';
    
    // Get current case count (real-time calculation)
    $currentCaseCount = 0;
    
    // Get current user count for the practice
    $currentUserCount = 0;
    
    if ($currentPracticeId) {
        // First, fix any cases that don't have practice_id set (one-time migration)
        $stmt = $pdo->prepare("UPDATE cases_cache SET practice_id = ? WHERE practice_id IS NULL");
        $stmt->execute([$currentPracticeId]);
        
        // For evaluate plan, count ALL cases including archived ones
        if ($effectiveTier === 'evaluate') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ?");
        } else {
            // For paid plans, only count active cases
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ? AND archived = 0");
        }
        $stmt->execute([$currentPracticeId]);
        $currentCaseCount = (int)$stmt->fetchColumn();
        
        // Count users in this practice
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM practice_users WHERE practice_id = ?");
        $stmt->execute([$currentPracticeId]);
        $currentUserCount = (int)$stmt->fetchColumn();
    }
    
    // Get billing tier configuration (use effective tier)
    $tierConfig = $appConfig['billing']['tiers'][$effectiveTier] ?? $appConfig['billing']['tiers']['evaluate'];
    
    // Calculate trial days remaining for Evaluate plan
    $trialDaysRemaining = null;
    $trialExpired = false;
    $isTrial = $tierConfig['is_trial'] ?? false;
    
    if ($isTrial && isset($user['created_at'])) {
        $trialDays = $appConfig['billing']['trial_days'] ?? 30;
        $createdAt = new DateTime($user['created_at']);
        $now = new DateTime();
        $daysSinceSignup = $now->diff($createdAt)->days;
        $trialDaysRemaining = max(0, $trialDays - $daysSinceSignup);
        $trialExpired = $trialDaysRemaining <= 0;
    }
    
    // Check if user can create more cases
    $canCreateCases = true;
    
    // For trial plans, check if trial has expired
    if ($isTrial && $trialExpired) {
        $canCreateCases = false;
    } elseif ($tierConfig['max_cases'] > 0) {
        $canCreateCases = $currentCaseCount < $tierConfig['max_cases'];
    }
    
    // Update case count in users table
    if ($currentCaseCount !== $user['case_count']) {
        $stmt = $pdo->prepare("UPDATE users SET case_count = ? WHERE id = ?");
        $stmt->execute([$currentCaseCount, $userId]);
    }
    
    // Get max_users from tier config (0 means unlimited)
    $maxUsers = $tierConfig['max_users'] ?? 0;
    
    // Check if user can add more users
    $canAddUsers = $tierConfig['can_add_users'] ?? true;
    if ($isTrial && $trialExpired) {
        $canAddUsers = false;
    } elseif ($maxUsers > 0 && $currentUserCount >= $maxUsers) {
        $canAddUsers = false;
    }
    
    // Check if workspace exceeds user limit (for showing warning)
    $exceedsUserLimit = $maxUsers > 0 && $currentUserCount > $maxUsers;
    
    echo json_encode([
        'billing_tier' => $effectiveTier,
        'tier_name' => $tierConfig['name'],
        'case_count' => $currentCaseCount,
        'max_cases' => $tierConfig['max_cases'],
        'can_create_cases' => $canCreateCases,
        'can_add_users' => $canAddUsers,
        'has_analytics' => $isTrial ? !$trialExpired : ($tierConfig['has_analytics'] ?? true),
        'practice_id' => $currentPracticeId,
        'is_trial' => $isTrial,
        'trial_days_remaining' => $trialDaysRemaining,
        'trial_expired' => $trialExpired,
        'user_count' => $currentUserCount,
        'max_users' => $maxUsers,
        'exceeds_user_limit' => $exceedsUserLimit
    ]);
    
} catch (PDOException $e) {
    error_log('Billing API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
