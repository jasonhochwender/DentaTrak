<?php
/**
 * Google Sheets sync for marketing suppressions (ported from SiteRecord).
 *
 * - Service-account auth (JWT -> OAuth access token), file set via
 *   GOOGLE_SHEETS_SA_JSON env (path or inline JSON).
 * - Resolves columns by HEADER NAME, never letters: requires
 *   "Public Email" + "Outreach Status"; appends "Unsubscribed At" +
 *   "Unsubscribe Source" if absent.
 * - Every row whose parsed email(s) exactly match (case-insensitive) gets
 *   "Do Not Contact" + UTC timestamp + "Email Unsubscribe".
 * - Org-level: after email match, rows sharing the matched row's
 *   "Practice Name" are also marked Do Not Contact.
 * - All other cells/formulas/validation untouched.
 */
final class ProspectSheetSync
{
    private const SHEET_ID = '1r7G5QaKe69LAU93d0WoWDSXIsWVr0cbDnpjF7z1tARc';
    private const TAB      = 'Prospects';
    private const SCOPE    = 'https://www.googleapis.com/auth/spreadsheets';

    private function accessToken(): string
    {
        if (getenv('K_SERVICE')) {
            $r = self::http('GET','http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token?scopes=' . self::SCOPE, null, false, null, ['Metadata-Flavor: Google']);
            return json_decode($r, true)['access_token'] ?? throw new RuntimeException('metadata token failed');
        }
        $sa = getenv('GOOGLE_SHEETS_SA_JSON') ?: '';
        if ($sa === '') throw new RuntimeException('GOOGLE_SHEETS_SA_JSON unset');
        if ($sa[0] !== '{') $sa = file_get_contents($sa);
        $c = json_decode($sa, true);
        $hdr = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
        $now = time();
        $claims = ['iss' => $c['client_email'], 'scope' => self::SCOPE, 'aud' => 'https://oauth2.googleapis.com/token',
                   'iat' => $now, 'exp' => $now + 3600];
        $pay = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        openssl_sign("$hdr.$pay", $sig, $c['private_key'], 'sha256');
        $jwt = "$hdr.$pay." . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
        $r = self::http('POST', 'https://oauth2.googleapis.com/token',
            ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt], true);
        return json_decode($r, true)['access_token'] ?? throw new RuntimeException('oauth failed');
    }

    private static function http(string $m, string $u, $body = null, bool $form = false, ?string $tok = null, array $extraHdr = []): string
    {
        $ch = curl_init($u);
        $h = ['Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json')];
        if ($tok) $h[] = 'Authorization: Bearer ' . $tok;
        foreach ($extraHdr as $eh) $h[] = $eh;
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body));
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($code >= 400) throw new RuntimeException("HTTP $code: " . substr((string) $r, 0, 200));
        return (string) $r;
    }

    /** Sync one suppressed email (+ same-practice rows) to the sheet. */
    public function sync(string $email): array
    {
        $tok = $this->accessToken();
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/' . self::SHEET_ID;
        $data = json_decode(self::http('GET', "$base/values/" . rawurlencode(self::TAB), null, false, $tok), true);
        $rows = $data['values'] ?? [];
        if (!$rows) throw new RuntimeException('empty sheet');
        $hdr = array_map('strtolower', array_map('trim', $rows[0]));
        $ci = fn(string $name) => array_search($name, $hdr, true);
        $cEmail = $ci('public email'); $cStatus = $ci('outreach status'); $cOrg = $ci('practice name');
        if ($cEmail === false || $cStatus === false) throw new RuntimeException('required headers missing');
        // Append tracking columns if absent
        $cAt = $ci('unsubscribed at'); $cSrc = $ci('unsubscribe source');
        $addCols = [];
        if ($cAt === false) $addCols[] = 'Unsubscribed At';
        if ($cSrc === false) $addCols[] = 'Unsubscribe Source';
        if ($addCols) {
            self::http('PUT', "$base/values/" . rawurlencode(self::TAB . '!1:' . (1)) . "?valueInputOption=RAW",
                ['range' => self::TAB . '!1:1', 'majorDimension' => 'ROWS',
                 'values' => [array_merge($rows[0], $addCols)]], false, $tok);
            $cAt = $cAt === false ? count($hdr) : $cAt;
            $cSrc = $cSrc === false ? count($hdr) + ($cAt === false ? 1 : 0) : $cSrc;
        }
        $target = strtolower(trim($email));
        $now = gmdate('Y-m-d H:i:s') . 'Z';
        $matched = 0; $org = null; $updates = [];
        foreach ($rows as $i => $row) {
            if ($i === 0) continue;
            $cell = strtolower((string) ($row[$cEmail] ?? ''));
            $found = false;
            foreach (preg_split('/[\s,;]+/', $cell, -1, PREG_SPLIT_NO_EMPTY) as $e) {
                if ($e === $target) { $found = true; break; }
            }
            if ($found) { $matched++; if ($org === null && $cOrg !== false) $org = trim((string) ($row[$cOrg] ?? '')); }
        }
        // second pass: email match OR same practice name (org suppression)
        foreach ($rows as $i => $row) {
            if ($i === 0) continue;
            $cell = strtolower((string) ($row[$cEmail] ?? ''));
            $isTarget = false;
            foreach (preg_split('/[\s,;]+/', $cell, -1, PREG_SPLIT_NO_EMPTY) as $e) if ($e === $target) $isTarget = true;
            $sameOrg = $org !== null && $org !== '' && $cOrg !== false && trim((string) ($row[$cOrg] ?? '')) === $org;
            if (!$isTarget && !$sameOrg) continue;
            $n = $i + 1;
            $u = $row;
            $u[$cStatus] = 'Do Not Contact';
            if ($cAt !== false) $u[$cAt] = $now;
            if ($cSrc !== false) $u[$cSrc] = $isTarget ? 'Email Unsubscribe' : 'Organization Unsubscribe';
            $updates[] = ['range' => self::TAB . "!$n:$n", 'majorDimension' => 'ROWS', 'values' => [$u]];
        }
        if ($updates) {
            self::http('POST', "$base/values:batchUpdate",
                ['valueInputOption' => 'RAW', 'data' => $updates], false, $tok);
        }
        return ['matched_rows' => count($updates), 'direct' => $matched, 'org' => $org];
    }
}
