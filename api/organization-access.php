<?php
/**
 * Organization Access Scope
 *
 * DentaTrak has no separate `organizations` table: the organization is the
 * subscription owner's account. Every practice created through
 * api/accept-baa.php inserts a practice_users row with is_owner = 1 for the
 * creating user; all practices where the same user is owner form one
 * organization and share that owner's single `subscriptions` row (plan,
 * trial, Stripe billing). See api/subscription-owner.php and
 * api/plan-entitlements.php.
 *
 * Authority model (reuses the existing role system - no new role):
 *   - Organization authority = the subscription owner resolved through any
 *     owned practice (getSubscriptionOwnerUserId()). The data model has NO
 *     distinct "organization administrator" role today: a practice_users
 *     role='admin' membership is practice-scoped and must never be treated
 *     as organization authority. users.role='admin' is a platform-level
 *     DentaTrak flag (first user / configured admin lists) and likewise
 *     grants nothing here.
 *   - Every other user can access only the organization's practices they
 *     are explicitly assigned to (an active practice_users membership row).
 *
 * These helpers resolve ACCESS SCOPE only. Inclusion in the returned list
 * grants no action permissions: per-practice authorization
 * (practice-security.php - getUserPracticePermissions(), isPracticeAdmin(),
 * canEditCases(), canViewAnalytics(), hasLimitedVisibility()) and feature
 * entitlement (subscription-access.php - hasScaleAccess()/hasControlAccess())
 * still apply unchanged inside each practice. Practices from another
 * organization are never included.
 */

require_once __DIR__ . '/subscription-owner.php';

/**
 * ============================================================
 * Consolidated "All practices" case-view scope (Scale)
 * ============================================================
 *
 * "All practices" is a VIEW SCOPE, not a synthetic practice: it is stored
 * as a session flag (org_case_view) and never appears as a practice_id in
 * any API payload or query. The session's real current_practice_id always
 * remains set and continues to anchor the organization; every request
 * re-derives the authorized practice set from live membership/ownership
 * rows plus the live Scale entitlement, so revoked access or a lost
 * entitlement narrows the scope on the very next request.
 */

/**
 * Whether the session has requested the consolidated organization case
 * view. This flag alone grants nothing - orgCaseViewEntitled() performs
 * the live entitlement check on top of it.
 */
function isOrgCaseViewRequested(): bool {
    return !empty($_SESSION['org_case_view']);
}

/**
 * Whether the consolidated view is currently usable for this request:
 * the session requested it, has a valid practice context, and the
 * organization still holds the Scale entitlement (subscription/trial/
 * grace/bypass semantics from subscription-access.php). Evaluated once
 * per request.
 */
function orgCaseViewEntitled(PDO $pdo): bool {
    static $entitled = null;
    if ($entitled !== null) {
        return $entitled;
    }

    $entitled = false;
    if (!isOrgCaseViewRequested()) {
        return $entitled;
    }

    $anchorPracticeId = (int)($_SESSION['current_practice_id'] ?? 0);
    $userId = (int)($_SESSION['db_user_id'] ?? 0);
    if ($anchorPracticeId <= 0 || $userId <= 0) {
        return $entitled;
    }

    require_once __DIR__ . '/subscription-access.php';
    require_once __DIR__ . '/practice-security.php';
    try {
        $entitled = hasScaleAccess($pdo, $anchorPracticeId, (string)(getCurrentUserEmail() ?? ''));
    } catch (Throwable $e) {
        error_log('[organization-access] Scale entitlement check failed: ' . $e->getMessage());
        $entitled = false;
    }
    return $entitled;
}

/**
 * Authorized practice rows for the consolidated view, or [] when the view
 * is not requested/entitled. Each row carries per-practice permission
 * flags (see getAuthorizedOrganizationPractices) so callers keep applying
 * per-practice rules - org visibility never implies action rights.
 *
 * @return array[]
 */
function getOrgCaseViewPractices(PDO $pdo): array {
    if (!orgCaseViewEntitled($pdo)) {
        return [];
    }
    $userId = (int)($_SESSION['db_user_id'] ?? 0);
    $anchorPracticeId = (int)($_SESSION['current_practice_id'] ?? 0);
    return getAuthorizedOrganizationPractices($pdo, $userId, $anchorPracticeId);
}

/**
 * Just the authorized practice IDs for the consolidated view ([] when
 * not entitled). An empty result must be treated as "no practices", never
 * as an unrestricted scope.
 *
 * @return int[]
 */
