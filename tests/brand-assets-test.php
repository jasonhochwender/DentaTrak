<?php
// Brand asset contract test. No database: verifies the central asset
// registry, generated icon sizes, page references, the web manifest, the
// login/landing logo variants, and the marketing email footer.

$root = dirname(__DIR__);
require_once $root . '/api/brand-assets.php';
require_once $root . '/api/marketing/MarketingFooter.php';

$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

// ── Registry + files ──────────────────────────────────────────────────
$expected = [
    'logo_standard'    => [1024, 341],
    'logo_inverse'     => [1024, 341],
    'icon'             => [1024, 1024],
    'favicon_16'       => [16, 16],
    'favicon_32'       => [32, 32],
    'favicon_48'       => [48, 48],
    'apple_touch_icon' => [180, 180],
    'icon_192'         => [192, 192],
    'icon_512'         => [512, 512],
    'icon_maskable'    => [512, 512],
];
foreach ($expected as $key => [$w, $h]) {
    $path = $root . '/' . brandAssetPath($key);
    $info = is_file($path) ? getimagesize($path) : false;
    ok($info !== false, "$key exists");
    ok($info && $info[0] === $w && $info[1] === $h, "$key is {$w}x{$h}");
    ok($info && $info['mime'] === 'image/png', "$key is a real PNG");
}
ok(strpos(brandAssetPath('logo_standard'), 'images/brand/dentatrak-logo-standard.png') === 0, 'standard logo filename');
ok(strpos(brandAssetPath('logo_inverse'), 'images/brand/dentatrak-logo-inverse.png') === 0, 'inverse logo filename');
ok(strpos(brandAssetPath('icon'), 'images/brand/dentatrak-icon.png') === 0, 'icon filename');
ok(is_file($root . '/' . brandAssetPath('favicon_ico')), 'brand favicon.ico exists');
ok(is_file($root . '/favicon.ico')
    && md5_file($root . '/favicon.ico') === md5_file($root . '/' . brandAssetPath('favicon_ico')),
    'root favicon.ico (default browser request) matches the brand favicon');
ok(preg_match('/^images\/brand\/dentatrak-icon\.png\?v=\d{8}[0-9a-f]{6}$/', brandAssetUrl('icon')) === 1, 'page URLs are cache-busted');
ok(brandAssetUrl('icon', 'https://x.test/') === 'https://x.test/' . brandAssetUrl('icon'), 'prefix applied');
ok(brandAssetPublicUrl('logo_standard') === 'https://dentatrak.com/images/brand/dentatrak-logo-standard.png', 'public URL is absolute and unversioned');
$threw = false;
try { brandAssetPath('nope'); } catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'unknown asset key rejected');

// Transparency: logos + icon + favicons keep alpha; solid-background app
// icons (iOS / maskable) are fully opaque.
if (function_exists('imagecreatefrompng')) {
    $corner = function (string $key) use ($root) {
        $im = imagecreatefrompng($root . '/' . brandAssetPath($key));
        $c = imagecolorsforindex($im, imagecolorat($im, 0, 0));
        return $c;
    };
    foreach (['logo_standard', 'logo_inverse', 'icon', 'favicon_32'] as $k) {
        ok($corner($k)['alpha'] === 127, "$k has a transparent background");
    }
    foreach (['apple_touch_icon', 'icon_192', 'icon_512', 'icon_maskable'] as $k) {
        $c = $corner($k);
        ok($c['alpha'] === 0 && $c['red'] === 255 && $c['green'] === 255 && $c['blue'] === 255, "$k has an opaque white background");
    }
}

// ── Page references ───────────────────────────────────────────────────
$pages = array_filter(glob($root . '/*.php'), function ($f) { return basename($f)[0] !== '_'; });
$withPartial = 0; $hardcoded = []; $legacy = [];
foreach ($pages as $f) {
    $s = file_get_contents($f);
    if (strpos($s, "partials/brand-icons.php") !== false) { $withPartial++; }
    if (preg_match('/<link rel="(icon|apple-touch-icon|shortcut icon)"/', $s)) { $hardcoded[] = basename($f); }
    if (preg_match('/images\/(main|logo|logo-large|icon-small|icon-192|icon-512|icon-maskable-512|apple-touch-icon)\.png|favicon-(16x16|32x32)\.png/', $s)) { $legacy[] = basename($f); }
}
ok($withPartial >= 37, "shared icon partial used by all page heads ($withPartial)");
ok(count($hardcoded) === 0, 'no hard-coded favicon links remain' . ($hardcoded ? ': ' . implode(',', $hardcoded) : ''));
ok(count($legacy) === 0, 'no legacy logo/icon references remain' . ($legacy ? ': ' . implode(',', $legacy) : ''));

