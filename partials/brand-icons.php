<?php
/**
 * Favicon / application icon <link> tags shared by every page <head>.
 * Set $brandIconPrefix before including to prepend a base URL; the default
 * emits page-relative paths.
 */
require_once __DIR__ . '/../api/brand-assets.php';
$brandIconPrefix = isset($brandIconPrefix) ? (string)$brandIconPrefix : '';
$brandIconHref = static function (string $key) use ($brandIconPrefix): string {
    return htmlspecialchars(brandAssetUrl($key, $brandIconPrefix), ENT_QUOTES, 'UTF-8');
};
?>
<link rel="icon" type="image/x-icon" href="<?= $brandIconHref('favicon_ico') ?>">
<link rel="icon" type="image/png" sizes="48x48" href="<?= $brandIconHref('favicon_48') ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $brandIconHref('favicon_32') ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $brandIconHref('favicon_16') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $brandIconHref('apple_touch_icon') ?>">
<link rel="manifest" href="<?= htmlspecialchars($brandIconPrefix, ENT_QUOTES, 'UTF-8') ?>site.webmanifest">
