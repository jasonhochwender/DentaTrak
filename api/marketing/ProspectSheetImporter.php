<?php
/**
 * Idempotent Google Sheet -> MySQL prospect importer (Phase 1).
 *
 * Reads a sheet snapshot (['header'=>[], 'rows'=>[]] as returned by
 * McpSheetSync::sheet()) and upserts marketing_orgs / marketing_contacts,
 * recording a disposition for EVERY source row in marketing_import_rows so
 * reconciliation can account for 100% of the sheet.
 *
 * Rules:
 *  - Never overwrite an existing non-blank value with a blank import.
 *  - On conflicting non-blank values the existing value wins and the
 *    conflict is reported (never silently merged or overwritten).
 *  - Email identity is dedupe-only, never a unique constraint: shared or
 *    missing addresses are flagged for review, not auto-merged.
 *  - Suppression / DNC can only be strengthened, never weakened: a
 *    suppressed email or DNC-flagged row imports with dnc=1.
 *  - Repeated imports are idempotent: contacts/orgs match on stable keys,
 *    and prospect events dedupe on a content hash per sheet row.
 *
 * The class performs no network I/O; callers supply the snapshot.
 */
require_once __DIR__ . '/ProspectDirectory.php';
require_once __DIR__ . '/SuppressionService.php';

final class ProspectSheetImporter
{
    public const DISPOSITIONS = ['imported', 'matched', 'duplicate', 'skipped_invalid', 'review'];

    private PDO $pdo;
    private SuppressionService $suppression;
    private ProspectDirectory $dir;
    private array $colIdx = [];
    private array $extraIdx = [];   // sheet header => col index, for headers outside the canonical map
    private array $orgByNorm = [];   // normalized_name => list of ['id'=>, 'domain'=>]
    private array $contactByEmail = []; // email_normalized => contact id
    private array $contactByNameOrg = []; // org_id|norm_full_name => contact id
    private array $contactBySheetRow = []; // sheet_row => contact id (identity-less contacts only)
    private array $parentPending = [];   // child org id => parent normalized name
    private array $snapshotRows = [];
    private array $seenEmailRows = [];   // email_normalized => first sheet_row this run
    private array $stats;
    private array $conflicts = [];
    private int $conflictCap = 500;

    public function __construct(PDO $pdo, array $snapshot, ?SuppressionService $suppression = null)
    {
        $this->pdo = $pdo;
        $this->suppression = $suppression ?? new SuppressionService($pdo);
        // Snapshot-backed sheet sync: no token, no network.
        $this->dir = new ProspectDirectory($pdo, new McpSheetSync($snapshot), $this->suppression);
        $this->buildColumnIndex($snapshot['header'] ?? []);
        $this->stats = $this->emptyStats();
    }

    private function emptyStats(): array
    {
        return [
            'source_rows' => 0, 'contacts_created' => 0, 'contacts_updated' => 0,
            'orgs_created' => 0, 'orgs_matched' => 0, 'org_links_set' => 0,
            'duplicate_orgs' => 0, 'events_written' => 0, 'skipped_invalid' => 0,
            'duplicate_rows' => 0, 'review_rows' => 0,
            'dispositions' => [], 'conflicts' => &$this->conflicts,
        ];
    }

    private function buildColumnIndex(array $header): void
    {
        $sheet = new McpSheetSync(['header' => $header, 'rows' => []]);
        $claimed = [];
        foreach (ProspectDirectory::cols() as $key => $names) {
            $this->colIdx[$key] = $sheet->colAny($names);
            if ($this->colIdx[$key] !== null) $claimed[$this->colIdx[$key]] = true;
        }
        // Unsubscribe audit columns are not part of the canonical prospect
        // field map but DO affect do-not-contact state.
        $this->colIdx['unsubscribed_at'] = $sheet->colAny(['Unsubscribed At', 'Unsubscribed']);
        if ($this->colIdx['unsubscribed_at'] !== null) $claimed[$this->colIdx['unsubscribed_at']] = true;
        // Every other header is preserved verbatim in extra_fields.
        $this->extraIdx = [];
        foreach ($header as $i => $h) {
            $h = trim((string)$h);
            if ($h !== '' && !isset($claimed[$i])) $this->extraIdx[$h] = $i;
        }
    }

