<?php
/**
 * Schema verification helpers
 *
 * Application code must not run DDL during ordinary requests. These helpers
 * confirm that the required legal/account-classification migration has been
 * applied and fail closed if it has not.
 */

/**
 * Verify that all legal/account-classification columns exist on users and practices.
 * Caches the result per request. Does not modify the schema.
 */
function hasAccountClassificationColumns(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $required = [
        'practices' => [
            'legal_name',
            'display_name',
            'practice_address',
            'baa_accepted',
            'baa_accepted_at',
            'baa_version',
            'baa_accepted_by_user_id',
            'baa_signer_name',
            'baa_signer_title',
            'organization_type',
        ],
        'users' => [
            'organization_type',
            'organization_type_other',
            'lab_practice_creation_approved',
            'terms_accepted_version',
            'terms_accepted_at',
        ],
    ];

    foreach ($required as $table => $columns) {
        foreach ($columns as $col) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
            if ($stmt->rowCount() === 0) {
                $cached = false;
                return false;
            }
        }
    }

    $cached = true;
    return true;
}

/**
 * Require the account-classification schema to be present. Returns a 500
 * response and exits if the migration has not been applied. Use this at the
 * start of affected request handlers, never for DDL.
 */
function requireAccountClassificationSchema(PDO $pdo): void {
    if (hasAccountClassificationColumns($pdo)) {
        return;
    }

    $message = 'Database schema is not current. Please run migrations/2026_09_01_account_classification.php.';
    error_log('[schema-helpers] ' . $message);

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}
