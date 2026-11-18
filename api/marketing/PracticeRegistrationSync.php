<?php
/**
 * Synchronizes DentaTrak practice registrations into the Marketing CRM.
 *
 * Called after a practice is created (api/accept-baa.php) and by the
 * one-time reconciliation script. For each practice it either links an
 * existing CRM organization or creates one, marks linked contacts
 * 'Signed Up', and records prospect events. Registered organizations are
 * excluded from introductory outreach via PracticeRegistrationSync::
 * registeredEmails() + the eligibility 'already_registered' flag.
 *
 * Safety properties:
 *  - Matching is conservative: practice_id link > exact owner email >
 *    verified domain + name > exact name + compatible location. Anything
 *    ambiguous creates a NEW flagged org (needs_review) rather than
 *    merging unrelated practices.
 *  - Idempotent: the practice_id link and 'Signed Up' statuses make
 *    repeat runs no-ops; events are only written on actual changes.
 *  - Never deletes or overwrites outreach history, notes, dates, dnc
 *    flags, or manually maintained fields. Prospect statuses that carry
 *    suppression meaning are preserved.
 */
require_once __DIR__ . '/SuppressionService.php';

final class PracticeRegistrationSync
{
    /** Contact statuses that must never be rewritten to 'Signed Up'. */
    private const PRESERVE_STATUSES = ['do not contact', 'unsubscribed', 'bounced', 'invalid'];

    /** Status for registered users who have not completed practice setup. */
    public const STATUS_SETUP_INCOMPLETE = 'Registered - Setup Incomplete';

    /** Free-mail domains can never verify an organization by domain alone. */
    private const FREEMAIL = [
        'gmail.com','googlemail.com','yahoo.com','ymail.com','hotmail.com','outlook.com',
        'live.com','msn.com','aol.com','icloud.com','me.com','mac.com','comcast.net',
        'att.net','verizon.net','sbcglobal.net','bellsouth.net','earthlink.net',
        'charter.net','cox.net','frontier.com','windstream.net','roadrunner.com',
        'rr.com','netzero.net','juno.com','embarqmail.com','mail.com','protonmail.com',
        'proton.me','gmx.com','zoho.com','yandex.com','inbox.com','lavabit.com',
    ];

    private PDO $pdo;
    private string $actor;

