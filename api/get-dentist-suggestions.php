<?php
/**
 * Dentist Name Autocomplete API
 * 
 * Returns dentist names previously used by the current practice,
 * ordered by most recently used first.
 * 
 * Security: Results are scoped to the current practice only.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/encryption.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();

// Get search query parameter
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Minimum characters before searching (prevents overly broad queries)
$minQueryLength = 1;

if (strlen($query) < $minQueryLength) {
    echo json_encode([
        'success' => true,
        'suggestions' => []
    ]);
    exit;
}

try {
    global $pdo;
    
    if (!$pdo) {
        throw new Exception('Database connection unavailable');
    }
    
    // ============================================
    // DENTIST NAME AUTOCOMPLETE QUERY
    // Business Rule: Returns distinct dentist names from cases
    // belonging to the current practice, ordered by most recent use.
    // This derives suggestions from existing case data rather than
    // maintaining a separate dentist directory.
    // 
    // Note: dentist_name is encrypted, so we fetch all unique values,
    // decrypt them, then filter by the search query in PHP.
    // ============================================
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT dentist_name, MAX(last_update_date) as last_used
        FROM cases_cache
        WHERE practice_id = :practice_id
          AND dentist_name IS NOT NULL
          AND dentist_name != ''
          AND archived = 0
        GROUP BY dentist_name
        ORDER BY last_used DESC
    ");
    
    $stmt->execute([
        'practice_id' => $currentPracticeId
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decrypt dentist names and filter by search query
    $queryLower = strtolower($query);
    $suggestions = [];
    
    foreach ($results as $row) {
        try {
            $decryptedName = PIIEncryption::decrypt($row['dentist_name']);
            if ($decryptedName && stripos($decryptedName, $query) !== false) {
                $suggestions[] = $decryptedName;
            }
        } catch (Exception $e) {
            // If decryption fails, the value might be unencrypted (legacy data)
            // Try using it directly
            if (stripos($row['dentist_name'], $query) !== false) {
                $suggestions[] = $row['dentist_name'];
            }
        }
        
        // Limit to 10 suggestions
        if (count($suggestions) >= 10) {
            break;
        }
    }
    
    // Remove duplicates (in case same name appears with different encryption)
    $suggestions = array_values(array_unique($suggestions));
    
    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions
    ]);
    
} catch (Exception $e) {
    error_log('[get-dentist-suggestions] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch suggestions',
        'suggestions' => []
    ]);
}
