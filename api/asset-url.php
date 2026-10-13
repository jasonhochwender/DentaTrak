<?php
/**
 * Asset URL cache-busting helper.
 *
 * Static assets are served with Cache-Control "immutable, max-age=1 year"
 * (.htaccess), so a ?v= tag that is not bumped on every change leaves users
 * running stale JS indefinitely. Deriving the tag from the file content
 * makes bumping automatic: unchanged files stay cached, changed files get
 * a new URL on the next page load.
 *
 * Format: "<path>?v=<Ymd of mtime><first 6 of md5>" — keeps the existing
 * date-prefixed convention while distinguishing same-day changes.
 *
 * @param string $path Repo-relative asset path, e.g. 'js/gcs-upload.js'
 * @return string URL path with cache-busting query
 */
function appAssetUrl($path) {
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $file = dirname(__DIR__) . '/' . ltrim((string)$path, '/');
    $v = '0';
    if (is_file($file)) {
        $v = date('Ymd', filemtime($file)) . substr(md5_file($file), 0, 6);
    }
    return $cache[$path] = $path . '?v=' . $v;
}
