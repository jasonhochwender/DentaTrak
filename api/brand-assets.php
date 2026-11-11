<?php
/**
 * Central registry for the approved DentaTrak brand assets.
 *
 * Every logo, favicon, and application icon lives in images/brand/ and is
 * referenced through this file, so swapping artwork is a one-directory
 * change. Variants:
 *   - logo_standard: tooth/orbit icon + navy "Denta" / teal "Trak".
 *                    Light backgrounds, public pages, marketing email.
 *   - logo_inverse:  white/teal icon + all-white wordmark.
 *                    Dark or colored backgrounds (login hero panel).
 *   - icon:          tooth/orbit symbol only. Compact spaces; source for
 *                    the favicon and application icons.
 *
 * Images are served immutable for a year (.htaccess), so page URLs carry
 * a content-derived ?v= tag via appAssetUrl().
 */

require_once __DIR__ . '/asset-url.php';

const BRAND_ASSET_DIR = 'images/brand/';
const BRAND_PUBLIC_ORIGIN = 'https://dentatrak.com';
const BRAND_ASSETS = [
    'logo_standard'    => 'dentatrak-logo-standard.png',
    'logo_inverse'     => 'dentatrak-logo-inverse.png',
    'icon'             => 'dentatrak-icon.png',
    'favicon_ico'      => 'favicon.ico',
    'favicon_16'       => 'favicon-16.png',
    'favicon_32'       => 'favicon-32.png',
    'favicon_48'       => 'favicon-48.png',
    'apple_touch_icon' => 'apple-touch-icon-180.png',
    'icon_192'         => 'icon-192.png',
    'icon_512'         => 'icon-512.png',
    'icon_maskable'    => 'icon-maskable-512.png',
];

/** Repo-relative path of a brand asset, e.g. images/brand/dentatrak-icon.png. */
function brandAssetPath(string $key): string
{
    if (!isset(BRAND_ASSETS[$key])) {
        throw new InvalidArgumentException('Unknown brand asset: ' . $key);
    }
    return BRAND_ASSET_DIR . BRAND_ASSETS[$key];
}

/** Cache-busted page URL for a brand asset; $prefix is the page's base URL. */
function brandAssetUrl(string $key, string $prefix = ''): string
{
    return $prefix . appAssetUrl(brandAssetPath($key));
}

/**
 * Absolute production URL for contexts that cannot use relative paths
 * (email clients, structured data).
 */
function brandAssetPublicUrl(string $key): string
{
    return BRAND_PUBLIC_ORIGIN . '/' . brandAssetPath($key);
}
