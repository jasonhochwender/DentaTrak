<?php
/**
 * Sheet pre-check + confirmed-send write-back for the MCP marketing
 * connector. Reuses ProspectSheetSync's sheet/token internals.
 */
require_once __DIR__ . '/OutreachEligibility.php';

class McpSheetSync {
    private const SHEET_ID = '1r7G5QaKe69LAU93d0WoWDSXIsWVr0cbDnpjF7z1tARc';
    private const TAB = 'Prospects';
    private string $token;
    private ?array $rows = null; private ?array $header = null;
    /**
     * @param ?array $snapshot optional ['header'=>[], 'rows'=>[]] to operate
     *        on a preloaded sheet snapshot (no network; read-only use).
     */
    public function __construct(?array $snapshot = null) {
        if ($snapshot !== null) {
            $this->token = '';
            $this->header = array_values($snapshot['header'] ?? []);
            $this->rows = array_values($snapshot['rows'] ?? []);
            return;
        }
        $this->token = ProspectSheetSync::accessTokenPublic();
    }
    private function load(): void {
        if ($this->rows !== null) return;
        $r = ProspectSheetSync::httpPublic('GET','https://sheets.googleapis.com/v4/spreadsheets/'.self::SHEET_ID.'/values/'.rawurlencode(self::TAB),null,false,$this->token);
        $v = json_decode($r,true)['values'] ?? [];
        $this->header = array_shift($v) ?: [];
        $this->rows = $v;
    }
    private function col(string $name): ?int { $this->load(); $i = array_search($name,$this->header); return $i===false?null:$i; }
    public function prospectRow(string $email): ?array {
        $this->load();
        $ec = $this->col('Public Email') ?? $this->col('Email'); if ($ec===null) return null;
        foreach ($this->rows as $i=>$r) if (strtolower(trim($r[$ec]??''))===strtolower($email)) return ['row'=>$i+2,'data'=>$r];
        return null;
    }
    /**
     * Sheet eligibility for one recipient and campaign kind. Rules live in
     * OutreachEligibility: initial outreach refuses previously contacted
     * prospects and duplicate organizations; follow-ups require a valid
     * prior outreach record. Do Not Contact / unsubscribed / bounced /
     * invalid rows and suppressed recipients are refused for every kind.
     *
     * @param string $kind initial | followup
     * @param array  $ctx  suppressed?, org_suppressed?, ledger_sent?, require_in_sheet?
     * @return array{ok: bool, flags: string[], hard: bool, in_sheet: bool, row: ?int, prior_outreach: bool}
     */
    public function preCheck(string $email, string $kind = OutreachEligibility::KIND_INITIAL, array $ctx = []): array {
        return OutreachEligibility::evaluate($this->eligibilityIndex(), $email, $kind, $ctx);
    }

    private ?array $eligIndex = null;
    /** Cached OutreachEligibility index over the loaded snapshot. */
    public function eligibilityIndex(): array {
        $this->load();
        return $this->eligIndex ??= OutreachEligibility::index($this->header ?? [], $this->rows ?? []);
    }

    /**
     * Record a confirmed send on the prospect's row. Only the named cells
     * are written (values:batchUpdate), the row is located by email in this
     * instance's snapshot, and the API response is checked - a transport
     * failure or a zero-cell update throws so the caller can retry later
     * without resending mail. The Gmail note is appended once: a repeat
     * call with the same message id leaves the notes unchanged.
     *
     * @param array $fields header => value
     * @return array{row: int, cells: int}
     */
    public function recordSend(string $email, array $fields, string $note = ''): array {
        $p = $this->prospectRow(strtolower(trim($email)));
        if (!$p) throw new RuntimeException('prospect row not found in sheet');
        if ($note !== '') {
            $nc = $this->colAny(['Outreach Notes', 'Notes']);
            if ($nc !== null) {
                $existing = trim((string)($p['data'][$nc] ?? ''));
                if (strpos($existing, $note) === false) {
                    $fields[$this->header[$nc]] = $existing !== '' ? $existing . ' | ' . $note : $note;
                }
            }
        }
        $data = [];
        foreach ($fields as $h => $v) {
            $c = $this->colAny([(string)$h]);
            if ($c === null) continue;
            $data[] = ['range' => self::TAB . '!' . self::colLetter($c) . $p['row'], 'values' => [[(string)$v]]];
        }
        if (!$data) return ['row' => $p['row'], 'cells' => 0];
        $resp = ProspectSheetSync::httpPublic('POST',
            'https://sheets.googleapis.com/v4/spreadsheets/' . self::SHEET_ID . '/values:batchUpdate',
            ['valueInputOption' => 'USER_ENTERED', 'data' => $data], false, $this->token);
        $cells = (int)(json_decode((string)$resp, true)['totalUpdatedCells'] ?? 0);
        if ($cells < 1) throw new RuntimeException('sheet update not confirmed');
        return ['row' => $p['row'], 'cells' => $cells];
    }