function getOrgCaseViewPracticeIds(PDO $pdo): array {
    $ids = [];
    foreach (getOrgCaseViewPractices($pdo) as $row) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

/**
 * Resolve the effective practice for a case-scoped request.
 *
 * Single-practice mode (flag off or entitlement lost): returns the session
 * practice unchanged - identical behavior to before.
 *
 * Consolidated view: the case's OWN practice_id is looked up server-side
 * and returned only when it belongs to the authorized organization set.
 * Otherwise the session practice is returned, so the downstream
 * requireCaseAccess() still produces the indistinguishable 404 "Case not
 * found" for cases in another practice/organization or after access was
 * revoked. When the resolved practice differs from the session practice
 * and that practice requires 2FA, the standard 2FA challenge response is
 * emitted before any case data flows.
 *
 * Call AFTER requireValidPracticeContext() and once the client-supplied
 * case ID has been extracted/validated. Reassign $currentPracticeId with
 * the result so every downstream permission check, workflow-column
 * validation, notification, and storage path applies to the case's actual
 * practice.
 *
 * @param string $caseId Client-supplied case identifier (may be bogus).
 * @return int The practice ID to use for this request's case context.
 */
function resolveCaseScopePracticeId($caseId) {
    global $pdo;

    $sessionPracticeId = (int)($_SESSION['current_practice_id'] ?? 0);

    if (!$pdo || !isOrgCaseViewRequested() || !orgCaseViewEntitled($pdo)) {
        return $sessionPracticeId;
    }

    $caseId = trim((string)$caseId);
    if ($caseId === '' || strlen($caseId) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $caseId)) {
        return $sessionPracticeId;
    }

    try {
        $stmt = $pdo->prepare("SELECT practice_id FROM cases_cache WHERE case_id = :case_id LIMIT 1");
        $stmt->execute(['case_id' => $caseId]);
        $casePracticeId = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[organization-access] case practice lookup failed: ' . $e->getMessage());
        return $sessionPracticeId;
    }

    if ($casePracticeId <= 0 || $casePracticeId === $sessionPracticeId) {
        return $sessionPracticeId;
    }

    $userId = (int)($_SESSION['db_user_id'] ?? 0);
    if (!in_array($casePracticeId, getOrgCaseViewPracticeIds($pdo), true)) {
        // Case belongs to a practice outside the authorized set (other
        // organization, unassigned practice, revoked access). Return the
        // session practice so requireCaseAccess() yields its standard
        // indistinguishable "case not found".
        return $sessionPracticeId;
    }

    // The case's own practice may enforce 2FA even when the anchor does
    // not; reuse the same block/redirect contract as
    // requireValidPracticeContext().
    $twoFABlock = getPractice2FABlock($casePracticeId, $userId);
    if ($twoFABlock !== null) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $twoFABlock['message'],
            'error_code' => $twoFABlock['error_code'],
            'redirect' => $twoFABlock['redirect']
        ]);
        exit;
    }

    return $casePracticeId;
}

/**
 * Validate a client-supplied practice_id for organization-scoped option
 * endpoints (assignees, workflow columns, dentist suggestions) in the
 * consolidated view. Returns the session practice when no override is
 * requested; returns the validated practice when the consolidated view is
 * active AND the id belongs to the authorized set; any other supplied
 * value is rejected with 403.
 *
 * @param mixed $practiceIdParam Client-supplied practice id (or null/'').
 * @return int
 */
function resolveOrgAuthorizedPracticeParam($practiceIdParam) {
    global $pdo;

    $sessionPracticeId = (int)($_SESSION['current_practice_id'] ?? 0);

    if ($practiceIdParam === null || $practiceIdParam === '') {
        return $sessionPracticeId;
    }

    $target = filter_var($practiceIdParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($target !== false && (int)$target === $sessionPracticeId) {
        return $sessionPracticeId;
    }

    if ($pdo && $target !== false && orgCaseViewEntitled($pdo)) {
        if (in_array((int)$target, getOrgCaseViewPracticeIds($pdo), true)) {
            return (int)$target;
        }
    }

    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. You do not have permission to access this practice.'
    ]);
    exit;
}

/**
 * Whether the user account itself is active. A deactivated account has no
 * authorized practices in any organization.
 */
