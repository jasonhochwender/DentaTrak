<?php
/**
 * Ask DentaTrak usage telemetry (product analytics, NOT PHI audit).
 *
 * Privacy design:
 *   - Stores only sanitized classification metadata: category, intent, a
 *     normalized_topic chosen from a FIXED allowlist, the allowlisted
 *     tool names that ran, a controlled outcome enum, locale and latency.
 *   - NEVER stores the user's question, the conversation, model prompts,
 *     model answers, patient names, case content, filenames, or tool
 *     result payloads. Anything the model emits that is not on the
 *     allowlist is discarded before it can reach the database.
 *   - PHI audit remains in phi_access_log (PHI_ACTION_ASK_QUERY); this
 *     table is intentionally separate.
 *
 * Writes are fail-safe: a telemetry error is logged and never affects the
 * assistant response.
 */

if (!function_exists('askTelemetryCategories')) {

/**
 * Controlled allowlists - the only values that may ever be stored.
 */
function askTelemetryCategories(): array {
    return [
        'product_help',      // how to use a DentaTrak feature
        'product_info',      // pricing, plans, support, public product facts
        'case_data',         // questions about the user's own cases
        'insights_redirect', // deep analytics routed to Insights
        'private_refusal',   // private/internal company info declined
        'out_of_scope',      // anything else the assistant cannot help with
    ];
}

function askTelemetryIntents(): array {
    return [
        'how_to', 'definition', 'pricing', 'contact', 'availability',
        'count', 'list', 'aggregate', 'summarize', 'lookup',
        'navigate', 'redirect', 'refusal', 'clarify', 'other',
    ];
}

function askTelemetryTopics(): array {
    return [
        // product help / info
        'what_is_dentatrak', 'pricing_plans', 'trial', 'support_contact',
        'security_contact', 'interface_languages', 'attachments_storage',
        'billing', 'security_hipaa', 'keyboard_shortcuts',
        'add_user', 'add_lab_user', 'create_case', 'edit_case',
        'workflow_statuses', 'filters', 'download_files', 'two_factor',
        'needs_review', 'appointment_risk', 'integrations',
        'archive_history', 'remake_process', 'insights_feature',
        'preferences_notifications',
        // case data
        'cases_general', 'cases_due', 'cases_overdue', 'cases_by_status',
        'cases_assigned', 'cases_by_lab', 'remakes', 'case_lookup',
        'reference_values',
        // boundaries
        'private_company_info', 'unsupported_feature', 'other',
    ];
}

function askTelemetryOutcomes(): array {
    return [
        'answered', 'clarification_requested', 'insights_redirect',
        'refused_private', 'not_supported', 'tool_error', 'model_error',
        'authorization_denied', 'other',
    ];
}

/** Value -> allowlist member, or null when it does not belong. */
function askTelemetryAllowed(?string $value, array $allowlist): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, $allowlist, true) ? $value : null;
}

function askTelemetryCategory(?string $v): ?string {
    return askTelemetryAllowed($v, askTelemetryCategories());
}

function askTelemetryIntent(?string $v): ?string {
    return askTelemetryAllowed($v, askTelemetryIntents());
}

function askTelemetryTopic(?string $v): ?string {
    return askTelemetryAllowed($v, askTelemetryTopics());
}

function askTelemetryOutcome(?string $v): ?string {
    return askTelemetryAllowed($v, askTelemetryOutcomes());
}

/**
 * Tool names: validate each against the real tool registry so a hallucinated
 * name can never be persisted.
 */
function askTelemetryToolUsed(array $toolNames): ?string {
    if (!function_exists('getAskDentatrakToolNames')) {
        return null;
    }
    $valid = getAskDentatrakToolNames();
    $clean = [];
    foreach ($toolNames as $name) {
        $name = strtolower(trim((string)$name));
        if (in_array($name, $valid, true) && !in_array($name, $clean, true)) {
            $clean[] = $name;
        }
    }
    return $clean ? implode(',', array_slice($clean, 0, 3)) : null;
}

/**
 * Write one usage event. Returns the new row id, or null when telemetry is
 * unavailable - callers must treat this as best-effort and never let it
 * affect the user-facing response.
 *
 * $event keys (all sanitized here): category, intent, normalized_topic,
 * tool_used, outcome, latency_ms.
 */
function recordAskDentatrakUsage(array $event): ?int {
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    $category = askTelemetryCategory($event['category'] ?? null) ?? 'out_of_scope';
    $outcome  = askTelemetryOutcome($event['outcome'] ?? null) ?? 'other';
    $latency  = isset($event['latency_ms']) && is_numeric($event['latency_ms'])
        ? max(0, min(3600000, (int)$event['latency_ms']))
        : null;
    $locale = isset($event['locale']) ? mb_substr((string)$event['locale'], 0, 10) : null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO ask_dentatrak_usage
                (user_id, practice_id, locale, category, intent,
                 normalized_topic, tool_used, outcome, latency_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$event['user_id'],
            (int)$event['practice_id'],
            $locale,
            $category,
            askTelemetryIntent($event['intent'] ?? null),
            askTelemetryTopic($event['normalized_topic'] ?? null),
            isset($event['tool_used']) ? askTelemetryToolUsed((array)$event['tool_used']) : null,
            $outcome,
            $latency,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Table may not exist yet (migration pending) - never surface to user.
        error_log('Ask DentaTrak telemetry write failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Record thumbs-up/down feedback on a telemetry row the caller owns.
 */
function recordAskDentatrakFeedback(int $usageId, int $userId, int $practiceId, string $value): bool {
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO) || $usageId <= 0) {
        return false;
    }
    $map = ['up' => 1, 'down' => -1, 'none' => null];
    if (!array_key_exists($value, $map)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("
            UPDATE ask_dentatrak_usage
            SET feedback = ?
            WHERE id = ? AND user_id = ? AND practice_id = ?
        ");
        $stmt->execute([$map[$value], $usageId, $userId, $practiceId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('Ask DentaTrak feedback write failed: ' . $e->getMessage());
        return false;
    }
}

}
