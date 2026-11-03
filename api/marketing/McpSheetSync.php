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
    public function markSent(string $email, string $campaign, string $msgId): bool {
        $p=$this->prospectRow($email); if(!$p) return false;
        $now=gmdate('Y-m-d');
        $vals=['Outreach Status'=>'Sent','First Contact Date'=>$now,'Outreach Method'=>'Email','Campaign'=>$campaign,'Last Contact Date'=>$now];
        $notes=$p['data'][$this->col('Outreach Notes')??PHP_INT_MAX]??'';
        $vals['Outreach Notes']=trim(($notes? $notes.' | ':'')."Gmail $msgId");
        $data=[]; foreach($vals as $h=>$v){$c=$this->col($h); if($c===null) continue;
            $col='';$n=$c+1;while($n>0){$col=chr(65+($n-1)%26).$col;$n=intdiv($n-1,26);}
            $data[]=['range'=>self::TAB."!{$col}{$p['row']}",'values'=>[[$v]]];}
        ProspectSheetSync::httpPublic('POST','https://sheets.googleapis.com/v4/spreadsheets/'.self::SHEET_ID.'/values:batchUpdate',['valueInputOption'=>'USER_ENTERED','data'=>$data],true,$this->token);
        return true;
    }
}
