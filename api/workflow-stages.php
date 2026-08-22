<?php
/**
 * Centralized workflow-stage definitions and practice-specific display
 * label resolution.
 *
 * DentaTrak has six fixed internal workflow statuses. These strings are
 * BOTH the permanent internal identifiers (also the literal
 * `cases_cache.status` DB values used by drag/drop, revision/backward-
 * movement logic, filters, etc.) AND, today, the default display text.
 * A practice may eventually override only the *display label* shown for
 * each stage (e.g. rename "Manufactured" to "Ready for Delivery" on the
 * board) - the internal status string itself never changes.
 *
 * getWorkflowStageDefinitions() below is the single authoritative
 * definition of the six statuses, their order, and their default labels.
 * Every other helper in this file - and every existing caller elsewhere
 * in the codebase (getWorkflowStageOrder(), getValidWorkflowStatuses(),
 * isValidWorkflowStatus(), isBackwardStatusMovement() in cases-cache.php)
 * - derives from it, so there is never more than one six-item list to keep
 * in sync.
 */

require_once __DIR__ . '/appConfig.php';

/** Maximum length (in characters) of a practice-specific stage label. */
const WORKFLOW_STAGE_LABEL_MAX_LENGTH = 40;

/**
 * The one authoritative workflow-stage definition: internal status =>
 * ['order' => <int>, 'defaultLabel' => <string>]. Do not add a second,
 * independent copy of the six statuses anywhere else - derive from this.
 */
function getWorkflowStageDefinitions() {
    return [
        'Originated'                  => ['order' => 0, 'defaultLabel' => 'Originated'],
        'Sent To External Lab'        => ['order' => 1, 'defaultLabel' => 'Sent To External Lab'],
        'Designed'                    => ['order' => 2, 'defaultLabel' => 'Designed'],
        'Manufactured'                => ['order' => 3, 'defaultLabel' => 'Manufactured'],
        'Received From External Lab'  => ['order' => 4, 'defaultLabel' => 'Received From External Lab'],
        'Delivered'                   => ['order' => 5, 'defaultLabel' => 'Delivered'],
    ];
}

/**
 * Centralized workflow stage order (index 0 = earliest, higher = later).
 * Used by every status-change code path (drag/drop, Edit Case save, demo
 * data generation) to determine forward vs. backward stage movement, so
 * the "backward movement" business rule is defined in exactly one place.
 */
function getWorkflowStageOrder() {
    $order = [];
    foreach (getWorkflowStageDefinitions() as $status => $definition) {
        $order[$status] = $definition['order'];
    }
    return $order;
}

/**
 * Build a `CASE WHEN status = '...' THEN n ... ELSE n+1 END` SQL fragment
 * (no leading "ORDER BY") derived from getWorkflowStageOrder(), for
 * queries that need to sort rows into workflow-stage order. Centralizes
 * what used to be several independent, hand-written six-line CASE blocks
 * (e.g. in get-analytics.php) into one place - if the order/stages ever
 * change, every caller of this function picks it up automatically.
 *
 * All values embedded here come from the fixed, internal
 * getWorkflowStageDefinitions() array (never user input), so simple
 * single-quote wrapping is safe - there is nothing to escape.
 *
 * @param string $column The (unquoted, already-trusted) SQL column/
 *   expression to compare, e.g. 'status' or 'cc.status'.
 * @return string
 */
/**
 * Build a "Stage A → Stage B → ... " description of the workflow, in
 * order, from the single centralized workflow-stage definition
 * (getValidWorkflowStatuses(), already in stage order) and the given
 * practice's resolved display labels - never a second, independent
 * hardcoded stage list. Used to keep AI system-prompt/context text
 * accurate instead of a stale hardcoded sentence. Falls back to the six
 * internal/default stage names when $practiceId is unavailable.
 *
 * @param int|null $practiceId
 * @return string
 */
