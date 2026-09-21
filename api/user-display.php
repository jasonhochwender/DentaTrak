<?php
/**
 * Shared user display-name resolution.
 *
 * Practice members are provisioned with an email-only users row (see
 * api/save-settings.php) and may never have first/last names set, so any
 * surface that renders a user identity (case creator, reviewer, comment
 * author, notification actor) must use the same fallback order:
 *
 *   "First Last"  ->  email  ->  generic label
 *
 * This keeps a valid user_id from rendering as 'Unknown' while the account
 * exists, and matches the existing convention already used by
 * api/admin-practices.php and api/case-comments.php.
 */
function formatUserDisplayName($firstName, $lastName, $email = null, string $fallback = 'Unknown'): string {
    $name = trim(trim((string)$firstName) . ' ' . trim((string)$lastName));
    if ($name !== '') {
        return $name;
    }
    $email = trim((string)$email);
    return $email !== '' ? $email : $fallback;
}
