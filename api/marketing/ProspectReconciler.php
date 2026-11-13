<?php
/**
 * Reconciliation between the Prospects Google Sheet snapshot and the
 * marketing CRM tables after an import run.
 *
 * Every source row must be accounted for: imported, matched, duplicate,
 * review, skipped_invalid, or explicitly unaccounted. The report is
 * counts-first; record-level detail is available via detailRows() for
 * authorized local review and is never written to deployment logs.
 */
require_once __DIR__ . '/ProspectDirectory.php';
require_once __DIR__ . '/SuppressionService.php';

final class ProspectReconciler
{
    private PDO $pdo;
    private array $snapshot;

    public function __construct(PDO $pdo, array $snapshot)
    {
        $this->pdo = $pdo;
        $this->snapshot = $snapshot;
    }

    /**
     * @return array reconciliation report (counts + bounded samples)
     */
    public function report(): array
    {
        $rows = array_values($this->snapshot['rows'] ?? []);
        $sourceRows = count($rows);

        $run = $this->pdo->query('SELECT id, mode, started_at, finished_at, stats_json FROM marketing_import_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $rep = [
            'source_rows' => $sourceRows,
            'latest_run' => $run ?: null,
            'unaccounted_source_rows' => [],
            'dispositions' => [],
            'orgs_total' => 0, 'contacts_total' => 0,
            'contacts_needs_review' => 0, 'contacts_dnc' => 0,
            'contacts_without_sheet_row' => 0,
            'suppression_inconsistencies' => ['email_suppressed_but_not_dnc' => 0, 'org_suppressed_but_not_dnc' => 0],
            'status_distribution' => [],
            'followup_distribution' => ['overdue' => 0, 'due_today' => 0, 'upcoming' => 0, 'none' => 0],
            'dso_links' => 0, 'orgs_orphan_parent' => 0,
            'fields_not_migrated' => $this->unmigratedHeaders(),
            'conflict_count' => 0,
        ];
        if (!$run) {
            $rep['error'] = 'no import run found - run the importer first';
            return $rep;
        }

        // Latest-run row dispositions keyed by sheet_row.
        $stmt = $this->pdo->prepare('SELECT sheet_row, contact_id, org_id, disposition, reason FROM marketing_import_rows WHERE run_id=?');
        $stmt->execute([(int)$run['id']]);
        $byRow = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byRow[(int)$r['sheet_row']] = $r;

        $dispCounts = [];
        foreach ($byRow as $sr => $r) {
            $d = $r['disposition'];
            $dispCounts[$d] = ($dispCounts[$d] ?? 0) + 1;
        }
        $rep['dispositions'] = $dispCounts;

        // Every source row (sheet_row = index + 2) must appear.
        foreach ($rows as $i => $_) {
            $sr = $i + 2;
            if (!isset($byRow[$sr])) $rep['unaccounted_source_rows'][] = $sr;
        }
        // Rows recorded for sheet_rows beyond the current snapshot (sheet
        // shrank since import) are reported, not silently dropped.
        $rep['import_rows_beyond_snapshot'] = count(array_diff(array_keys($byRow), array_map(fn($i) => $i + 2, array_keys($rows))));

        // CRM totals.
        $rep['orgs_total'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_orgs')->fetchColumn();
        $rep['contacts_total'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_contacts')->fetchColumn();
        $rep['contacts_needs_review'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_contacts WHERE needs_review=1')->fetchColumn();
        $rep['contacts_dnc'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_contacts WHERE dnc=1')->fetchColumn();
        $rep['contacts_without_sheet_row'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_contacts WHERE sheet_row IS NULL')->fetchColumn();

        // Suppression consistency: a suppressed email/domain must never sit
        // on a contact with dnc=0.
        $rep['suppression_inconsistencies']['email_suppressed_but_not_dnc'] = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM marketing_contacts c
             JOIN marketing_suppressions s ON s.email_normalized = c.email_normalized
             WHERE c.dnc = 0'
        )->fetchColumn();
        $rep['suppression_inconsistencies']['org_suppressed_but_not_dnc'] = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM marketing_contacts c
             JOIN marketing_org_suppressions o ON o.org_ref = SUBSTRING_INDEX(c.email_normalized, \'@\', -1)
             WHERE c.dnc = 0'
        )->fetchColumn();

        foreach ($this->pdo->query('SELECT COALESCE(NULLIF(status,""),"(blank)") s, COUNT(*) c FROM marketing_contacts GROUP BY s ORDER BY c DESC')->fetchAll(PDO::FETCH_KEY_PAIR) as $s => $c) {
            $rep['status_distribution'][$s] = (int)$c;
        }

        $today = gmdate('Y-m-d');
        foreach ($this->pdo->query('SELECT followup_date FROM marketing_contacts WHERE followup_date IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $fd) {
            if ($fd < $today) $rep['followup_distribution']['overdue']++;
            elseif ($fd === $today) $rep['followup_distribution']['due_today']++;
            else $rep['followup_distribution']['upcoming']++;
        }
        $rep['followup_distribution']['none'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_contacts WHERE followup_date IS NULL')->fetchColumn();

        $rep['dso_links'] = (int)$this->pdo->query('SELECT COUNT(*) FROM marketing_orgs WHERE parent_org_id IS NOT NULL')->fetchColumn();
        $rep['orgs_orphan_parent'] = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM marketing_orgs c LEFT JOIN marketing_orgs p ON p.id=c.parent_org_id WHERE c.parent_org_id IS NOT NULL AND p.id IS NULL'
        )->fetchColumn();

        if (!empty($run['stats_json'])) {
            $st = json_decode((string)$run['stats_json'], true);
            $rep['conflict_count'] = (int)($st['conflict_count'] ?? 0);
            $rep['run_stats'] = $st;
        }

        // Acceptance gate: every source row accounted for.
        $rep['all_source_rows_accounted'] = count($rep['unaccounted_source_rows']) === 0;
        return $rep;
    }

    /** Record-level discrepancy detail for authorized local review. */
    public function detailRows(): array
    {
        $run = $this->pdo->query('SELECT id FROM marketing_import_runs ORDER BY id DESC LIMIT 1')->fetchColumn();
        if (!$run) return [];
        $stmt = $this->pdo->prepare(
            'SELECT ir.sheet_row, ir.disposition, ir.reason, ir.contact_id, c.email_normalized, c.full_name, c.needs_review, c.review_reason
             FROM marketing_import_rows ir
             LEFT JOIN marketing_contacts c ON c.id = ir.contact_id
             WHERE ir.run_id=? AND (ir.disposition IN ("skipped_invalid","review","duplicate") OR ir.reason IS NOT NULL OR c.needs_review=1)
             ORDER BY ir.sheet_row LIMIT 500'
        );
        $stmt->execute([(int)$run]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function unmigratedHeaders(): array
    {
        $header = array_map(fn($h) => mb_strtolower(trim((string)$h)), $this->snapshot['header'] ?? []);
        $known = [];
        foreach (ProspectDirectory::cols() as $names) {
            foreach ($names as $n) $known[mb_strtolower($n)] = true;
        }
        $out = [];
        foreach ($header as $h) {
            if ($h === '' || isset($known[$h])) continue;
            $out[] = $h;
        }
        return $out;
    }
}