function buildWorkflowStagesPromptText($practiceId) {
    $overrides = $practiceId ? getWorkflowStageLabelOverridesForPractice($practiceId) : [];
    $labels = array_map(function ($status) use ($overrides) {
        return resolveWorkflowStageLabel($status, $overrides);
    }, getValidWorkflowStatuses());
    return implode(' → ', $labels);
}

function getWorkflowStageOrderCaseSql($column = 'status') {
    $order = getWorkflowStageOrder();
    $lines = ['CASE'];
    foreach ($order as $status => $index) {
        $lines[] = "    WHEN {$column} = '{$status}' THEN " . ($index + 1);
    }
    $lines[] = '    ELSE ' . (count($order) + 1);
    $lines[] = 'END';
    return implode("\n", $lines);
}

/**
 * Authoritative list of the six internal workflow status values, derived
 * from getWorkflowStageDefinitions() so there is exactly one place that
 * defines them - nothing else should hardcode a second copy of this list.
 */
function getValidWorkflowStatuses() {
    return array_keys(getWorkflowStageDefinitions());
}

/**
 * True if $status is one of the six authoritative internal workflow status
 * values (exact, case-sensitive match). Every case-mutation endpoint that
 * writes cases_cache.status must reject the request when this returns
 * false, so an arbitrary string (e.g. a practice-specific display label)
 * can never be persisted as the internal status.
 */
function isValidWorkflowStatus($status) {
    return is_string($status) && array_key_exists($status, getWorkflowStageDefinitions());
}

/**
 * Map of internal status => default (un-customized) display label. Today
 * these are identical to the internal status strings, but callers must
 * treat them as independent - the internal string is permanent, the
 * default label is just what's shown when a practice hasn't customized it.
 */
function getWorkflowStageDefaultLabels() {
    $labels = [];
    foreach (getWorkflowStageDefinitions() as $status => $definition) {
        $labels[$status] = $definition['defaultLabel'];
    }
    return $labels;
}

/**
 * Strip ASCII control characters and trim whitespace from a candidate
 * label value. Shared by both the tolerant read-time parser
 * (parseWorkflowStageLabelOverrides()) and the strict write-time validator
 * (validateWorkflowStageLabelValue()), so "what counts as a clean label"
 * is defined in exactly one place. Does not enforce the length limit -
 * callers decide whether to truncate (read path) or reject (write path).
 */
function sanitizeWorkflowStageLabelText($value) {
    if (!is_string($value)) {
        return '';
    }
    // Strip control characters (0x00-0x1F, 0x7F) but keep normal
    // punctuation, Unicode, and emoji. The /u modifier keeps this
    // multi-byte-safe; if the input isn't valid UTF-8, preg_replace()
    // returns null - fall back to an empty string rather than erroring.
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    if ($clean === null) {
        return '';
    }
    return trim($clean);
}

/**
 * Safely parse the raw `practices.workflow_stage_labels` JSON column into
 * a normalized overrides array (internal status => non-blank custom
 * label). This is the ONE place stored overrides get decoded - callers
 * must never json_decode() this column directly.
 *
 * Tolerant of anything: NULL/empty input, malformed JSON, a non-object
 * JSON value, unknown/legacy keys, blank values, and overlong legacy
 * values (truncated, not dropped) all resolve to a safe result. Never
 * throws, and never lets malformed stored data break the request - on any
 * decode failure this simply returns no overrides (i.e. all defaults) and
 * logs via error_log(), the existing logging convention in this codebase.
 *
 * @param string|null $rawJson
 * @return array<string,string> internal status => trimmed, non-blank label
 */
