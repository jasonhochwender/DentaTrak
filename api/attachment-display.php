<?php
/**
 * Shared safe display-name resolution for case attachments.
 *
 * Mirrors resolveAttachmentDisplayName() in js/app.js: new records always
 * store the original browser filename in fileName/name, but some older
 * records carry a storage-path-derived value instead - either a raw object
 * path (cases/{practice}/{case}/{type}/{uuid}-{name}) or the same path with
 * slashes flattened to underscores (cases_20_...). The raw or flattened
 * path is internal data and is never returned as a user-facing name; when
 * the stored value looks like one of those shapes the original filename is
 * recovered from the "{uuid}-{name}" tail the upload pipeline writes.
 *
 * Returns null when no safe name exists; callers decide the fallback label.
 */

/**
 * @param array $attachment Attachment record (fileName/name fields)
 * @return string|null Safe display name, or null when none can be resolved
 */
function resolveAttachmentDisplayName($attachment): ?string {
    if (!is_array($attachment)) {
        return null;
    }
    $raw = '';
    if (isset($attachment['fileName']) && is_string($attachment['fileName']) && trim($attachment['fileName']) !== '') {
        $raw = trim($attachment['fileName']);
    } elseif (isset($attachment['name']) && is_string($attachment['name']) && trim($attachment['name']) !== '') {
        $raw = trim($attachment['name']);
    }
    if ($raw === '') {
        return null;
    }

    $isPathLike = (strpos($raw, '/') !== false || strpos($raw, '\\') !== false)
        || preg_match('/^cases_\d+(_|$)/', $raw) === 1;
    if (!$isPathLike) {
        return $raw;
    }

    // Recover the sanitized original filename that follows the server-
    // generated {uuid}- prefix (16-32 hex chars), from either the basename
    // of a raw path or a flattened "_uuid-name" segment.
    $base = basename(str_replace('\\', '/', $raw));
    if (preg_match('/^[a-f0-9]{13,32}-(.+)$/', $base, $m) !== 1
        && preg_match('#(?:^|[\\\\/_])[a-f0-9]{13,32}-(.+)$#', $raw, $m) !== 1) {
        return null;
    }
    $recovered = trim($m[1]);
    return $recovered !== '' ? $recovered : null;
}