function orgAccessUserIsActive(PDO $pdo, int $userId): bool {
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log('[organization-access] user active check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Resolve the organization owner user ID for a practice. The organization is
 * the subscription owner's account, so this is the user with
 * practice_users.is_owner = 1 for the anchor practice (with the established
 * practices.created_by legacy fallback inside getSubscriptionOwnerUserId()).
 *
 * @return int|null null when the practice has no resolvable owner.
 */
function getOrganizationOwnerUserId(PDO $pdo, int $anchorPracticeId): ?int {
    if ($anchorPracticeId <= 0) {
        return null;
    }
    return getSubscriptionOwnerUserId($pdo, $anchorPracticeId);
}

/**
 * Whether the user holds organization authority over the organization that
 * the anchor practice belongs to. Today that means exactly one thing: the
 * user IS the subscription owner of that organization. A practice admin of
 * one or more practices is NOT an organization admin.
 */
function isOrganizationAuthority(PDO $pdo, int $userId, int $anchorPracticeId): bool {
    if ($userId <= 0 || $anchorPracticeId <= 0) {
        return false;
    }
    $ownerUserId = getOrganizationOwnerUserId($pdo, $anchorPracticeId);
    if ($ownerUserId === null || $ownerUserId !== $userId) {
        return false;
    }
    return orgAccessUserIsActive($pdo, $userId);
}

/**
 * Fetch the active practices that make up an organization: every practice
 * where the owner has a practice_users.is_owner = 1 membership. Inactive
 * practices (is_active = 0) are never part of the accessible set; legacy
 * NULL is treated as active, matching practice-security.php.
 *
 * @return array[] rows: id, uuid, practice_name, organization_type
 */
function getOrganizationPracticeRows(PDO $pdo, int $ownerUserId): array {
    if ($ownerUserId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.practice_id AS uuid, p.practice_name, p.organization_type
            FROM practices p
            JOIN practice_users pu
              ON pu.practice_id = p.id
             AND pu.user_id = :owner_id
             AND pu.is_owner = 1
            WHERE (p.is_active = 1 OR p.is_active IS NULL)
        ");
        $stmt->execute(['owner_id' => $ownerUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[organization-access] org practice lookup failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * The single authoritative resolver for "which practices may this user
 * access within the current organization?"
 *
 * - $anchorPracticeId identifies the current organization: the organization
 *   is whatever organization that practice belongs to (its subscription
 *   owner). Callers should pass the validated session practice
 *   ($_SESSION['current_practice_id']) or another already-authorized
 *   practice - never an unverified client-supplied ID alone, since the
 *   anchor silently determines which organization is in scope.
 * - The subscription owner (organization authority) can access every active
 *   practice in the organization.
 * - Any other user can access only organization practices they are
 *   explicitly assigned to via practice_users.
 * - A practice in a different organization can never appear here.
 *
 * Each returned row carries the user's per-practice membership permissions
 * when a membership exists ('role', 'is_owner', 'limited_visibility',
 * 'can_view_analytics', 'can_edit_cases', 'is_lab'), so callers can keep
 * enforcing action-level permissions per practice. 'access' records how the
 * practice became visible: 'organization' (organization authority) or
 * 'member' (explicit assignment). A practice visible only through
 * organization authority (no membership row - possible for an owner whose
 * membership row is missing) reports all permission flags as false: org
 * access alone never grants admin/edit rights.
 *
 * @return array[] authorized practice rows ordered by practice_name
 */
function getAuthorizedOrganizationPractices(PDO $pdo, int $userId, int $anchorPracticeId): array {
    if ($userId <= 0 || $anchorPracticeId <= 0) {
        return [];
    }

    if (!orgAccessUserIsActive($pdo, $userId)) {
        return [];
    }

    $ownerUserId = getOrganizationOwnerUserId($pdo, $anchorPracticeId);
    $orgRows = $ownerUserId !== null
        ? getOrganizationPracticeRows($pdo, $ownerUserId)
        : [];

    if (empty($orgRows)) {
        // No resolvable organization - e.g. a legacy practice whose owner
        // row no longer exists. The scope collapses to exactly the anchor
        // practice (if it is active) and authority to nobody.
        try {
            $stmt = $pdo->prepare("
                SELECT p.id, p.practice_id AS uuid, p.practice_name, p.organization_type
                FROM practices p
                WHERE p.id = :anchor
                  AND (p.is_active = 1 OR p.is_active IS NULL)
            ");
            $stmt->execute(['anchor' => $anchorPracticeId]);
            $orgRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('[organization-access] anchor practice lookup failed: ' . $e->getMessage());
            return [];
        }
        $ownerUserId = null;
    }

    if (empty($orgRows)) {
        return [];
    }

    $isAuthority = ($ownerUserId !== null && $ownerUserId === $userId);

    // The user's explicit memberships inside this organization only.
    $orgIds = array_map('intval', array_column($orgRows, 'id'));
    $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
    $memberships = [];
    try {
        $memberStmt = $pdo->prepare("
            SELECT pu.practice_id, pu.role, pu.is_owner,
                   IFNULL(pu.limited_visibility, 0) AS limited_visibility,
                   IFNULL(pu.can_view_analytics, 1) AS can_view_analytics,
                   IFNULL(pu.can_edit_cases, 1) AS can_edit_cases,
                   IFNULL(pu.is_lab, 0) AS is_lab
            FROM practice_users pu
            WHERE pu.user_id = ? AND pu.practice_id IN ({$placeholders})
        ");
        $memberStmt->execute(array_merge([$userId], $orgIds));
        foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $memberships[(int)$m['practice_id']] = $m;
        }
    } catch (PDOException $e) {
        error_log('[organization-access] membership lookup failed: ' . $e->getMessage());
        // Fail closed on memberships: the org authority path below is the
        // only remaining basis for access.
        $memberships = [];
    }

    $authorized = [];
    foreach ($orgRows as $row) {
        $practiceId = (int)$row['id'];
        $membership = $memberships[$practiceId] ?? null;

        if (!$isAuthority && $membership === null) {
            continue; // not assigned to this practice
        }

        $authorized[] = [
            'id'                 => $practiceId,
            'uuid'               => $row['uuid'] ?? null,
            'practice_name'      => $row['practice_name'] ?? null,
            'organization_type'  => $row['organization_type'] ?? null,
            'access'             => $isAuthority ? 'organization' : 'member',
            'is_member'          => $membership !== null,
            'role'               => $membership['role'] ?? null,
            'is_owner'           => $membership !== null ? (bool)$membership['is_owner'] : $isAuthority,
            'limited_visibility' => $membership !== null && !empty($membership['limited_visibility']),
            'can_view_analytics' => $membership !== null && !empty($membership['can_view_analytics']),
            'can_edit_cases'     => $membership !== null && !empty($membership['can_edit_cases']),
            'is_lab'             => $membership !== null && !empty($membership['is_lab']),
        ];
    }

    usort($authorized, function ($a, $b) {
        return strcasecmp($a['practice_name'] ?? '', $b['practice_name'] ?? '');
    });
    return $authorized;
}

/**
 * Convenience: just the authorized practice IDs for the user within the
 * anchor practice's organization.
 *
 * @return int[]
 */
function getAuthorizedOrganizationPracticeIds(PDO $pdo, int $userId, int $anchorPracticeId): array {
    $ids = [];
    foreach (getAuthorizedOrganizationPractices($pdo, $userId, $anchorPracticeId) as $row) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

/**
 * Authorized practice IDs for the current session user within the session's
 * current practice's organization. Empty when there is no authenticated
 * session or no practice context - callers must treat an empty set as
 * "no accessible practices", never as "all practices".
 *
 * @return int[]
 */
function getSessionAuthorizedOrgPracticeIds(PDO $pdo): array {
    $userId = $_SESSION['db_user_id'] ?? null;
    $anchorPracticeId = $_SESSION['current_practice_id'] ?? null;
    if (!$userId || !$anchorPracticeId) {
        return [];
    }
    return getAuthorizedOrganizationPracticeIds($pdo, (int)$userId, (int)$anchorPracticeId);
}

/**
 * Whether the user may access a specific target practice within the
 * organization anchored by $anchorPracticeId. Use this to validate any
 * client-supplied practice ID for future cross-practice operations: the
 * target is validated strictly (int >= 1) and must be a member of the
 * authorized set, so manipulated IDs, other organizations' practices, and
 * non-assigned practices are all denied.
 *
 * @param mixed $targetPracticeId Client-supplied practice ID (validated).
 */
function userCanAccessOrganizationPractice(PDO $pdo, int $userId, int $anchorPracticeId, $targetPracticeId): bool {
    $target = filter_var($targetPracticeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($target === false) {
        return false;
    }
    return in_array(
        (int)$target,
        getAuthorizedOrganizationPracticeIds($pdo, $userId, $anchorPracticeId),
        true
    );
}

/**
 * Build a SQL fragment constraining a query to an authorized practice set.
 * Reusable scoping support for future cross-practice case queries: pass the
 * IDs from getAuthorizedOrganizationPracticeIds() and the qualified column
 * (e.g. 'c.practice_id') to scope a query to exactly the practices the user
 * may access in the current organization.
 *
 * All IDs are re-validated to positive ints before interpolation, and the
 * column is restricted to a simple identifier/dotted-identifier whitelist -
 * no part of the fragment is ever built from raw client input. An empty
 * authorized set produces `1 = 0` (matches nothing), never an unrestricted
 * clause - there is deliberately no "all practices" bypass.
 *
 * @param int[]  $practiceIds Authorized practice IDs.
 * @param string $column      Column reference such as 'practice_id' or 'c.practice_id'.
 * @throws InvalidArgumentException when $column is not a simple identifier.
 */
function buildAuthorizedPracticeScopeSql(array $practiceIds, string $column = 'practice_id'): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
        throw new InvalidArgumentException('Invalid practice scope column');
    }

    $ids = [];
    foreach ($practiceIds as $id) {
        $n = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($n !== false) {
            $ids[] = (int)$n;
        }
    }
    $ids = array_values(array_unique($ids));

    if (empty($ids)) {
        return '1 = 0';
    }
    return $column . ' IN (' . implode(',', $ids) . ')';
}