function parseWorkflowStageLabelOverrides($rawJson) {
    $overrides = [];

    if ($rawJson === null || $rawJson === '') {
        return $overrides;
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        error_log('[workflow-stages] Malformed workflow_stage_labels JSON, falling back to defaults'
            . (json_last_error() !== JSON_ERROR_NONE ? (': ' . json_last_error_msg()) : ''));
        return $overrides;
    }

    $validStatuses = getValidWorkflowStatuses();
    foreach ($decoded as $status => $label) {
        if (!is_string($status) || !in_array($status, $validStatuses, true)) {
            continue; // Ignore unknown/legacy keys rather than erroring.
        }

        $clean = sanitizeWorkflowStageLabelText($label);
        if ($clean === '') {
            continue; // Blank => no override, use the default label.
        }

        // Read-time self-healing: an overlong value that somehow made it
        // into storage is truncated rather than dropped entirely, so a
        // single bad row degrades gracefully instead of losing the
        // practice's customization outright. (Writes are rejected
        // outright instead - see validateWorkflowStageLabelValue().)
        $overrides[$status] = mb_substr($clean, 0, WORKFLOW_STAGE_LABEL_MAX_LENGTH);
    }

    return $overrides;
}

/**
 * Resolve the display label for a single internal status, given a
 * practice's normalized overrides array (as returned by
 * parseWorkflowStageLabelOverrides()).
 *
 *  - valid status + non-blank override => the override
 *  - valid status + no/blank override  => the default label
 *  - unknown status                    => returned as-is (never invented,
 *                                          never throws) - the internal
 *                                          value is always authoritative
 *
 * @param string $internalStatus
 * @param array<string,string>|null $practiceOverrides
 * @return string
 */
function resolveWorkflowStageLabel($internalStatus, $practiceOverrides) {
    if (!is_array($practiceOverrides)) {
        $practiceOverrides = [];
    }

    if (!isValidWorkflowStatus($internalStatus)) {
        return $internalStatus;
    }

    $override = isset($practiceOverrides[$internalStatus])
        ? sanitizeWorkflowStageLabelText($practiceOverrides[$internalStatus])
        : '';

    if ($override !== '') {
        return $override;
    }

    $defaults = getWorkflowStageDefaultLabels();
    $default = $defaults[$internalStatus];
    $i18nKey = 'cases.status.' . strtolower(str_replace(' ', '_', $internalStatus));
    $label = function_exists('t') ? t($i18nKey) : '';
    if ($label === '' || $label === $i18nKey) {
        $label = $default;
    }
    return $label;
}

/**
 * Fully-resolved six-entry map (internal status => display label) for a
 * practice, applying resolveWorkflowStageLabel() to every stage. This is
 * the preferred payload shape for client-side consumption (see
 * get-settings.php's `workflowStageLabels` field).
 *
 * @param array<string,string>|null $practiceOverrides
 * @return array<string,string>
 */
function getResolvedWorkflowStageLabels($practiceOverrides) {
    $resolved = [];
    foreach (getValidWorkflowStatuses() as $status) {
        $resolved[$status] = resolveWorkflowStageLabel($status, $practiceOverrides);
    }
    return $resolved;
}

/**
 * Idempotent/self-healing schema helper for practices.workflow_stage_labels
 * - same auto-migration convention as ensureLabDesignationColumns() in
 * lab-assignment-history.php (SHOW COLUMNS ... LIKE, then ALTER TABLE ADD
 * COLUMN if missing). Purely additive: nullable, no default value that
 * would require backfilling every existing practice, and no case-data
 * migration of any kind.
 */
function ensureWorkflowStageLabelsColumn() {
    global $pdo;
    static $done = false;

    if ($done || !$pdo) {
        return;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'workflow_stage_labels'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN workflow_stage_labels TEXT DEFAULT NULL COMMENT 'JSON map of internal workflow status -> practice-specific display label override; only overridden stages are stored, NULL/empty means all defaults'");
        }
        $done = true;
    } catch (PDOException $e) {
        error_log('[workflow-stages] Error adding practices.workflow_stage_labels: ' . $e->getMessage());
    }
}

