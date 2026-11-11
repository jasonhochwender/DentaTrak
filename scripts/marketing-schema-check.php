<?php
/**
 * Read-only schema check for the Marketing Outreach migrations.
 *
 *   php scripts/marketing-schema-check.php --pre    before migrating
 *   php scripts/marketing-schema-check.php --post   after migrating
 *
 * Issues only SELECTs against information_schema and COUNT(*) on the
 * marketing_* tables; it never writes. It prints which database it is
 * connected to so the operator can confirm the target before running
 *   php migrations/2026_11_03_marketing_admin.php
 *   php migrations/2026_11_12_marketing_send_hardening.php
 *
 * Exit code 0 = checks passed for the selected mode, 1 = attention needed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/appConfig.php';
global $pdo, $appConfig;

$mode = in_array('--post', $argv, true) ? 'post' : 'pre';
$problems = [];
$note = function (string $m) { echo $m, "\n"; };

$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$host = (string)$pdo->query('SELECT @@hostname')->fetchColumn();
$note("mode:        $mode");
$note('environment: ' . ($appConfig['current_environment'] ?? 'unknown'));
$note("database:    $db @ $host (MySQL $ver)");
$note(str_repeat('-', 60));

$tables = function () use ($pdo): array {
    return $pdo->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'marketing\\_%' ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
};
$columns = function (string $t) use ($pdo): array {
    $s = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$t]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
};
$indexes = function (string $t) use ($pdo): array {
    $s = $pdo->prepare('SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols, MIN(NON_UNIQUE) non_unique
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? GROUP BY INDEX_NAME');
    $s->execute([$t]);
    return $s->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
};

$existing = $tables();
$note('existing marketing_* tables:');
foreach ($existing as $name => $meta) {
    $rows = (int)$pdo->query("SELECT COUNT(*) FROM `$name`")->fetchColumn();
    $note(sprintf('  %-34s %-7s %-20s rows=%d', $name, $meta['ENGINE'], $meta['TABLE_COLLATION'], $rows));
}
if (!$existing) $note('  (none)');
$note(str_repeat('-', 60));

// Prerequisites created by earlier, already-deployed migrations.
foreach (['marketing_sends', 'marketing_suppressions', 'marketing_org_suppressions', 'marketing_unsub_tokens'] as $t) {
    if (!isset($existing[$t])) $problems[] = "prerequisite table missing: $t";
}

$expected = [
    'marketing_templates' => [
        'columns' => ['id', 'name', 'kind', 'subject_tpl', 'body_tpl', 'is_default', 'created_by', 'created_at', 'updated_at'],
        'indexes' => ['PRIMARY', 'ix_mt_kind'],
    ],
    'marketing_campaigns' => [
        'columns' => ['id', 'name', 'kind', 'status', 'created_by', 'approved_by', 'approved_at', 'content_hash',
            'recipient_count', 'sent_count', 'skipped_count', 'failed_count', 'created_at', 'updated_at'],
        'indexes' => ['PRIMARY', 'ix_mc_status', 'ix_mc_created'],
    ],
    'marketing_campaign_recipients' => [
        'columns' => ['id', 'campaign_id', 'sheet_row', 'prospect_email', 'prospect_org', 'prospect_contact', 'subject',
            'body_html', 'body_text', 'status', 'skip_reason', 'is_edited', 'gmail_message_id', 'gmail_thread_id',
            'error', 'sent_at', 'created_at', 'updated_at',
            'thread_status', 'thread_reason', 'thread_source', 'thread_subject', 'thread_rfc_message_id',
            'thread_references', 'sheet_sync_status', 'sheet_sync_attempts', 'sheet_sync_error', 'sheet_synced_at'],
        'indexes' => ['PRIMARY', 'uq_mcr_campaign_email', 'ix_mcr_status', 'ix_mcr_sheet_row', 'ix_mcr_sheet_sync', 'ix_mcr_email'],
        'enum' => ['status' => 'uncertain'],
    ],
    'marketing_audit_log' => [
        'columns' => ['id', 'actor_email', 'action', 'campaign_id', 'detail', 'created_at'],
        'indexes' => ['PRIMARY', 'ix_mal_campaign', 'ix_mal_created'],
    ],
    'marketing_sends' => [
        'columns' => ['id', 'campaign_ref', 'email_normalized', 'status', 'gmail_message_id', 'sent_at', 'created_at',
            'idempotency_key', 'attempts', 'attempt_started_at', 'gmail_thread_id', 'last_error'],
        'indexes' => ['PRIMARY', 'ux_send_camp_email', 'ux_send_idempotency'],
        'enum' => ['status' => 'uncertain'],
    ],
];
$newTables = ['marketing_templates', 'marketing_campaigns', 'marketing_campaign_recipients', 'marketing_audit_log'];

if ($mode === 'pre') {
    foreach ($newTables as $t) {
        if (!isset($existing[$t])) { $note("will create:  $t"); continue; }
        // Present already: CREATE TABLE IF NOT EXISTS will skip it, so its
        // shape must already be compatible with what the code expects.
        $have = $columns($t);
        $base = array_diff($expected[$t]['columns'], ['thread_status', 'thread_reason', 'thread_source', 'thread_subject',
            'thread_rfc_message_id', 'thread_references', 'sheet_sync_status', 'sheet_sync_attempts', 'sheet_sync_error', 'sheet_synced_at']);
        $missing = array_diff($base, array_keys($have));
        $note("exists:       $t (" . count($have) . ' columns)' . ($missing ? ' MISSING ' . implode(',', $missing) : ' - compatible'));
        if ($missing) $problems[] = "$t exists with an incompatible shape (missing " . implode(',', $missing) . ')';
    }
    if (isset($existing['marketing_sends'])) {
        $dupes = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT campaign_ref, email_normalized FROM marketing_sends
            GROUP BY campaign_ref, email_normalized HAVING COUNT(*) > 1) d')->fetchColumn();
        $note("marketing_sends duplicate (campaign,email) pairs: $dupes (must be 0 for the unique idempotency index)");
        if ($dupes > 0) $problems[] = 'marketing_sends has duplicate (campaign,email) pairs';
        $byStatus = $pdo->query('SELECT status, COUNT(*) FROM marketing_sends GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $note('marketing_sends by status: ' . json_encode($byStatus));
    }
} else {
    foreach ($expected as $t => $spec) {
        if (!isset($existing[$t])) { $problems[] = "table missing: $t"; continue; }
        $have = $columns($t);
        $idx = $indexes($t);
        $missingCols = array_diff($spec['columns'], array_keys($have));
        $missingIdx = array_diff($spec['indexes'], array_keys($idx));
        foreach (($spec['enum'] ?? []) as $col => $value) {
            if (strpos((string)($have[$col] ?? ''), "'$value'") === false) $problems[] = "$t.$col enum lacks '$value'";
        }
        $note(sprintf('%-34s columns=%d indexes=%d%s%s', $t, count($have), count($idx),
            $missingCols ? ' MISSING COLS ' . implode(',', $missingCols) : '',
            $missingIdx ? ' MISSING IDX ' . implode(',', $missingIdx) : ''));
        if ($missingCols) $problems[] = "$t missing columns: " . implode(',', $missingCols);
        if ($missingIdx) $problems[] = "$t missing indexes: " . implode(',', $missingIdx);
    }
    foreach (['ux_send_camp_email', 'ux_send_idempotency'] as $u) {
        $i = $indexes('marketing_sends')[$u] ?? null;
        if ($i && (int)$i['non_unique'] !== 0) $problems[] = "marketing_sends.$u is not UNIQUE";
    }
    $u = $indexes('marketing_campaign_recipients')['uq_mcr_campaign_email'] ?? null;
    if ($u && (int)$u['non_unique'] !== 0) $problems[] = 'marketing_campaign_recipients.uq_mcr_campaign_email is not UNIQUE';
    $nullKeys = (int)$pdo->query('SELECT COUNT(*) FROM marketing_sends WHERE idempotency_key IS NULL')->fetchColumn();
    $note("marketing_sends rows without idempotency_key: $nullKeys");
    if ($nullKeys > 0) $problems[] = 'marketing_sends has rows without an idempotency key';
}

$note(str_repeat('-', 60));
if ($problems) {
    foreach ($problems as $p) $note('PROBLEM: ' . $p);
    exit(1);
}
$note($mode === 'pre' ? 'pre-check OK: safe to run the two marketing migrations' : 'post-check OK: schema verified');