    private static function colLetter(int $zeroBased): string {
        $col = ''; $n = $zeroBased + 1;
        while ($n > 0) { $col = chr(65 + ($n - 1) % 26) . $col; $n = intdiv($n - 1, 26); }
        return $col;
    }

    /** Full sheet snapshot: ['header'=>string[], 'rows'=>array<int,array>] (row 0 = first data row). */
    public function sheet(): array {
        $this->load();
        return ['header' => $this->header ?? [], 'rows' => $this->rows ?? []];
    }

    /** Column index for a header name (case-sensitive match on trimmed header). */
    public function colIndex(string $name): ?int { return $this->col($name); }

    /** Case-insensitive column lookup across common header variants. */
    public function colAny(array $names): ?int {
        foreach ($names as $n) { $i = $this->col($n); if ($i !== null) return $i; }
        $this->load();
        $lower = array_map(fn($h) => strtolower(trim((string)$h)), $this->header ?? []);
        foreach ($names as $n) { $i = array_search(strtolower($n), $lower, true); if ($i !== false) return $i; }
        return null;
    }

    /**
     * Write selected header=>value pairs into one sheet row (1-based sheet
     * row number, i.e. header is row 1). Only the named columns are touched;
     * the full row is re-read and rewritten so other cells are preserved.
     */
    public function updateRow(int $sheetRow, array $fields): bool {
        $this->load();
        $dataRow = $sheetRow - 2; // sheet row -> index into $this->rows
        if ($dataRow < 0 || $dataRow >= count($this->rows)) return false;
        $row = $this->rows[$dataRow];
        foreach ($fields as $header => $value) {
            $c = $this->colAny([$header]);
            if ($c === null) continue;
            $row[$c] = (string) $value;
        }
        // normalize: ensure trailing cells exist up to highest written index
        $max = max(array_keys($row));
        for ($i = 0; $i <= $max; $i++) { if (!isset($row[$i])) $row[$i] = ''; }
        ksort($row);
        ProspectSheetSync::httpPublic('PUT',
            'https://sheets.googleapis.com/v4/spreadsheets/' . self::SHEET_ID . '/values/' . rawurlencode(self::TAB . "!$sheetRow:$sheetRow"),
            ['range' => self::TAB . "!$sheetRow:$sheetRow", 'majorDimension' => 'ROWS', 'values' => [array_values($row)]],
            false, $this->token);
        return true;
    }

    public function markSent(string $email, string $campaign, string $msgId): bool {
        $p=$this->prospectRow($email); if(!$p) return false;
        $now=gmdate('Y-m-d');
        $vals=['Outreach Status'=>'Sent','First Contact Date'=>$now,'Outreach Method'=>'Email','Campaign'=>$campaign,'Last Contact Date'=>$now];
        $notes=$p['data'][$this->col('Outreach Notes')??PHP_INT_MAX]??'';
        $vals['Outreach Notes']=trim(($notes? $notes.' | ':'')."Gmail $msgId");
        $data=[]; foreach($vals as $h=>$v){$c=$this->col($h); if($c===null) continue;
            $col='';$n=$c+1;while($n>0){$col=chr(65+($n-1)%26).$col;$n=intdiv($n-1,26);}
            $data[]=['range'=>self::TAB."!{$col}{$p['row']}",'values'=>[[$v]]];}
        $resp=ProspectSheetSync::httpPublic('POST','https://sheets.googleapis.com/v4/spreadsheets/'.self::SHEET_ID.'/values:batchUpdate',['valueInputOption'=>'USER_ENTERED','data'=>$data],false,$this->token);
        // Only report success when the API confirms cells were written, so
        // an unconfirmed write-back stays queued for retry.
        return (int)(json_decode((string)$resp,true)['totalUpdatedCells']??0) > 0;
    }
}
