<?php
/**
 * Configurable sender signature for marketing email.
 *
 * Stored as JSON in marketing_settings ('sender_signature'); falls back to
 * DEFAULTS when unset or unreadable so mail never breaks on a missing row.
 * The signature is appended at SEND time (never stored in campaign
 * content), in both the HTML and plain-text parts, after the message body
 * and before the centrally managed MarketingFooter.
 *
 * Dedup: if the stored body already contains the signature name and title,
 * nothing is appended - regenerated/edited bodies that sign off
 * themselves are left alone.
 */
final class MarketingSignature
{
    public const KEY = 'sender_signature';
    public const DEFAULTS = [
        'name'   => 'Jason Hochwender',
        'title'  => 'Co-Founder, DentaTrak',
        'detail' => '',
    ];
    private const MAX = ['name' => 120, 'title' => 160, 'detail' => 200];

    /** @return array{name:string,title:string,detail:string} */
    public static function load(PDO $pdo): array
    {
        try {
            $s = $pdo->prepare('SELECT sval FROM marketing_settings WHERE sk=?');
            $s->execute([self::KEY]);
            $raw = $s->fetchColumn();
            $d = $raw ? json_decode((string)$raw, true) : null;
            if (is_array($d)) {
                $out = self::DEFAULTS;
                foreach (self::DEFAULTS as $k => $_) {
                    $out[$k] = substr(trim((string)($d[$k] ?? '')), 0, self::MAX[$k]);
                }
                return $out;
            }
        } catch (Throwable $e) { /* table absent: defaults apply */ }
        return self::DEFAULTS;
    }

    /** Validate + persist. Returns the stored signature. */
    public static function save(PDO $pdo, array $in): array
    {
        $sig = [];
        foreach (self::DEFAULTS as $k => $_) {
            $sig[$k] = substr(trim((string)($in[$k] ?? '')), 0, self::MAX[$k]);
        }
        $pdo->prepare('INSERT INTO marketing_settings (sk,sval,updated_at) VALUES (?,?,UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_at=UTC_TIMESTAMP()')
            ->execute([self::KEY, json_encode($sig)]);
        return $sig;
    }

    /** Restrained HTML block appended after the body. */
    public static function html(array $sig): string
    {
        $line = static function (string $v, string $extra = ''): string {
            return '<span' . $extra . '>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</span>';
        };
        $out = '<div style="margin-top:24px;font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:10pt;color:#374151;line-height:1.55;">'
            . '<strong>' . htmlspecialchars($sig['name'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
            . $line($sig['title'], ' style="color:#6b7280;"');
        if ($sig['detail'] !== '') $out .= '<br>' . $line($sig['detail'], ' style="color:#6b7280;"');
        return $out . '</div>';
    }

    /** Plain-text signature block. */
    public static function text(array $sig): string
    {
        $out = "\n" . $sig['name'] . "\n" . $sig['title'];
        if ($sig['detail'] !== '') $out .= "\n" . $sig['detail'];
        return $out . "\n";
    }

    /** True when the body already carries this signature (name + title). */
    public static function contains(string $bodyHtml, array $sig): bool
    {
        $text = strtolower(trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8')));
        if ($text === '') return false;
        $hasName  = $sig['name']  !== '' && strpos($text, strtolower($sig['name'])) !== false;
        $hasTitle = $sig['title'] !== '' && strpos($text, strtolower($sig['title'])) !== false;
        return $hasName && $hasTitle;
    }

    /** Append the signature unless the body already has one. */
    public static function applyToHtml(string $bodyHtml, array $sig): string
    {
        if (self::contains($bodyHtml, $sig)) return $bodyHtml;
        return $bodyHtml . self::html($sig);
    }

    /** Stable fingerprint for approval hashing. */
    public static function fingerprint(array $sig): string
    {
        return hash('sha256', json_encode([$sig['name'], $sig['title'], $sig['detail']]));
    }
}