    public function __construct(PDO $pdo, string $actor = 'system')
    {
        $this->pdo = $pdo;
        $this->actor = $actor !== '' ? $actor : 'system';
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Normalize a practice/org name for comparison: lowercase, strip
     * punctuation, drop common legal/descriptor suffix words.
     */
    public static function normName(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        $n = trim($n);
        $n = preg_replace('/\b(llc|inc|corp|corporation|ltd|co|company|group|the|of|and)\b/', ' ', $n) ?? $n;
        return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    }

    /**
     * Sync one practice into the CRM. Returns a result descriptor; safe to
     * call repeatedly (no-ops once linked and marked).
     */
    public function syncPractice(int $practiceId): array
    {
        $out = ['practice_id' => $practiceId, 'action' => 'none', 'org_id' => null,
            'contacts_updated' => 0, 'contacts_created' => 0, 'review' => false, 'error' => null];

        $st = $this->pdo->prepare(
            'SELECT id, practice_id, legal_name, display_name, practice_name, practice_address,
                    organization_type, created_at, created_by, is_active
               FROM practices WHERE id=?'
        );
        $st->execute([$practiceId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) { $out['error'] = 'practice_not_found'; return $out; }

        // Owner/admin user for contact creation (is_owner first, else creator).
        $owner = $this->practiceOwner($practiceId, (int)($p['created_by'] ?? 0));
        $ownerEmail = $owner !== null ? SuppressionService::normalizeEmail((string)$owner['email']) : '';

        $practiceName = trim((string)($p['legal_name'] ?? '')) !== ''
            ? trim((string)$p['legal_name'])
            : trim((string)($p['display_name'] ?? $p['practice_name'] ?? ''));
        $registeredAt = !empty($p['created_at']) ? substr((string)$p['created_at'], 0, 10) : gmdate('Y-m-d');

        // ---- find candidate orgs -------------------------------------
        $linked = $this->orgByPracticeId($practiceId);
        $candidates = [];   // org_id => ['by' => reason, 'reliable' => bool]
        if ($linked) {
            $candidates[(int)$linked['id']] = ['by' => 'practice_id', 'reliable' => true];
        }

        $emailOrgs = [];
        if ($ownerEmail !== '') {
            // orgsForEmail() returns a set map (org_id => true): iterate keys.
            foreach (array_keys($this->orgsForEmail($ownerEmail)) as $oid) {
                $emailOrgs[$oid] = true;
            }
        }
        foreach (array_keys($emailOrgs) as $oid) {
            if (isset($candidates[$oid])) continue;
            $candidates[$oid] = ['by' => 'owner_email', 'reliable' => true];
        }

        $domain = $ownerEmail !== '' ? substr($ownerEmail, strrpos($ownerEmail, '@') + 1) : '';
        $nameNorm = self::normName($practiceName);
        $locNorm = self::normLocation((string)($p['practice_address'] ?? ''));

        $nameOrgs = $this->orgsByName($nameNorm);
        $domainOrgs = ($domain !== '' && !in_array($domain, self::FREEMAIL, true))
            ? $this->orgsByDomain($domain) : [];

        foreach ($nameOrgs as $oid => $org) {
            if (isset($candidates[$oid])) continue;
            $rel = $this->nameLocationCompatible($org, $nameNorm, $locNorm);
            $candidates[$oid] = ['by' => 'name', 'reliable' => $rel === 'compatible'];
            if ($rel === 'conflict') $candidates[$oid]['conflict'] = true;
        }
        foreach ($domainOrgs as $oid => $org) {
            if (isset($candidates[$oid])) continue;
            // Domain alone never links: the org name must also be compatible,
            // and a conflicting location demotes it to a weak signal. This
            // keeps shared/ambiguous domains from merging different practices.
            $nameOk = $this->namesCompatible($org['name'] ?? '', $practiceName);
            $orgLocT = self::locationTokens((string)($org['location'] ?? ''));
            $pracLocT = self::locationTokens((string)($p['practice_address'] ?? ''));
            $locConflict = $orgLocT !== [] && $pracLocT !== []
                && !array_intersect($orgLocT, $pracLocT);
            if ($nameOk && !$locConflict) {
                $candidates[$oid] = ['by' => 'domain', 'reliable' => true];
            } else {
                $candidates[$oid] = ['by' => 'domain_only', 'reliable' => false];
            }
        }

        // ---- resolve ---------------------------------------------------
        $reliable = array_filter($candidates, static fn($c) => !empty($c['reliable']));
        $org = null; $matchedBy = null; $ambiguous = false;
        if (count($reliable) === 1) {
            $oid = (int)array_key_first($reliable);
            $org = $this->orgRow($oid);
            $matchedBy = $reliable[$oid]['by'];
        } elseif (count($reliable) > 1) {
            // Reliable signals disagree about WHICH org: never guess.
            $ambiguous = true;
        } elseif ($candidates !== []) {
            // Only weak/ambiguous signals: represent the practice as a new,
            // flagged org instead of merging on a guess.
            $ambiguous = true;
        }

        $this->pdo->beginTransaction();
        try {
            if ($org === null) {
                $orgId = $this->createRegisteredOrg($p, $practiceName, $registeredAt, $domain, $ambiguous);
                $out['action'] = 'created';
                $out['org_id'] = $orgId;
            } else {
                $orgId = (int)$org['id'];
                $this->linkOrg($orgId, $practiceId, $registeredAt, $p, $domain);
                $out['action'] = $org['practice_id'] !== null ? 'linked' : 'updated';
                $out['org_id'] = $orgId;
            }
            $out['review'] = $ambiguous;

            // Mark every contact at this org 'Signed Up' (preserving
            // suppression-meaning statuses), then ensure the registering
            // admin has a contact here.
            $out['contacts_updated'] = $this->markOrgContactsSignedUp($orgId);
            if ($owner !== null) {
                $created = $this->ensureOwnerContact($orgId, $owner, $practiceName);
                if ($created) {
                    $out['contacts_created']++;
                    $out['contacts_updated']++;
                }
            }
            // The registering admin's email is 'Signed Up' wherever it
            // appears, even if a stale record sits under another org.
            if ($ownerEmail !== '') {
                $out['contacts_updated'] += $this->markEmailSignedUp($ownerEmail, $orgId);
            }

            $this->event(null, $orgId, 'practice_registered', [
                'practice_id' => $practiceId,
                'practice_uuid' => $p['practice_id'] ?? null,
                'action' => $out['action'],
                'matched_by' => $matchedBy,
                'ambiguous' => $ambiguous,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $out['error'] = $e->getMessage();
            return $out;
        }
        return $out;
    }

    /**
     * Normalized emails that belong to registered practices: contacts at
     * an org linked to a practice_id, or contacts already marked
     * 'Signed Up'. Used by campaign eligibility and the send-time gate.
     *
     * @return array<string,true> map email_normalized => true
     */
    public static function registeredEmails(PDO $pdo, array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn($e) => SuppressionService::normalizeEmail((string)$e), $emails))));
        $out = [];
        $hasCol = false; $hasUserCol = false;
        try {
            $hasCol = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_orgs'
                    AND COLUMN_NAME='practice_id' LIMIT 1")->fetchColumn();
            $hasUserCol = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts'
                    AND COLUMN_NAME='user_id' LIMIT 1")->fetchColumn();
        } catch (Throwable $e) { /* pre-migration: status fallback still applies */ }
        foreach (array_chunk($emails, 400) as $chunk) {
            if (!$chunk) continue;
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            // Pre-migration fallback: registration statuses still count.
            $conds = [];
            if ($hasCol) $conds[] = 'o.practice_id IS NOT NULL';
            if ($hasUserCol) $conds[] = 'c.user_id IS NOT NULL';
            $conds[] = "LOWER(c.status) IN ('signed up','registered - setup incomplete')";
            $orgCond = '(' . implode(' OR ', $conds) . ')';
            try {
                $st = $pdo->prepare(
                    "SELECT DISTINCT c.email_normalized
                       FROM marketing_contacts c
                       LEFT JOIN marketing_orgs o ON o.id = c.org_id
                      WHERE c.email_normalized IN ($ph)
                        AND $orgCond"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) {
                    $out[strtolower((string)$e)] = true;
                }
            } catch (Throwable $e) {
                error_log('[practice-registration] registeredEmails: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Matching helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function orgRow(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function orgByPracticeId(int $practiceId): ?array
    {
        try {
            $st = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE practice_id=? ORDER BY id LIMIT 1');
            $st->execute([$practiceId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r === false ? null : $r;
        } catch (Throwable $e) {
            return null; // column absent pre-migration: treat as unlinked
        }
    }

    /** @return array<int,true> org ids having a contact with this email */
    private function orgsForEmail(string $emailNorm): array
    {
        $st = $this->pdo->prepare(
            'SELECT DISTINCT org_id FROM marketing_contacts
              WHERE email_normalized=? AND org_id IS NOT NULL'
        );
        $st->execute([$emailNorm]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = true;
        return $out;
    }

    /** Orgs whose name is compatible with the practice name (normName
     *  comparison in PHP: the stored normalized_name keeps legal suffixes
     *  that normName strips, so a SQL equality check would miss matches). */
    private function orgsByName(string $nameNorm): array
    {
        if ($nameNorm === '') return [];
        $out = [];
        foreach ($this->pdo->query('SELECT id, name, location, domain FROM marketing_orgs')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $on = self::normName((string)$r['name']);
            if ($on === '') continue;
            $compatible = $on === $nameNorm
                || (min(strlen($on), strlen($nameNorm)) >= 8
                    && (str_contains($on, $nameNorm) || str_contains($nameNorm, $on)));
            if ($compatible) $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** @return array<int,array> org_id => org row for exact domain */
    private function orgsByDomain(string $domain): array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE domain=?');
        $st->execute([$domain]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    private function practiceOwner(int $practiceId, int $fallbackUserId): ?array
    {
        $uid = 0;
        try {
            $st = $this->pdo->prepare('SELECT user_id FROM practice_users WHERE practice_id=? AND is_owner=1 LIMIT 1');
            $st->execute([$practiceId]);
            $uid = (int)$st->fetchColumn();
        } catch (Throwable $e) { /* practice_users shape varies */ }
        if ($uid <= 0) $uid = $fallbackUserId;
        if ($uid <= 0) return null;
        $st = $this->pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE id=?');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u === false ? null : $u;
    }

    /** 'compatible' | 'conflict' | 'unknown' for name+location evidence.
     *  $nameNorm/$locNorm are the PRACTICE's normalized name/location. */
    private function nameLocationCompatible(array $org, string $nameNorm, string $locNorm): string
    {
        $orgName = self::normName((string)($org['name'] ?? ''));
        $nameOk = $orgName !== '' && $nameNorm !== '' && ($orgName === $nameNorm
            || (min(strlen($orgName), strlen($nameNorm)) >= 8
                && (str_contains($orgName, $nameNorm) || str_contains($nameNorm, $orgName))));
        if (!$nameOk) return 'conflict';
        $orgLoc = self::normLocation((string)($org['location'] ?? ''));
        if ($locNorm === '' || $orgLoc === '') return 'unknown';
        $ta = self::locationTokens($locNorm); $tb = self::locationTokens($orgLoc);
        return $ta && $tb && array_intersect($ta, $tb) ? 'compatible' : 'conflict';
    }

    /** Names match when normalized forms are equal or one contains the other. */
    private function namesCompatible(string $a, string $b): bool
    {
        $na = self::normName($a); $nb = self::normName($b);
        if ($na === '' || $nb === '') return false;
        if ($na === $nb) return true;
        // Containment only for reasonably long names to avoid 'a' in 'abc'.
        $short = min(strlen($na), strlen($nb));
        if ($short < 8) return false;
        return str_contains($na, $nb) || str_contains($nb, $na);
    }

    private function locationsCompatible(string $a, string $b): bool
    {
        $ta = self::locationTokens($a); $tb = self::locationTokens($b);
        if (!$ta || !$tb) return false;
        return (bool)array_intersect($ta, $tb);
    }

    private static function normLocation(string $loc): string
    {
        $l = mb_strtolower(trim($loc));
        $l = preg_replace('/[^a-z0-9 ]+/', ' ', $l) ?? $l;
        return trim(preg_replace('/\s+/', ' ', $l) ?? $l);
    }

    /** Significant lowercase tokens from a free-text location/address. */
    private static function locationTokens(string $loc): array
    {
        $n = self::normLocation($loc);
        if ($n === '') return [];
        $stop = ['street','st','road','rd','avenue','ave','drive','dr','lane','ln','boulevard',
            'blvd','suite','ste','unit','po','box','north','south','east','west','n','s','e','w'];
        $out = [];
        foreach (explode(' ', $n) as $t) {
            if (strlen($t) >= 3 && !in_array($t, $stop, true)) $out[$t] = true;
        }
        return array_keys($out);
    }

    /**
     * Sync one DentaTrak USER account into the CRM. Every registered user
     * is represented: users with practice memberships are linked to the
     * practice's CRM org and marked 'Signed Up'; users without any
     * practice get a standalone contact (org_id NULL - contacts are not
     * required to belong to an org) marked 'Registered - Setup
     * Incomplete'. Idempotent via user_id + email matching; never
     * creates placeholder orgs.
     */
    public function syncUser(int $userId): array
    {
        $out = ['user_id' => $userId, 'action' => 'none', 'contact_id' => null,
            'org_id' => null, 'has_practice' => false, 'incomplete' => false,
            'error' => null];
        if (!$this->hasUserCol()) { $out['error'] = 'migration_required'; return $out; }

        $st = $this->pdo->prepare('SELECT id, email, first_name, last_name, created_at FROM users WHERE id=?');
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) { $out['error'] = 'user_not_found'; return $out; }
        $email = SuppressionService::normalizeEmail((string)$u['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out['error'] = 'invalid_email'; return $out;
        }
        $registeredAt = !empty($u['created_at']) ? substr((string)$u['created_at'], 0, 10) : gmdate('Y-m-d');

        // Practice memberships -> ensure each practice's org exists. The
        // practice sync owns org matching/creation and is idempotent.
        $mem = $this->pdo->prepare('SELECT practice_id, is_owner FROM practice_users WHERE user_id=?');
        $mem->execute([$userId]);
        $practiceIds = []; $ownerPid = null;
        foreach ($mem->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $pid = (int)$m['practice_id'];
            $practiceIds[$pid] = true;
            if ((int)$m['is_owner'] === 1 && $ownerPid === null) $ownerPid = $pid;
        }
        $syncErrors = [];
        foreach (array_keys($practiceIds) as $pid) {
            $r = $this->syncPractice($pid);
            if ($r['error'] !== null) $syncErrors[] = "#$pid:{$r['error']}";
        }
        $hasPractice = $practiceIds !== [];
        $out['has_practice'] = $hasPractice;
        if ($syncErrors) $out['practice_errors'] = $syncErrors;

        // Target org: prefer the org of a practice the user owns, else
        // the lowest practice id's org. Deterministic for multi-practice
        // users; the contact keeps whichever org it already had.
        $targetOrg = null;
        if ($hasPractice) {
            $order = $ownerPid !== null ? [$ownerPid] : [];
            $order = array_merge($order, array_values(array_diff(array_keys($practiceIds), $order)));
            sort($order);
            $in = implode(',', array_fill(0, count($order), '?'));
            $st = $this->pdo->prepare("SELECT practice_id, id FROM marketing_orgs WHERE practice_id IN ($in)");
            $st->execute($order);
            $orgByPractice = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $orgByPractice[(int)$o['practice_id']] = (int)$o['id'];
            }
            foreach ($order as $pid) {
                if (isset($orgByPractice[$pid])) { $targetOrg = $orgByPractice[$pid]; break; }
            }
            $out['org_id'] = $targetOrg;
        }

        $status = $hasPractice ? 'Signed Up' : self::STATUS_SETUP_INCOMPLETE;
        $first = trim((string)($u['first_name'] ?? ''));
        $last = trim((string)($u['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);

        $this->pdo->beginTransaction();
        try {
            // Resolution order: existing user_id link (prefer org-linked),
            // then email at the target org, then any contact with this
            // email. Never creates a second contact for a known user.
            $st = $this->pdo->prepare(
                'SELECT * FROM marketing_contacts WHERE user_id=?
                  ORDER BY (org_id IS NULL), id LIMIT 1');
            $st->execute([$userId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c && $targetOrg !== null) {
                $st = $this->pdo->prepare(
                    'SELECT * FROM marketing_contacts WHERE org_id=? AND email_normalized=? LIMIT 1');
                $st->execute([$targetOrg, $email]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
            }
            if (!$c) {
                $st = $this->pdo->prepare(
                    'SELECT * FROM marketing_contacts WHERE email_normalized=? ORDER BY id LIMIT 1');
                $st->execute([$email]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
            }

            if ($c) {
                $cid = (int)$c['id'];
                $sets = []; $args = [];
                if (($c['user_id'] ?? null) === null) { $sets[] = 'user_id=?'; $args[] = $userId; }
                // Place a standalone (org-less) contact into the practice
                // org; never move a contact that already belongs to a
                // different org.
                if (($c['org_id'] ?? null) === null && $targetOrg !== null) {
                    $sets[] = 'org_id=?'; $args[] = $targetOrg;
                }
                if (trim((string)($c['user_registered_at'] ?? '')) === '') {
                    $sets[] = 'user_registered_at=?'; $args[] = $registeredAt;
                }
                $cur = strtolower(trim((string)$c['status']));
                $preserved = false;
                foreach (self::PRESERVE_STATUSES as $bad) {
                    if (strpos($cur, $bad) !== false) { $preserved = true; break; }
                }
                if (!$preserved && $cur !== strtolower($status)) {
                    $sets[] = 'status=?'; $args[] = $status;
                }
                if ($full !== '' && trim((string)($c['full_name'] ?? '')) === '') {
                    $sets[] = 'full_name=?'; $args[] = mb_substr($full, 0, 255);
                }
                if ($sets) {
                    $args[] = $cid;
                    $this->pdo->prepare('UPDATE marketing_contacts SET ' . implode(',', $sets) . ' WHERE id=?')
                        ->execute($args);
                }
                $out['action'] = 'updated';
                $out['contact_id'] = $cid;
            } else {
                $this->pdo->prepare(
                    "INSERT INTO marketing_contacts
                     (org_id, user_id, first_name, last_name, full_name, email, email_normalized,
                      status, source, user_registered_at, version)
                     VALUES (?,?,?,?,?,?,?,?,?,?,1)"
                )->execute([
                    $targetOrg, $userId,
                    $first !== '' ? $first : null, $last !== '' ? $last : null,
                    $full !== '' ? $full : null, $email, $email,
                    $status, 'user_registration', $registeredAt,
                ]);
                $cid = (int)$this->pdo->lastInsertId();
                $out['action'] = 'created';
                $out['contact_id'] = $cid;
                $out['incomplete'] = $status !== 'Signed Up';
            }

            // Same-email contacts elsewhere reflect the same human: a user
            // with a practice is 'Signed Up' wherever their address appears.
            if ($hasPractice && $targetOrg !== null) {
                $this->markEmailSignedUp($email, $targetOrg);
            }
            $out['incomplete'] = $status !== 'Signed Up';
            $this->event($out['contact_id'], $targetOrg, 'user_synced', [
                'user_id' => $userId, 'action' => $out['action'],
                'has_practice' => $hasPractice, 'status' => $status,
                'practice_errors' => $syncErrors ?: null,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $out['error'] = $e->getMessage();
            return $out;
        }
        return $out;
    }

    /** Whether the user already has an org-linked canonical contact. */
    private function hasOrgLinkedUserContact(int $userId): bool
    {
        if (!$this->hasUserCol() || $userId <= 0) return false;
        try {
            $st = $this->pdo->prepare(
                'SELECT 1 FROM marketing_contacts WHERE user_id=? AND org_id IS NOT NULL LIMIT 1');
            $st->execute([$userId]);
            return $st->fetchColumn() !== false;
        } catch (Throwable $e) { return false; }
    }

    /** Whether marketing_contacts.user_id exists (post-migration). */
    private function hasUserCol(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $has = (bool)$this->pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts'
                    AND COLUMN_NAME='user_id' LIMIT 1")->fetchColumn();
        } catch (Throwable $e) { $has = false; }
        return $has;
    }

    /* ------------------------------------------------------------------ */
    /* Writes (called inside syncPractice's transaction)                  */
    /* ------------------------------------------------------------------ */

    private function createRegisteredOrg(array $p, string $name, string $registeredAt, string $domain, bool $ambiguous): int
    {
        $location = trim((string)($p['practice_address'] ?? ''));
        $orgType = trim((string)($p['organization_type'] ?? ''));
        $this->pdo->prepare(
            "INSERT INTO marketing_orgs
             (name, normalized_name, org_type, domain, location, status, source,
              practice_id, registered_at, needs_review, review_reason)
             VALUES (?,?,?,?,?,'active','practice_registration',?,?,?,?)"
        )->execute([
            mb_substr($name !== '' ? $name : 'Practice #' . $p['id'], 0, 255),
            mb_strtolower(mb_substr($name !== '' ? $name : 'Practice #' . $p['id'], 0, 255)),
            $orgType !== '' ? mb_substr($orgType, 0, 60) : null,
            $domain !== '' ? $domain : null,
            $location !== '' ? mb_substr($location, 0, 255) : null,
            (int)$p['id'], $registeredAt,
            $ambiguous ? 1 : 0,
            $ambiguous ? 'possible_duplicate_registration' : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Fill the practice link + registration date; fill only EMPTY fields. */
    private function linkOrg(int $orgId, int $practiceId, string $registeredAt, array $p, string $domain): void
    {
        $org = $this->orgRow($orgId);
        if (!$org) return;
        $sets = []; $args = [];
        if ($org['practice_id'] === null) { $sets[] = 'practice_id=?'; $args[] = $practiceId; }
        if (($org['registered_at'] ?? null) === null) { $sets[] = 'registered_at=?'; $args[] = $registeredAt; }
        if (trim((string)($org['location'] ?? '')) === '' && trim((string)($p['practice_address'] ?? '')) !== '') {
            $sets[] = 'location=?'; $args[] = mb_substr((string)$p['practice_address'], 0, 255);
        }
        if (trim((string)($org['domain'] ?? '')) === '' && $domain !== '' && !in_array($domain, self::FREEMAIL, true)) {
            $sets[] = 'domain=?'; $args[] = $domain;
        }
        if (!$sets) return;
        $args[] = $orgId;
        $this->pdo->prepare('UPDATE marketing_orgs SET ' . implode(',', $sets) . ' WHERE id=?')->execute($args);
    }

    /** Contacts at the org -> 'Signed Up', preserving protected statuses. */
    private function markOrgContactsSignedUp(int $orgId): int
    {
        $st = $this->pdo->prepare('SELECT id, status FROM marketing_contacts WHERE org_id=?');
        $st->execute([$orgId]);
        $n = 0;
        $upd = $this->pdo->prepare(
            "UPDATE marketing_contacts SET status='Signed Up', updated_at=UTC_TIMESTAMP() WHERE id=?"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $s = strtolower(trim((string)$c['status']));
            if ($s === 'signed up') continue;
            foreach (self::PRESERVE_STATUSES as $bad) {
                if (strpos($s, $bad) !== false) continue 2;
            }
            $upd->execute([(int)$c['id']]);
            $this->event((int)$c['id'], $orgId, 'status_signed_up', ['reason' => 'practice_registered']);
            $n++;
        }
        return $n;
    }

    /** Any contact anywhere with this email -> 'Signed Up' (same human). */
    private function markEmailSignedUp(string $emailNorm, int $orgId): int
    {
        $st = $this->pdo->prepare('SELECT id, status, org_id FROM marketing_contacts WHERE email_normalized=?');
        $st->execute([$emailNorm]);
        $n = 0;
        $upd = $this->pdo->prepare(
            "UPDATE marketing_contacts SET status='Signed Up', updated_at=UTC_TIMESTAMP() WHERE id=?"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $s = strtolower(trim((string)$c['status']));
            if ($s === 'signed up') continue;
            foreach (self::PRESERVE_STATUSES as $bad) {
                if (strpos($s, $bad) !== false) continue 2;
            }
            $upd->execute([(int)$c['id']]);
            $this->event((int)$c['id'], $orgId, 'status_signed_up',
                ['reason' => 'practice_registered', 'email_match' => true]);
            $n++;
        }
        return $n;
    }

    /** Create the registering admin's contact if not already present at org. */
    private function ensureOwnerContact(int $orgId, array $user, string $practiceName): bool
    {
        $email = SuppressionService::normalizeEmail((string)$user['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $uid = (int)($user['id'] ?? 0);

        // Adopt a standalone user-registration contact (org-less) into
        // this org instead of creating a second contact for the same
        // person - preserves the contact's history and user_registered_at.
        if ($this->hasUserCol() && $uid > 0) {
            $st = $this->pdo->prepare(
                'SELECT id, status FROM marketing_contacts WHERE user_id=? AND org_id IS NULL LIMIT 1');
            $st->execute([$uid]);
            $solo = $st->fetch(PDO::FETCH_ASSOC);
            if ($solo !== false) {
                // Only adopt when the org does not already hold this email;
                // otherwise the org contact is canonical and the standalone
                // row is left for user-level sync to reconcile.
                $dup = $this->pdo->prepare(
                    'SELECT id FROM marketing_contacts WHERE org_id=? AND email_normalized=? AND id<>? LIMIT 1');
                $dup->execute([$orgId, $email, (int)$solo['id']]);
                if ($dup->fetchColumn() === false) {
                    $cur = strtolower(trim((string)$solo['status']));
                    $preserved = false;
                    foreach (self::PRESERVE_STATUSES as $bad) {
                        if (strpos($cur, $bad) !== false) { $preserved = true; break; }
                    }
                    if ($preserved) {
                        $this->pdo->prepare('UPDATE marketing_contacts SET org_id=? WHERE id=?')
                            ->execute([$orgId, (int)$solo['id']]);
                    } else {
                        $this->pdo->prepare(
                            "UPDATE marketing_contacts SET org_id=?, status='Signed Up', updated_at=UTC_TIMESTAMP() WHERE id=?")
                            ->execute([$orgId, (int)$solo['id']]);
                    }
                    $this->event((int)$solo['id'], $orgId, 'contact_org_linked',
                        ['reason' => 'practice_registered', 'adopted_user_contact' => true]);
                    return true;
                }
            }
        }

        // Already a contact at THIS org with this email? Stamp the user
        // link so user-level sync can find it later - but only when the
        // user does not already have an org-linked canonical contact
        // (multi-practice users keep ONE user-linked contact).
        $uidCol = $this->hasUserCol() ? 'user_id' : 'NULL AS user_id';
        $st = $this->pdo->prepare(
            "SELECT id, $uidCol FROM marketing_contacts WHERE org_id=? AND email_normalized=? LIMIT 1"
        );
        $st->execute([$orgId, $email]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            if ($this->hasUserCol() && ($existing['user_id'] ?? null) === null && $uid > 0
                && !$this->hasOrgLinkedUserContact($uid)) {
                $this->pdo->prepare('UPDATE marketing_contacts SET user_id=? WHERE id=?')
                    ->execute([$uid, (int)$existing['id']]);
            }
            return false;
        }

        $first = trim((string)($user['first_name'] ?? ''));
        $last = trim((string)($user['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        $uid = (int)($user['id'] ?? 0);
        // Stamp user_id only for the user's first org-linked contact.
        $stampUid = ($this->hasUserCol() && $uid > 0 && !$this->hasOrgLinkedUserContact($uid)) ? $uid : 0;
        if ($stampUid > 0) {
            $this->pdo->prepare(
                "INSERT INTO marketing_contacts
                 (org_id, user_id, first_name, last_name, full_name, email, email_normalized,
                  title, status, source, version)
                 VALUES (?,?,?,?,?,?,?,'Practice Owner','Signed Up','practice_registration',1)"
            )->execute([
                $orgId, $stampUid, $first !== '' ? $first : null, $last !== '' ? $last : null,
                $full !== '' ? $full : null, $email, $email,
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO marketing_contacts
                 (org_id, first_name, last_name, full_name, email, email_normalized,
                  title, status, source, version)
                 VALUES (?,?,?,?,?,?,'Practice Owner','Signed Up','practice_registration',1)"
            )->execute([
                $orgId, $first !== '' ? $first : null, $last !== '' ? $last : null,
                $full !== '' ? $full : null, $email, $email,
            ]);
        }
        $cid = (int)$this->pdo->lastInsertId();
        $this->event($cid, $orgId, 'contact_created',
            ['source' => 'practice_registration', 'practice' => $practiceName]);
        return true;
    }

    private function event(?int $contactId, ?int $orgId, string $type, array $details = []): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $contactId, $orgId, substr($type, 0, 40),
                $details === [] ? null : json_encode($details),
                substr($this->actor, 0, 190), 'practice_registration',
            ]);
        } catch (Throwable $e) {
            error_log('[practice-registration-sync] event: ' . $e->getMessage());
        }
    }
}