$partial = file_get_contents($root . '/partials/brand-icons.php');
foreach (['favicon_ico', 'favicon_48', 'favicon_32', 'favicon_16', 'apple_touch_icon'] as $k) {
    ok(strpos($partial, "'$k'") !== false, "partial links $k");
}
ok(strpos($partial, 'site.webmanifest') !== false, 'partial keeps the manifest link');
ob_start(); require $root . '/partials/brand-icons.php'; $rendered = ob_get_clean();
ok(substr_count($rendered, '<link ') === 6, 'partial renders 6 link tags');
ok(strpos($rendered, 'sizes="180x180"') !== false && strpos($rendered, 'images/brand/apple-touch-icon-180.png?v=') !== false, 'apple touch icon rendered with version');

// ── Manifest ──────────────────────────────────────────────────────────
$manifest = json_decode(file_get_contents($root . '/site.webmanifest'), true);
ok(is_array($manifest) && count($manifest['icons'] ?? []) === 3, 'manifest keeps three icons');
foreach (($manifest['icons'] ?? []) as $icon) {
    $p = $root . $icon['src'];
    $info = is_file($p) ? getimagesize($p) : false;
    ok(strpos($icon['src'], '/images/brand/') === 0, "manifest icon in brand dir: {$icon['src']}");
    ok($info && ($info[0] . 'x' . $info[1]) === $icon['sizes'], "manifest icon size matches: {$icon['src']}");
}
ok(($manifest['display'] ?? '') === 'standalone' && ($manifest['name'] ?? '') === 'DentaTrak', 'manifest behavior preserved');
$purposes = array_column($manifest['icons'] ?? [], 'purpose');
ok(in_array('maskable', $purposes, true) && in_array('any', $purposes, true), 'manifest keeps any + maskable purposes');

// ── Landing page: Standard Logo, link behavior preserved ──────────────
$index = file_get_contents($root . '/index.php');
ok(preg_match('/<a href="<\?= \$baseUrl \?>" class="site-logo"[^\n]*\n\s*<img src="<\?= htmlspecialchars\(brandAssetUrl\(\'logo_standard\', \$baseUrl\)\) \?>"/', $index) === 1, 'landing nav uses Standard Logo inside the home link');
ok(strpos($index, 'logo_inverse') === false, 'landing does not use the inverse logo');
ok(substr_count($index, "brandAssetPublicUrl('logo_standard')") === 2, 'structured data logo + image use the Standard Logo');
ok(strpos($index, '.site-logo img { height: auto; width: auto; max-width: 140px;') !== false, 'landing logo sizing unchanged');

// ── Login page: Inverse Logo, panel content preserved ─────────────────
$login = file_get_contents($root . '/login.php');
ok(strpos($login, "brandAssetUrl('logo_inverse')") !== false, 'login uses Inverse Logo');
ok(strpos($login, 'logo_standard') === false, 'login does not use the standard logo');
ok(preg_match('/<h1 class="hero-title hero-title--logo">\s*<img [^\n]*alt="<\?php echo htmlspecialchars\(\$appName\); \?>"/', $login) === 1, 'login h1 keeps an accessible name');
foreach (["t('auth.login.headline')", "t('auth.login.tagline')", "t('auth.login.feature_1')", "t('auth.login.feature_2')", "t('auth.login.hipaa_compliant')"] as $needle) {
    ok(strpos($login, $needle) !== false, "login panel keeps $needle");
}
$loginCss = file_get_contents($root . '/css/login.css');
ok(strpos($loginCss, '.hero-brand-logo') !== false && strpos($loginCss, '.hero-brand-icon') === false, 'login css sized for the logo lockup');
ok(preg_match('/\.hero-brand-logo\s*\{[^}]*filter/', $loginCss) === 0, 'no filter/effects applied to the logo');

