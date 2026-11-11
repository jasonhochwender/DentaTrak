<?php
/**
 * Sheet pre-check + confirmed-send write-back for the MCP marketing
 * connector. Reuses ProspectSheetSync's sheet/token internals.
 */
class McpSheetSync {
    private const SHEET_ID = '1r7G5QaKe69LAU93d0WoWDSXIsWVr0cbDnpjF7z1tARc';
    private const TAB = 'Prospects';
    private string $token;
    private ?array $rows = null; private ?array $header = null;
    public function __construct() { $this->token = ProspectSheetSync::accessTokenPublic(); }
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
    public function preCheck(string $email): array {
        $p = $this->prospectRow($email); if (!$p) return ['ok'=>true,'flags'=>[]];
        $d=$p['data']; $flags=[];
        $get=function($n)use($d){$i=array_search($n,$this->header);return $i===false?'':trim($d[$i]??'');};
        if ($get('Do Not Contact')!==''||$get('DNC')!=='') $flags[]='do_not_contact';
        if (in_array($get('Outreach Status'),['Sent','Contacted','Replied'])) $flags[]='already_contacted';
        $dom = strtolower(substr(strrchr($email,'@')?:'',1));
        if ($dom) { $pc=$this->col('Parent Organization')??$this->col('Parent Org'); $parent=$get('Parent Organization')?:$get('Parent Org');
            foreach ($this->rows as $r) {
                $re=strtolower(trim($r[$this->col('Public Email')??$this->col('Email')]??''));
                if ($re===$email) continue;
                $sameDom=$re!==''&&substr(strrchr($re,'@'),1)===$dom;
                $samePar=$parent!==''&&$pc!==null&&strtolower(trim($r[$pc]??''))===strtolower($parent);
                $os=trim($r[$this->col('Outreach Status')??PHP_INT_MAX]??'');
                if (($sameDom||$samePar)&&in_array($os,['Sent','Contacted','Replied'])){$flags[]='duplicate_org';break;}
            }
        }
        return ['ok'=>empty($flags),'flags'=>$flags];
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
        ProspectSheetSync::httpPublic('POST','https://sheets.googleapis.com/v4/spreadsheets/'.self::SHEET_ID.'/values:batchUpdate',['valueInputOption'=>'USER_ENTERED','data'=>$data],false,$this->token);
        return true;
    }
}