    /**
     * Import a sheet snapshot (['header'=>[], 'rows'=>[]] as returned by
     * McpSheetSync::sheet()). Dry-run (default) performs all writes inside
     * a transaction and rolls back, so the report reflects exactly what a
     * real run would do - including the import_runs bookkeeping row.
     *
     * @return array stats + per-disposition counts + bounded conflict list
     */
    public function importSnapshot(array $snapshot, bool $dryRun = true, ?int $limit = null): array
    {
        $this->snapshotRows = array_values($snapshot['rows'] ?? []);
        $this->colIdx = [];
        $this->buildColumnIndex($snapshot['header'] ?? []);
        $this->stats = $this->emptyStats();
        $this->stats['source_rows'] = count($this->snapshotRows);
        $this->conflicts = [];
        $this->orgByNorm = [];
        $this->contactByEmail = [];
        $this->contactByNameOrg = [];
        $this->contactBySheetRow = [];
        $this->parentPending = [];
        $this->seenEmailRows = [];

        // Preload existing CRM state once (bounded by table size).
        foreach ($this->pdo->query('SELECT id, normalized_name, domain FROM marketing_orgs')->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $this->orgByNorm[$o['normalized_name']][] = ['id' => (int)$o['id'], 'domain' => (string)($o['domain'] ?? '')];
        }
        foreach ($this->pdo->query('SELECT id, email_normalized, org_id, full_name, sheet_row FROM marketing_contacts')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['email_normalized'] !== null && $c['email_normalized'] !== '') {
                $this->contactByEmail[$c['email_normalized']] ??= (int)$c['id'];
            }
            if ($c['org_id'] !== null && $c['full_name'] !== null) {
                $this->contactByNameOrg[$c['org_id'] . '|' . $this->norm($c['full_name'])] ??= (int)$c['id'];
            }
            // Identity-less contacts (no email, no name) can only be
            // re-matched by their source row - and only by rows that are
            // themselves identity-less, so a shifted row can't hijack an
            // identified contact.
            $noEmail = $c['email_normalized'] === null || $c['email_normalized'] === '';
            $noName = $c['full_name'] === null || trim((string)$c['full_name']) === '';
            if ($noEmail && $noName && $c['sheet_row'] !== null) {
                $this->contactBySheetRow[(int)$c['sheet_row']] ??= (int)$c['id'];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $runId = $this->startRun($dryRun);
            $i = 0;
            foreach ($this->snapshotRows as $idx => $row) {
                if ($limit !== null && $i >= $limit) break;
                $i++;
                $this->importRow($runId, $idx + 2, $row); // sheet row = data idx + 2 (header is row 1)
            }
            $this->applyParentLinks();
            $stats = $this->finishStats();
            $this->finishRun($runId, $stats);
            if ($dryRun) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        $stats['dry_run'] = $dryRun;
        $stats['run_id'] = $dryRun ? null : $runId;
        return $stats;
    }

    private function startRun(bool $dryRun): int
    {
        $this->pdo->prepare("INSERT INTO marketing_import_runs (mode, source) VALUES (?, 'google_sheet')")
            ->execute([$dryRun ? 'dry_run' : 'write']);
        return (int)$this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, array $stats): void
    {
        $this->pdo->prepare('UPDATE marketing_import_runs SET stats_json=?, finished_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), $runId]);
    }

    private function importRow(int $runId, int $sheetRow, array $row): void
    {
        $f = [];
        foreach (ProspectDirectory::cols() as $key => $names) {
            $c = $this->colIdx[$key] ?? null;
            $f[$key] = $c === null ? '' : trim((string)($row[$c] ?? ''));
        }

        $orgName = $f['org'] !== '' ? $f['org'] : '';
        $parentName = trim($f['parent_org']);
        $emailRaw = $f['email'];
        $email = SuppressionService::normalizeEmail($emailRaw);
        // A sheet cell may hold several addresses; the first valid one is
        // the contact identity and the rest are flagged for review.
        $cellEmails = OutreachEligibility::cellEmails($emailRaw);
        if (count($cellEmails) > 1) {
            $email = $cellEmails[0];
        }
        $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        $extra = $this->extraFields($row);
        if (count($cellEmails) > 1) {
            $extra['additional_emails'] = array_slice($cellEmails, 1);
        }
        $f['_unsubscribed_at'] = $this->colIdx['unsubscribed_at'] !== null
            ? trim((string)($row[$this->colIdx['unsubscribed_at']] ?? '')) : '';
        // Phase-2 promoted columns: sheet headers that now have dedicated
        // columns (also still preserved verbatim in extra_fields).
        foreach (['Demo Status' => '_demo_status', 'Trial Status' => '_trial_status',
                  'Outcome' => '_outcome', 'Objection/Lost Reason' => '_objection_reason',
                  'Unsubscribe Source' => '_unsubscribe_source', 'Contact Notes' => '_contact_notes'] as $h => $k) {
            $f[$k] = trim((string)($extra[$h] ?? ''));
        }
        $f['_unsubscribed_at_iso'] = $f['_unsubscribed_at'] !== '' ? $this->isoDate($f['_unsubscribed_at']) : null;
        $fullName = $f['contact_name'] !== '' ? $f['contact_name'] : trim($f['contact_first'] . ' ' . $f['contact_last']);

        // Fully empty rows are accounted but not imported.
        if ($orgName === '' && $parentName === '' && $emailRaw === '' && $fullName === '' && trim($f['notes']) === '') {
            $this->lastFieldHash = md5(json_encode($f));
            $this->recordRow($runId, $sheetRow, null, null, 'skipped_invalid', 'empty_row');
            $this->bump('skipped_invalid');
            $this->bumpDisp('skipped_invalid');
            return;
        }

        $reviewReasons = [];
        if (count($cellEmails) > 1) $reviewReasons[] = 'multi_email_cell';
        if (!$emailValid) $reviewReasons[] = 'missing_or_invalid_email';
        if ($orgName === '' && $parentName === '') $reviewReasons[] = 'missing_org';

        // ---- organization ----
        $orgId = null;
        $effectiveOrgName = $orgName !== '' ? $orgName : $parentName;
        if ($effectiveOrgName !== '') {
            $orgId = $this->resolveOrg($effectiveOrgName, $f, $sheetRow, $reviewReasons);
        }

        // DSO parent link: parent_org names a different organization above this one.
        if ($orgId !== null && $parentName !== '' && $this->norm($parentName) !== $this->norm($effectiveOrgName)) {
            $this->parentPending[$orgId] = $parentName;
        }

        // ---- contact identity ----
        $contactId = null;
        $disposition = 'imported';
        $this->lastFieldHash = md5(json_encode($f));

        if ($emailValid) {
            // A second+ source row carrying an email already imported THIS
            // run merges non-destructively into the same contact but is
            // dispositioned 'duplicate' for reconciliation.
            $dupRow = isset($this->seenEmailRows[$email]) && $this->seenEmailRows[$email] !== $sheetRow;
            if (isset($this->contactByEmail[$email])) {
                $contactId = $this->contactByEmail[$email];
                $disposition = $dupRow ? 'duplicate' : 'matched';
            } else {
                // Shared email already seen on a DIFFERENT org this run? First
                // writer wins; later rows are quarantined for review.
                $contactId = $this->createContact($orgId, $f, $email, $fullName, $sheetRow, $reviewReasons, $extra);
                $this->contactByEmail[$email] = $contactId;
                $this->indexNameOrg($contactId, $orgId, $fullName);
                $this->bump('contacts_created');
            }
            $this->seenEmailRows[$email] ??= $sheetRow;
        } else {
            // No usable email: match by org + normalized full name to avoid
            // dupes; identity-less rows fall back to their source row.
            $nameKey = ($orgId ?? 0) . '|' . $this->norm($fullName);
            if ($fullName !== '' && isset($this->contactByNameOrg[$nameKey])) {
                $contactId = $this->contactByNameOrg[$nameKey];
                $disposition = 'matched';
                $reviewReasons[] = 'matched_without_email';
            } elseif ($fullName === '' && isset($this->contactBySheetRow[$sheetRow])) {
                $contactId = $this->contactBySheetRow[$sheetRow];
                $disposition = 'matched';
            } else {
                $contactId = $this->createContact($orgId, $f, null, $fullName, $sheetRow, $reviewReasons, $extra);
                $this->indexNameOrg($contactId, $orgId, $fullName);
                if ($fullName === '') $this->contactBySheetRow[$sheetRow] = $contactId;
                $this->bump('contacts_created');
            }
        }

        if (in_array($disposition, ['matched', 'duplicate'], true)) {
            if ($disposition === 'matched') $this->bump('contacts_updated');
            $this->updateContact($contactId, $orgId, $f, $emailValid ? $email : null, $fullName, $sheetRow, $reviewReasons, $extra);
        }

        // Suppression can only strengthen DNC.
        $this->applySuppression($contactId, $email, $reviewReasons);

        // Review flags: needs_review + reason are recorded per contact and
        // per import row; the disposition still reports the match outcome.
        if ($reviewReasons) {
            $reason = implode(',', array_unique($reviewReasons));
            $this->pdo->prepare('UPDATE marketing_contacts SET needs_review=1, review_reason=? WHERE id=?')
                ->execute([substr($reason, 0, 255), $contactId]);
            $this->bump('review_rows');
            if ($disposition === 'imported') $disposition = 'review';
        }

        // Duplicate email pointing at a contact already claimed by another org.
        if ($emailValid && $orgId !== null) {
            $cur = $this->pdo->prepare('SELECT org_id FROM marketing_contacts WHERE id=?');
            $cur->execute([$contactId]);
            $contactOrg = $cur->fetchColumn();
            if ($contactOrg !== null && (int)$contactOrg !== (int)$orgId) {
                $this->pdo->prepare("UPDATE marketing_contacts SET needs_review=1, review_reason=TRIM(BOTH ',' FROM CONCAT(COALESCE(review_reason,''), ',email_org_mismatch')) WHERE id=?")
                    ->execute([$contactId]);
                $reviewReasons[] = 'email_org_mismatch';
            }
        }

        $this->writeImportEvent($contactId, $orgId, $sheetRow, $f);
        $this->recordRow($runId, $sheetRow, $contactId, $orgId, $disposition, implode(',', array_unique($reviewReasons)) ?: null);
        $this->bumpDisp($disposition);
    }

    private function resolveOrg(string $name, array $f, int $sheetRow, array &$reviewReasons): ?int
    {
        $norm = $this->norm($name);
        $incDomain = $this->domainFromWebsite(trim((string)$f['website']));

        // Name match is only trusted when domains are compatible: a row
        // with no website, or an org with no recorded domain, or matching
        // domains. Same name + different domain means two distinct
        // organizations (e.g. "Grace Dental" in two states) - never merge.
        $candidates = $this->orgByNorm[$norm] ?? [];
        foreach ($candidates as $cand) {
            $cd = $cand['domain'];
            if ($incDomain === null || $cd === '' || $cd === $incDomain) {
                $id = $cand['id'];
                $this->bump('orgs_matched');
                // Fill blanks only; never overwrite.
                $this->mergeOrgFields($id, $f);
                return $id;
            }
        }

        $website = trim($f['website']);
        $domain = $incDomain;
        $orgType = $this->guessOrgType($f);
        $this->pdo->prepare(
            'INSERT INTO marketing_orgs (name, normalized_name, org_type, website, domain, location, phone, source, sheet_row, needs_review, review_reason)
             VALUES (?,?,?,?,?,?,?,?,?, ?, ?)'
        )->execute([
            mb_substr($name, 0, 255), mb_substr($norm, 0, 255), $orgType,
            $website !== '' ? mb_substr($website, 0, 500) : null,
            $domain, $this->nullStr($f['location']), $this->nullStr($f['phone']),
            'sheet_import', $sheetRow,
            $candidates ? 1 : 0,
            $candidates ? 'duplicate_org_name' : null,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->orgByNorm[$norm][] = ['id' => $id, 'domain' => (string)($domain ?? '')];
        if ($candidates) {
            $this->bump('duplicate_orgs');
            $reviewReasons[] = 'duplicate_org_name';
        }
        $this->bump('orgs_created');
        return $id;
    }

    private function mergeOrgFields(int $orgId, array $f): void
    {
        $row = $this->pdo->prepare('SELECT website, domain, location, phone, org_type FROM marketing_orgs WHERE id=?');
        $row->execute([$orgId]);
        $cur = $row->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return;
        $sets = []; $params = [];
        $map = ['website' => 'website', 'location' => 'location', 'phone' => 'phone'];
        foreach ($map as $col => $field) {
            $inc = trim((string)$f[$field]);
            if ($inc !== '' && ($cur[$col] === null || $cur[$col] === '')) {
                $sets[] = "$col=?"; $params[] = mb_substr($inc, 0, $col === 'website' ? 500 : 255);
            } elseif ($inc !== '' && $cur[$col] !== '' && $this->norm((string)$cur[$col]) !== $this->norm($inc)) {
                $this->addConflict('org', $orgId, $col, (string)$cur[$col], $inc);
            }
        }
        $incDomain = $this->domainFromWebsite(trim((string)$f['website']));
        if ($incDomain !== null && ($cur['domain'] === null || $cur['domain'] === '')) {
            $sets[] = 'domain=?'; $params[] = $incDomain;
        } elseif ($incDomain !== null && $cur['domain'] !== '' && $cur['domain'] !== $incDomain) {
            $this->addConflict('org', $orgId, 'domain', (string)$cur['domain'], $incDomain);
        }
        if ($sets) {
            $params[] = $orgId;
            $this->pdo->prepare('UPDATE marketing_orgs SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
        }
    }

    private function extraFields(array $row): array
    {
        $out = [];
        foreach ($this->extraIdx as $header => $c) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') $out[$header] = mb_substr($v, 0, 4000);
        }
        return $out;
    }

    private function createContact(?int $orgId, array $f, ?string $email, string $fullName, int $sheetRow, array $reviewReasons, array $extra = []): int
    {
        [$first, $last] = $this->splitName($f, $fullName);
        $dnc = $this->computeDnc($f, $email);
        $status = trim($f['status']) !== '' ? mb_substr(trim($f['status']), 0, 60) : 'new';
        $this->pdo->prepare(
            'INSERT INTO marketing_contacts
             (org_id, first_name, last_name, full_name, email, email_normalized, title, phone,
              status, priority, fit, source, notes, dnc,
              first_contact_date, last_contact_date, followup_date, response, campaign_ref, outreach_method,
              sheet_row, needs_review, review_reason, extra_fields,
              demo_status, trial_status, outcome, objection_reason,
              unsubscribed_at, unsubscribe_source, contact_notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $orgId,
            $this->nullStr($first), $this->nullStr($last), $this->nullStr(mb_substr($fullName, 0, 255)),
            $email !== null ? mb_substr($email, 0, 320) : null,
            $email,
            $this->nullStr($f['title']), $this->nullStr($f['phone']),
            $status,
            $this->nullStr($f['priority']), $this->nullStr($f['fit']),
            'sheet_import',
            $f['notes'] !== '' ? $f['notes'] : null,
            $dnc ? 1 : 0,
            $this->isoDate($f['first_contact']), $this->isoDate($f['last_contact']),
            $this->isoDate($f['followup_date']),
            $this->nullStr($f['response']), $this->nullStr($f['campaign']), $this->nullStr($f['method']),
            $sheetRow,
            $reviewReasons ? 1 : 0,
            $reviewReasons ? substr(implode(',', array_unique($reviewReasons)), 0, 255) : null,
            $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $this->nullStr(mb_substr((string)$f['_demo_status'], 0, 60)),
            $this->nullStr(mb_substr((string)$f['_trial_status'], 0, 60)),
            $this->nullStr(mb_substr((string)$f['_outcome'], 0, 120)),
            $this->nullStr(mb_substr((string)$f['_objection_reason'], 0, 255)),
            $f['_unsubscribed_at_iso'] ?? null,
            $this->nullStr(mb_substr((string)$f['_unsubscribe_source'], 0, 60)),
            trim((string)$f['_contact_notes']) !== '' ? $f['_contact_notes'] : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateContact(int $contactId, ?int $orgId, array $f, ?string $email, string $fullName, int $sheetRow, array &$reviewReasons, array $extra = []): void
    {
        $cur = $this->pdo->prepare('SELECT * FROM marketing_contacts WHERE id=?');
        $cur->execute([$contactId]);
        $c = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$c) return;

        $sets = []; $params = [];
        $scalar = [
            'title' => 'title', 'phone' => 'phone', 'priority' => 'priority', 'fit' => 'fit',
            'response' => 'response', 'campaign_ref' => 'campaign', 'outreach_method' => 'method',
            'demo_status' => '_demo_status', 'trial_status' => '_trial_status',
            'outcome' => '_outcome', 'objection_reason' => '_objection_reason',
            'unsubscribe_source' => '_unsubscribe_source',
        ];
        $scalarMax = ['objection_reason' => 255];
        foreach ($scalar as $col => $field) {
            $this->mergeScalar($sets, $params, $c, $col, trim((string)$f[$field]), $scalarMax[$col] ?? 120);
        }
        // contact_notes is free text (fill blanks only, like notes).
        $incContactNotes = trim((string)$f['_contact_notes']);
        if ($incContactNotes !== '' && ($c['contact_notes'] === null || trim((string)$c['contact_notes']) === '')) {
            $sets[] = 'contact_notes=?'; $params[] = $incContactNotes;
        }
        // unsubscribed_at: fill blanks only.
        $incUnsub = $f['_unsubscribed_at_iso'] ?? null;
        if ($incUnsub !== null && ($c['unsubscribed_at'] === null || $c['unsubscribed_at'] === '')) {
            $sets[] = 'unsubscribed_at=?'; $params[] = $incUnsub;
        }
        // status: non-blank import wins over 'new' default; conflicting real
        // statuses are kept (existing wins) and reported.
        $incStatus = trim($f['status']);
        if ($incStatus !== '' && ($c['status'] === 'new' || $c['status'] === '')) {
            $sets[] = 'status=?'; $params[] = mb_substr($incStatus, 0, 60);
        } elseif ($incStatus !== '' && $this->norm($incStatus) !== $this->norm((string)$c['status'])) {
            $this->addConflict('contact', $contactId, 'status', (string)$c['status'], $incStatus);
        }
        // Dates: fill blanks only.
        foreach (['first_contact_date' => 'first_contact', 'last_contact_date' => 'last_contact', 'followup_date' => 'followup_date'] as $col => $field) {
            $inc = $this->isoDate($f[$field]);
            if ($inc !== null && ($c[$col] === null || $c[$col] === '')) {
                $sets[] = "$col=?"; $params[] = $inc;
            }
        }
        // Notes: blank never overwrites; differing non-blank notes are kept
        // and reported (append semantics deferred to Phase 2 activity log).
        $incNotes = trim($f['notes']);
        if ($incNotes !== '' && ($c['notes'] === null || trim((string)$c['notes']) === '')) {
            $sets[] = 'notes=?'; $params[] = $f['notes'];
        } elseif ($incNotes !== '' && $this->norm((string)$c['notes']) !== $this->norm($incNotes)) {
            $this->addConflict('contact', $contactId, 'notes', mb_substr((string)$c['notes'], 0, 120), mb_substr($incNotes, 0, 120));
        }
        // Org link: set when contact had none; never silently re-parent.
        if ($orgId !== null && ($c['org_id'] === null || (int)$c['org_id'] === 0)) {
            $sets[] = 'org_id=?'; $params[] = $orgId;
        } elseif ($orgId !== null && (int)$c['org_id'] !== (int)$orgId) {
            $reviewReasons[] = 'org_link_differs';
        }
        // Email fill: contacts created without email may gain one here.
        if ($email !== null && ($c['email_normalized'] === null || $c['email_normalized'] === '')) {
            $sets[] = 'email=?'; $params[] = mb_substr($email, 0, 320);
            $sets[] = 'email_normalized=?'; $params[] = $email;
        }
        // Names: fill blanks only.
        [$first, $last] = $this->splitName($f, $fullName);
        foreach (['first_name' => $first, 'last_name' => $last, 'full_name' => $fullName] as $col => $inc) {
            $inc = trim((string)$inc);
            if ($inc !== '' && ($c[$col] === null || trim((string)$c[$col]) === '')) {
                $sets[] = "$col=?"; $params[] = mb_substr($inc, 0, $col === 'full_name' ? 255 : 120);
            }
        }
        // sheet_row tracks the most recent source row for reconciliation.
        $sets[] = 'sheet_row=?'; $params[] = $sheetRow;

        // extra_fields: preserve unmapped sheet columns; fill missing keys
        // only, never overwrite an existing value.
        if ($extra) {
            $existing = json_decode((string)($c['extra_fields'] ?? ''), true);
            $existing = is_array($existing) ? $existing : [];
            $merged = $extra + $existing; // existing wins on key conflicts
            if ($merged !== $existing) {
                $sets[] = 'extra_fields=?'; $params[] = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        // DNC only ever strengthens.
        $dnc = ((int)($c['dnc'] ?? 0) === 1 || $this->computeDnc($f, $email)) ? 1 : 0;
        if ($dnc === 1 && (int)$c['dnc'] !== 1) { $sets[] = 'dnc=?'; $params[] = 1; }

        if ($sets) {
            $params[] = $contactId;
            $this->pdo->prepare('UPDATE marketing_contacts SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
        }
    }

    private function applySuppression(int $contactId, ?string $email, array &$reviewReasons): void
    {
        if ($email === null) return;
        $suppressed = $this->suppression->isSuppressed($email);
        $orgRef = SuppressionService::orgRefForEmail($email);
        $orgSuppressed = !$suppressed && $orgRef !== null && $this->suppression->isOrgSuppressed($orgRef);
        if ($suppressed || $orgSuppressed) {
            $this->pdo->prepare('UPDATE marketing_contacts SET dnc=1 WHERE id=? AND dnc=0')->execute([$contactId]);
            $reviewReasons[] = $suppressed ? 'suppressed_email' : 'suppressed_org';
        }
    }

    private function computeDnc(array $f, ?string $email): bool
    {
        if (strtolower(trim($f['dnc'])) !== '') return true;
        if (trim((string)($f['_unsubscribed_at'] ?? '')) !== '') return true;
        if (preg_match('/unsubscrib|do not contact|do-not-contact|opted out/i', $f['status'])) return true;
        if ($email !== null && $this->suppression->isSuppressed($email)) return true;
        if ($email !== null) {
            $orgRef = SuppressionService::orgRefForEmail($email);
            if ($orgRef !== null && $this->suppression->isOrgSuppressed($orgRef)) return true;
        }
        return false;
    }

    private function writeImportEvent(int $contactId, ?int $orgId, int $sheetRow, array $f): void
    {
        $hash = md5(json_encode($f, JSON_UNESCAPED_UNICODE));
        $dedupe = 'sheet_import:' . $sheetRow . ':' . $hash;
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $contactId, $orgId, 'import',
            json_encode(['sheet_row' => $sheetRow], JSON_UNESCAPED_UNICODE),
            'importer', 'sheet_import', $dedupe,
        ]);
        $this->bump('events_written', $stmt->rowCount() > 0 ? 1 : 0);
    }

    private function applyParentLinks(): void
    {
        foreach ($this->parentPending as $childId => $parentName) {
            $pnorm = $this->norm($parentName);
            // Parent may itself be a contact-level org created this run.
            $cands = $this->orgByNorm[$pnorm] ?? [];
            $parentId = $cands ? $cands[0]['id'] : null;
            if ($parentId === null) {
                // Create the parent org shell (DSO/group) if it doesn't exist.
                $this->pdo->prepare(
                    'INSERT INTO marketing_orgs (name, normalized_name, org_type, source) VALUES (?,?,?,?)'
                )->execute([mb_substr($parentName, 0, 255), mb_substr($pnorm, 0, 255), 'group', 'sheet_import']);
                $parentId = (int)$this->pdo->lastInsertId();
                $this->orgByNorm[$pnorm][] = ['id' => $parentId, 'domain' => ''];
                $this->bump('orgs_created');
            }
            if ((int)$parentId === (int)$childId) continue; // self-reference guard
            $this->pdo->prepare('UPDATE marketing_orgs SET parent_org_id=? WHERE id=? AND (parent_org_id IS NULL OR parent_org_id=0)')
                ->execute([$parentId, $childId]);
            $this->bump('org_links_set');
        }
    }

    private function indexNameOrg(int $contactId, ?int $orgId, string $fullName): void
    {
        if ($orgId === null || trim($fullName) === '') return;
        $this->contactByNameOrg[$orgId . '|' . $this->norm($fullName)] ??= $contactId;
    }

    private function recordRow(int $runId, int $sheetRow, ?int $contactId, ?int $orgId, string $disposition, ?string $reason): void
    {
        $hash = isset($this->lastFieldHash) ? $this->lastFieldHash : null;
        $this->pdo->prepare(
            'INSERT INTO marketing_import_rows (run_id, sheet_row, contact_id, org_id, disposition, reason, field_hash)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$runId, $sheetRow, $contactId, $orgId, $disposition, $reason ? substr($reason, 0, 190) : null, $hash]);
    }

    private ?string $lastFieldHash = null;

    private function mergeScalar(array &$sets, array &$params, array $cur, string $col, string $inc, int $max): void
    {
        $inc = trim($inc);
        if ($inc === '') return;
        $existing = $cur[$col] === null ? '' : trim((string)$cur[$col]);
        if ($existing === '') {
            $sets[] = "$col=?"; $params[] = mb_substr($inc, 0, $max);
        } elseif ($this->norm($existing) !== $this->norm($inc)) {
            $this->addConflict('contact', (int)$cur['id'], $col, $existing, $inc);
        }
    }

    private function addConflict(string $entity, int $id, string $field, string $existing, string $incoming): void
    {
        if (count($this->conflicts) < $this->conflictCap) {
            $this->conflicts[] = [
                'entity' => $entity, 'id' => $id, 'field' => $field,
                'existing' => mb_substr($existing, 0, 120), 'imported' => mb_substr($incoming, 0, 120),
            ];
        }
    }

    private function bump(string $key, int $by = 1): void { $this->stats[$key] = ($this->stats[$key] ?? 0) + $by; }
    private function bumpDisp(string $d): void { $this->stats['dispositions'][$d] = ($this->stats['dispositions'][$d] ?? 0) + 1; }

    private function finishStats(): array
    {
        $this->stats['conflict_count'] = count($this->conflicts);
        return $this->stats;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    private function nullStr(string $s): ?string
    {
        $s = trim($s);
        return $s === '' ? null : $s;
    }

    private function splitName(array $f, string $fullName): array
    {
        $first = trim($f['contact_first']); $last = trim($f['contact_last']);
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim($fullName), 2);
            $first = $parts[0] ?? ''; $last = $parts[1] ?? '';
        }
        return [$first, $last];
    }

    private function isoDate(string $raw): ?string
    {
        $ts = $this->dir->parseSheetDate($raw);
        return $ts === null ? null : gmdate('Y-m-d', $ts);
    }

    private function guessOrgType(array $f): string
    {
        $hay = mb_strtolower($f['practice_type'] . ' ' . $f['parent_org']);
        if (str_contains($hay, 'dso')) return 'dso';
        if (trim($f['parent_org']) !== '') return 'dental_practice';
        $pt = mb_strtolower(trim($f['practice_type']));
        return $pt !== '' ? mb_substr($pt, 0, 60) : 'dental_practice';
    }

    private function domainFromWebsite(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') return null;
        if (!preg_match('#^https?://#i', $website)) $website = 'https://' . $website;
        $host = parse_url($website, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return null;
        $host = mb_strtolower($host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