// ── Other light-background usage ──────────────────────────────────────
ok(strpos(file_get_contents($root . '/practice-setup.php'), "brandAssetUrl('logo_standard')") !== false, 'practice setup uses Standard Logo');
ok(strpos(file_get_contents($root . '/about.php'), "brandAssetUrl('logo_standard', \$baseUrl)") !== false, 'about uses Standard Logo');

// ── Marketing email footer ────────────────────────────────────────────
$u = 'https://dentatrak.com/unsubscribe.php?t=abc123&x=1';
$html = MarketingFooter::html($u);
$text = MarketingFooter::text($u);
$year = gmdate('Y');
ok(strpos($html, 'src="https://dentatrak.com/images/brand/dentatrak-logo-standard.png"') !== false, 'footer logo is the public Standard Logo URL');
ok(preg_match('/<a href="https:\/\/dentatrak\.com"[^>]*><img /', $html) === 1, 'footer logo links to dentatrak.com');
ok(strpos($html, 'width="150" height="50"') !== false, 'footer logo has explicit undistorted dimensions (3:1)');
ok(abs((150 / 50) - (1024 / 341)) < 0.01, 'footer logo ratio matches the artwork');
ok(strpos($html, 'text-align:center') !== false && strpos($html, 'margin:12px auto') !== false, 'footer content and logo centered');
ok(strpos($html, "&copy; $year DentaTrak LLC. All rights reserved.") !== false, 'copyright line');
ok(strpos($html, 'DentaTrak<br>PO Box 1<br>Tryon, NC 28782') !== false, 'mailing address');
ok(strpos($html, 'If you no longer wish to receive these emails, you can <a href="' . htmlspecialchars($u, ENT_QUOTES) . '"') !== false, 'unsubscribe sentence links the recipient-specific URL');
ok(preg_match('/>unsubscribe here<\/a>\./', $html) === 1, '"unsubscribe here" is the link text');
ok(stripos($html, 'mailto:unsubscribe') === false && substr_count($html, 'mailto:') === 1, 'unsubscribe is not a mailto (only Contact Us is)');
foreach (['Privacy Policy' => 'https://dentatrak.com/privacy', 'Contact Us' => 'mailto:support@dentatrak.com', 'All Resources' => 'https://dentatrak.com/resources'] as $label => $href) {
    ok(strpos($html, '<a href="' . $href . '"') !== false && strpos($html, '>' . $label . '</a>') !== false, "footer link: $label");
}
foreach (MarketingFooter::ARTICLES as $label => $href) {
    $slug = basename(parse_url($href, PHP_URL_PATH));
    ok(is_file($root . '/' . $slug . '.php'), "article destination exists: $slug");
    ok(strpos($html, '<a href="' . $href . '"') !== false, "footer links article: $label");
}
ok(is_file($root . '/resources.php') && is_file($root . '/privacy.php'), 'resources + privacy destinations exist');
$order = array_map(function ($n) use ($html) { return strpos($html, $n); },
    ['Privacy Policy', '&copy;', 'PO Box 1', '<img ', 'If you no longer wish']);
$sorted = $order; sort($sorted);
ok(!in_array(false, $order, true) && $order === $sorted, 'footer order: links, copyright, address, logo, unsubscribe');
ok(strpos($text, "(c) $year DentaTrak LLC. All rights reserved.") !== false && strpos($text, $u) !== false, 'plain-text footer carries copyright + unsubscribe URL');

// Unsubscribe mechanics untouched.
$hdr = MarketingFooter::headers($u);
ok($hdr === ['List-Unsubscribe' => '<' . $u . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], 'RFC 8058 headers unchanged');
$mailer = file_get_contents($root . '/api/marketing/MarketingMailer.php');
ok(strpos($mailer, "'/unsubscribe.php?t='") !== false, 'mailer still builds the recipient-specific unsubscribe URL');
ok(strpos($mailer, 'MarketingFooter::headers(') !== false && strpos($mailer, 'MarketingFooter::html(') !== false, 'mailer still applies footer + headers');
ok(is_file($root . '/unsubscribe.php') && is_file($root . '/api/unsubscribe-one-click.php'), 'unsubscribe endpoints present');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
