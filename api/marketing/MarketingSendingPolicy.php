<?php
/**
 * Marketing delivery controls, separate from interface access.
 *
 *  - MARKETING_OUTREACH       : Marketing Administration UI/API (review
 *                               prospects, generate, edit, prepare, approve).
 *  - MARKETING_SENDING        : whether marketing email may be delivered
 *                               at all. Environment variable only; there
 *                               is no session, role, or request override.
 *  - MARKETING_SEND_ALLOWLIST : who may receive mail while sending is on.
 *                               Comma-separated exact addresses and/or
 *                               "@domain" entries. UNSET/EMPTY permits NO
 *                               recipient. "*" permits any recipient and
 *                               is the only way to reach real prospects.
 *
 * Enforced inside MarketingMailer::send(), the single delivery path, so
 * the administration campaign sender and the token-authenticated
 * api/marketing-send.php endpoint obey the same rule. Transactional and
 * account email does not pass through MarketingMailer and is unaffected.
 */
final class MarketingSendingPolicy
{
    public const FLAG = 'MARKETING_SENDING';
    public const ALLOWLIST = 'MARKETING_SEND_ALLOWLIST';

    /** @var callable(string): ?string */
    private $env;

    public function __construct(?callable $env = null)
    {
        $this->env = $env ?? static function (string $key): ?string {
            if (function_exists('getEnvVar')) {
                return getEnvVar($key);
            }
            $v = getenv($key);
            return ($v === false || $v === '') ? null : $v;
        };
    }

    /** True only when MARKETING_SENDING is explicitly a true-ish value. */
    public function enabled(): bool
    {
        $v = ($this->env)(self::FLAG);
        return $v !== null && $v !== '' && filter_var($v, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /** Normalized allowlist entries (lower-case, trimmed, de-duplicated). */
    public function allowlist(): array
    {
        $raw = (string)(($this->env)(self::ALLOWLIST) ?? '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $out[$entry] = true;
        }
        return array_keys($out);
    }

    /** disabled | allowlist | unrestricted */
    public function mode(): string
    {
        if (!$this->enabled()) {
            return 'disabled';
        }
        return in_array('*', $this->allowlist(), true) ? 'unrestricted' : 'allowlist';
    }

    /**
     * May this recipient be emailed right now?
     * @return array{allowed: bool, reason: string}
     */
    public function check(string $email): array
    {
        if (!$this->enabled()) {
            return ['allowed' => false, 'reason' => 'sending_disabled'];
        }
        $list = $this->allowlist();
        if (in_array('*', $list, true)) {
            return ['allowed' => true, 'reason' => ''];
        }
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at);
        if ($email !== '' && (in_array($email, $list, true) || ($domain !== '' && in_array($domain, $list, true)))) {
            return ['allowed' => true, 'reason' => ''];
        }
        return ['allowed' => false, 'reason' => 'recipient_not_allowlisted'];
    }

    /** Safe-to-display summary (never exposes the addresses themselves). */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'mode' => $this->mode(),
            'allowlist_count' => count(array_diff($this->allowlist(), ['*'])),
        ];
    }
}
