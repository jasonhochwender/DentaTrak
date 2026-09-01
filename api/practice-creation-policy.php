<?php
/**
 * Centralized Practice-creation policy
 *
 * All production paths that create a new Practice must use this helper so
 * laboratory and external-service creation is governed by one durable, server-
 * side rule set: explicit super-user status or the
 * users.lab_practice_creation_approved flag. Ordinary account-level
 * administrator status (users.role) is not written DentaTrak approval.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/dev-tools-access.php';

/**
 * Evaluate whether the given user may create a new Practice with the requested
 * organization type. Returns a structured result that callers can turn into an
 * HTTP response or use for an early exit.
 *
 * @param PDO   $pdo              Database connection
 * @param array $appConfig          Application configuration
 * @param int   $userId             Creating user ID
 * @param string $organizationType  Requested organization_type value
 * @return array { allowed: bool, is_super: bool, is_approved: bool, existing_org_type: ?string, reason: ?string, code: ?string, contact: ?string }
 */
function evaluatePracticeCreationPolicy(PDO $pdo, array $appConfig, int $userId, string $organizationType): array
{
    $stmt = $pdo->prepare("
        SELECT email, organization_type, lab_practice_creation_approved
        FROM users
        WHERE id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        return [
            'allowed' => false,
            'is_super' => false,
            'is_approved' => false,
            'existing_org_type' => null,
            'reason' => 'User not found.',
            'code' => 'USER_NOT_FOUND',
            'contact' => null,
        ];
    }

    $existingOrgType = $userRow['organization_type'] ?? null;
    $isSuper = isSuperUser($appConfig, $userRow['email'] ?? '');
    $isApproved = $isSuper || !empty($userRow['lab_practice_creation_approved']);

    // Once an organization type is recorded, an ordinary user may not change it
    // through self-service forms or direct API calls. Only DentaTrak super users
    // may correct classification.
    if ($existingOrgType !== null && $existingOrgType !== $organizationType && !$isSuper) {
        return [
            'allowed' => false,
            'is_super' => false,
            'is_approved' => false,
            'existing_org_type' => $existingOrgType,
            'reason' => 'Organization type may not be changed after initial classification. Please contact support@dentatrak.com.',
            'code' => 'ORG_TYPE_CHANGE_NOT_ALLOWED',
            'contact' => 'support@dentatrak.com',
        ];
    }

    // Dental laboratories and other external service providers may not create a
    // Practice unless expressly approved by DentaTrak.
    if ($organizationType === 'lab' && !$isApproved) {
        return [
            'allowed' => false,
            'is_super' => false,
            'is_approved' => false,
            'existing_org_type' => $existingOrgType,
            'reason' => 'Dental laboratories may not create a self-service Practice. Please contact support@dentatrak.com for approved partnership options.',
            'code' => 'LAB_CREATION_NOT_APPROVED',
            'contact' => 'support@dentatrak.com',
        ];
    }

    return [
        'allowed' => true,
        'is_super' => $isSuper,
        'is_approved' => $isApproved,
        'existing_org_type' => $existingOrgType,
        'reason' => null,
        'code' => null,
        'contact' => null,
    ];
}

/**
 * Convenience wrapper that exits with a 403 JSON response when the policy
 * disallows creation.
 *
 * @return array The successful policy result, including is_super/is_approved.
 */
function requirePracticeCreationAllowed(PDO $pdo, array $appConfig, int $userId, string $organizationType): array
{
    $result = evaluatePracticeCreationPolicy($pdo, $appConfig, $userId, $organizationType);

    if ($result['allowed']) {
        return $result;
    }

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error_code' => $result['code'],
        'message' => $result['reason'],
        'contact' => $result['contact'],
    ]);
    exit;
}
