<?php
/**
 * Defensive source checks for the user/practice access helpers and
 * the get-user-practices listing endpoint. These verify the SQL and
 * response shape enforce active-state filtering without requiring a
 * live database connection.
 */
namespace PracticeAccessDefensiveTest;

if (PHP_SAPI !== 'cli') {
    exit;
}

$passed = 0;
function check($condition, $name) {
    if (!$condition) {
        throw new \RuntimeException('FAIL: ' . $name);
    }
    $GLOBALS['passed']++;
    echo "PASS: {$name}\n";
}

$listingSource = file_get_contents(__DIR__ . '/../api/get-user-practices.php');
$accessSource = file_get_contents(__DIR__ . '/../api/unified-identity.php');

$activeUserFilter = 'u.is_active = 1';
$activePracticeFilter = '(p.is_active = 1 OR p.is_active IS NULL)';
$noMembershipIsActive = 'pu.is_active';

// get-user-practices.php
foreach ([
    'JOIN users u ON u.id = pu.user_id',
    $activeUserFilter,
    $activePracticeFilter,
    'IFNULL(pu.is_lab, 0) AS is_lab',
    'p.organization_type',
] as $fragment) {
    check(strpos($listingSource, $fragment) !== false, 'get-user-practices query contains ' . $fragment);
}
check(strpos($listingSource, $noMembershipIsActive) === false, 'get-user-practices does not use unsupported practice_users.is_active');
check(strpos($listingSource, "'practices'") !== false && strpos($listingSource, "'current_practice_id'") !== false && strpos($listingSource, "'has_multiple'") !== false, 'get-user-practices returns expected JSON shape');
check(strpos($listingSource, 'success') !== false && strpos($listingSource, 'error') !== false, 'get-user-practices returns success/error JSON');

// userHasPracticeAccess()
$hasAccessBlock = substr($accessSource, strpos($accessSource, 'function userHasPracticeAccess'));
$hasAccessBlock = substr($hasAccessBlock, 0, strpos($hasAccessBlock, "function getUserPractices"));
check(strpos($hasAccessBlock, 'JOIN users u ON u.id = pu.user_id') !== false, 'userHasPracticeAccess joins users');
check(strpos($hasAccessBlock, 'JOIN practices p ON p.id = pu.practice_id') !== false, 'userHasPracticeAccess joins practices');
check(strpos($hasAccessBlock, $activeUserFilter) !== false, 'userHasPracticeAccess filters active users');
check(strpos($hasAccessBlock, $activePracticeFilter) !== false, 'userHasPracticeAccess filters active practices');
check(strpos($hasAccessBlock, $noMembershipIsActive) === false, 'userHasPracticeAccess does not use unsupported practice_users.is_active');
check(strpos($hasAccessBlock, 'LIMIT 1') !== false, 'userHasPracticeAccess uses LIMIT 1');

// getUserPractices()
$practicesBlock = substr($accessSource, strpos($accessSource, 'function getUserPractices'));
$practicesBlock = substr($practicesBlock, 0, strpos($practicesBlock, "function checkUserPracticeAccess"));
check(strpos($practicesBlock, 'JOIN users u ON u.id = pu.user_id') !== false, 'getUserPractices joins users');
check(strpos($practicesBlock, $activeUserFilter) !== false, 'getUserPractices filters active users');
check(strpos($practicesBlock, $activePracticeFilter) !== false, 'getUserPractices filters active practices');
check(strpos($practicesBlock, $noMembershipIsActive) === false, 'getUserPractices does not use unsupported practice_users.is_active');
check(strpos($practicesBlock, 'is_lab') !== false, 'getUserPractices selects is_lab');
check(strpos($practicesBlock, 'organization_type') !== false, 'getUserPractices selects organization_type');

echo "{$passed} practice access source checks passed.\n";