/**
 * Fetch and parse the current practice's workflow_stage_labels overrides
 * directly from the database (ensuring the column exists first). Returns
 * the normalized OVERRIDES array (see parseWorkflowStageLabelOverrides()),
 * not the fully-resolved six-entry map - pass this to
 * getResolvedWorkflowStageLabels() for that.
 *
 * @param int|string|null $practiceId
 * @return array<string,string>
 */
function getWorkflowStageLabelOverridesForPractice($practiceId) {
    global $pdo;
    if (!$pdo || empty($practiceId)) {
        return [];
    }

    ensureWorkflowStageLabelsColumn();

    try {
        $stmt = $pdo->prepare("SELECT workflow_stage_labels FROM practices WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $practiceId]);
        $raw = $stmt->fetchColumn();
        return parseWorkflowStageLabelOverrides($raw === false ? null : $raw);
    } catch (PDOException $e) {
        error_log('[workflow-stages] Error reading practices.workflow_stage_labels: ' . $e->getMessage());
        return [];
    }
}

/**
 * Strict write-time validation/normalization for a single stage-label
 * value. For future use by the Settings save endpoint - not wired up yet.
 * Unlike sanitizeWorkflowStageLabelText()'s read-time truncation, this
 * REJECTS values that are too long so the admin gets clear feedback rather
 * than a silently-shortened label.
 *
 * @param mixed $rawValue
 * @return array{valid:bool, value:string, error:?string} `value` is the
 *   trimmed/control-character-stripped candidate (an empty string means
 *   "blank - remove override / use default"); `error` is set only when
 *   `valid` is false.
 */
function validateWorkflowStageLabelValue($rawValue) {
    $clean = sanitizeWorkflowStageLabelText($rawValue);

    if ($clean === '') {
        // Blank/whitespace-only => remove override, fall back to default.
        return ['valid' => true, 'value' => '', 'error' => null];
    }

    if (mb_strlen($clean) > WORKFLOW_STAGE_LABEL_MAX_LENGTH) {
        return [
            'valid' => false,
            'value' => $clean,
            'error' => 'Display label cannot exceed ' . WORKFLOW_STAGE_LABEL_MAX_LENGTH . ' characters',
        ];
    }

    return ['valid' => true, 'value' => $clean, 'error' => null];
}

/**
 * Strict write-time validation/normalization for a full stage-label
 * overrides payload (e.g. the request body a future Settings save
 * endpoint would receive: internal status => raw label string). For
 * future use - not wired into save-settings.php yet.
 *
 * Only the six valid internal statuses may appear as keys; anything else
 * is rejected outright (unlike the tolerant read-time parser, which
 * silently ignores unknown keys already sitting in storage - that
 * asymmetry is intentional: be lenient about what's already stored, be
 * strict about what a client is trying to save right now).
 *
 * @param array $input Map of internal status => raw label string.
 * @return array{valid:bool, overrides:array<string,string>, errors:array<string,string>}
 *   `overrides` contains only the non-blank, validated entries, ready to
 *   json_encode() and store (blank entries are simply omitted - they mean
 *   "use default"). `errors` maps status => error message for any invalid
 *   entries; `valid` is true only when `errors` is empty.
 */
function normalizeWorkflowStageLabelsForSave($input) {
    $overrides = [];
    $errors = [];

    if (!is_array($input)) {
        return ['valid' => true, 'overrides' => $overrides, 'errors' => $errors];
    }

    $validStatuses = getValidWorkflowStatuses();

    foreach ($input as $status => $rawValue) {
        if (!is_string($status) || !in_array($status, $validStatuses, true)) {
            $errors[(string)$status] = 'Unknown workflow status';
            continue;
        }

        $result = validateWorkflowStageLabelValue($rawValue);
        if (!$result['valid']) {
            $errors[$status] = $result['error'];
            continue;
        }

        if ($result['value'] !== '') {
            $overrides[$status] = $result['value'];
        }
    }

    return [
        'valid' => empty($errors),
        'overrides' => $overrides,
        'errors' => $errors,
    ];
}
