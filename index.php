<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/feature-flags.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$showLabInsights = isFeatureEnabled('SHOW_LAB_INSIGHTS');
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
require_once __DIR__ . '/api/csrf.php';
$csrfToken = generateCsrfToken();
// Server-side render timestamp for the demo-request timing check:
// api/demo-request.php rejects submissions that arrive implausibly fast.
$_SESSION['demo_form_rendered_at'] = time();
$hipaaUrl = $baseUrl . ($articleUrls['page_hipaa_security'] ?? 'hipaa-security');
$softwareOffers = [];
foreach (['operate', 'control', 'scale'] as $plan) {
    foreach (['month' => 'P1M', 'year' => 'P1Y'] as $interval => $duration) {
        $price = $appConfig['stripe']['display_prices'][$plan][$interval] / 100;
        $softwareOffers[] = [
            '@type' => 'Offer',
            'name' => t('marketing.pricing.' . $plan) . ($interval === 'month' ? ' (monthly billing)' : ' (annual billing)'),
            'url' => 'https://dentatrak.com/#pricing',
            'price' => $price,
            'priceCurrency' => 'USD',
            'description' => t('marketing.pricing.' . $plan . '_description'),
            'seller' => ['@id' => 'https://dentatrak.com/#organization'],
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => $price,
                'priceCurrency' => 'USD',
                'billingDuration' => $duration,
            ],
        ];
    }
}
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Google Analytics -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-MBJDENR3H2"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-MBJDENR3H2');
  </script>
  <?php require_once __DIR__ . '/partials/clarity.php'; ?>

  <meta name="description" content="<?php echo htmlspecialchars(t('marketing.seo.index.description')); ?>">
  <title><?php echo htmlspecialchars(t('marketing.seo.index.title')); ?></title>
  <link rel="canonical" href="https://dentatrak.com/">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Structured Data: Organization, WebSite, SoftwareApplication -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        "@id": "https://dentatrak.com/#organization",
        "name": <?php echo json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "url": "https://dentatrak.com/",
        "logo": "https://dentatrak.com/images/logo-large.png",
        "founder": { "@id": "https://dentatrak.com/about#william-verrillo" },
        "email": <?php echo json_encode(t('marketing.footer.support_email'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
      },
      {
        "@type": "WebSite",
        "@id": "https://dentatrak.com/#website",
        "name": <?php echo json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "url": "https://dentatrak.com/",
        "publisher": { "@id": "https://dentatrak.com/#organization" }
      },
      {
        "@type": "SoftwareApplication",
        "@id": "https://dentatrak.com/#software",
        "name": <?php echo json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "applicationCategory": "BusinessApplication",
        "operatingSystem": "Web",
        "description": <?php echo json_encode(t('marketing.seo.index.description'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "url": "https://dentatrak.com/",
        "publisher": { "@id": "https://dentatrak.com/#organization" },
        "creator": { "@id": "https://dentatrak.com/about#william-verrillo" },
        "image": "https://dentatrak.com/images/logo-large.png",
        "featureList": [
          "Visual dental case workflow tracking",
          "Customizable workflow stages",
          "Case status, ownership, and assignments",
          "Due dates, late cases, and appointment-risk visibility",
          "Lab activity and turnaround tracking",
          "Case files and supported 3D dental files",
          "Shipping information",
          "Practice and Lab Insights on Control and Scale plans",
          "Recommendations on Control and Scale plans",
          "Ask DentaTrak in-product assistant",
          "Open Dental lab case integration on Control and Scale plans",
          "In-app notifications",
          "Case comments and @mentions",
          "Interface in nine languages",
          "Mobile-browser access",
          "Role-based access and secure file access"
        ],
        "offers": <?php echo json_encode($softwareOffers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
      }
    ]
  }
  </script>

  <style>
    :root {
      --dt-warm: #fdfbf7;
      --dt-cream: #f7f4ef;
      --dt-pale: #f2f6fc;
      --dt-ink: #111827;
      --dt-ink-secondary: #3d464f;
      --dt-ink-muted: #6b7280;
      --dt-blue: #1e40af;
      --dt-blue-light: #2563eb;
      --dt-blue-dark: #1e3a8a;
      --dt-cyan: #06b6d4;
      --dt-border: #e8e4df;
      --dt-radius: 18px;
      --dt-radius-sm: 12px;
      --dt-shadow: 0 22px 55px -14px rgba(0,0,0,0.1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Poppins', 'Inter', 'Noto Sans KR', system-ui, -apple-system, sans-serif; background: var(--dt-warm); color: var(--dt-ink); line-height: 1.6; }
    img { max-width: 100%; height: auto; display: block; }
    a { text-decoration: none; color: inherit; }

    .container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 24px; }
    .section { padding: 96px 0; }

    .eyebrow { display: inline-block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--dt-blue); margin-bottom: 16px; }
    h2 { font-size: clamp(1.9rem, 4vw, 2.8rem); font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 18px; }
    .lead { font-size: 1.1rem; color: var(--dt-ink-secondary); line-height: 1.65; max-width: 680px; }

    .btn { display: inline-flex; align-items: center; justify-content: center; padding: 13px 28px; border-radius: 100px; font-size: 0.95rem; font-weight: 600; transition: transform 0.15s ease, background 0.2s ease, border-color 0.2s ease; }
    .btn:hover { transform: translateY(-1px); }
    .btn-primary { background: var(--dt-blue); color: #fff; }
    .btn-primary:hover { background: var(--dt-blue-light); }
    .btn-secondary { background: #fff; color: var(--dt-ink); border: 1px solid var(--dt-border); }
    .btn-secondary:hover { border-color: var(--dt-blue); color: var(--dt-blue); }

    /* Header */
    .site-header { position: fixed; top: 0; left: 0; right: 0; z-index: 100; background: rgba(253,251,247,0.95); border-bottom: 1px solid var(--dt-border); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
    .site-header .container { height: 72px; display: flex; align-items: center; justify-content: space-between; }
    .site-logo img { height: auto; width: auto; max-width: 140px; object-fit: contain; display: block; }
    .site-nav { display: flex; align-items: center; gap: 20px; }
    .site-nav .nav-divider { height: 20px; border-inline-start: 1px solid var(--dt-border); flex-shrink: 0; }
    .site-nav a { font-size: 0.9rem; font-weight: 500; color: var(--dt-ink-secondary); white-space: nowrap; }
    .site-nav a:hover { color: var(--dt-blue); }
    .site-nav a.nav-cta,
    .nav-cta, .nav-cta:visited { background: var(--dt-blue); color: #fff; padding: 9px 20px; border-radius: 100px; font-weight: 600; }
    .site-nav a.nav-cta:hover, .nav-cta:hover { background: var(--dt-blue-light); color: #fff; }
    .nav-cta { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; white-space: nowrap; flex-shrink: 0; text-decoration: none; }

    /* Collapsed header: hamburger toggle + dropdown panel */
    .nav-collapsed { display: none; align-items: center; gap: 12px; }
    .nav-toggle { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; padding: 0; background: #fff; border: 1px solid var(--dt-border); border-radius: 10px; color: var(--dt-ink); cursor: pointer; }
    .nav-toggle:hover { border-color: var(--dt-blue); color: var(--dt-blue); }
    .nav-toggle:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }
    .nav-menu { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border-bottom: 1px solid var(--dt-border); box-shadow: 0 18px 40px -18px rgba(0,0,0,0.18); padding: 10px 0 18px; }
    .nav-menu[hidden] { display: none; }
    .nav-menu a.nav-link { display: block; padding: 12px 28px; font-size: 1rem; font-weight: 500; color: var(--dt-ink-secondary); text-decoration: none; }
    .nav-menu a.nav-link:hover, .nav-menu a.nav-link:focus-visible { color: var(--dt-blue); background: var(--dt-pale); outline: none; }
    .nav-menu .nav-menu-lang { padding: 10px 28px 4px; border-top: 1px solid var(--dt-border); margin-top: 6px; }

    /* Below this width the full nav (links + Sign In + Start Free +
       language selector) no longer fits on one line - collapse it. */
    @media (max-width: 1220px) {
      .site-nav { display: none; }
      .nav-collapsed { display: flex; }
    }

    /* Hero */
    .hero { padding: 150px 0 80px; text-align: center; }
    .hero h1 { font-size: clamp(2rem, 4.6vw, 3.6rem); font-weight: 700; line-height: 1.12; letter-spacing: -0.03em; max-width: 880px; margin: 0 auto 24px; }
    .hero .lead { margin: 0 auto 34px; }
    .hero-actions { display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; margin-bottom: 44px; }

    /* Product board */
    .product-preview { background: #fff; border: 1px solid var(--dt-border); border-radius: 24px; box-shadow: var(--dt-shadow); overflow: hidden; text-align: left; }
    .app-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: #fafafa; border-bottom: 1px solid var(--dt-border); }
    .app-brand { display: flex; align-items: center; gap: 10px; font-size: 1rem; font-weight: 700; }
    .app-brand img { height: auto; width: auto; max-width: 105px; object-fit: contain; display: block; }
    .app-practice { font-size: 0.78rem; color: var(--dt-ink-muted); padding: 4px 10px; background: #fff; border: 1px solid var(--dt-border); border-radius: 100px; }
    .app-header-right { display: flex; align-items: center; gap: 8px; }
    .app-tabs { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #fafafa; border-bottom: 1px solid var(--dt-border); font-size: 0.85rem; font-weight: 600; }
    .app-tab { padding: 6px 12px; border-radius: 8px; color: var(--dt-ink-muted); background: transparent; border: 1px solid transparent; font: inherit; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease; }
    .app-tab:hover { color: var(--dt-ink); }
    .app-tab:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }
    .app-tab.active { background: #fff; color: var(--dt-ink); border: 1px solid var(--dt-border); }
    .app-filter { margin-left: auto; font-size: 0.78rem; color: var(--dt-ink-muted); font-weight: 500; }
    .app-views { position: relative; min-height: 420px; transition: height 0.4s ease; }
    .app-view { position: absolute; top: 0; left: 0; width: 100%; opacity: 0; transform: translateY(12px); pointer-events: none; transition: opacity 0.4s ease, transform 0.4s ease; }
    .app-view.active { opacity: 1; transform: translateY(0); pointer-events: auto; z-index: 1; }
    .app-board { padding: 20px; }

    /* Board / List view toggle in the product preview */
    .demo-toolbar { display: flex; justify-content: flex-end; margin-bottom: 14px; }
    .demo-view-toggle { display: inline-flex; background: #fff; border: 1px solid var(--dt-border); border-radius: 8px; padding: 2px; gap: 2px; }
    .demo-view-toggle button { padding: 4px 14px; font-size: 0.72rem; font-weight: 600; font-family: inherit; border: none; background: transparent; color: var(--dt-ink-muted); border-radius: 6px; cursor: pointer; transition: background 0.15s ease, color 0.15s ease; }
    .demo-view-toggle button.active { background: var(--dt-blue); color: #fff; }
    .demo-view-toggle button:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }

    /* Demo List view: compact sortable rows with one expanded detail row */
    .demo-list { border: 1px solid var(--dt-border); border-radius: 10px; overflow: hidden; background: #fff; }
    .demo-list-row { display: grid; grid-template-columns: 18px minmax(0,1.3fr) minmax(0,0.6fr) minmax(0,1fr) minmax(0,0.7fr) minmax(0,0.95fr); align-items: center; gap: 8px; padding: 9px 12px; border-bottom: 1px solid #eef0f3; font-size: 0.75rem; color: var(--dt-ink-secondary); }
    .demo-list-row.demo-list-head { background: #fafafa; font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dt-ink-muted); padding-top: 8px; padding-bottom: 8px; }
    .demo-list-row:last-child { border-bottom: none; }
    .demo-caret { color: var(--dt-ink-muted); font-size: 0.62rem; }
    .demo-list-name { font-weight: 700; color: var(--dt-ink); }
    .demo-list-status { display: inline-block; padding: 2px 8px; font-size: 0.6rem; font-weight: 600; border-radius: 999px; background: #f3f4f6; color: var(--dt-ink-secondary); white-space: nowrap; }
    .demo-list-status.is-late { background: #fee2e2; color: #b91c1c; }
    .demo-list-status.is-due { background: #eff6ff; color: #1d4ed8; }
    .review-chip { display: inline-block; font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 2px 7px; border-radius: 999px; white-space: nowrap; }
    .review-chip.needs-review { background: #fef3c7; color: #92400e; }
    .review-chip.reviewed { background: #d1fae5; color: #065f46; }
    .demo-list-detail { background: #f9fafb; padding: 10px 12px 10px 38px; border-bottom: 1px solid #eef0f3; display: flex; flex-wrap: wrap; gap: 4px 22px; font-size: 0.7rem; color: var(--dt-ink-muted); line-height: 1.5; }
    .demo-list-detail strong { color: var(--dt-ink-secondary); font-weight: 600; }

    .board { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; }
    .board-col { min-width: 0; }
    .board-stage { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 10px 0; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dt-ink-muted); border-top: 3px solid var(--dt-border); border-bottom: 1px solid var(--dt-border); margin-bottom: 10px; line-height: 1.35; }
    .stage-count { font-size: 0.6rem; background: #f3f4f6; padding: 2px 6px; border-radius: 100px; flex-shrink: 0; }
    .stage-originated { border-top-color: #64748b; }
    .stage-lab { border-top-color: #3b82f6; }
    .stage-designed { border-top-color: #8b5cf6; }
    .stage-manufactured { border-top-color: #f59e0b; }
    .stage-received { border-top-color: #14b8a6; }
    .stage-delivered { border-top-color: #22c55e; }

    .case-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 10px; padding: 10px; margin-bottom: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .case-card:last-child { margin-bottom: 0; }
    .case-card h4 { font-size: 0.82rem; font-weight: 700; margin-bottom: 3px; }
    .case-type { font-size: 0.7rem; color: var(--dt-ink-muted); margin-bottom: 6px; }
    .case-meta { font-size: 0.68rem; color: var(--dt-ink-muted); line-height: 1.4; }
    .case-flag { display: inline-block; margin-top: 6px; padding: 2px 6px; font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; border-radius: 999px; }
    .flag-due { color: #1d4ed8; background: #eff6ff; }
    .flag-late { color: #b91c1c; background: #fee2e2; }
    .flag-appt { color: #7c3aed; background: #ede9fe; }
    .flag-needs-review { color: #92400e; background: #fef3c7; }
    .flag-reviewed { color: #065f46; background: #d1fae5; }
    .case-card.due { border-left: 3px solid #3b82f6; }
    .case-card.late { border-left: 3px solid #dc2626; }
    .case-card.appt { border-left: 3px solid #8b5cf6; }

    /* Demo Insights view */
    .demo-insights { padding: 24px 20px; }
    .di-header { margin-bottom: 20px; }
    .di-header h3 { font-size: 1.15rem; font-weight: 700; color: var(--dt-ink); margin-bottom: 2px; }
    .di-header p { font-size: 0.78rem; color: var(--dt-ink-muted); }

    .di-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
    .di-metric { background: #fff; border: 1px solid var(--dt-border); border-radius: 14px; padding: 16px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .di-metric:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -8px rgba(0,0,0,0.08); }
    .di-metric-value { font-size: 1.75rem; font-weight: 700; line-height: 1; margin-bottom: 6px; color: var(--dt-ink); }
    .di-metric-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dt-ink-muted); }
    .di-metric.due .di-metric-value { color: #1d4ed8; }
    .di-metric.late .di-metric-value { color: #dc2626; }
    .di-metric.delivered .di-metric-value { color: #15803d; }

    .di-charts { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
    .di-chart { background: #fff; border: 1px solid var(--dt-border); border-radius: 16px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .di-chart-title { font-size: 0.85rem; font-weight: 600; color: var(--dt-ink); margin-bottom: 12px; }
    .di-donut { width: 100%; height: 160px; }
    .di-donut svg { display: block; width: 100%; height: 100%; }
    .di-donut-track { fill: none; stroke: #f3f4f6; stroke-width: 10; }
    .di-donut-seg { fill: none; stroke-width: 10; stroke-linecap: round; transition: stroke-dasharray 0.8s ease, stroke-dashoffset 0.8s ease; }
    .di-legend { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 10px; font-size: 0.65rem; color: var(--dt-ink-muted); }
    .di-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .di-legend-dot { width: 7px; height: 7px; border-radius: 50%; }

    .di-line-chart { width: 100%; height: 160px; }
    .di-line-chart svg { display: block; width: 100%; height: 100%; overflow: visible; }
    .di-line-grid { stroke: #f3f4f6; stroke-width: 1; }
    .di-line-area { fill: url(#diAreaGradient); transition: opacity 0.6s ease; }
    .di-line-path { fill: none; stroke: var(--dt-blue); stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 1000; stroke-dashoffset: 1000; transition: stroke-dashoffset 1s ease; }
    .di-line-dot { fill: #fff; stroke: var(--dt-blue); stroke-width: 2; r: 3; opacity: 0; transition: opacity 0.3s ease; }
    .di-line-chart.animated .di-line-path { stroke-dashoffset: 0; }
    .di-line-chart.animated .di-line-dot { opacity: 1; }

    .di-split { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
    .di-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 16px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .di-card h4 { font-size: 0.78rem; font-weight: 600; color: var(--dt-ink-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 12px; }
    .di-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 0.7rem; }
    .di-bar:last-child { margin-bottom: 0; }
    .di-bar-label { width: 80px; color: var(--dt-ink-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .di-bar-track { flex: 1; height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; }
    .di-bar-fill { height: 100%; border-radius: 3px; width: 0; transition: width 0.7s ease; }
    .di-bar-value { width: 22px; text-align: right; color: var(--dt-ink); font-weight: 600; }
    .di-kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .di-kpi { background: #f9fafb; border-radius: 12px; padding: 12px; }
    .di-kpi-value { font-size: 1.3rem; font-weight: 700; color: var(--dt-ink); }
    .di-kpi-label { font-size: 0.68rem; color: var(--dt-ink-muted); }

    .di-smart { background: #fff; border: 1px solid var(--dt-border); border-left: 4px solid #f59e0b; border-radius: 16px; padding: 16px; display: flex; gap: 12px; align-items: flex-start; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .di-smart-icon { flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; }
    .di-smart-icon svg { width: 18px; height: 18px; stroke: currentColor; }
    .di-smart h4 { font-size: 0.8rem; font-weight: 700; margin-bottom: 4px; }
    .di-smart p { font-size: 0.75rem; color: var(--dt-ink-secondary); line-height: 1.5; }

    /* Problem visual animation */
    .problem-connections { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
    .problem-connections line { stroke-width: 1.5; stroke-linecap: round; opacity: 0; transition: opacity 0.5s ease; }
    .fragment { transition: transform 0.55s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.35s ease; }
    .problem-case { transition: transform 0.55s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.55s ease; }
    .problem-visual.is-converging .fragment { transition-duration: 0.8s; }
    .problem-visual.is-converging .problem-case { transform: translateY(-6px); box-shadow: var(--dt-shadow), 0 0 0 1px rgba(30,64,175,0.08); }

    /* Problem fragment outward spread on hover/enter */
    .problem-visual.is-active .f-1 { transform: translate(-10px, -10px) rotate(-3deg); }
    .problem-visual.is-active .f-2 { transform: translate(10px, -8px) rotate(3deg); }
    .problem-visual.is-active .f-3 { transform: translate(-14px, 4px) rotate(-2deg); }
    .problem-visual.is-active .f-4 { transform: translate(12px, 6px) rotate(2deg); }
    .problem-visual.is-active .f-5 { transform: translate(-8px, 14px) rotate(-1deg); }
    .problem-visual.is-active .f-6 { transform: translate(10px, 16px) rotate(2deg); }

    /* Problem connections visible when converged */
    .problem-visual.is-converging .problem-connections line { opacity: 0.65; }

    /* Problem visual reduced motion fallback */
    @media (prefers-reduced-motion: reduce) {
      .app-view, .app-views, .fragment, .problem-case, .problem-connections line { transition: none; }
      .di-line-path, .di-donut-seg, .di-bar-fill, .di-metric-value { transition: none; }
      .problem-visual.is-active .fragment { transform: none; }
      .problem-visual.is-converging .problem-case { transform: none; }
    }
    .problem { background: var(--dt-cream); }
    .problem-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
    .problem-visual { position: relative; min-height: 360px; display: grid; place-items: center; }
    .problem-case { background: #fff; border: 1px solid var(--dt-border); border-left: 4px solid var(--dt-blue); border-radius: 18px; padding: 26px; box-shadow: var(--dt-shadow); max-width: 300px; z-index: 2; }
    .problem-case h3 { font-size: 1.15rem; margin-bottom: 4px; }
    .problem-case p { font-size: 0.85rem; color: var(--dt-ink-muted); }
    .fragment { position: absolute; background: #fff; border: 1px solid var(--dt-border); border-radius: 12px; padding: 12px 14px; box-shadow: 0 10px 30px -12px rgba(0,0,0,0.08); font-size: 0.8rem; max-width: 200px; }
    .fragment strong { color: var(--dt-ink); }
    .fragment small { display: block; color: var(--dt-ink-muted); font-size: 0.72rem; margin-top: 4px; }
    .f-1 { top: 6%; left: 0; border-left: 3px solid #8b5cf6; }
    .f-2 { top: 12%; right: 0; border-left: 3px solid #3b82f6; }
    .f-3 { top: 40%; left: -6%; border-left: 3px solid #f59e0b; }
    .f-4 { top: 36%; right: -4%; border-left: 3px solid #22c55e; }
    .f-5 { bottom: 14%; left: 0; }
    .f-6 { bottom: 8%; right: 0; border-left: 3px solid #64748b; }

    /* Scattered-information animated illustration */
    .pv-stage { position: relative; width: 100%; max-width: 480px; height: 380px; overflow: hidden; margin: 0 auto; }
    .pv-trails { position: absolute; inset: 0; width: 100%; height: 100%; }
    .pv-trail { fill: none; stroke: #c7d3e6; stroke-width: 1.6; stroke-dasharray: 6 8; opacity: 0.7; }
    .pv-obj { position: absolute; will-change: transform; filter: drop-shadow(0 10px 14px rgba(30,58,95,0.16)); }
    .pv-obj svg { display: block; }
    .pv-wob { transform-origin: 50% 50%; }
    .pv-o1 { top: 2%; left: 6%; width: 60px; animation: pv-a1 5.4s ease-in-out infinite alternate; }
    .pv-o2 { top: 8%; right: 10%; width: 78px; animation: pv-a2 6.6s ease-in-out infinite alternate; }
    .pv-o3 { top: 30%; left: 2%; width: 82px; animation: pv-a3 4.6s ease-in-out infinite alternate; }
    .pv-o4 { top: 34%; right: 4%; width: 94px; animation: pv-a4 5.9s ease-in-out infinite alternate; }
    .pv-o5 { top: 56%; left: 10%; width: 80px; animation: pv-a5 4.2s ease-in-out infinite alternate; }
    .pv-o6 { top: 58%; right: 16%; width: 58px; animation: pv-a6 6.9s ease-in-out infinite alternate; }
    .pv-o7 { bottom: 4%; left: 30%; width: 70px; animation: pv-a7 5.1s ease-in-out infinite alternate; }
    .pv-o8 { bottom: 14%; right: 4%; width: 62px; animation: pv-a8 4.8s ease-in-out infinite alternate; }
    .pv-o9 { top: 6%; left: 40%; width: 74px; animation: pv-a9 6.3s ease-in-out infinite alternate; }
    .pv-o1 .pv-wob, .pv-o5 .pv-wob, .pv-o9 .pv-wob { animation: pv-wob 1.9s ease-in-out infinite; }
    .pv-o2 .pv-wob, .pv-o6 .pv-wob { animation: pv-wob 2.6s ease-in-out -0.7s infinite; }
    .pv-o3 .pv-wob, .pv-o7 .pv-wob { animation: pv-wob 1.6s ease-in-out -0.4s infinite; }
    .pv-o4 .pv-wob, .pv-o8 .pv-wob { animation: pv-wob 2.9s ease-in-out -1.1s infinite; }
    @keyframes pv-wob {
      0%,100% { transform: rotate(0deg); }
      30% { transform: rotate(4.5deg) translateY(-3px); }
      65% { transform: rotate(-4deg) translateY(2px); }
    }
    @keyframes pv-a1 { 0% { transform: translate(0,0) rotate(-7deg); } 50% { transform: translate(46px,26px) rotate(-2deg); } 100% { transform: translate(-14px,44px) rotate(-10deg); } }
    @keyframes pv-a2 { 0% { transform: translate(0,0) rotate(10deg); } 50% { transform: translate(-52px,20px) rotate(4deg); } 100% { transform: translate(-24px,-30px) rotate(13deg); } }
    @keyframes pv-a3 { 0% { transform: translate(0,0) rotate(-4deg); } 50% { transform: translate(60px,-24px) rotate(3deg); } 100% { transform: translate(30px,36px) rotate(-8deg); } }
    @keyframes pv-a4 { 0% { transform: translate(0,0) rotate(5deg); } 50% { transform: translate(-46px,30px) rotate(-3deg); } 100% { transform: translate(-70px,-16px) rotate(8deg); } }
    @keyframes pv-a5 { 0% { transform: translate(0,0) rotate(-9deg); } 50% { transform: translate(52px,-34px) rotate(2deg); } 100% { transform: translate(18px,28px) rotate(-5deg); } }
    @keyframes pv-a6 { 0% { transform: translate(0,0) rotate(8deg); } 50% { transform: translate(-38px,-44px) rotate(-2deg); } 100% { transform: translate(-58px,14px) rotate(11deg); } }
    @keyframes pv-a7 { 0% { transform: translate(0,0) rotate(-3deg); } 50% { transform: translate(48px,-26px) rotate(5deg); } 100% { transform: translate(-30px,-38px) rotate(-7deg); } }
    @keyframes pv-a8 { 0% { transform: translate(0,0) rotate(12deg); } 50% { transform: translate(-44px,26px) rotate(5deg); } 100% { transform: translate(-20px,-22px) rotate(15deg); } }
    @keyframes pv-a9 { 0% { transform: translate(0,0) rotate(-6deg); } 50% { transform: translate(38px,36px) rotate(4deg); } 100% { transform: translate(-44px,12px) rotate(-9deg); } }
    .pv-toggle { position: absolute; bottom: 6px; right: 6px; z-index: 3; background: rgba(255,255,255,0.9); border: 1px solid var(--dt-border); border-radius: 999px; padding: 4px 12px; font-size: 0.68rem; color: var(--dt-ink-muted); cursor: pointer; }
    .pv-toggle:hover, .pv-toggle:focus-visible { color: var(--dt-ink); border-color: var(--dt-blue); }
    .problem-visual.pv-paused .pv-obj,
    .problem-visual.pv-paused .pv-wob { animation-play-state: paused; }
    @media (prefers-reduced-motion: reduce) {
      .pv-obj, .pv-wob { animation: none !important; }
      .pv-toggle { display: none; }
    }
    @media (max-width: 720px) {
      .pv-stage { max-width: 340px; height: 300px; }
      .pv-obj { transform-origin: 50% 50%; }
      .pv-o4 { width: 76px; } .pv-o3 { width: 66px; } .pv-o5 { width: 64px; }
      .pv-o2 { width: 62px; } .pv-o9 { width: 58px; } .pv-o7 { width: 56px; }
    }

    /* How it works */
    .steps-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-top: 48px; }
    .step { background: #fff; border: 1px solid var(--dt-border); border-radius: var(--dt-radius); padding: 32px; position: relative; border-bottom: 3px solid var(--dt-border); }
    .step-1 { border-bottom-color: #3b82f6; }
    .step-2 { border-bottom-color: #8b5cf6; }
    .step-3 { border-bottom-color: #14b8a6; }
    .step-4 { border-bottom-color: #f59e0b; }
    .step-num { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 0.85rem; font-weight: 700; margin-bottom: 18px; color: #fff; }
    .step-1 .step-num { background: #3b82f6; }
    .step-2 .step-num { background: #8b5cf6; }
    .step-3 .step-num { background: #14b8a6; }
    .step-4 .step-num { background: #f59e0b; }
    .step-icon { position: absolute; top: 28px; right: 28px; width: 22px; height: 22px; color: var(--dt-ink-muted); }
    .step-1 .step-icon { color: #3b82f6; }
    .step-2 .step-icon { color: #8b5cf6; }
    .step-3 .step-icon { color: #14b8a6; }
    .step-4 .step-icon { color: #f59e0b; }
    .step h3 { font-size: 1.2rem; font-weight: 700; margin-bottom: 10px; }
    .step p { font-size: 0.95rem; color: var(--dt-ink-secondary); line-height: 1.6; }
    .section-body { margin-top: 40px; }

    /* Attention */
    .attention { background: var(--dt-pale); text-align: center; }
    .attention .lead { margin: 0 auto 48px; }
    .attention-board { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; text-align: left; }
    .attention-col { background: #fff; border: 1px solid var(--dt-border); border-radius: var(--dt-radius); padding: 20px; }
    .attention-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid var(--dt-border); }
    .attention-title.late { color: #b91c1c; border-color: #dc2626; }
    .attention-title.due { color: #1d4ed8; border-color: #3b82f6; }
    .attention-title.appt { color: #7c3aed; border-color: #8b5cf6; }
    .attention-title.ready { color: #15803d; border-color: #22c55e; }
    .attention-title.review { color: #b45309; border-color: #d97706; }

    /* Founder */
    .founder { background: #fff; }
    .founder-grid { display: grid; grid-template-columns: 0.45fr 0.55fr; gap: 64px; align-items: center; }
    .founder-card { background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: var(--dt-radius); padding: 40px; text-align: center; }
    .founder-initials { font-size: 4.5rem; font-weight: 700; letter-spacing: -0.04em; margin-bottom: 16px; }
    .founder-initials span { color: var(--dt-cyan); }
    .founder-name { font-size: 1.4rem; font-weight: 700; }
    .founder-role { font-size: 0.95rem; color: var(--dt-ink-muted); margin-top: 4px; }

    /* Pricing */
    .pricing { background: var(--dt-cream); }
    .pricing-intro { text-align: center; margin-bottom: 48px; }
    .pricing-intro .lead { margin: 0 auto; }
    .plans { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; align-items: start; }
    .plan { background: #fff; border: 1px solid var(--dt-border); border-radius: var(--dt-radius); padding: 32px; display: flex; flex-direction: column; }
    .plan.featured { border: 2px solid var(--dt-blue); }
    .plan-popular { align-self: flex-start; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #fff; background: var(--dt-blue); padding: 5px 10px; border-radius: 100px; margin-bottom: 14px; }
    .plan-name { font-size: 1.25rem; font-weight: 700; margin-bottom: 8px; }
    .plan-price { font-size: 2.6rem; font-weight: 700; line-height: 1; margin-bottom: 6px; }
    .plan-price span { font-size: 1rem; font-weight: 500; color: var(--dt-ink-muted); margin-left: 4px; }
    .plan-annual { font-size: 0.9rem; color: var(--dt-ink-muted); margin-bottom: 20px; }
    .plan p { font-size: 0.95rem; color: var(--dt-ink-secondary); line-height: 1.55; margin-bottom: 18px; }
    .plan ul { list-style: none; flex: 1; }
    .plan li { font-size: 0.9rem; padding: 6px 0; padding-left: 20px; position: relative; }
    .plan li::before { content: '✓'; position: absolute; left: 0; color: var(--dt-blue); font-weight: 700; }
    .plan li.plan-soon { color: var(--dt-ink-muted); }
    .plan li.plan-soon::before { content: ''; }
    .plan-extra { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--dt-border); font-size: 0.85rem; color: var(--dt-ink-secondary); }

    /* Plan feature comparison */
    .plan-compare { margin-top: 56px; background: #fff; border: 1px solid var(--dt-border); border-radius: var(--dt-radius); box-shadow: var(--dt-shadow); padding: 32px 36px 24px; overflow-x: clip; }
    @supports not (overflow-x: clip) { .plan-compare { overflow-x: hidden; } }
    .plan-compare h3 { font-size: 1.4rem; font-weight: 700; margin: 0 0 6px; }
    .plan-compare-lead { font-size: 0.98rem; color: var(--dt-ink-secondary); margin: 0 0 22px; }
    .plan-compare-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -36px; padding: 0 36px; }
    .plan-table { width: 100%; min-width: 620px; border-collapse: collapse; font-size: 0.92rem; }
    .plan-table th, .plan-table td { padding: 11px 14px; text-align: center; vertical-align: middle; }
    .plan-table thead th { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--dt-ink-secondary); border-bottom: 2px solid var(--dt-border); }
    .plan-table thead th.pf-first, .plan-table tbody th[scope="row"] { text-align: left; font-weight: 500; color: var(--dt-ink); }
    .plan-table thead th.pf-first { background: #fff; position: sticky; left: 0; z-index: 3; }
    .plan-table tbody th[scope="row"] { background: #fff; position: sticky; left: 0; z-index: 2; min-width: 200px; font-weight: 500; box-shadow: inset -1px 0 0 var(--dt-border); }
    .plan-table tbody td { border-bottom: 1px solid var(--dt-border); }
    .plan-table tbody tr:last-child th, .plan-table tbody tr:last-child td { border-bottom: none; }
    .plan-table tr.pf-cat th { text-align: left; background: var(--dt-pale); color: var(--dt-blue-dark); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; padding: 9px 14px; border-bottom: 1px solid var(--dt-border); }
    .pf-check { color: var(--dt-blue); font-weight: 700; font-size: 1rem; }
    .pf-dash { color: var(--dt-ink-muted); }
    .pf-text { font-size: 0.86rem; color: var(--dt-ink-secondary); }
    .pf-soon { font-size: 0.8rem; color: var(--dt-ink-muted); font-style: italic; }
    .pf-info { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; margin-left: 6px; border: 1px solid var(--dt-border); border-radius: 50%; background: #fff; color: var(--dt-ink-muted); font-size: 0.68rem; font-weight: 700; font-style: italic; cursor: help; padding: 0; vertical-align: 2px; }
    .pf-info:hover, .pf-info:focus-visible { border-color: var(--dt-blue); color: var(--dt-blue); outline: none; }
    .pf-tip { position: absolute; left: 0; bottom: calc(100% + 8px); width: 240px; padding: 10px 12px; background: var(--dt-ink); color: #fff; border-radius: var(--dt-radius-sm); font-size: 0.78rem; font-weight: 400; font-style: normal; line-height: 1.5; text-align: left; box-shadow: var(--dt-shadow); opacity: 0; visibility: hidden; transition: opacity 0.15s ease; z-index: 20; pointer-events: none; }
    .pf-info:hover .pf-tip, .pf-info:focus-within .pf-tip, .pf-info[aria-expanded="true"] .pf-tip { opacity: 1; visibility: visible; }
    .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

    /* Final CTA */
    .final-cta { text-align: center; background: var(--dt-warm); }
    .final-cta h2 { margin-bottom: 16px; }
    .final-cta .lead { margin: 0 auto 32px; }
    .final-cta .btn { margin: 0 6px; }

    /* Trust strip */
    .trust { padding: 32px 0; background: var(--dt-cream); border-top: 1px solid var(--dt-border); border-bottom: 1px solid var(--dt-border); }
    .trust-items { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px 28px; font-size: 0.9rem; color: var(--dt-ink-secondary); font-weight: 500; }
    .trust-items span { display: inline-flex; align-items: center; gap: 8px; }
    .trust-items span::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--dt-blue); }

    /* Footer */
    .footer { background: var(--dt-ink); color: #d1d5db; padding: 64px 0 32px; }
    .footer a { color: #9ca3af; }
    .footer a:hover { color: #fff; }
    .footer-grid { display: flex; justify-content: space-between; gap: 40px; flex-wrap: wrap; }
    .footer-brand img { height: 28px; margin-bottom: 14px; }
    .footer-brand p { font-size: 0.9rem; color: #9ca3af; max-width: 280px; }
    .footer-nav { display: flex; gap: 64px; }
    .footer-nav h4 { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: #9ca3af; margin-bottom: 14px; }
    .footer-nav a { display: block; font-size: 0.9rem; margin-bottom: 10px; }
    .footer-bottom { border-top: 1px solid #374151; padding-top: 24px; margin-top: 48px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px; font-size: 0.85rem; color: #9ca3af; }
    .footer-wordmark { display: inline-flex; align-items: center; gap: 0; font-size: 22px; font-weight: 700; text-decoration: none; line-height: 1.2; margin-bottom: 14px; }
    .footer-wordmark .denta { color: #fff; }
    .footer-wordmark .trak { color: var(--dt-cyan); }
    @media (max-width: 640px) { .footer-wordmark { font-size: 18px; } }

    @media (max-width: 900px) {
      .site-nav a:not(.nav-cta), .site-nav .nav-divider { display: none; }
      .site-nav { gap: 16px; }
      .founder-grid { grid-template-columns: 1fr; text-align: center; }
      .founder .lead { margin-left: auto; margin-right: auto; }
      .di-metrics, .di-charts, .di-split { grid-template-columns: 1fr; }
      .di-metric-value { font-size: 1.45rem; }
      .steps-grid { grid-template-columns: 1fr; }
      .attention-board { grid-template-columns: 1fr; }
      .plans { grid-template-columns: 1fr; max-width: 600px; margin: 0 auto; }
      .plan.featured { order: -1; }
    }

    @media (max-width: 480px) {
      .nav-collapsed { gap: 10px; }
    }

    /* Phone layout for the problem section: text first, then a compact
       two-column grid of source fragments with the consolidated case card
       spanning the full width beneath them. */
    @media (max-width: 767px) {
      .problem-grid { grid-template-columns: 1fr; text-align: center; gap: 28px; }
      .problem .lead { margin-left: auto; margin-right: auto; }
      .problem-visual {
        order: 0;
        min-height: auto;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        place-items: stretch;
      }
      .problem-visual .fragment {
        position: relative;
        inset: auto !important;
        top: auto !important;
        left: auto !important;
        right: auto !important;
        bottom: auto !important;
        margin: 0;
        padding: 10px;
        max-width: none;
        width: auto;
        min-height: 70px;
        font-size: 0.75rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: flex-start;
      }
      .problem-visual .fragment strong { font-size: 0.78rem; }
      .problem-visual .fragment small { font-size: 0.65rem; line-height: 1.35; margin-top: 3px; }
      .problem-case {
        grid-column: 1 / -1;
        max-width: none;
        width: auto;
        padding: 16px;
      }
      .problem-case h3 { font-size: 1rem; }
      .problem-case p { font-size: 0.8rem; }
      .problem-connections { display: none; }
      .problem-visual.is-active .fragment,
      .problem-visual.is-converging .problem-case { transform: none !important; }
    }

    @media (max-width: 720px) {
      .section { padding: 72px 0; }
      .hero { padding: 120px 0 60px; }
      .hero h1 { font-size: clamp(1.85rem, 6.4vw, 2.2rem); }
      .hero .lead { font-size: 1rem; }
      .footer-nav { flex-direction: column; gap: 32px; }
      .board { grid-template-columns: repeat(3, 1fr); }
      .board-col:nth-child(n+4) { display: none; }
      .demo-list-row { grid-template-columns: 18px minmax(0,1.4fr) minmax(0,0.7fr) minmax(0,1fr); }
      .demo-list-row .optional { display: none; }
      .demo-list-detail { padding-left: 12px; }
      .footer-grid { flex-direction: column; }
    }

    /* ============================================================
       Scroll reveal and section animations (remaining landing sections)
       ============================================================ */

    /* Base transitions for hover on interactive cards */
    .step { transition: transform 0.25s ease, box-shadow 0.25s ease; }
    .step:hover {
      transform: translateY(-4px);
      box-shadow: 0 16px 40px -12px rgba(0,0,0,0.1);
    }

    .plan { transition: transform 0.25s ease, box-shadow 0.25s ease; }
    .plan:hover {
      transform: translateY(-4px);
      box-shadow: 0 18px 48px -12px rgba(0,0,0,0.12);
    }
    .plan.featured:hover {
      box-shadow: 0 18px 48px -12px rgba(0,0,0,0.12);
    }

    .founder-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .founder-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 16px 40px -12px rgba(0,0,0,0.08);
    }

    /* Scroll reveal core */
    .reveal {
      opacity: 0;
      visibility: hidden;
      transform: translateY(20px);
      transition: opacity 0.8s cubic-bezier(0.22, 1, 0.36, 1),
                  transform 0.8s cubic-bezier(0.22, 1, 0.36, 1),
                  box-shadow 0.25s ease,
                  visibility 0s;
      will-change: opacity, transform;
    }
    .reveal-left { transform: translateX(-20px); }
    .reveal-right { transform: translateX(20px); }

    .reveal.is-revealed,
    .reveal-left.is-revealed,
    .reveal-right.is-revealed,
    .is-revealed {
      opacity: 1;
      visibility: visible;
      transform: none;
      will-change: auto;
    }

    /* Step icon animations (triggered after card reveal) */
    .step-icon { transform-origin: center; }
    .step-icon-animated .step-icon {
      animation-duration: 0.6s;
      animation-fill-mode: both;
    }
    .step-1.step-icon-animated .step-icon { animation-name: stepRefresh; }
    .step-2.step-icon-animated .step-icon { animation-name: stepPlus; }
    .step-3.step-icon-animated .step-icon { animation-name: stepChat; }
    .step-4.step-icon-animated .step-icon { animation-name: stepAlert; }

    @keyframes stepPlus {
      from { opacity: 0; transform: scale(0.6); }
      to { opacity: 1; transform: scale(1); }
    }
    @keyframes stepRefresh {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    @keyframes stepChat {
      from { opacity: 0; transform: translateY(5px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes stepAlert {
      0% { transform: scale(1); }
      50% { transform: scale(1.15); }
      100% { transform: scale(1); }
    }

    /* Product view status pulse */
    .status-pulse .case-flag { display: inline-block; animation: statusPulse 0.6s ease; }
    @keyframes statusPulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.12); }
      100% { transform: scale(1); }
    }

    /* Pricing featured emphasis (applied after all three plans have revealed) */
    .plan.featured.is-featured-emphasized {
      box-shadow: 0 0 0 4px rgba(30,64,175,0.08), 0 18px 48px -12px rgba(0,0,0,0.12);
    }
    .plan.featured.is-featured-emphasized:hover {
      transform: translateY(-4px);
      box-shadow: 0 18px 48px -12px rgba(0,0,0,0.12);
    }

    /* Mobile and tablet reveal sizing */
    @media (max-width: 900px) {
      .reveal,
      .reveal-left,
      .reveal-right {
        transform: translateY(12px);
        transition: opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
                    transform 0.6s cubic-bezier(0.22, 1, 0.36, 1),
                    box-shadow 0.25s ease,
                    visibility 0s;
      }
      .reveal.is-revealed,
      .reveal-left.is-revealed,
      .reveal-right.is-revealed,
      .is-revealed {
        transform: translateY(0);
      }
    }

    /* Homepage additions: Insights, Security, Mobile, demo CTA */
    .content-link { color: var(--dt-blue); font-weight: 600; text-decoration: underline; }
    .content-link:hover { color: var(--dt-blue-light); }
    .content-link:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }

    .hero-link { color: var(--dt-ink-secondary); font-weight: 500; text-decoration: underline; }
    .hero-link:hover { color: var(--dt-blue); }
    .hero-link:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }
    .trial-sep { color: var(--dt-ink-muted); margin: 0 6px; }

    .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-top: 48px; }
    .feature-card { background: #fff; border: 1px solid var(--dt-border); border-radius: var(--dt-radius-sm); padding: 28px; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .feature-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px -12px rgba(0,0,0,0.08); }
    .feature-card h3 { font-size: 1.05rem; font-weight: 600; margin-bottom: 8px; color: var(--dt-ink); }
    .feature-card p { font-size: 0.95rem; color: var(--dt-ink-secondary); line-height: 1.6; margin: 0; }
    .insights .feature-card:nth-child(1) { border-top: 3px solid #3b82f6; }
    .insights .feature-card:nth-child(2) { border-top: 3px solid #8b5cf6; }
    .insights .feature-card:nth-child(3) { border-top: 3px solid #f59e0b; }
    .insights .feature-card:nth-child(4) { border-top: 3px solid #22c55e; }

    /* Already-tracking comparison: four question cards */
    .compare-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 48px; }
    .compare-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 14px; padding: 28px 26px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
    .compare-card h3 { font-size: 1.05rem; font-weight: 700; margin: 0 0 10px; color: var(--dt-ink); line-height: 1.35; }
    .compare-card p { font-size: 0.92rem; color: var(--dt-ink-secondary); line-height: 1.6; margin: 0; }
    @media (max-width: 720px) {
      .compare-grid { grid-template-columns: 1fr; }
    }
    .section-note { font-size: 0.95rem; color: var(--dt-ink-muted); margin-top: 28px; }
    .section-cta { margin-top: 28px; }

    .security .lead { max-width: 760px; }
    .security-intro { text-align: center; margin-bottom: 48px; }
    .security-intro .lead { margin-left: auto; margin-right: auto; }
    .security-link { display: inline-flex; align-items: center; gap: 8px; margin-top: 14px; }
    .security-panel { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #fff; border-radius: 24px; padding: 40px 32px; box-shadow: 0 18px 50px -14px rgba(30,64,175,0.18); margin-bottom: 48px; }
    .security-panel-title { font-size: 1.25rem; font-weight: 700; text-align: center; margin-bottom: 36px; }
    .security-flow { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 32px; }
    .security-stage { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 18px; padding: 22px 16px; text-align: center; width: 170px; }
    .security-stage-icon { width: 44px; height: 44px; margin: 0 auto 12px; color: #93c5fd; }
    .security-stage-icon svg { width: 100%; height: 100%; }
    .security-stage-text { font-size: 0.84rem; font-weight: 600; line-height: 1.4; margin-bottom: 10px; color: #fff; }
    .security-stage-check { font-size: 1.1rem; color: #86efac; font-weight: 700; }
    .security-connector { width: 34px; height: 2px; background: rgba(255,255,255,0.25); }
    .security-trust { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px 28px; font-size: 0.84rem; color: rgba(255,255,255,0.82); }
    .security-trust span { display: inline-flex; align-items: center; gap: 8px; }
    .security-trust span::before { content: ""; display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #60a5fa; }
    .security-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .security-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 18px; padding: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .security-card h3 { font-size: 1.05rem; font-weight: 700; margin: 16px 0 8px; color: var(--dt-ink); }
    .security-card p { font-size: 0.95rem; color: var(--dt-ink-secondary); line-height: 1.55; margin: 0; }
    .security-card-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; }
    .security-card-icon svg { width: 26px; height: 26px; }
    .security-card:nth-child(1) .security-card-icon { background: #eff6ff; color: #1e40af; }
    .security-card:nth-child(2) .security-card-icon { background: #f0fdf4; color: #15803d; }
    .security-card:nth-child(3) .security-card-icon { background: #fff7ed; color: #c2410c; }
    .security-card:nth-child(4) .security-card-icon { background: #faf5ff; color: #7c3aed; }
    .security-card:nth-child(5) .security-card-icon { background: #fff1f2; color: #be123c; }
    .security-card:nth-child(3) .security-card-icon svg { color: #ea580c; }
    @media (max-width: 900px) {
      .security-panel { padding: 28px 20px; }
      .security-flow { flex-direction: column; }
      .security-stage { width: 100%; max-width: 280px; }
      .security-connector { width: 2px; height: 20px; }
      .security-cards { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .security-intro { margin-bottom: 36px; }
      .security-panel { padding: 24px 18px; border-radius: 18px; }
      .security-panel-title { font-size: 1.05rem; margin-bottom: 24px; }
      .security-stage { padding: 16px 12px; }
      .security-stage-icon { width: 36px; height: 36px; }
      .security-stage-text { font-size: 0.78rem; }
      .security-trust { flex-direction: column; align-items: flex-start; gap: 8px; }
      .security-card { padding: 20px; }
    }

    .mobile-grid { display: grid; grid-template-columns: 0.42fr 0.58fr; gap: 64px; align-items: center; margin-top: 48px; }
    .phone-frame { width: 280px; height: 560px; border: 12px solid var(--dt-ink); border-radius: 36px; background: #fff; position: relative; overflow: hidden; margin: 0 auto; box-shadow: var(--dt-shadow); }
    .phone-notch { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 110px; height: 22px; background: var(--dt-ink); border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; z-index: 2; }
    .phone-screen { height: 100%; padding: 30px 14px 18px; overflow: hidden; display: flex; flex-direction: column; }
    .phone-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid var(--dt-border); margin-bottom: 10px; font-size: 0.65rem; color: var(--dt-ink-muted); }
    .phone-logo { font-weight: 700; color: var(--dt-ink); }
    .phone-board { display: flex; gap: 10px; overflow: hidden; flex: 1; }
    .phone-col { flex: 0 0 85%; border-top: 3px solid #3b82f6; background: #fafafa; border-radius: 10px; padding: 10px; }
    .phone-col.next { border-top-color: #8b5cf6; }
    .phone-stage { font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dt-ink-muted); padding: 6px 0; border-bottom: 1px solid var(--dt-border); margin-bottom: 8px; }
    .phone-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 8px; padding: 8px; margin-bottom: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .phone-card h4 { font-size: 0.75rem; font-weight: 700; margin-bottom: 2px; }
    .phone-type { font-size: 0.6rem; color: var(--dt-ink-muted); margin-bottom: 6px; }
    .phone-flag { display: inline-block; padding: 2px 5px; font-size: 0.5rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border-radius: 999px; }
    .phone-flag.due { color: #1d4ed8; background: #eff6ff; }
    .phone-flag.late { color: #b91c1c; background: #fee2e2; }

    .mobile-features { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .mobile-features li strong { display: block; font-size: 1rem; color: var(--dt-ink); margin-bottom: 4px; }
    .mobile-features li p { font-size: 0.9rem; color: var(--dt-ink-secondary); line-height: 1.55; margin: 0; }
    .mobile-note { font-size: 0.9rem; color: var(--dt-ink-muted); margin-top: 24px; }

    .cta-signin { font-size: 0.95rem; color: var(--dt-ink-secondary); margin-top: 18px; }
    .cta-signin a { color: var(--dt-ink-secondary); text-decoration: underline; }
    .cta-signin a:hover { color: var(--dt-blue); }
    .cta-signin a:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }

    a:focus-visible, button:focus-visible, .btn:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }

    @media (max-width: 900px) {
      .mobile-grid { grid-template-columns: 1fr; text-align: center; }
      .mobile-features { text-align: left; }
      .phone-frame { margin-bottom: 32px; }
    }
    @media (max-width: 720px) {
      .mobile-features { grid-template-columns: 1fr; }
      .feature-grid { grid-template-columns: 1fr; }
      .hero-actions .btn { width: 100%; justify-content: center; }
    }

    .pricing-transparency { font-size: 0.98rem; color: var(--dt-ink-secondary); max-width: 760px; margin: 20px auto 0; line-height: 1.6; }
    .pricing-transparency strong { display: block; font-size: 1.1rem; color: var(--dt-ink); margin-bottom: 6px; }

    .cta-note { font-size: 0.95rem; color: var(--dt-ink-muted); margin-top: 14px; }

    /* Insights section: two-column layout and snapshot */
    .insights-grid { display: grid; grid-template-columns: 0.46fr 0.54fr; gap: 56px; align-items: start; }
    .insights-copy .eyebrow { text-align: left; }
    .insights-copy h2 { margin-bottom: 16px; }
    .insights-copy .lead { margin: 0 0 18px 0; max-width: 460px; }
    .insights-callouts { list-style: none; margin: 24px 0 0; padding: 0; }
    .insights-callouts li { position: relative; padding-left: 28px; margin-bottom: 18px; font-size: 0.96rem; color: var(--dt-ink-secondary); line-height: 1.55; }
    .insights-callouts li strong { display: block; color: var(--dt-ink); font-weight: 600; margin-bottom: 4px; }
    .insights-callouts li::before { content: ""; position: absolute; left: 0; top: 6px; width: 10px; height: 10px; border-radius: 50%; background: var(--dt-blue); }
    .insights-copy .section-note { text-align: left; margin: 22px 0 0 0; max-width: 460px; }
    .insights-copy .section-note + .section-note { margin-top: 10px; }

    .insights-snapshot { background: #fff; border: 1px solid var(--dt-border); border-radius: 16px; padding: 22px; box-shadow: 0 8px 24px -12px rgba(0,0,0,0.08); overflow: hidden; }
    .is-header { margin-bottom: 14px; }
    .is-header h3 { font-size: 1.1rem; font-weight: 700; color: var(--dt-ink); margin-bottom: 2px; }
    .is-header p { font-size: 0.75rem; color: var(--dt-ink-muted); }
    .is-metrics { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 14px; }
    .is-metrics .di-metric { padding: 12px; border-radius: 12px; }
    .is-metrics .di-metric-value { font-size: 1.4rem; margin-bottom: 4px; }
    .is-charts { grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
    .is-charts .di-chart { padding: 12px; border-radius: 12px; }
    .is-charts .di-chart-title { font-size: 0.78rem; margin-bottom: 8px; }
    .is-charts .di-donut { height: 110px; }
    .is-charts .di-line-chart { height: 110px; }
    .is-lab { padding: 12px; margin-bottom: 12px; }
    .is-lab .di-kpi-value { font-size: 1.5rem; color: var(--dt-ink); }
    .is-smart { padding: 12px; border-left-width: 3px; border-radius: 12px; }
    .is-smart .di-smart-icon { width: 30px; height: 30px; }
    .is-smart .di-smart-icon svg { width: 16px; height: 16px; }
    .is-smart h4 { font-size: 0.78rem; }
    .is-smart p { font-size: 0.72rem; }

    @media (max-width: 900px) {
      .insights-grid { grid-template-columns: 1fr; }
      .insights-snapshot { margin-top: 40px; }
      .is-metrics { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 720px) {
      .insights-grid { gap: 40px; }
      .is-charts { grid-template-columns: 1fr; }
      .insights-snapshot { padding: 16px; }
    }
    @media (max-width: 360px) {
      .is-metrics { grid-template-columns: 1fr; }
    }

    /* Collaboration: comment thread mockup */
    .collab { background: #fff; }
    .collab-grid, .ask-grid { display: grid; grid-template-columns: 0.46fr 0.54fr; gap: 56px; align-items: center; }
    .ct-panel { background: #fff; border: 1px solid var(--dt-border); border-radius: 16px; box-shadow: 0 8px 24px -12px rgba(0,0,0,0.08); overflow: hidden; }
    .ct-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--dt-border); background: var(--dt-warm); }
    .ct-head strong { font-size: 0.85rem; color: var(--dt-ink); }
    .ct-head span { font-size: 0.7rem; color: var(--dt-ink-muted); }
    .ct-comment { display: flex; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--dt-border); }
    .ct-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--dt-blue); color: #fff; font-size: 0.62rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ct-avatar.alt { background: #14b8a6; }
    .ct-meta { font-size: 0.68rem; color: var(--dt-ink-muted); margin-bottom: 3px; }
    .ct-meta strong { color: var(--dt-ink); font-weight: 600; }
    .ct-body p { font-size: 0.82rem; color: var(--dt-ink-secondary); line-height: 1.5; margin: 0; }
    .ct-mention { color: var(--dt-blue); font-weight: 600; }
    .ct-attach { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 4px 10px; background: var(--dt-pale); border: 1px solid var(--dt-border); border-radius: 999px; font-size: 0.68rem; color: var(--dt-ink-secondary); }
    .ct-attach svg { width: 12px; height: 12px; color: var(--dt-ink-muted); }
    .ct-activity { display: flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 0.72rem; color: var(--dt-ink-muted); background: #fafbfc; }
    .ct-activity svg { width: 13px; height: 13px; flex-shrink: 0; }
    .ct-composer { display: flex; align-items: center; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--dt-border); font-size: 0.74rem; color: var(--dt-ink-muted); }

    /* Ask DentaTrak: chat mockup */
    .ask { background: var(--dt-pale); }
    .ask-panel { background: #fff; border: 1px solid var(--dt-border); border-radius: 16px; box-shadow: 0 8px 24px -12px rgba(0,0,0,0.08); overflow: hidden; }
    .ask-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--dt-border); background: var(--dt-warm); }
    .ask-head strong { font-size: 0.85rem; color: var(--dt-ink); }
    .ask-head kbd { font-size: 0.62rem; font-weight: 600; color: var(--dt-ink-muted); background: #fff; border: 1px solid var(--dt-border); border-radius: 6px; padding: 2px 7px; font-family: inherit; }
    .ask-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; }
    .ask-msg { max-width: 82%; padding: 9px 13px; border-radius: 14px; font-size: 0.8rem; line-height: 1.5; }
    .ask-msg.user { align-self: flex-end; background: var(--dt-blue); color: #fff; border-bottom-right-radius: 4px; }
    .ask-msg.ai { align-self: flex-start; background: var(--dt-warm); border: 1px solid var(--dt-border); color: var(--dt-ink-secondary); border-bottom-left-radius: 4px; }
    .ask-msg.ai strong { color: var(--dt-ink); }
    .ask-input { display: flex; align-items: center; gap: 8px; margin: 0 18px 16px; padding: 9px 12px; border: 1px solid var(--dt-border); border-radius: 10px; font-size: 0.74rem; color: var(--dt-ink-muted); background: #fafbfc; }

    /* Open Dental: compact integration flow */
    .pms { background: #fff; }
    .pms-inner { max-width: 780px; margin: 0 auto; text-align: center; }
    .pms-inner .lead { margin: 0 auto 40px; }
    .pms-flow { display: flex; align-items: center; justify-content: center; gap: 20px; }
    .pms-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 14px; padding: 16px 18px; text-align: left; min-width: 230px; box-shadow: 0 4px 14px -8px rgba(0,0,0,0.08); }
    .pms-card-label { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--dt-ink-muted); margin-bottom: 8px; }
    .pms-card h4 { font-size: 0.9rem; font-weight: 700; color: var(--dt-ink); margin: 0 0 4px; }
    .pms-card p { font-size: 0.74rem; color: var(--dt-ink-secondary); margin: 0; line-height: 1.5; }
    .pms-arrow { color: var(--dt-blue); flex-shrink: 0; }
    .pms-arrow svg { width: 30px; height: 30px; }
    .pms-cards { display: flex; gap: 20px; justify-content: center; align-items: stretch; text-align: left; }
    .pms-cards .pms-card { flex: 1 1 0; max-width: 340px; }
    .pms-note { font-size: 0.78rem; color: var(--dt-ink-muted); margin: 22px 0 0; }
    .pms-plan-note { font-size: 0.72rem; color: var(--dt-ink-secondary); margin: 6px 0 0; }

    /* Languages */
    .langs { background: var(--dt-cream); text-align: center; }
    .langs .lead { margin: 0 auto 36px; }
    .lang-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; max-width: 760px; margin: 0 auto; }
    .lang-chip { padding: 8px 18px; background: #fff; border: 1px solid var(--dt-border); border-radius: 999px; font-size: 0.88rem; font-weight: 500; color: var(--dt-ink-secondary); }
    .lang-note { margin-top: 30px; font-size: 0.88rem; color: var(--dt-ink-muted); max-width: 620px; margin-left: auto; margin-right: auto; }

    @media (max-width: 900px) {
      .collab-grid, .ask-grid { grid-template-columns: 1fr; }
      .pms-flow, .pms-cards { flex-direction: column; }
      .pms-cards .pms-card { max-width: 340px; margin: 0 auto; width: 100%; }
      .pms-arrow { transform: rotate(90deg); }
    }
    @media (max-width: 720px) {
      .collab-grid, .ask-grid { gap: 40px; }
      .pms-card { min-width: 0; width: 100%; max-width: 340px; }
    }

    /* Mobile browser: dark product section */
    .mobile-dark { background: var(--dt-ink); color: #fff; }
    .mobile-dark .eyebrow { color: #60a5fa; }
    .mobile-dark h2 { color: #fff; }
    .mobile-dark .lead { color: rgba(255,255,255,0.75); margin: 0 0 24px 0; max-width: 420px; }
    .mobile-showcase { display: grid; grid-template-columns: 0.42fr 0.58fr; gap: 60px; align-items: center; }
    .mobile-copy .eyebrow { text-align: left; margin-bottom: 14px; }
    .mobile-copy h2 { margin-bottom: 16px; }
    .mobile-callouts { list-style: none; margin: 0 0 24px; padding: 0; }
    .mobile-callouts li { position: relative; padding-left: 32px; margin-bottom: 16px; font-size: 0.95rem; color: rgba(255,255,255,0.78); line-height: 1.55; }
    .mobile-callouts li strong { display: block; color: #fff; font-weight: 600; margin-bottom: 3px; }
    .mobile-callouts li::before { content: ""; position: absolute; left: 0; top: 5px; width: 20px; height: 20px; border-radius: 50%; background: var(--dt-blue); }
    .mobile-note { display: inline-flex; align-items: center; gap: 8px; font-size: 0.86rem; color: rgba(255,255,255,0.75); margin: 0; }
    .mobile-note svg { width: 18px; height: 18px; color: #60a5fa; flex-shrink: 0; }
    .mobile-phones { position: relative; min-height: 640px; display: flex; align-items: center; justify-content: center; background: radial-gradient(circle at 50% 45%, rgba(37,99,235,0.16) 0%, rgba(15,23,42,0) 65%); background-size: 520px 520px; background-repeat: no-repeat; background-position: center; }
    .m-device { background: #fff; border: 14px solid #0f172a; border-radius: 42px; box-shadow: 0 28px 60px -12px rgba(0,0,0,0.5); overflow: hidden; position: relative; box-sizing: border-box; z-index: 2; }
    .m-notch { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 110px; height: 24px; background: #0f172a; border-bottom-left-radius: 14px; border-bottom-right-radius: 14px; z-index: 2; }
    .m-screen { height: 100%; padding: 44px 14px 16px; display: flex; flex-direction: column; color: var(--dt-ink); overflow: hidden; box-sizing: border-box; }
    .m-device-primary { width: 320px; height: 660px; z-index: 2; }
    .m-device-secondary { width: 280px; height: 580px; position: absolute; right: -20px; bottom: 10px; z-index: 1; transform: rotate(2deg); }
    .m-appbar { display: flex; align-items: center; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid var(--dt-border); margin-bottom: 10px; font-size: 0.7rem; }
    .m-appbar-left { display: flex; align-items: center; }
    .m-appbar-logo { font-weight: 700; color: var(--dt-ink); font-size: 0.78rem; }
    .m-appbar-right { display: flex; align-items: center; gap: 10px; }
    .m-bell { width: 18px; height: 18px; color: var(--dt-ink-muted); display: flex; align-items: center; }
    .m-bell svg { width: 100%; height: 100%; }
    .m-appbar-practice { color: var(--dt-ink-muted); padding: 3px 8px; background: #f3f4f6; border-radius: 100px; font-size: 0.6rem; }
    .m-searchbar { display: flex; align-items: center; gap: 8px; background: #f3f4f6; border-radius: 10px; padding: 6px 10px; margin-bottom: 10px; }
    .m-searchbar input { flex: 1; border: none; background: transparent; font: inherit; font-size: 0.75rem; color: var(--dt-ink); outline: none; }
    .m-searchbar input::placeholder { color: var(--dt-ink-muted); }
    .m-search-icon, .m-filter-icon { width: 16px; height: 16px; color: var(--dt-ink-muted); display: flex; align-items: center; }
    .m-kanban-nav { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
    .m-kanban-nav select { flex: 1; border: 1px solid var(--dt-border); border-radius: 8px; padding: 6px 8px; background: #fff; font-size: 0.75rem; font-family: inherit; color: var(--dt-ink); }
    .m-kanban-prev, .m-kanban-next { width: 26px; height: 26px; border: 1px solid var(--dt-border); background: #fff; border-radius: 8px; color: var(--dt-ink-muted); font-size: 1rem; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .m-kanban-next { color: var(--dt-blue); }
    .m-kanban-prev:disabled { opacity: 0.45; }
    .m-board { flex: 1; overflow: hidden; position: relative; }
    .m-board-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .m-board-title { font-size: 0.75rem; font-weight: 700; color: var(--dt-ink); }
    .m-board-count { font-size: 0.7rem; font-weight: 600; color: #fff; background: var(--dt-ink); padding: 1px 6px; border-radius: 100px; }
    .m-board-cards { overflow: hidden; }
    .m-case-card { background: #fff; border: 1px solid var(--dt-border); border-radius: 12px; padding: 10px; margin-bottom: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .m-case-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 6px; }
    .m-case-top h4 { font-size: 0.78rem; font-weight: 700; color: var(--dt-ink); margin: 0 0 2px 0; }
    .m-case-sub { display: block; font-size: 0.6rem; color: var(--dt-ink-muted); }
    .m-case-details { display: flex; gap: 12px; font-size: 0.6rem; color: var(--dt-ink-secondary); margin-bottom: 4px; }
    .m-case-details strong { color: var(--dt-ink); font-weight: 600; }
    .m-case-appt { font-size: 0.6rem; color: var(--dt-ink-muted); padding-top: 4px; border-top: 1px dashed var(--dt-border); margin-top: 4px; }
    .m-case-flag { display: inline-block; padding: 2px 5px; font-size: 0.5rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border-radius: 100px; }
    .m-case-flag.due { color: #1d4ed8; background: #eff6ff; }
    .m-case-flag.late { color: #b91c1c; background: #fee2e2; }
    .m-case-flag.appt { color: #7c3aed; background: #ede9fe; }
    .m-case-flag.ready { color: #15803d; background: #dcfce7; }
    .m-column-peek { position: absolute; right: -12px; top: 80px; bottom: 80px; width: 24px; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 3; }
    .m-peek-col { width: 14px; height: 70%; background: linear-gradient(to bottom, rgba(243,244,246,0.9), rgba(229,231,235,0.6)); border-radius: 8px; box-shadow: -2px 0 6px rgba(0,0,0,0.05); }
    .m-modal-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid var(--dt-border); margin-bottom: 10px; }
    .m-modal-title { font-weight: 700; color: var(--dt-ink); font-size: 0.8rem; }
    .m-modal-back, .m-modal-actions { color: var(--dt-ink-muted); font-size: 1rem; }
    .m-modal-tabs { display: flex; gap: 6px; margin-bottom: 10px; border-bottom: 1px solid var(--dt-border); padding-bottom: 6px; }
    .m-modal-tab { font-size: 0.7rem; font-weight: 600; color: var(--dt-ink-muted); padding: 4px 8px; border-radius: 6px; }
    .m-modal-tab.active { color: var(--dt-ink); background: #f3f4f6; }
    .m-section-nav { margin-bottom: 10px; }
    .m-section-nav select { width: 100%; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--dt-border); background: #fff; font-size: 0.7rem; font-family: inherit; color: var(--dt-ink); }
    .m-modal-summary { background: #f9fafb; border-radius: 12px; padding: 12px; margin-bottom: 10px; }
    .m-modal-patient { font-size: 0.9rem; font-weight: 700; color: var(--dt-ink); margin-bottom: 2px; }
    .m-modal-meta { font-size: 0.62rem; color: var(--dt-ink-muted); margin-bottom: 8px; }
    .m-patient-status { display: inline-block; font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border-radius: 100px; padding: 2px 5px; }
    .m-patient-status.due { color: #1d4ed8; background: #eff6ff; }
    .m-patient-status.appt { color: #7c3aed; background: #ede9fe; }
    .m-patient-status.late { color: #b91c1c; background: #fee2e2; }
    .m-patient-status.ready { color: #15803d; background: #dcfce7; }
    .m-modal-body { flex: 1; overflow-y: auto; font-size: 0.7rem; }
    .m-field-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #f3f4f6; }
    .m-field-label { color: var(--dt-ink-muted); }
    .m-field-value { font-weight: 500; color: var(--dt-ink); }
    .m-detail-block { margin-top: 9px; }
    .m-block-label { font-size: 0.62rem; color: var(--dt-ink-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 4px; }
    .m-detail-block p { margin: 0 0 6px; color: var(--dt-ink-secondary); line-height: 1.4; }
    .m-attachment-row { display: flex; align-items: center; justify-content: space-between; }
    .m-attachment { display: inline-block; background: #eff6ff; color: #1e40af; font-size: 0.6rem; font-weight: 600; padding: 4px 8px; border-radius: 6px; }
    .m-attachment-label { font-size: 0.6rem; color: var(--dt-ink-muted); }
    .m-modal-footer { padding-top: 10px; border-top: 1px solid var(--dt-border); margin-top: auto; }
    .m-save-btn { width: 100%; background: var(--dt-blue); color: #fff; border: none; border-radius: 10px; padding: 10px; font-size: 0.75rem; font-weight: 600; cursor: pointer; }

    @media (max-width: 1220px) {
      .m-device-secondary { right: 10px; }
    }
    @media (max-width: 900px) {
      .mobile-showcase { grid-template-columns: 1fr; text-align: center; }
      .mobile-phones { min-height: 560px; margin-top: 48px; }
      .m-device-secondary { right: auto; left: 52%; bottom: 10px; top: auto; transform: rotate(2deg); }
      .m-device-primary { margin: 0 auto; }
      .mobile-copy .lead { margin-left: auto; margin-right: auto; }
      .mobile-callouts { text-align: left; max-width: 500px; margin-left: auto; margin-right: auto; }
      .mobile-note { justify-content: center; }
    }
    @media (max-width: 720px) {
      .m-device-secondary { display: none; }
      .m-device-primary { width: 280px; height: 580px; }
      .mobile-phones { min-height: auto; }
    }
    @media (max-width: 390px) {
      .m-device-primary { width: 260px; height: 540px; }
    }
    @media (max-width: 360px) {
      .m-device-primary { width: 240px; height: 500px; }
      .m-case-card { padding: 8px; margin-bottom: 6px; }
      .m-case-top h4 { font-size: 0.72rem; }
      .m-case-sub { font-size: 0.55rem; }
      .m-case-details { gap: 8px; font-size: 0.55rem; }
      .m-case-flag { font-size: 0.45rem; padding: 2px 4px; }
    }

    /* Demo request modal */
    .demo-modal-overlay { position: fixed; inset: 0; background: rgba(17,24,39,0.65); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 16px; overflow-y: auto; }
    .demo-modal-overlay.is-open { display: flex; }
    .demo-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; max-height: calc(100vh - 32px); overflow-y: auto; box-shadow: 0 24px 60px -12px rgba(0,0,0,0.25); }
    .demo-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--dt-border); }
    .demo-modal-header h3 { margin: 0; font-size: 1.15rem; color: var(--dt-ink); }
    .demo-modal-close { background: none; border: none; font-size: 1.6rem; line-height: 1; color: var(--dt-ink-muted); cursor: pointer; padding: 4px 8px; border-radius: 6px; }
    .demo-modal-close:hover { color: var(--dt-ink); }
    .demo-modal-close:focus-visible { outline: 2px solid var(--dt-blue-light); outline-offset: 2px; }
    .demo-form { padding: 24px; }
    .demo-modal-desc { margin: 0 0 20px; color: var(--dt-ink-secondary); font-size: 0.95rem; }
    .demo-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .demo-field { display: flex; flex-direction: column; }
    .demo-field-wide { grid-column: 1 / -1; }
    .demo-field label { font-size: 0.8rem; font-weight: 600; margin-bottom: 5px; color: var(--dt-ink); }
    .demo-field label span[aria-label="required"] { color: #dc2626; }
    .demo-field input, .demo-field textarea, .demo-field select { font: inherit; padding: 10px 12px; border: 1px solid var(--dt-border); border-radius: 10px; font-size: 0.95rem; background: #fff; color: var(--dt-ink); }
    .demo-field input:focus, .demo-field textarea:focus, .demo-field select:focus { outline: 2px solid var(--dt-blue-light); border-color: var(--dt-blue-light); }
    .demo-field.is-invalid input, .demo-field.is-invalid textarea, .demo-field.is-invalid select { border-color: #dc2626; }
    .demo-field.is-invalid label { color: #dc2626; }
    .demo-form-actions { display: flex; gap: 12px; margin-top: 4px; }
    .demo-form-status { font-size: 0.95rem; margin-top: 16px; }
    .demo-form-status.success { color: #15803d; }
    .demo-form-status.error { color: #dc2626; }
    .hp { display: none; }
    @media (max-width: 600px) {
      .demo-form-grid { grid-template-columns: 1fr; }
      .demo-form { padding: 20px; }
      .demo-modal { max-height: calc(100vh - 24px); }
      .demo-form-actions { flex-direction: column; }
      .demo-form-actions .btn { width: 100%; justify-content: center; }
    }

    /* Reduced motion: show everything immediately, no movement/animation */
    @media (prefers-reduced-motion: reduce) {
      .reveal, .reveal-left, .reveal-right,
      .reveal.is-revealed, .reveal-left.is-revealed, .reveal-right.is-revealed,
      .is-revealed {
        opacity: 1 !important;
        visibility: visible !important;
        transform: none !important;
        transition: none !important;
        animation: none !important;
        will-change: auto !important;
      }
      .step-icon-animated .step-icon,
      .status-pulse .case-flag,
      .plan.featured.is-featured-emphasized {
        animation: none !important;
        transform: none !important;
        box-shadow: none !important;
      }
      html { scroll-behavior: auto; }
    }
    /* How-it-works walkthrough */
    .hw-walk { display: grid; grid-template-columns: 230px 1fr; gap: 36px; margin-top: 40px; text-align: left; }
    .hw-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
    .hw-step { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border: 1px solid transparent; border-radius: 10px; background: none; cursor: pointer; font-size: 0.82rem; color: var(--dt-ink-secondary); text-align: left; }
    .hw-step:hover { background: var(--dt-warm); }
    .hw-step[aria-selected="true"] { background: #eef4fb; border-color: var(--dt-border); color: var(--dt-ink); font-weight: 600; }
    .hw-step:focus-visible { outline: 2px solid var(--dt-blue); outline-offset: 2px; }
    .hw-step-num { width: 22px; height: 22px; border-radius: 50%; background: var(--dt-blue); color: #fff; font-size: 0.68rem; font-weight: 700; display: grid; place-items: center; flex-shrink: 0; }
    .hw-main { min-width: 0; }
    .hw-scene-title { font-size: 1.15rem; color: var(--dt-ink); margin: 0 0 4px; }
    .hw-scene-copy { font-size: 0.85rem; color: var(--dt-ink-secondary); margin: 0 0 14px; }
    .hw-stage { position: relative; background: #fff; border: 1px solid var(--dt-border); border-radius: 18px; padding: 20px; height: 340px; overflow: hidden; box-shadow: var(--dt-shadow); }
    .hw-scene { position: absolute; inset: 20px; opacity: 0; transition: opacity 0.45s ease; pointer-events: none; overflow: hidden; }
    .hw-scene.hw-active { opacity: 1; pointer-events: auto; }
    .hw-paused .hw-scene *, .hw-paused .hw-cursor { animation-play-state: paused !important; }
    .hw-cursor { position: absolute; width: 16px; height: 16px; z-index: 5; pointer-events: none; opacity: 0;
      background: #1e3a5f; border-radius: 50% 50% 50% 2px; box-shadow: 0 1px 4px rgba(0,0,0,0.3); }
    .hw-anim .hw-cursor { animation: hw-cursormove 9.5s ease forwards; }
    @keyframes hw-cursormove {
      0% { opacity: 0; right: 20px; top: 40%; }
      6% { opacity: 1; right: 20px; top: 40%; }
      14%,26% { opacity: 1; right: 24px; top: 46%; }
      34% { opacity: 1; right: 46%; top: 50%; }
      44%,60% { opacity: 1; right: 46%; top: 50%; }
      70% { opacity: 1; right: 88%; top: 6%; }
      78%,92% { opacity: 1; right: 88%; top: 6%; }
      100% { opacity: 0; right: 88%; top: 6%; }
    }
    /* board (scenes 1+6 shared) */
    .hw-boardX { display: flex; gap: 8px; height: 100%; }
    .hw-colx { flex: 1; min-width: 0; background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 10px; padding: 8px; overflow: hidden; }
    .hw-colx-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; }
    .hw-colx-name { font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dt-ink-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hw-colx-edit { font-size: 0.6rem; color: var(--dt-ink-muted); }
    .hw-casecard { background: #fff; border: 1px solid var(--dt-border); border-left: 3px solid var(--dt-blue); border-radius: 7px; padding: 6px 8px; font-size: 0.68rem; font-weight: 600; color: var(--dt-ink); margin-bottom: 6px; box-shadow: 0 2px 6px -2px rgba(30,58,95,0.12); }
    .hw-cc-meta { display: block; font-size: 0.56rem; font-weight: 400; color: var(--dt-ink-muted); margin-top: 2px; }
    .hw-casecard-dim { opacity: 0.4; border-left-color: var(--dt-border); }
    .hw-badge { float: right; font-size: 0.52rem; font-weight: 700; padding: 1px 6px; border-radius: 999px; }
    .hw-b-pms { background: #eef4fb; color: #2563eb; } .hw-b-scan { background: #e0f7f5; color: #0ea5a8; } .hw-b-man { background: #f3eafb; color: #8b5cf6; }
    /* scene 1 choreography */
    .hw-addstage { flex: 0 0 34px; background: var(--dt-warm); border: 1.5px dashed var(--dt-border); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: var(--dt-ink-muted); writing-mode: vertical-rl; }
    .hw-plus { font-size: 0.85rem; font-weight: 700; }
    .hw-anim .hw-addstage { animation: hw-btnpulse 0.9s ease 1.2s 2; }
    @keyframes hw-btnpulse { 0%,100% { box-shadow: 0 0 0 0 rgba(37,99,235,0); } 50% { box-shadow: 0 0 0 4px rgba(37,99,235,0.35); border-color: var(--dt-blue); } }
    .hw-typebox { position: absolute; left: 50%; top: 42%; transform: translate(-50%,-50%); display: flex; gap: 6px; background: #fff; border: 1.5px solid var(--dt-blue); border-radius: 8px; padding: 6px; box-shadow: 0 8px 22px -8px rgba(0,0,0,0.25); opacity: 0; z-index: 4; }
    .hw-typeinput { border: none; font-size: 0.78rem; width: 110px; color: var(--dt-ink); outline: none; }
    .hw-okbtn { background: var(--dt-blue); color: #fff; border-radius: 6px; padding: 2px 8px; font-size: 0.75rem; }
    .hw-anim .hw-typebox { animation: hw-fadein 0.4s ease 2.6s forwards; }
    .hw-anim .hw-typeinput { animation: hw-type 1.4s steps(13) 3s both; }
    @keyframes hw-type { from { width: 0; } to { width: 110px; } }
    .hw-anim .hw-typebox { animation: hw-typeboxlife 2.6s ease 2.6s both; }
    @keyframes hw-typeboxlife { 0% { opacity: 0; transform: translate(-50%,-50%) scale(0.9); } 12%,82% { opacity: 1; transform: translate(-50%,-50%) scale(1); } 100% { opacity: 0; } }
    .hw-colx-new { flex: 0 0 0 !important; opacity: 0; overflow: hidden; padding: 0; border-width: 0; }
    .hw-anim .hw-colx-new { animation: hw-colexpand 1.1s ease 5.4s forwards; }
    @keyframes hw-colexpand { from { flex: 0 0 0; opacity: 0; } to { flex: 1 1 0; opacity: 1; padding: 8px; border-width: 1px; } }
    .hw-anim .hw-colx-edit { animation: hw-btnpulse 0.9s ease 6.8s 2; }
    .hw-colx-name { position: relative; }
    .hw-anim #hwRenameTarget::after { content: '<?php echo t("marketing.walkthrough.stage_ready"); ?>'; position: absolute; left: 0; top: 0; background: #fef9e7; color: var(--dt-ink); opacity: 0; animation: hw-swap 2.6s ease 8s forwards; }
    @keyframes hw-swap { 0% { opacity: 0; } 15%,85% { opacity: 1; } 100% { opacity: 1; } }
    /* scene 2 */
    .hw-team { display: grid; grid-template-columns: 1.1fr 1fr; gap: 16px; height: 100%; }
    .hw-panel-head { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dt-ink-muted); margin-bottom: 10px; }
    .hw-field { display: flex; align-items: center; gap: 8px; background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 10px; padding: 9px 11px; font-size: 0.76rem; margin-bottom: 12px; }
    .hw-field-text { flex: 1; color: var(--dt-ink-secondary); white-space: nowrap; overflow: hidden; }
    .hw-anim .hw-field-text::after { content: 'maya.chen@example.com'; display: block; overflow: hidden; white-space: nowrap; animation: hw-typeline 1.6s steps(22) 0.8s both; }
    @keyframes hw-typeline { from { max-width: 0; } to { max-width: 100%; } }
    .hw-btn-mini { background: var(--dt-blue); color: #fff; border-radius: 7px; padding: 5px 12px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; }
    .hw-anim #hwInviteBtn { animation: hw-btnpulse 0.8s ease 2.6s 2; }
    .hw-emailcard { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid var(--dt-border); border-left: 3px solid var(--dt-blue); border-radius: 10px; padding: 10px 12px; font-size: 0.7rem; opacity: 0; }
    .hw-email-ic { font-size: 1.1rem; }
    .hw-emailcard b { display: block; font-size: 0.72rem; } .hw-email-sub { font-size: 0.6rem; color: var(--dt-ink-muted); }
    .hw-accept { margin-left: auto; }
    .hw-anim .hw-emailcard { animation: hw-slideup 0.5s ease 3.4s forwards; }
    @keyframes hw-slideup { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
    .hw-anim .hw-accept { animation: hw-btnpulse 0.8s ease 4.6s 2; }
    .hw-freenote { font-size: 0.62rem; color: var(--dt-ink-muted); margin-top: 12px; }
    .hw-roster { border-left: 1px solid var(--dt-border); padding-left: 16px; }
    .hw-person { display: flex; align-items: center; gap: 9px; background: #fff; border: 1px solid var(--dt-border); border-radius: 9px; padding: 8px 10px; margin-bottom: 7px; }
    .hw-av { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-size: 0.58rem; font-weight: 700; color: #fff; flex-shrink: 0; }
    .hw-av-b { background: var(--dt-blue); } .hw-av-p { background: #8b5cf6; } .hw-av-t { background: #0ea5a8; }
    .hw-p-info b { display: block; font-size: 0.72rem; color: var(--dt-ink); }
    .hw-p-info i { font-style: normal; font-size: 0.58rem; color: var(--dt-ink-muted); }
    .hw-join1, .hw-join2 { opacity: 0; }
    .hw-anim .hw-join1 { animation: hw-slideup 0.5s ease 5.6s forwards; }
    .hw-anim .hw-join2 { animation: hw-slideup 0.5s ease 7.4s forwards; }
    .hw-joined { margin-left: auto; font-size: 0.56rem; font-weight: 700; color: #0ea5a8; background: #e0f7f5; border-radius: 999px; padding: 2px 8px; }
    /* scene 3 */
    .hw-cases { height: 100%; display: flex; flex-direction: column; }
    .hw-srcrow { display: flex; gap: 10px; justify-content: space-between; margin-bottom: 10px; }
    .hw-srcbox { flex: 1; text-align: center; }
    .hw-srcwin { background: #fff; border: 1px solid var(--dt-border); border-radius: 8px; padding: 8px; min-height: 74px; box-shadow: 0 3px 8px -4px rgba(0,0,0,0.1); }
    .hw-srcwin-bar { height: 6px; border-radius: 3px; background: var(--dt-warm); margin-bottom: 6px; }
    .hw-srcwin-line { height: 5px; border-radius: 3px; background: #dbe7f6; margin-bottom: 5px; }
    .hw-srcwin-line.hw-w60 { width: 60%; } .hw-srcwin-line.hw-w80 { width: 80%; } .hw-srcwin-line.hw-w40 { width: 40%; }
    .hw-srcwin-hl { font-size: 0.56rem; text-align: left; background: #eef4fb; border-radius: 4px; padding: 3px 6px; margin-bottom: 5px; color: var(--dt-ink); }
    .hw-scanart { width: 100%; max-width: 80px; }
    .hw-src-label { display: block; font-size: 0.66rem; font-weight: 700; color: var(--dt-ink); margin-top: 5px; }
    .hw-src-vendor { font-size: 0.56rem; color: var(--dt-ink-muted); }
    .hw-anim .hw-srcbox { animation: hw-srcglow 1.1s ease both; }
    .hw-anim #hwSrcPms { animation-delay: 0.6s; } .hw-anim #hwSrcScan { animation-delay: 3.2s; } .hw-anim #hwSrcManual { animation-delay: 6s; }
    @keyframes hw-srcglow { 0%,100% { transform: none; } 30%,70% { transform: scale(1.05); box-shadow: 0 0 0 3px rgba(37,99,235,0.2); } }
    .hw-inboxbar { display: flex; align-items: center; gap: 8px; background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 9px; padding: 7px 10px; font-size: 0.68rem; opacity: 0; justify-content: center; }
    .hw-inbox-tag { color: var(--dt-ink-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .hw-anim .hw-inboxbar { animation: hw-slideup 0.5s ease 4.2s forwards; }
    .hw-anim #hwInboxSel { animation: hw-btnpulse 0.7s ease 4.8s 1; }
    .hw-anim #hwInboxCreate { animation: hw-btnpulse 0.7s ease 5.3s 1; }
    .hw-btn-ghost { background: #fff; color: var(--dt-blue); border: 1px solid var(--dt-blue); }
    .hw-newcases { display: flex; align-items: center; gap: 8px; margin-top: auto; flex-wrap: wrap; }
    .hw-nc-head { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; color: var(--dt-ink-muted); width: 100%; margin-bottom: 2px; }
    .hw-born { opacity: 0; transform: translateY(12px) scale(0.92); }
    .hw-anim .hw-born1 { animation: hw-born 0.55s ease 2.4s forwards; }
    .hw-anim .hw-born2 { animation: hw-born 0.55s ease 5.6s forwards; }
    .hw-anim .hw-born3 { animation: hw-born 0.55s ease 8.2s forwards; }
    @keyframes hw-born { to { opacity: 1; transform: none; } }
    .hw-planline { font-size: 0.6rem; color: var(--dt-ink-muted); margin-top: 8px; }
    /* scene 4 */
    .hw-assignp { display: grid; grid-template-columns: 1fr 1.15fr; gap: 14px; height: 100%; }
    .hw-caseX { background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 12px; padding: 14px; height: fit-content; }
    .hw-caseX-head { font-size: 0.8rem; font-weight: 700; color: var(--dt-ink); margin-bottom: 12px; }
    .hw-assignedto { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .hw-at-label { font-size: 0.62rem; text-transform: uppercase; color: var(--dt-ink-muted); font-weight: 700; }
    .hw-at-val { font-size: 0.74rem; font-weight: 600; color: var(--dt-ink); background: #fff; border: 1px solid var(--dt-border); border-radius: 7px; padding: 4px 10px; transition: background 0.3s; }
    .hw-picker { background: #fff; border: 1px solid var(--dt-border); border-radius: 12px; padding: 10px; box-shadow: 0 10px 26px -12px rgba(0,0,0,0.2); opacity: 0; transform: translateY(8px); }
    .hw-anim .hw-picker { animation: hw-picklife 10.5s linear forwards; }
    @keyframes hw-picklife { 0%,7% { opacity: 0; transform: translateY(8px); } 12%,92% { opacity: 1; transform: none; } 100% { opacity: 0; } }
    .hw-pick-group { font-size: 0.56rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dt-ink-muted); margin: 6px 0 4px; }
    .hw-pick { display: flex; align-items: center; gap: 8px; border: 1px solid var(--dt-border); border-radius: 8px; padding: 6px 9px; margin-bottom: 5px; }
    .hw-anim .hw-pick[data-who="Maya Chen"] { animation: hw-picksel 10.5s linear forwards; }
    .hw-anim .hw-pick[data-who="Dr. Alex Rivera"] { animation: hw-picksel2 10.5s linear forwards; }
    .hw-anim .hw-pick-lab { animation: hw-picksel3 10.5s linear forwards; }
    @keyframes hw-picksel { 0%,15% { box-shadow: none; border-color: var(--dt-border); } 20%,32% { border-color: var(--dt-blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.2); } 36%,100% { box-shadow: none; border-color: var(--dt-border); } }
    @keyframes hw-picksel2 { 0%,48% { box-shadow: none; border-color: var(--dt-border); } 53%,64% { border-color: var(--dt-blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.2); } 68%,100% { box-shadow: none; border-color: var(--dt-border); } }
    @keyframes hw-picksel3 { 0%,78% { box-shadow: none; border-color: var(--dt-border); } 83%,96% { border-color: var(--dt-blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.2); } 100% { border-color: var(--dt-blue); box-shadow: 0 0 0 3px rgba(14,165,168,0.25); } }
    .hw-at-val { position: relative; min-width: 96px; min-height: 1.1em; }
    .hw-at-n { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); white-space: nowrap; opacity: 0; }
    .hw-anim .hw-n0 { animation: hw-n0 10.5s linear forwards; }
    .hw-anim .hw-n1 { animation: hw-n1 10.5s linear forwards; }
    .hw-anim .hw-n2 { animation: hw-n2 10.5s linear forwards; }
    .hw-anim .hw-n3 { animation: hw-n3 10.5s linear forwards; }
    @keyframes hw-n0 { 0%,24% { opacity: 1; color: var(--dt-ink-muted); } 25%,100% { opacity: 0; } }
    @keyframes hw-n1 { 0%,24% { opacity: 0; } 25%,57% { opacity: 1; } 58%,100% { opacity: 0; } }
    @keyframes hw-n2 { 0%,57% { opacity: 0; } 58%,85% { opacity: 1; } 86%,100% { opacity: 0; } }
    @keyframes hw-n3 { 0%,85% { opacity: 0; } 86%,100% { opacity: 1; } }
    .hw-anim #hwPickBtn { animation: hw-pickbtnpulse 10.5s linear forwards; }
    @keyframes hw-pickbtnpulse { 0%,4%,20%,36%,52%,68%,84% { box-shadow: none; } 8%,14%,40%,46%,72%,78% { box-shadow: 0 0 0 3px rgba(37,99,235,0.35); } 100% { box-shadow: none; } }
    /* scene 5 */
    .hw-detailp { height: 100%; position: relative; }
    .hw-dp-head { background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 10px; padding: 9px 14px; margin-bottom: 10px; }
    .hw-dp-head b { font-size: 0.8rem; color: var(--dt-ink); } .hw-dp-head span { font-size: 0.62rem; color: var(--dt-ink-muted); margin-left: 8px; }
    .hw-dp-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .hw-dp-sec { font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dt-ink-muted); margin: 8px 0 5px; }
    .hw-noteX { background: #fef9e7; border-left: 3px solid #f59e0b; border-radius: 7px; padding: 7px 9px; font-size: 0.66rem; color: var(--dt-ink-secondary); opacity: 0; }
    .hw-anim .hw-noteX { animation: hw-slideup 0.5s ease 1.6s forwards; }
    .hw-thumbs { display: flex; gap: 8px; }
    .hw-thumb { width: 44px; height: 33px; border-radius: 6px; border: 1px solid var(--dt-border); overflow: hidden; background: #fff; opacity: 0; }
    .hw-anim #hwThumb1 { animation: hw-slideup 0.5s ease 5.6s forwards; }
    .hw-anim #hwThumb2 { animation: hw-slideup 0.5s ease 6.2s forwards; }
    .hw-upbar { font-size: 0.58rem; color: var(--dt-ink-muted); border: 1px dashed var(--dt-border); border-radius: 7px; padding: 5px 8px; margin-top: 8px; text-align: center; }
    .hw-anim .hw-upbar { animation: hw-btnpulse 0.8s ease 5s 1; }
    .hw-cmt { display: flex; gap: 8px; background: #eef4fb; border-radius: 8px; padding: 7px 9px; margin-bottom: 6px; opacity: 0; }
    .hw-cmt .hw-av { width: 22px; height: 22px; font-size: 0.5rem; }
    .hw-anim #hwCmt1 { animation: hw-slideup 0.5s ease 2.8s forwards; }
    .hw-anim #hwCmt2 { animation: hw-slideup 0.5s ease 4.4s forwards; }
    .hw-cmtbox { display: flex; align-items: center; gap: 6px; border: 1px solid var(--dt-border); border-radius: 8px; padding: 6px 9px; font-size: 0.64rem; color: var(--dt-ink-muted); opacity: 0; }
    .hw-anim .hw-cmtbox { animation: hw-fadein 0.4s ease 2.2s forwards; }
    .hw-sendbtn { margin-left: auto; color: var(--dt-blue); font-weight: 700; }
    .hw-imgview { position: absolute; inset: 20% 15%; background: #fff; border: 1px solid var(--dt-border); border-radius: 12px; display: grid; place-items: center; box-shadow: 0 16px 40px -12px rgba(0,0,0,0.3); opacity: 0; pointer-events: none; }
    .hw-anim .hw-imgview { animation: hw-imgpop 2.8s ease 7s both; }
    @keyframes hw-imgpop { 0% { opacity: 0; transform: scale(0.7); } 15%,80% { opacity: 1; transform: scale(1); } 100% { opacity: 0; transform: scale(0.8); } }
    /* scene 6 */
    .hw-board6 .hw-casecard { font-size: 0.6rem; padding: 5px 7px; }
    .hw-flag { display: inline-block; font-size: 0.5rem; font-weight: 700; border-radius: 999px; padding: 1px 7px; margin-top: 3px; }
    .hw-flag-amber { background: #fef3e2; color: #b45309; }
    .hw-flag-red { background: #fee2e2; color: #b91c1c; }
    .hw-anim #hwDueSoon { animation: hw-duecard 9.5s ease forwards; }
    @keyframes hw-duecard { 0%,18% { transform: none; box-shadow: 0 2px 6px -2px rgba(30,58,95,0.12); } 24%,42% { transform: translateY(-7px) scale(1.05); box-shadow: 0 12px 22px -8px rgba(180,83,9,0.4); border-left-color: #f59e0b; } 48%,100% { transform: none; border-left-color: #f59e0b; } }
    .hw-anim #hwDueSoon .hw-flag-amber { animation: hw-fadein 0.5s ease 2s both; }
    .hw-anim #hwLate { animation: hw-latecard 9.5s ease forwards; }
    @keyframes hw-latecard { 0%,52% { transform: none; } 58%,74% { transform: translateY(-7px) scale(1.05); box-shadow: 0 12px 22px -8px rgba(185,28,28,0.4); border-left-color: #b91c1c; } 80%,100% { transform: none; border-left-color: #b91c1c; } }
    .hw-anim #hwLate .hw-flag-red { animation: hw-fadein 0.5s ease 5.6s both; }
    @keyframes hw-fadein { from { opacity: 0; } to { opacity: 1; } }
    .hw-riskline { text-align: center; font-size: 0.66rem; color: var(--dt-ink-muted); position: absolute; bottom: 4px; left: 0; right: 0; }
    /* scene 7 */
    .hw-dashp { height: 100%; display: flex; flex-direction: column; }
    .hw-dash-head { display: flex; align-items: baseline; justify-content: space-between; font-size: 0.8rem; font-weight: 700; color: var(--dt-ink); padding-bottom: 8px; border-bottom: 1px solid var(--dt-border); }
    .hw-sample { font-size: 0.58rem; font-weight: 400; color: var(--dt-ink-muted); }
    .hw-dash-scroll { flex: 1; overflow: hidden; position: relative; }
    .hw-anim .hw-dash-scroll-inner { animation: hw-scrollup 6s ease-in-out 4.5s forwards; }
    .hw-chartbox { background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 10px; padding: 9px 12px; margin-top: 9px; }
    .hw-cb-title { font-size: 0.62rem; font-weight: 700; color: var(--dt-ink); margin-bottom: 5px; }
    .hw-kpis { display: flex; gap: 12px; justify-content: space-around; margin-top: 10px; }
    .hw-kpi { text-align: center; background: #fff; border: 1px solid var(--dt-border); border-radius: 10px; padding: 8px 16px; opacity: 0; }
    .hw-kpi-v { display: block; font-size: 1.2rem; font-weight: 800; color: var(--dt-blue); }
    .hw-kpi-l { font-size: 0.58rem; color: var(--dt-ink-muted); }
    .hw-anim .hw-kpi { animation: hw-slideup 0.5s ease both; }
    .hw-anim .hw-kpi:nth-child(2) { animation-delay: 0.3s; } .hw-anim .hw-kpi:nth-child(3) { animation-delay: 0.6s; }
    .hw-linechart { width: 100%; height: 60px; }
    .hw-anim .hw-linechart polyline { stroke-dasharray: 340; stroke-dashoffset: 340; animation: hw-draw 1.6s ease 1s forwards; }
    .hw-anim .hw-linechart circle { opacity: 0; animation: hw-fadein 0.4s ease 2.4s forwards; }
    @keyframes hw-draw { to { stroke-dashoffset: 0; } }
    .hw-hbars { display: flex; flex-direction: column; gap: 5px; }
    .hw-hbar { display: flex; align-items: center; gap: 8px; font-size: 0.6rem; }
    .hw-hbar i { font-style: normal; width: 70px; color: var(--dt-ink-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hw-hbar b { font-size: 0.6rem; color: var(--dt-ink); }
    .hw-hbar-fill { height: 9px; border-radius: 5px; background: var(--dt-blue); opacity: 0.8; }
    .hw-anim .hw-hbar-fill { transform-origin: left; animation: hw-barin 0.9s ease both; }
    .hw-anim .hw-hbar:nth-child(1) .hw-hbar-fill { animation-delay: 5.4s; } .hw-anim .hw-hbar:nth-child(2) .hw-hbar-fill { animation-delay: 5.7s; } .hw-anim .hw-hbar:nth-child(3) .hw-hbar-fill { animation-delay: 6s; } .hw-anim .hw-hbar:nth-child(4) .hw-hbar-fill { animation-delay: 6.3s; }
    @keyframes hw-barin { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    @keyframes hw-scrollup { 0% { transform: translateY(0); } 100% { transform: translateY(-46%); } }
    .hw-controls { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
    .hw-ctl { background: #fff; border: 1px solid var(--dt-border); border-radius: 8px; width: 30px; height: 30px; display: grid; place-items: center; cursor: pointer; font-size: 0.9rem; color: var(--dt-ink-secondary); }
    .hw-ctl:hover, .hw-ctl:focus-visible { border-color: var(--dt-blue); color: var(--dt-ink); outline: none; }
    .hw-pause, .hw-replay { width: auto; padding: 0 14px; font-size: 0.7rem; }
    .hw-dots { display: flex; gap: 5px; align-items: center; }
    .hw-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--dt-border); }
    .hw-dot.on { background: var(--dt-blue); }
    .hw-cta { display: inline-block; margin-top: 16px; }
    @media (max-width: 900px) {
      .hw-walk { grid-template-columns: 1fr; }
      .hw-steps { display: none; }
      .hw-stage { height: 300px; padding: 12px; }
      .hw-scene { inset: 12px; }
      .hw-team, .hw-assignp, .hw-dp-cols { grid-template-columns: 1fr; gap: 8px; }
      .hw-roster { border-left: none; padding-left: 0; }
      .hw-boardX { gap: 5px; } .hw-colx { padding: 5px; } .hw-casecard { font-size: 0.54rem; padding: 4px 5px; }
      .hw-addstage { display: none; }
      .hw-controls { justify-content: center; }
    }
    @media (prefers-reduced-motion: reduce) {
      .hw-scene * { animation: none !important; opacity: 1 !important; transform: none !important; }
      .hw-scene { transition: none; }
      .hw-pause { display: none; }
      .hw-typebox, .hw-picker, .hw-emailcard, .hw-imgview, .hw-inboxbar, .hw-born, .hw-cmt, .hw-cmtbox, .hw-noteX, .hw-thumb, .hw-kpi { opacity: 1 !important; }
      .hw-colx-new { flex: 1 1 0; opacity: 1; padding: 8px; border-width: 1px; }
    }
    /* Compare checklist panel */
    .cmp-panel { max-width: 760px; margin: 40px auto 0; background: #fff; border: 1px solid var(--dt-border); border-radius: 18px; overflow: hidden; box-shadow: var(--dt-shadow); }
    .cmp-head { display: grid; grid-template-columns: 1fr 170px; }
    .cmp-h-q { padding: 14px 22px; font-size: 0.78rem; font-weight: 700; color: var(--dt-ink-secondary); }
    .cmp-h-a { padding: 14px 18px; font-size: 0.82rem; font-weight: 800; color: var(--dt-blue); background: #eef4fb; border-left: 1px solid var(--dt-border); text-align: center; }
    .cmp-row { display: grid; grid-template-columns: 1fr 170px; border-top: 1px solid var(--dt-border); }
    .cmp-row:nth-child(even) { background: #fafcfe; }
    .cmp-q { padding: 16px 22px; }
    .cmp-q-text { display: block; font-size: 0.88rem; font-weight: 600; color: var(--dt-ink); line-height: 1.4; }
    .cmp-q-sub { display: block; font-size: 0.74rem; color: var(--dt-ink-muted); margin-top: 3px; }
    .cmp-a { display: grid; place-items: center; background: #f4f9fe; border-left: 1px solid var(--dt-border); padding: 14px 10px; }
    .cmp-check svg { width: 30px; height: 30px; }
    .cmp-ck-circle { fill: #e0f7f5; stroke: #0ea5a8; stroke-width: 1.4; }
    .cmp-ck-path { stroke: #0ea5a8; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 16; stroke-dashoffset: 16; }
    .is-revealed .cmp-ck-path { animation: cmp-draw 0.45s ease 0.15s forwards; }
    @keyframes cmp-draw { to { stroke-dashoffset: 0; } }
    .cmp-note { padding: 10px 22px; font-size: 0.68rem; color: var(--dt-ink-muted); border-top: 1px solid var(--dt-border); }
    .cmp-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
    @media (max-width: 620px) {
      .cmp-head, .cmp-row { grid-template-columns: 1fr 76px; }
      .cmp-h-a { font-size: 0.7rem; padding: 12px 8px; }
      .cmp-q { padding: 13px 16px; }
      .cmp-check svg { width: 26px; height: 26px; }
    }
    @media (prefers-reduced-motion: reduce) { .cmp-ck-path { stroke-dashoffset: 0 !important; animation: none !important; } }
    /* Ask DentaTrak animated demo */
    .ask-demo { display: flex; flex-direction: column; height: 100%; }
    .ask-body { display: flex; flex-direction: column; gap: 10px; padding: 16px 18px; min-height: 150px; }
    .ask-msg { max-width: 88%; padding: 11px 15px; font-size: 1rem; line-height: 1.55; }
    .ask-msg.user, .ask-msg.ai { animation: ask-in 0.35s ease both; }
    @keyframes ask-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    .ask-dots { display: inline-flex; gap: 4px; align-self: flex-start; background: var(--dt-warm); border: 1px solid var(--dt-border); border-radius: 14px; padding: 11px 14px; }
    .ask-dots span { width: 6px; height: 6px; border-radius: 50%; background: var(--dt-ink-muted); animation: ask-dot 1s ease-in-out infinite; }
    .ask-dots span:nth-child(2) { animation-delay: 0.15s; } .ask-dots span:nth-child(3) { animation-delay: 0.3s; }
    @keyframes ask-dot { 0%,60%,100% { transform: none; opacity: 0.5; } 30% { transform: translateY(-3px); opacity: 1; } }
    .ask-typed { color: var(--dt-ink-secondary); }
    .ask-caret { display: inline-block; width: 2px; height: 1em; background: var(--dt-blue); vertical-align: text-bottom; animation: ask-blink 0.9s steps(1) infinite; }
    @keyframes ask-blink { 50% { opacity: 0; } }
    .ask-demo-foot { display: flex; align-items: center; justify-content: space-between; padding: 0 18px 14px; }
    .ask-example { font-size: 0.62rem; color: var(--dt-ink-muted); }
    .ask-demo-ctl { display: flex; gap: 6px; }
    .ask-ctl { background: #fff; border: 1px solid var(--dt-border); border-radius: 7px; min-width: 26px; height: 26px; padding: 0 9px; display: grid; place-items: center; cursor: pointer; font-size: 0.72rem; color: var(--dt-ink-secondary); }
    .ask-ctl:hover, .ask-ctl:focus-visible { border-color: var(--dt-blue); color: var(--dt-ink); outline: none; }
    .ask-paused .ask-caret, .ask-paused .ask-dots span, .ask-paused .ask-msg { animation-play-state: paused !important; }
    @media (max-width: 720px) { .ask-msg { font-size: 0.94rem; } .ask-body { min-height: 130px; } }
    @media (prefers-reduced-motion: reduce) {
      .ask-msg, .ask-dots span, .ask-caret { animation: none !important; }
      .ask-caret { display: none; }
      #askPause { display: none; }
    }
    /* Security illustration */
    .sec-illus { position: relative; max-width: 640px; margin: 0 auto 30px; height: 300px; }
    .sec-piece { position: absolute; background: rgba(255,255,255,0.96); border-radius: 10px; padding: 9px 12px; font-size: 0.62rem; color: #1e3a5f; box-shadow: 0 8px 20px -8px rgba(0,0,0,0.35); z-index: 3; }
    .sec-piece-head { font-size: 0.56rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #5c6b80; margin-bottom: 5px; }
    .sec-piece-line { height: 5px; border-radius: 3px; background: #dbe7f6; width: 90px; margin-bottom: 4px; }
    .sec-piece-line.sec-w60 { width: 56px; } .sec-piece-line.sec-w80 { width: 72px; }
    .sec-p-fields { left: 0; top: 12%; }
    .sec-p-imgs { right: 0; top: 8%; display: flex; gap: 6px; }
    .sec-thumb { width: 36px; height: 28px; border-radius: 5px; background: linear-gradient(135deg,#dbe7f6,#0ea5a8); border: 1px solid #c7d3e6; }
    .sec-p-doc { left: 0; bottom: 22%; display: flex; align-items: center; gap: 8px; }
    .sec-doc-svg { width: 22px; height: 28px; }
    .sec-p-chat { right: 0; bottom: 26%; display: flex; flex-direction: column; gap: 5px; align-items: flex-end; }
    .sec-bubble { width: 64px; height: 12px; border-radius: 8px; background: #dbe7f6; }
    .sec-bubble.sec-b2 { width: 46px; background: #bcd3ef; }
    .sec-case { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: 300px; background: rgba(255,255,255,0.97); border-radius: 14px; overflow: visible; z-index: 2; box-shadow: 0 16px 38px -12px rgba(0,0,0,0.4); }
    .sec-case-head { background: #eef4fb; border-radius: 14px 14px 0 0; padding: 10px 16px; border-bottom: 1px solid #c7d3e6; }
    .sec-case-head b { font-size: 0.8rem; color: #1e3a5f; } .sec-case-head span { font-size: 0.62rem; color: #5c6b80; margin-left: 8px; }
    .sec-case-body { padding: 12px 16px 14px; display: grid; grid-template-columns: 1fr auto; gap: 10px 12px; align-items: start; }
    .sec-s-fields .sec-piece-line { width: 120px; }
    .sec-s-imgs { display: flex; gap: 6px; }
    .sec-s-chat { display: flex; flex-direction: column; gap: 5px; grid-column: 1 / -1; }
    .sec-shield { position: absolute; top: -16px; right: -14px; width: 46px; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.25)); }
    .sec-lockshackle { transform-origin: 22px 19px; }
    .sec-boundary { position: absolute; inset: -14px; width: calc(100% + 28px); height: calc(100% + 28px); pointer-events: none; }
    .sec-bpath { fill: none; stroke: #0ea5a8; stroke-width: 2; stroke-dasharray: 8 7; }
    .sec-member { position: absolute; left: 50%; bottom: -8px; transform: translateX(-50%); display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.97); border-radius: 10px; padding: 8px 14px; z-index: 4; box-shadow: 0 8px 20px -8px rgba(0,0,0,0.35); }
    .sec-member-av { width: 26px; height: 26px; border-radius: 50%; background: #8b5cf6; color: #fff; font-size: 0.52rem; font-weight: 700; display: grid; place-items: center; }
    .sec-member-info b { display: block; font-size: 0.66rem; color: #1e3a5f; } .sec-member-info i { font-style: normal; font-size: 0.54rem; color: #5c6b80; }
    .sec-member-check { font-size: 0.56rem; font-weight: 700; color: #0ea5a8; background: #e0f7f5; border-radius: 999px; padding: 2px 8px; }
    .sec-link { position: absolute; left: 50%; bottom: 42px; width: 20px; height: 60px; transform: translateX(-50%); }
    .sec-linkpath { stroke: #0ea5a8; stroke-width: 2; stroke-dasharray: 5 5; }
    .sec-proofs { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px 22px; }
    .sec-proof { display: flex; align-items: center; gap: 8px; font-size: 0.72rem; }
    .sec-proof svg { width: 18px; height: 18px; color: #93c5fd; flex-shrink: 0; }
    .sec-proof b { font-weight: 600; color: #fff; white-space: nowrap; }
    .sec-proof i { font-style: normal; color: rgba(255,255,255,0.55); font-size: 0.62rem; }
    /* animation (plays once when the panel reveals) */
    .is-revealed .sec-p-fields, .is-revealed .sec-p-imgs, .is-revealed .sec-p-doc, .is-revealed .sec-p-chat { animation: sec-fadein 0.5s ease both; }
    .is-revealed .sec-p-fields { animation-name: sec-to-fields; animation-duration: 1.8s; }
    .is-revealed .sec-p-imgs { animation-name: sec-to-imgs; animation-duration: 1.8s; animation-delay: 0.25s; }
    .is-revealed .sec-p-doc { animation-name: sec-to-doc; animation-duration: 1.8s; animation-delay: 0.5s; }
    .is-revealed .sec-p-chat { animation-name: sec-to-chat; animation-duration: 1.8s; animation-delay: 0.75s; }
    @keyframes sec-fadein { from { opacity: 0; } to { opacity: 1; } }
    @keyframes sec-to-fields { 0% { opacity: 0; transform: translate(0,0); } 25% { opacity: 1; } 70%,100% { opacity: 0; transform: translate(160px,46px) scale(0.7); } }
    @keyframes sec-to-imgs { 0% { opacity: 0; transform: translate(0,0); } 25% { opacity: 1; } 70%,100% { opacity: 0; transform: translate(-170px,50px) scale(0.7); } }
    @keyframes sec-to-doc { 0% { opacity: 0; transform: translate(0,0); } 25% { opacity: 1; } 70%,100% { opacity: 0; transform: translate(170px,-32px) scale(0.7); } }
    @keyframes sec-to-chat { 0% { opacity: 0; transform: translate(0,0); } 25% { opacity: 1; } 70%,100% { opacity: 0; transform: translate(-160px,-30px) scale(0.7); } }
    .sec-case-body .sec-slot { opacity: 0; }
    .is-revealed .sec-s-fields { animation: sec-fadein 0.4s ease 1.5s forwards; }
    .is-revealed .sec-s-imgs { animation: sec-fadein 0.4s ease 1.7s forwards; }
    .is-revealed .sec-s-doc { animation: sec-fadein 0.4s ease 1.9s forwards; }
    .is-revealed .sec-s-chat { animation: sec-fadein 0.4s ease 2.1s forwards; }
    .sec-bpath { stroke-dashoffset: 1300; stroke-dasharray: 1300; }
    .is-revealed .sec-bpath { animation: sec-draw 1.3s ease 2.4s forwards; }
    @keyframes sec-draw { to { stroke-dashoffset: 0; } }
    .sec-shield { opacity: 0; transform: scale(0.5); }
    .is-revealed .sec-shield { animation: sec-lock 0.6s cubic-bezier(0.34,1.56,0.64,1) 3.6s forwards; }
    @keyframes sec-lock { from { opacity: 0; transform: scale(0.5); } to { opacity: 1; transform: scale(1); } }
    .sec-lockshackle { stroke-dasharray: 40; stroke-dashoffset: 40; }
    .is-revealed .sec-lockshackle { animation: sec-shackle 0.5s ease 4s forwards; }
    @keyframes sec-shackle { to { stroke-dashoffset: 0; } }
    .sec-member, .sec-link { opacity: 0; }
    .is-revealed .sec-link { animation: sec-fadein 0.4s ease 4.5s forwards; }
    .is-revealed .sec-member { animation: sec-memberin 0.6s ease 4.7s forwards; }
    @keyframes sec-memberin { from { opacity: 0; transform: translate(-50%,10px); } to { opacity: 1; transform: translate(-50%,0); } }
    @media (max-width: 720px) {
      .sec-illus { height: 250px; }
      .sec-case { width: 240px; }
      .sec-p-imgs, .sec-p-chat { display: none; }
      .sec-s-fields .sec-piece-line { width: 80px; }
      .sec-proofs { gap: 8px 14px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .sec-piece { display: none; }
      .sec-case-body .sec-slot, .sec-shield, .sec-member, .sec-link { opacity: 1 !important; animation: none !important; transform: none; }
      .sec-bpath { stroke-dashoffset: 0 !important; stroke-dasharray: 8 7 !important; }
      .sec-lockshackle { stroke-dashoffset: 0 !important; }
      .sec-member { transform: translateX(-50%); }
    }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="container">
      <a href="<?= $baseUrl ?>" class="site-logo" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>">
        <img src="<?= $baseUrl ?>images/main.png" alt="<?php echo htmlspecialchars(t('marketing.accessibility.logo_alt')); ?>">
      </a>
      <nav class="site-nav" aria-label="<?php echo htmlspecialchars(t('marketing.navigation.primary_nav')); ?>">
        <a href="#problem"><?php echo t('marketing.navigation.problem'); ?></a>
        <a href="#how-it-works"><?php echo t('marketing.navigation.how_it_works'); ?></a>
        <a href="#pricing"><?php echo t('marketing.navigation.pricing'); ?></a>
        <span class="nav-divider" aria-hidden="true"></span>
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t('marketing.navigation.resources'); ?></a>
        <a href="<?= $baseUrl ?>login.php"><?php echo t('marketing.navigation.sign_in'); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t('marketing.navigation.start_free'); ?></a>
        <?php echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false); ?>
      </nav>
      <div class="nav-collapsed">
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t('marketing.navigation.start_free'); ?></a>
        <button type="button" class="nav-toggle" id="nav-menu-toggle" aria-expanded="false" aria-controls="nav-menu" aria-label="<?php echo htmlspecialchars(t('marketing.navigation.menu_open')); ?>">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="2.5" y1="5" x2="17.5" y2="5"/><line x1="2.5" y1="10" x2="17.5" y2="10"/><line x1="2.5" y1="15" x2="17.5" y2="15"/></svg>
        </button>
      </div>
    </div>
    <div class="nav-menu" id="nav-menu" hidden>
      <nav aria-label="<?php echo htmlspecialchars(t('marketing.navigation.primary_nav')); ?>">
        <a class="nav-link" href="#problem"><?php echo t('marketing.navigation.problem'); ?></a>
        <a class="nav-link" href="#how-it-works"><?php echo t('marketing.navigation.how_it_works'); ?></a>
        <a class="nav-link" href="#pricing"><?php echo t('marketing.navigation.pricing'); ?></a>
        <a class="nav-link" href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t('marketing.navigation.resources'); ?></a>
        <a class="nav-link" href="<?= $baseUrl ?>login.php"><?php echo t('marketing.navigation.sign_in'); ?></a>
      </nav>
      <div class="nav-menu-lang"><?php echo renderLanguageSelector('api/set-session-locale.php?nav=mobile', getResolvedLocale(), false); ?></div>
    </div>
  </header>

  <section class="hero">
    <div class="container">
      <h1><?php echo t('marketing.hero.title'); ?></h1>
      <p class="lead"><?php echo t('marketing.hero.lead'); ?></p>
      <div class="hero-actions">
        <a href="<?= $baseUrl ?>login.php" class="btn btn-primary"><?php echo t('marketing.hero.start_free'); ?></a>
        <a href="#how-it-works" class="btn btn-secondary"><?php echo t('marketing.hero.see_how'); ?></a>
      </div>

      <div class="product-preview">
        <div class="app-header">
          <div class="app-brand">
            <img src="images/main.png" alt="" aria-hidden="true">
          </div>
          <span class="app-header-right">
            <span class="app-practice">Demo Dental Practice</span>
          </span>
        </div>
        <div class="app-tabs" role="tablist" aria-label="Product preview">
          <button class="app-tab active" id="demo-tab-cases" role="tab" aria-selected="true" aria-controls="demo-cases">Cases</button>
          <button class="app-tab" id="demo-tab-insights" role="tab" aria-selected="false" aria-controls="demo-insights" tabindex="-1">Insights</button>
          <span class="app-filter" id="demo-filter">Filters: All Cases</span>
        </div>
        <div class="app-views" id="demo-views">
          <div class="app-view active" id="demo-cases" role="tabpanel" aria-labelledby="demo-tab-cases">
            <div class="app-board">
              <div class="demo-toolbar">
                <div class="demo-view-toggle" role="group" aria-label="Case view">
                  <button type="button" id="demo-view-board" class="active" aria-pressed="true">Board</button>
                  <button type="button" id="demo-view-list" aria-pressed="false">List</button>
                </div>
              </div>
              <div id="demo-board-view">
              <div class="board">
                <div class="board-col">
                  <div class="board-stage stage-originated"><span>Originated</span><span class="stage-count">2</span></div>
                  <div class="case-card appt">
                    <h4>Hannah Lindqvist</h4>
                    <div class="case-type">Crown</div>
                    <div class="case-meta">Due: Mar 12</div>
                    <div class="case-meta">Patient Appt: Mar 10</div>
                    <div class="case-meta">Precision Dental Lab &middot; Dr. Rivera</div>
                    <span class="case-flag flag-appt">Appt Risk</span>
                  </div>
                  <div class="case-card">
                    <h4>Michael Torres</h4>
                    <div class="case-type">Implant</div>
                    <div class="case-meta">Due: Mar 18</div>
                    <div class="case-meta">Front Desk &middot; Atlas Dental Lab</div>
                  </div>
                </div>

                <div class="board-col">
                  <div class="board-stage stage-lab"><span>Sent To External Lab</span><span class="stage-count">2</span></div>
                  <div class="case-card late">
                    <h4>Justin Vance</h4>
                    <div class="case-type">Partial</div>
                    <div class="case-meta">Due: Mar 16</div>
                    <div class="case-meta">Precision Dental Lab</div>
                    <div class="case-meta">Revision 2</div>
                    <span class="case-flag flag-late">Late</span>
                  </div>
                  <div class="case-card">
                    <h4>Emily Sanders</h4>
                    <div class="case-type">Bridge</div>
                    <div class="case-meta">Due: Mar 18</div>
                    <div class="case-meta">Dr. Chen &middot; SmileCraft Lab</div>
                    <span class="case-flag flag-needs-review">Needs Review</span>
                  </div>
                </div>

                <div class="board-col hide-mobile">
                  <div class="board-stage stage-designed"><span>Designed</span><span class="stage-count">2</span></div>
                  <div class="case-card appt">
                    <h4>Sofia Patel</h4>
                    <div class="case-type">Veneer</div>
                    <div class="case-meta">Due: Mar 22</div>
                    <div class="case-meta">Patient Appt: Mar 14</div>
                    <div class="case-meta">Design Team</div>
                    <span class="case-flag flag-appt">Appt Risk</span>
                  </div>
                  <div class="case-card">
                    <h4>David Okafor</h4>
                    <div class="case-type">Crown</div>
                    <div class="case-meta">Due: Mar 23</div>
                    <div class="case-meta">Dr. Lin</div>
                  </div>
                </div>

                <div class="board-col hide-mobile">
                  <div class="board-stage stage-manufactured"><span>Manufactured</span><span class="stage-count">3</span></div>
                  <div class="case-card">
                    <h4>Ava Moreno</h4>
                    <div class="case-type">Crown</div>
                    <div class="case-meta">Due: Mar 24</div>
                    <div class="case-meta">Precision Dental Lab</div>
                  </div>
                  <div class="case-card due">
                    <h4>Noah Kim</h4>
                    <div class="case-type">Inlay</div>
                    <div class="case-meta">Due: Mar 26</div>
                    <div class="case-meta">Dr. Ortiz</div>
                    <span class="case-flag flag-due">Due Soon</span>
                  </div>
                  <div class="case-card">
                    <h4>Olivia Brooks</h4>
                    <div class="case-type">Onlay</div>
                    <div class="case-meta">Due: Mar 28</div>
                    <div class="case-meta">Dr. Patel</div>
                  </div>
                </div>

                <div class="board-col hide-mobile">
                  <div class="board-stage stage-received"><span>Received From External Lab</span><span class="stage-count">2</span></div>
                  <div class="case-card">
                    <h4>Marcus Webb</h4>
                    <div class="case-type">Implant</div>
                    <div class="case-meta">Seat: Mar 27</div>
                    <div class="case-meta">Patient Appt: Mar 19</div>
                    <div class="case-meta">Dr. Patel</div>
                  </div>
                  <div class="case-card">
                    <h4>Grace Hall</h4>
                    <div class="case-type">Bridge</div>
                    <div class="case-meta">Seat: Mar 29</div>
                    <div class="case-meta">Dr. Chen</div>
                  </div>
                </div>

                <div class="board-col hide-mobile">
                  <div class="board-stage stage-delivered"><span>Delivered</span><span class="stage-count">1</span></div>
                  <div class="case-card">
                    <h4>Isabella Reed</h4>
                    <div class="case-type">Crown</div>
                    <div class="case-meta">Delivered: Mar 4</div>
                    <div class="case-meta">Dr. Chen</div>
                    <span class="case-flag flag-reviewed">Reviewed</span>
                  </div>
                </div>
              </div>
              </div>

              <div id="demo-list-view" hidden>
                <div class="demo-list" role="table" aria-label="Case list preview">
                  <div class="demo-list-row demo-list-head" role="row">
                    <span aria-hidden="true"></span>
                    <span role="columnheader">Patient</span>
                    <span role="columnheader">Type</span>
                    <span role="columnheader" class="optional">Status</span>
                    <span role="columnheader" class="optional">Due</span>
                    <span role="columnheader">Review</span>
                  </div>
                  <div class="demo-list-row" role="row">
                    <span class="demo-caret" aria-hidden="true">&#9662;</span>
                    <span class="demo-list-name">Justin Vance</span>
                    <span>Partial</span>
                    <span class="optional"><span class="demo-list-status is-late">Sent To External Lab</span></span>
                    <span class="optional">Mar 16</span>
                    <span><span class="review-chip needs-review">Needs Review</span></span>
                  </div>
                  <div class="demo-list-detail">
                    <span><strong>Created</strong> Mar 2 &middot; Dr. Rivera</span>
                    <span><strong>Status Changed</strong> Mar 6</span>
                    <span><strong>Lab</strong> Precision Dental Lab</span>
                    <span><strong>Files</strong> shade_photo.jpg &middot; rx_form.pdf</span>
                    <span><strong>Notes</strong> Shade mismatch &mdash; verify photos before remake.</span>
                    <span><strong>Latest comment</strong> Front Desk: &ldquo;Lab confirmed the revision ships Mar 12.&rdquo;</span>
                  </div>
                  <div class="demo-list-row" role="row">
                    <span class="demo-caret" aria-hidden="true">&#9656;</span>
                    <span class="demo-list-name">Hannah Lindqvist</span>
                    <span>Crown</span>
                    <span class="optional"><span class="demo-list-status is-due">Originated</span></span>
                    <span class="optional">Mar 12</span>
                    <span><span class="review-chip needs-review">Needs Review</span></span>
                  </div>
                  <div class="demo-list-row" role="row">
                    <span class="demo-caret" aria-hidden="true">&#9656;</span>
                    <span class="demo-list-name">Sofia Patel</span>
                    <span>Veneer</span>
                    <span class="optional"><span class="demo-list-status">Designed</span></span>
                    <span class="optional">Mar 22</span>
                    <span><span class="review-chip reviewed">Reviewed</span></span>
                  </div>
                  <div class="demo-list-row" role="row">
                    <span class="demo-caret" aria-hidden="true">&#9656;</span>
                    <span class="demo-list-name">Marcus Webb</span>
                    <span>Implant</span>
                    <span class="optional"><span class="demo-list-status">Received</span></span>
                    <span class="optional">Mar 27</span>
                    <span><span class="review-chip reviewed">Reviewed</span></span>
                  </div>
                  <div class="demo-list-row" role="row">
                    <span class="demo-caret" aria-hidden="true">&#9656;</span>
                    <span class="demo-list-name">Isabella Reed</span>
                    <span>Crown</span>
                    <span class="optional"><span class="demo-list-status">Delivered</span></span>
                    <span class="optional">Mar 4</span>
                    <span><span class="review-chip reviewed">Reviewed</span></span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="app-view" id="demo-insights" role="tabpanel" aria-labelledby="demo-tab-insights" aria-hidden="true">
            <div class="demo-insights">
              <div class="di-header">
                <h3>Insights</h3>
                <p>Practice and lab performance in one place</p>
              </div>

              <div class="di-metrics" id="di-metrics">
                <div class="di-metric" data-target="11" data-prefix="">
                  <div class="di-metric-value" data-value="11">0</div>
                  <div class="di-metric-label">Active Cases</div>
                </div>
                <div class="di-metric due" data-target="1" data-prefix="">
                  <div class="di-metric-value" data-value="1">0</div>
                  <div class="di-metric-label">Due Soon</div>
                </div>
                <div class="di-metric late" data-target="1" data-prefix="">
                  <div class="di-metric-value" data-value="1">0</div>
                  <div class="di-metric-label">Late</div>
                </div>
                <div class="di-metric delivered" data-target="1" data-prefix="">
                  <div class="di-metric-value" data-value="1">0</div>
                  <div class="di-metric-label">Delivered</div>
                </div>
              </div>

              <div class="di-charts">
                <div class="di-chart">
                  <div class="di-chart-title">Case Flow Status</div>
                  <div class="di-donut" id="di-donut">
                    <svg viewBox="0 0 100 100" aria-label="Case flow status donut chart">
                      <circle class="di-donut-track" cx="50" cy="50" r="40"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#22c55e" stroke-dasharray="0 251.2" data-pct="59" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#3b82f6" stroke-dasharray="0 251.2" data-pct="8" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#8b5cf6" stroke-dasharray="0 251.2" data-pct="17" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#dc2626" stroke-dasharray="0 251.2" data-pct="8" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#14b8a6" stroke-dasharray="0 251.2" data-pct="8" transform="rotate(-90 50 50)"></circle>
                    </svg>
                  </div>
                  <div class="di-legend" aria-hidden="true">
                    <span><span class="di-legend-dot" style="background:#22c55e"></span>On Track (7)</span>
                    <span><span class="di-legend-dot" style="background:#3b82f6"></span>Due Soon (1)</span>
                    <span><span class="di-legend-dot" style="background:#8b5cf6"></span>Appt Risk (2)</span>
                    <span><span class="di-legend-dot" style="background:#dc2626"></span>Late (1)</span>
                    <span><span class="di-legend-dot" style="background:#14b8a6"></span>Delivered (1)</span>
                  </div>
                </div>

                <div class="di-chart">
                  <div class="di-chart-title">Monthly Case Volume</div>
                  <div class="di-line-chart" id="di-line-chart">
                    <svg viewBox="0 0 240 140" aria-label="Monthly case volume chart">
                      <defs>
                        <linearGradient id="diAreaGradient" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="0%" stop-color="rgba(30,64,175,0.18)"></stop>
                          <stop offset="100%" stop-color="rgba(30,64,175,0)"></stop>
                        </linearGradient>
                      </defs>
                      <line class="di-line-grid" x1="0" y1="35" x2="240" y2="35"></line>
                      <line class="di-line-grid" x1="0" y1="70" x2="240" y2="70"></line>
                      <line class="di-line-grid" x1="0" y1="105" x2="240" y2="105"></line>
                      <path class="di-line-area" d="M0,140 L0,110 C40,100 80,90 120,70 S200,40 240,20 L240,140 Z" style="fill:url(#diAreaGradient)"></path>
                      <path class="di-line-path" d="M0,110 C40,100 80,90 120,70 S200,40 240,20"></path>
                      <circle class="di-line-dot" cx="0" cy="110" r="3"></circle>
                      <circle class="di-line-dot" cx="120" cy="70" r="3"></circle>
                      <circle class="di-line-dot" cx="240" cy="20" r="3"></circle>
                      <text x="0" y="135" font-size="8" fill="#6b7280">Jan</text>
                      <text x="110" y="135" font-size="8" fill="#6b7280">Feb</text>
                      <text x="225" y="135" font-size="8" fill="#6b7280">Mar</text>
                    </svg>
                  </div>
                </div>
              </div>

              <div class="di-split">
                <div class="di-card">
                  <h4>Practice &amp; Lab Performance</h4>
                  <div class="di-kpis">
                    <div class="di-kpi">
                      <div class="di-kpi-value">2</div>
                      <div class="di-kpi-label">Appointment Risk</div>
                    </div>
                    <div class="di-kpi">
                      <div class="di-kpi-value">8.2 <small style="font-size:0.65rem;font-weight:500;color:var(--dt-ink-muted)">days</small></div>
                      <div class="di-kpi-label">Avg. Lab Turnaround</div>
                    </div>
                  </div>
                  <div style="margin-top:12px;">
                    <div class="di-bar">
                      <div class="di-bar-label">Precision</div>
                      <div class="di-bar-track"><div class="di-bar-fill" style="background:#3b82f6;" data-width="45"></div></div>
                      <div class="di-bar-value">5</div>
                    </div>
                    <div class="di-bar">
                      <div class="di-bar-label">Atlas</div>
                      <div class="di-bar-track"><div class="di-bar-fill" style="background:#8b5cf6;" data-width="18"></div></div>
                      <div class="di-bar-value">2</div>
                    </div>
                    <div class="di-bar">
                      <div class="di-bar-label">SmileCraft</div>
                      <div class="di-bar-track"><div class="di-bar-fill" style="background:#14b8a6;" data-width="18"></div></div>
                      <div class="di-bar-value">2</div>
                    </div>
                    <div class="di-bar">
                      <div class="di-bar-label">In-House</div>
                      <div class="di-bar-track"><div class="di-bar-fill" style="background:#f59e0b;" data-width="18"></div></div>
                      <div class="di-bar-value">2</div>
                    </div>
                  </div>
                </div>

                <div class="di-card">
                  <h4>Turnaround Trend</h4>
                  <div class="di-line-chart" id="di-trend-chart" style="height:120px;">
                    <svg viewBox="0 0 200 120" aria-label="Average turnaround trend">
                      <line class="di-line-grid" x1="0" y1="30" x2="200" y2="30"></line>
                      <line class="di-line-grid" x1="0" y1="60" x2="200" y2="60"></line>
                      <line class="di-line-grid" x1="0" y1="90" x2="200" y2="90"></line>
                      <defs>
                        <linearGradient id="diAreaGradient2" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="0%" stop-color="rgba(30,64,175,0.18)"></stop>
                          <stop offset="100%" stop-color="rgba(30,64,175,0)"></stop>
                        </linearGradient>
                      </defs>
                      <path class="di-line-area" d="M0,120 L0,100 C50,95 100,85 150,55 S175,35 200,25 L200,120 Z" style="fill:url(#diAreaGradient2)"></path>
                      <path class="di-line-path" d="M0,100 C50,95 100,85 150,55 S175,35 200,25"></path>
                      <circle class="di-line-dot" cx="0" cy="100" r="3"></circle>
                      <circle class="di-line-dot" cx="100" cy="85" r="3"></circle>
                      <circle class="di-line-dot" cx="200" cy="25" r="3"></circle>
                      <text x="0" y="115" font-size="8" fill="#6b7280">Wk 1</text>
                      <text x="90" y="115" font-size="8" fill="#6b7280">Wk 2</text>
                      <text x="180" y="115" font-size="8" fill="#6b7280">Wk 3</text>
                    </svg>
                  </div>
                </div>
              </div>

              <div class="di-smart">
                <div class="di-smart-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 1 7 7c0 2.38-1.19 4.5-3 5.74V17a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2v-2.26C6.19 13.5 5 11.38 5 9a7 7 0 0 1 7-7z"></path><line x1="12" y1="22" x2="12" y2="19"></line></svg>
                </div>
                <div>
                  <h4>Recommendation</h4>
                  <p>Hannah Lindqvist's crown is flagged Appointment Risk: her patient appointment is Mar 10 but the case is due Mar 12. Check whether Precision Dental Lab has confirmed receipt and what return date it has promised.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="problem" class="section problem">
    <div class="container">
      <div class="problem-grid">
        <div>
          <span class="eyebrow"><?php echo t('marketing.problem.eyebrow'); ?></span>
          <h2><?php echo t('marketing.problem.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.problem.lead'); ?></p>
        </div>
        <div class="problem-visual" id="problem-visual">
          <div class="pv-stage" aria-hidden="true">
            <svg class="pv-trails" viewBox="0 0 480 380" preserveAspectRatio="none" aria-hidden="true">
              <path class="pv-trail" d="M40,300 C120,180 260,340 430,120" />
              <path class="pv-trail" d="M30,90 C160,30 300,210 450,270" />
              <path class="pv-trail" d="M60,200 C180,140 240,300 400,190" />
            </svg>

            <div class="pv-obj pv-o1"><div class="pv-wob">
              <svg viewBox="0 0 64 110"><rect x="6" y="4" width="52" height="102" rx="10" fill="#fff" stroke="#1e3a5f" stroke-width="2.5"/><rect x="10" y="14" width="44" height="80" rx="4" fill="#eef4fb"/><circle cx="32" cy="100" r="3.5" fill="#1e3a5f"/><rect x="16" y="24" width="26" height="12" rx="6" fill="#2563eb"/><rect x="22" y="44" width="26" height="12" rx="6" fill="#dbe7f6" stroke="#1e3a5f" stroke-width="1.6"/><rect x="16" y="64" width="30" height="12" rx="6" fill="#2563eb"/></svg>
            </div></div>

            <div class="pv-obj pv-o2"><div class="pv-wob">
              <svg viewBox="0 0 80 60"><g transform="rotate(-18 40 30)"><path d="M18 44 L18 30 Q18 12 40 12 Q62 12 62 30 L62 44" fill="none" stroke="#1e3a5f" stroke-width="3"/><rect x="10" y="38" width="18" height="14" rx="7" fill="#8b5cf6" stroke="#1e3a5f" stroke-width="2"/><rect x="52" y="38" width="18" height="14" rx="7" fill="#8b5cf6" stroke="#1e3a5f" stroke-width="2"/><path d="M64 8 q6 -6 12 -2 M68 14 q8 -8 16 -4" stroke="#f59e0b" stroke-width="2.4" fill="none" stroke-linecap="round"/></g></svg>
            </div></div>

            <div class="pv-obj pv-o3"><div class="pv-wob">
              <svg viewBox="0 0 84 60"><rect x="4" y="8" width="76" height="46" rx="6" fill="#fff" stroke="#1e3a5f" stroke-width="2.5"/><path d="M8 12 L42 38 L76 12" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round"/><rect x="4" y="8" width="76" height="46" rx="6" fill="none" stroke="#1e3a5f" stroke-width="2.5"/></svg>
            </div></div>

            <div class="pv-obj pv-o4"><div class="pv-wob">
              <svg viewBox="0 0 96 62"><ellipse cx="48" cy="50" rx="44" ry="10" fill="#dbe7f6" stroke="#1e3a5f" stroke-width="2"/><rect x="30" y="16" width="36" height="30" rx="5" fill="#fef3e2" stroke="#1e3a5f" stroke-width="2.2"/><path d="M34 30 q6 -8 12 0 M50 30 q6 -8 12 0" fill="none" stroke="#f59e0b" stroke-width="2.2"/><path d="M36 40 q4 -4 8 0 M52 40 q4 -4 8 0" fill="none" stroke="#f59e0b" stroke-width="2"/></svg>
            </div></div>

            <div class="pv-obj pv-o5"><div class="pv-wob">
              <svg viewBox="0 0 84 64"><rect x="18" y="10" width="44" height="38" rx="4" fill="#eef4fb" stroke="#1e3a5f" stroke-width="2.2" transform="rotate(-8 40 29)"/><rect x="26" y="16" width="44" height="38" rx="4" fill="#fff" stroke="#1e3a5f" stroke-width="2.2" transform="rotate(4 48 35)"/><ellipse cx="48" cy="34" rx="9" ry="12" fill="#0ea5a8" transform="rotate(4 48 35)"/></svg>
            </div></div>

            <div class="pv-obj pv-o6"><div class="pv-wob">
              <svg viewBox="0 0 62 78"><path d="M8 4 h46 v70 l-8 -6 -8 6 -8 -6 -8 6 -8 -6 -6 4 z" fill="#fff" stroke="#1e3a5f" stroke-width="2.2"/><rect x="15" y="14" width="32" height="4" rx="2" fill="#1e3a5f"/><rect x="15" y="26" width="24" height="3" rx="1.5" fill="#8b9bb4"/><rect x="15" y="34" width="28" height="3" rx="1.5" fill="#8b9bb4"/><rect x="15" y="42" width="20" height="3" rx="1.5" fill="#8b9bb4"/><rect x="15" y="54" width="18" height="10" rx="2" fill="none" stroke="#0ea5a8" stroke-width="1.6"/></svg>
            </div></div>

            <div class="pv-obj pv-o7"><div class="pv-wob">
              <svg viewBox="0 0 72 68"><rect x="4" y="12" width="64" height="52" rx="7" fill="#fff" stroke="#1e3a5f" stroke-width="2.5"/><rect x="4" y="12" width="64" height="14" rx="7" fill="#2563eb"/><rect x="14" y="6" width="5" height="12" rx="2.5" fill="#1e3a5f"/><rect x="53" y="6" width="5" height="12" rx="2.5" fill="#1e3a5f"/><circle cx="36" cy="46" r="9" fill="#eef4fb" stroke="#0ea5a8" stroke-width="2"/></svg>
            </div></div>

            <div class="pv-obj pv-o8"><div class="pv-wob">
              <svg viewBox="0 0 66 62"><rect x="8" y="6" width="50" height="50" rx="5" fill="#fde68a" stroke="#1e3a5f" stroke-width="2.2" transform="rotate(6 33 31)"/><rect x="18" y="18" width="30" height="4" rx="2" fill="#b45309" transform="rotate(6 33 31)"/><rect x="18" y="28" width="24" height="4" rx="2" fill="#b45309" transform="rotate(6 33 31)"/><rect x="18" y="38" width="27" height="4" rx="2" fill="#b45309" transform="rotate(6 33 31)"/></svg>
            </div></div>

            <div class="pv-obj pv-o9"><div class="pv-wob">
              <svg viewBox="0 0 76 56"><rect x="4" y="6" width="68" height="44" rx="5" fill="#fff" stroke="#1e3a5f" stroke-width="2.2"/><path d="M27 6 v44 M50 6 v44 M4 20 h68 M4 35 h68" stroke="#c7d3e6" stroke-width="1.6"/><rect x="31" y="23" width="16" height="9" fill="#8b5cf6" opacity="0.55"/><rect x="7" y="38" width="16" height="9" fill="#2563eb" opacity="0.45"/></svg>
            </div></div>
          </div>
          <button type="button" class="pv-toggle" id="pvToggle" aria-pressed="false"><?php echo t('marketing.problem.anim_pause'); ?></button>
        </div>
      </div>
    </div>
  </section>

  <section id="already-tracking" class="section compare">
    <div class="container">
      <h2 data-reveal><?php echo t('marketing.compare.title'); ?></h2>
      <p class="lead" data-reveal><?php echo t('marketing.compare.lead'); ?></p>
      <div class="cmp-panel" role="table" aria-label="<?php echo htmlspecialchars(t('marketing.compare.panel_aria')); ?>">
        <div class="cmp-head" role="row">
          <div class="cmp-h-q" role="columnheader"><?php echo t('marketing.compare.col_q'); ?></div>
          <div class="cmp-h-a" role="columnheader"><?php echo t('marketing.compare.col_a'); ?></div>
        </div>
        <?php for ($i = 1; $i <= 4; $i++) : ?>
        <div class="cmp-row" role="row" data-reveal data-reveal-stagger="<?php echo $i; ?>" data-reveal-delay="140">
          <div class="cmp-q" role="cell">
            <span class="cmp-q-text"><?php echo t('marketing.compare.r' . $i . 'q'); ?></span>
            <span class="cmp-q-sub"><?php echo t('marketing.compare.r' . $i . 's'); ?></span>
          </div>
          <div class="cmp-a" role="cell">
            <span class="cmp-check" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><circle class="cmp-ck-circle" cx="12" cy="12" r="11"/><path class="cmp-ck-path" d="M7 12.5l3.2 3.2L17 8.5"/></svg>
            </span>
            <span class="cmp-sr"><?php echo t('marketing.compare.sr_yes'); ?></span>
          </div>
        </div>
        <?php endfor; ?>
        <div class="cmp-note"><?php echo t('marketing.compare.note'); ?></div>
      </div>
    </div>
  </section>

  <section id="how-it-works" class="section">
    <div class="container">
      <span class="eyebrow" data-reveal><?php echo t('marketing.walkthrough.eyebrow'); ?></span>
      <h2 data-reveal><?php echo t('marketing.walkthrough.title'); ?></h2>
      <p class="lead" data-reveal><?php echo t('marketing.walkthrough.lead'); ?></p>
      <div class="hw-walk" id="hwWalk">
        <ol class="hw-steps" role="tablist" aria-label="<?php echo htmlspecialchars(t('marketing.walkthrough.steps_aria')); ?>">
          <?php for ($i = 1; $i <= 7; $i++): ?>
          <li><button type="button" role="tab" class="hw-step" data-step="<?php echo $i; ?>" aria-selected="<?php echo $i === 1 ? 'true' : 'false'; ?>">
            <span class="hw-step-num"><?php echo $i; ?></span>
            <span class="hw-step-label"><?php echo t('marketing.walkthrough.step' . $i . '_title'); ?></span>
          </button></li>
          <?php endfor; ?>
        </ol>
        <div class="hw-main">
          <h3 class="hw-scene-title" id="hwSceneTitle"><?php echo t('marketing.walkthrough.step1_title'); ?></h3>
          <p class="hw-scene-copy" id="hwSceneCopy"><?php echo t('marketing.walkthrough.step1_copy'); ?></p>
                    <div class="hw-stage" id="hwStage" aria-hidden="true">

            <!-- SCENE 1: workflow board, add stage + rename -->
            <div class="hw-scene hw-active" data-scene="1">
              <div class="hw-boardX">
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name" id="hwRenameTarget"><?php echo t('marketing.walkthrough.stage_new'); ?></span><span class="hw-colx-edit" id="hwEditBtn">✎</span></div>
                  <div class="hw-casecard">Crown #14<span class="hw-cc-meta">M. Chen · Apr 10</span></div>
                  <div class="hw-casecard">Bridge #7<span class="hw-cc-meta">A. Rivera · Apr 12</span></div>
                </div>
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.stage_lab'); ?></span></div>
                  <div class="hw-casecard">Inlay #2<span class="hw-cc-meta">M. Chen · Apr 9</span></div>
                </div>
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.stage_done'); ?></span></div>
                  <div class="hw-casecard">Crown #9<span class="hw-cc-meta">J. Lee · Apr 4</span></div>
                </div>
                <div class="hw-colx hw-colx-new" id="hwNewCol"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.qc_stage'); ?></span></div>
                  <div class="hw-casecard hw-casecard-dim">—</div>
                </div>
                <div class="hw-addstage" id="hwAddStage"><span class="hw-plus">+</span> <?php echo t('marketing.walkthrough.add_stage'); ?></div>
                <div class="hw-typebox" id="hwTypeBox"><input class="hw-typeinput" id="hwTypeInput" readonly value=""><span class="hw-okbtn">✓</span></div>
              </div>
              <div class="hw-cursor" id="hwCursor"></div>
            </div>

            <!-- SCENE 2: invite + roster -->
            <div class="hw-scene" data-scene="2" hidden>
              <div class="hw-team">
                <div class="hw-inviteX">
                  <div class="hw-panel-head"><?php echo t('marketing.walkthrough.invite_head'); ?></div>
                  <div class="hw-field"><span class="hw-field-text" id="hwInviteEmail"></span><span class="hw-btn-mini" id="hwInviteBtn"><?php echo t('marketing.walkthrough.invite_btn'); ?></span></div>
                  <div class="hw-emailcard" id="hwEmailCard"><div class="hw-email-ic">✉</div><div><b><?php echo t('marketing.walkthrough.email_subj'); ?></b><span class="hw-email-sub">team@dentatrak.com</span></div><span class="hw-btn-mini hw-accept"><?php echo t('marketing.walkthrough.accept_btn'); ?></span></div>
                  <div class="hw-freenote"><?php echo t('marketing.walkthrough.no_charge'); ?></div>
                </div>
                <div class="hw-roster">
                  <div class="hw-panel-head"><?php echo t('marketing.walkthrough.roster_head'); ?></div>
                  <div class="hw-person"><span class="hw-av hw-av-b">AR</span><span class="hw-p-info"><b>Dr. Alex Rivera</b><i><?php echo t('marketing.walkthrough.role_dentist'); ?> · <?php echo t('marketing.walkthrough.org_yours'); ?></i></span></div>
                  <div class="hw-person hw-join1"><span class="hw-av hw-av-p">MC</span><span class="hw-p-info"><b>Maya Chen</b><i><?php echo t('marketing.walkthrough.role_coord'); ?> · <?php echo t('marketing.walkthrough.org_yours'); ?></i></span><span class="hw-joined"><?php echo t('marketing.walkthrough.joined'); ?></span></div>
                  <div class="hw-person hw-join2"><span class="hw-av hw-av-t">JL</span><span class="hw-p-info"><b>Jordan Lee</b><i><?php echo t('marketing.walkthrough.role_labtech'); ?> · <?php echo t('marketing.walkthrough.org_lab'); ?></i></span><span class="hw-joined"><?php echo t('marketing.walkthrough.joined'); ?></span></div>
                </div>
              </div>
            </div>

            <!-- SCENE 3: three case-creation paths -->
            <div class="hw-scene" data-scene="3" hidden>
              <div class="hw-cases">
                <div class="hw-srcrow">
                  <div class="hw-srcbox" id="hwSrcPms"><div class="hw-srcwin"><div class="hw-srcwin-bar"></div><div class="hw-srcwin-line"></div><div class="hw-srcwin-line hw-w60"></div><div class="hw-srcwin-hl" id="hwPmsRow">Hannah Lindqvist · Crown</div><div class="hw-srcwin-line hw-w40"></div></div><span class="hw-src-label"><?php echo t('marketing.walkthrough.src_pms'); ?></span><span class="hw-src-vendor">Open Dental</span></div>
                  <div class="hw-srcbox" id="hwSrcScan"><div class="hw-srcwin"><svg class="hw-scanart" viewBox="0 0 60 40"><rect x="6" y="14" width="34" height="12" rx="6" fill="#2563eb" opacity="0.9"/><rect x="38" y="17" width="16" height="6" rx="3" fill="#1e3a5f"/><path d="M20 8 q10 -6 20 0" stroke="#0ea5a8" stroke-width="2" fill="none" opacity="0.7"/><path d="M22 32 q8 5 16 0" stroke="#0ea5a8" stroke-width="2" fill="none" opacity="0.7"/></svg><div class="hw-srcwin-line hw-w60"></div></div><span class="hw-src-label"><?php echo t('marketing.walkthrough.src_scanner'); ?></span><span class="hw-src-vendor">Primescan / DS Core</span></div>
                  <div class="hw-srcbox" id="hwSrcManual"><div class="hw-srcwin"><div class="hw-srcwin-line" id="hwManF1"></div><div class="hw-srcwin-line hw-w80" id="hwManF2"></div><div class="hw-srcwin-line hw-w60" id="hwManF3"></div></div><span class="hw-src-label"><?php echo t('marketing.walkthrough.src_manual'); ?></span><span class="hw-src-vendor"><?php echo t('marketing.walkthrough.manual_sub'); ?></span></div>
                </div>
                <div class="hw-inboxbar" id="hwInboxBar"><span class="hw-inbox-tag"><?php echo t('marketing.walkthrough.scan_inbox_row'); ?></span><span class="hw-btn-mini hw-btn-ghost" id="hwInboxSel"><?php echo t('marketing.walkthrough.act_select'); ?></span><span class="hw-btn-mini" id="hwInboxCreate"><?php echo t('marketing.walkthrough.act_create'); ?></span></div>
                <div class="hw-newcases"><span class="hw-nc-head"><?php echo t('marketing.walkthrough.new_cases'); ?></span>
                  <div class="hw-casecard hw-born hw-born1">Crown #14<span class="hw-badge hw-b-pms">PMS</span></div>
                  <div class="hw-casecard hw-born hw-born2">Crown #15<span class="hw-badge hw-b-scan"><?php echo t('marketing.walkthrough.badge_scan'); ?></span></div>
                  <div class="hw-casecard hw-born hw-born3">Veneer #3<span class="hw-badge hw-b-man"><?php echo t('marketing.walkthrough.badge_manual'); ?></span></div>
                </div>
                <div class="hw-planline"><?php echo t('marketing.walkthrough.plan_note_int'); ?></div>
              </div>
            </div>

            <!-- SCENE 4: assignment -->
            <div class="hw-scene" data-scene="4" hidden>
              <div class="hw-assignp">
                <div class="hw-caseX"><div class="hw-caseX-head">Crown #14 · Hannah Lindqvist</div>
                  <div class="hw-assignedto"><span class="hw-at-label"><?php echo t('marketing.walkthrough.assigned_to'); ?></span><span class="hw-at-val" id="hwAssignee"><span class="hw-at-n hw-n0"><?php echo t('marketing.walkthrough.unassigned'); ?></span><span class="hw-at-n hw-n1">Maya Chen</span><span class="hw-at-n hw-n2">Dr. Alex Rivera</span><span class="hw-at-n hw-n3"><?php echo t('marketing.walkthrough.lab_label'); ?></span></span><span class="hw-btn-mini" id="hwPickBtn"><?php echo t('marketing.walkthrough.change_btn'); ?></span></div>
                </div>
                <div class="hw-picker" id="hwPicker">
                  <div class="hw-pick-group"><?php echo t('marketing.walkthrough.grp_people'); ?></div>
                  <div class="hw-pick" data-who="Maya Chen"><span class="hw-av hw-av-p">MC</span><span class="hw-p-info"><b>Maya Chen</b><i><?php echo t('marketing.walkthrough.role_coord'); ?></i></span></div>
                  <div class="hw-pick" data-who="Dr. Alex Rivera"><span class="hw-av hw-av-b">AR</span><span class="hw-p-info"><b>Dr. Alex Rivera</b><i><?php echo t('marketing.walkthrough.role_dentist'); ?></i></span></div>
                  <div class="hw-pick-group"><?php echo t('marketing.walkthrough.grp_labels'); ?></div>
                  <div class="hw-pick hw-pick-lab" data-who="Lab"><span class="hw-av hw-av-t">LB</span><span class="hw-p-info"><b><?php echo t('marketing.walkthrough.lab_label'); ?></b><i><?php echo t('marketing.walkthrough.lab_label_sub'); ?></i></span></div>
                </div>
              </div>
            </div>

            <!-- SCENE 5: case detail -->
            <div class="hw-scene" data-scene="5" hidden>
              <div class="hw-detailp">
                <div class="hw-dp-head"><b>Crown #14</b><span>Hannah Lindqvist · <?php echo t('marketing.walkthrough.due_lbl'); ?> Apr 19 · <?php echo t('marketing.walkthrough.shade_lbl'); ?> A2</span></div>
                <div class="hw-dp-cols">
                  <div class="hw-dp-col">
                    <div class="hw-dp-sec"><?php echo t('marketing.walkthrough.sec_notes'); ?></div>
                    <div class="hw-noteX" id="hwNote1"><?php echo t('marketing.walkthrough.note_txt'); ?></div>
                    <div class="hw-dp-sec"><?php echo t('marketing.walkthrough.sec_attach'); ?></div>
                    <div class="hw-thumbs"><span class="hw-thumb" id="hwThumb1"><svg viewBox="0 0 40 30"><ellipse cx="20" cy="15" rx="14" ry="10" fill="#0ea5a8" opacity="0.8"/><ellipse cx="16" cy="13" rx="3" ry="5" fill="#fff" opacity="0.85"/><ellipse cx="25" cy="14" rx="3" ry="5" fill="#fff" opacity="0.85"/></svg></span><span class="hw-thumb" id="hwThumb2"><svg viewBox="0 0 40 30"><rect x="4" y="4" width="32" height="22" rx="3" fill="#1e3a5f"/><path d="M10 22 q10 -14 20 0" stroke="#dbe7f6" stroke-width="2" fill="none"/></svg></span></div>
                    <div class="hw-upbar" id="hwUpBar"><?php echo t('marketing.walkthrough.upload_bar'); ?></div>
                  </div>
                  <div class="hw-dp-col">
                    <div class="hw-dp-sec"><?php echo t('marketing.walkthrough.sec_comments'); ?></div>
                    <div class="hw-cmt" id="hwCmt1"><span class="hw-av hw-av-p">MC</span><span class="hw-p-info"><b>Maya Chen · 10:24</b><i><?php echo t('marketing.walkthrough.comment_txt'); ?></i></span></div>
                    <div class="hw-cmt" id="hwCmt2"><span class="hw-av hw-av-t">JL</span><span class="hw-p-info"><b>Jordan Lee · 10:41</b><i><?php echo t('marketing.walkthrough.comment_reply'); ?></i></span></div>
                    <div class="hw-cmtbox" id="hwCmtBox"><span class="hw-cmt-type" id="hwCmtType"></span><span class="hw-sendbtn">→</span></div>
                  </div>
                </div>
                <div class="hw-imgview" id="hwImgView"><svg viewBox="0 0 120 80"><ellipse cx="60" cy="40" rx="40" ry="27" fill="#0ea5a8" opacity="0.85"/><ellipse cx="48" cy="34" rx="8" ry="13" fill="#fff" opacity="0.85"/><ellipse cx="72" cy="36" rx="8" ry="13" fill="#fff" opacity="0.85"/></svg></div>
              </div>
            </div>

            <!-- SCENE 6: populated board + attention -->
            <div class="hw-scene" data-scene="6" hidden>
              <div class="hw-boardX hw-board6">
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.stage_ready'); ?></span></div>
                  <div class="hw-casecard">Bridge #7<span class="hw-cc-meta">M. Chen · Apr 12</span></div>
                  <div class="hw-casecard">Inlay #5<span class="hw-cc-meta">A. Rivera · Apr 14</span></div>
                  <div class="hw-casecard">Onlay #1<span class="hw-cc-meta">M. Chen · Apr 15</span></div>
                </div>
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.stage_lab'); ?></span></div>
                  <div class="hw-casecard" id="hwDueSoon">Crown #14<span class="hw-cc-meta">M. Chen · <b class="hw-dd">Apr 10</b></span><span class="hw-flag hw-flag-amber"><?php echo t('marketing.walkthrough.due_soon'); ?></span></div>
                  <div class="hw-casecard" id="hwLate">Denture #3<span class="hw-cc-meta">Lab · <b class="hw-dd">Apr 6</b></span><span class="hw-flag hw-flag-red"><?php echo t('marketing.walkthrough.late_lbl'); ?></span></div>
                  <div class="hw-casecard">Crown #11<span class="hw-cc-meta">A. Rivera · Apr 16</span></div>
                </div>
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.qc_stage'); ?></span></div>
                  <div class="hw-casecard">Veneer #3<span class="hw-cc-meta">M. Chen · Apr 11</span></div>
                  <div class="hw-casecard">Crown #16<span class="hw-cc-meta">Lab · Apr 13</span></div>
                </div>
                <div class="hw-colx"><div class="hw-colx-head"><span class="hw-colx-name"><?php echo t('marketing.walkthrough.stage_done'); ?></span></div>
                  <div class="hw-casecard">Crown #9<span class="hw-cc-meta">J. Lee · Apr 4</span></div>
                  <div class="hw-casecard">Inlay #2<span class="hw-cc-meta">M. Chen · Apr 5</span></div>
                  <div class="hw-casecard">Bridge #4<span class="hw-cc-meta">A. Rivera · Apr 7</span></div>
                </div>
              </div>
              <div class="hw-riskline"><?php echo t('marketing.walkthrough.risk_line'); ?></div>
            </div>

            <!-- SCENE 7: insights dashboard, internal scroll -->
            <div class="hw-scene" data-scene="7" hidden>
              <div class="hw-dashp">
                <div class="hw-dash-head"><?php echo t('marketing.walkthrough.dash_head'); ?><span class="hw-sample"><?php echo t('marketing.walkthrough.sample_data'); ?></span></div>
                <div class="hw-dash-scroll" id="hwDashScroll"><div class="hw-dash-scroll-inner">
                  <div class="hw-kpis">
                    <div class="hw-kpi"><span class="hw-kpi-v">12</span><span class="hw-kpi-l"><?php echo t('marketing.walkthrough.kpi_active'); ?></span></div>
                    <div class="hw-kpi"><span class="hw-kpi-v">4.2d</span><span class="hw-kpi-l"><?php echo t('marketing.walkthrough.kpi_turn'); ?></span></div>
                    <div class="hw-kpi"><span class="hw-kpi-v">93%</span><span class="hw-kpi-l"><?php echo t('marketing.walkthrough.kpi_ontime'); ?></span></div>
                  </div>
                  <div class="hw-chartbox"><div class="hw-cb-title"><?php echo t('marketing.walkthrough.chart_volume'); ?></div>
                    <svg viewBox="0 0 240 70" class="hw-linechart"><polyline points="0,50 40,42 80,46 120,30 160,34 200,18 240,22" fill="none" stroke="#2563eb" stroke-width="2.5"/><circle cx="200" cy="18" r="3.5" fill="#2563eb"/></svg>
                  </div>
                  <div class="hw-chartbox"><div class="hw-cb-title"><?php echo t('marketing.walkthrough.chart_stages'); ?></div>
                    <div class="hw-hbars"><div class="hw-hbar"><i><?php echo t('marketing.walkthrough.stage_ready'); ?></i><div class="hw-hbar-fill" style="width:55%"></div><b>5</b></div>
                    <div class="hw-hbar"><i><?php echo t('marketing.walkthrough.stage_lab'); ?></i><div class="hw-hbar-fill" style="width:40%"></div><b>4</b></div>
                    <div class="hw-hbar"><i><?php echo t('marketing.walkthrough.qc_stage'); ?></i><div class="hw-hbar-fill" style="width:20%"></div><b>2</b></div>
                    <div class="hw-hbar"><i><?php echo t('marketing.walkthrough.stage_done'); ?></i><div class="hw-hbar-fill" style="width:10%"></div><b>1</b></div></div>
                  </div>
                  <div class="hw-chartbox"><div class="hw-cb-title"><?php echo t('marketing.walkthrough.chart_labs'); ?></div>
                    <svg viewBox="0 0 240 70"><rect x="30" y="30" width="30" height="34" rx="3" fill="#2563eb"/><rect x="105" y="18" width="30" height="46" rx="3" fill="#0ea5a8"/><rect x="180" y="40" width="30" height="24" rx="3" fill="#8b5cf6"/><text x="45" y="70" font-size="8" text-anchor="middle" fill="#5c6b80">PDL</text><text x="120" y="70" font-size="8" text-anchor="middle" fill="#5c6b80">Lab B</text><text x="195" y="70" font-size="8" text-anchor="middle" fill="#5c6b80">Lab C</text></svg>
                  </div>
                </div></div>
              </div>
            </div>

          </div>
          <div class="hw-controls">
            <button type="button" class="hw-ctl" id="hwPrev" aria-label="<?php echo htmlspecialchars(t('marketing.walkthrough.prev_aria')); ?>">‹</button>
            <span class="hw-dots" id="hwDots"></span>
            <button type="button" class="hw-ctl" id="hwNext" aria-label="<?php echo htmlspecialchars(t('marketing.walkthrough.next_aria')); ?>">›</button>
            <button type="button" class="hw-ctl hw-pause" id="hwPause" aria-pressed="false"><?php echo t('marketing.walkthrough.pause'); ?></button>
            <button type="button" class="hw-ctl hw-replay" id="hwReplay" hidden><?php echo t('marketing.walkthrough.replay'); ?></button>
          </div>
          <a href="<?= $baseUrl ?>login.php" class="btn btn-primary hw-cta"><?php echo t('marketing.pricing.start_free'); ?></a>
        </div>
      </div>
      <p class="section-body" data-reveal>
        <?php
          $catUrl = $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software');
          $catLink = '<a href="' . $catUrl . '" class="content-link">' . t('marketing.workflow.link_label') . '</a>';
          echo t('marketing.workflow.body', ['link' => $catLink]);
        ?>
      </p>
    </div>
  </section>

    <section class="section attention">
    <div class="container">
      <span class="eyebrow" data-reveal><?php echo t('marketing.attention.eyebrow'); ?></span>
      <h2 data-reveal><?php echo t('marketing.attention.title'); ?></h2>
      <p class="lead" data-reveal><?php echo t('marketing.attention.lead'); ?></p>
      <div class="attention-board">
        <div class="attention-col" data-reveal data-reveal-stagger="1" data-reveal-delay="150">
          <div class="attention-title late"><?php echo t('marketing.attention.late'); ?></div>
          <div class="case-card late" data-reveal data-reveal-stagger="1" data-reveal-delay="250">
            <h4>Justin Vance</h4>
            <div class="case-type">Partial</div>
            <div class="case-meta">Due: Mar 16</div>
            <div class="case-meta">Assigned: Front Desk</div>
            <div class="case-meta">Precision Dental Lab &middot; Revision 2</div>
            <span class="case-flag flag-late">Late</span>
          </div>
        </div>
        <div class="attention-col" data-reveal data-reveal-stagger="2" data-reveal-delay="150">
          <div class="attention-title due"><?php echo t('marketing.attention.due_soon'); ?></div>
          <div class="case-card due" data-reveal data-reveal-stagger="2" data-reveal-delay="250">
            <h4>Noah Kim</h4>
            <div class="case-type">Inlay</div>
            <div class="case-meta">Due: Mar 26</div>
            <div class="case-meta">Assigned: Dr. Ortiz</div>
            <div class="case-meta">SmileCraft Lab</div>
            <span class="case-flag flag-due">Due Soon</span>
          </div>
        </div>
        <div class="attention-col" data-reveal data-reveal-stagger="3" data-reveal-delay="150">
          <div class="attention-title appt"><?php echo t('marketing.attention.appointment_risk'); ?></div>
          <div class="case-card appt" data-reveal data-reveal-stagger="3" data-reveal-delay="250">
            <h4>Hannah Lindqvist</h4>
            <div class="case-type">Crown</div>
            <div class="case-meta">Due: Mar 12</div>
            <div class="case-meta">Assigned: Dr. Rivera</div>
            <div class="case-meta">Patient Appt: Mar 10</div>
            <span class="case-flag flag-appt">Appt Risk</span>
          </div>
        </div>
        <div class="attention-col" data-reveal data-reveal-stagger="4" data-reveal-delay="150">
          <div class="attention-title review"><?php echo t('marketing.attention.needs_review'); ?></div>
          <div class="case-card" data-reveal data-reveal-stagger="4" data-reveal-delay="250">
            <h4>Sarah Bennett</h4>
            <div class="case-type">Bridge</div>
            <div class="case-meta">Received: Mar 4</div>
            <div class="case-meta">Assigned: Dr. Chen</div>
            <div class="case-meta">Precision Dental Lab</div>
            <span class="case-flag flag-needs-review"><?php echo t('marketing.attention.needs_review'); ?></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section collab">
    <div class="container">
      <div class="collab-grid" data-reveal>
        <div class="insights-copy">
          <span class="eyebrow"><?php echo t('marketing.collaboration.eyebrow'); ?></span>
          <h2><?php echo t('marketing.collaboration.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.collaboration.lead'); ?></p>
          <ul class="insights-callouts">
            <?php for ($i = 1; $i <= 4; $i++) : ?>
            <li>
              <strong><?php echo t('marketing.collaboration.features.' . $i . '_title'); ?></strong>
              <?php echo t('marketing.collaboration.features.' . $i . '_body'); ?>
            </li>
            <?php endfor; ?>
          </ul>
          <p class="section-note"><?php echo t('marketing.collaboration.ack_note'); ?></p>
          <p class="section-note"><?php echo t('marketing.collaboration.lab_note'); ?></p>
        </div>
        <div class="ct-panel" role="img" aria-label="<?php echo htmlspecialchars(t('marketing.collaboration.mock_aria')); ?>">
          <div class="ct-head">
            <strong><?php echo t('marketing.collaboration.mock_case'); ?></strong>
            <span><?php echo t('marketing.collaboration.mock_tab'); ?></span>
          </div>
          <div class="ct-comment">
            <div class="ct-avatar" aria-hidden="true">DR</div>
            <div class="ct-body">
              <div class="ct-meta"><strong>Dr. Rivera</strong> &middot; Mar 6</div>
              <p><?php echo t('marketing.collaboration.mock_c1', ['mention' => '<span class="ct-mention">@' . t('marketing.mock.front_desk') . '</span>']); ?></p>
            </div>
          </div>
          <div class="ct-comment">
            <div class="ct-avatar alt" aria-hidden="true">FD</div>
            <div class="ct-body">
              <div class="ct-meta"><strong><?php echo t('marketing.mock.front_desk'); ?></strong> &middot; Mar 6</div>
              <p><?php echo t('marketing.collaboration.mock_c2'); ?></p>
              <span class="ct-attach"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>shade_photo.jpg</span>
            </div>
          </div>
          <div class="ct-activity">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?php echo t('marketing.collaboration.mock_activity'); ?>
          </div>
          <div class="ct-composer"><?php echo t('marketing.collaboration.mock_composer'); ?></div>
        </div>
      </div>
    </div>
  </section>

  <!--SECTION_INSIGHTS_MOVED-->
  <section id="insights" class="section insights">
    <div class="container">
      <div class="insights-grid" data-reveal>
        <div class="insights-copy">
          <span class="eyebrow"><?php echo t('marketing.insights_section.eyebrow'); ?></span>
          <h2><?php echo t('marketing.insights_section.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.insights_section.lead'); ?></p>
          <ul class="insights-callouts">
            <?php for ($i = 1; $i <= 3; $i++) : ?>
            <li>
              <strong><?php echo t('marketing.insights_section.features.' . $i . '_title'); ?></strong>
              <?php echo t('marketing.insights_section.features.' . $i . '_body'); ?>
            </li>
            <?php endfor; ?>
          </ul>
          <p class="section-note"><?php echo t('marketing.insights_section.included'); ?></p>
          <p class="section-note"><?php echo t('marketing.insights_section.org_note'); ?></p>
        </div>
        <div class="insights-snapshot" role="img" aria-label="Practice and Lab Insights snapshot showing active cases, case flow status, monthly case volume, average lab turnaround, and a Recommendation.">
          <div class="is-header">
            <h3>Insights</h3>
            <p>Practice and lab performance in one place</p>
          </div>
          <div class="di-metrics is-metrics">
            <div class="di-metric">
              <div class="di-metric-value" data-value="11">11</div>
              <div class="di-metric-label">Active Cases</div>
            </div>
            <div class="di-metric due">
              <div class="di-metric-value" data-value="1">1</div>
              <div class="di-metric-label">Due Soon</div>
            </div>
            <div class="di-metric late">
              <div class="di-metric-value" data-value="1">1</div>
              <div class="di-metric-label">Late</div>
            </div>
            <div class="di-metric delivered">
              <div class="di-metric-value" data-value="1">1</div>
              <div class="di-metric-label">Delivered</div>
            </div>
          </div>
          <div class="di-charts is-charts">
            <div class="di-chart">
              <div class="di-chart-title">Case Flow Status</div>
              <div class="di-donut">
                <svg viewBox="0 0 100 100" role="img" aria-label="Case flow status: 7 On Track, 1 Due Soon, 2 Appointment Risk, 1 Late, 1 Delivered.">
                  <circle class="di-donut-track" cx="50" cy="50" r="40"></circle>
                  <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#22c55e" data-pct="58.3" stroke-dasharray="148 251.2" transform="rotate(-90 50 50)"></circle>
                  <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#3b82f6" data-pct="8.3" stroke-dasharray="21 251.2" stroke-dashoffset="-148" transform="rotate(-90 50 50)"></circle>
                  <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#8b5cf6" data-pct="16.7" stroke-dasharray="42 251.2" stroke-dashoffset="-169" transform="rotate(-90 50 50)"></circle>
                  <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#dc2626" data-pct="8.3" stroke-dasharray="20 251.2" stroke-dashoffset="-211" transform="rotate(-90 50 50)"></circle>
                  <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#14b8a6" data-pct="8.3" stroke-dasharray="20 251.2" stroke-dashoffset="-231" transform="rotate(-90 50 50)"></circle>
                </svg>
              </div>
              <div class="di-legend" aria-hidden="true">
                <span><span class="di-legend-dot" style="background:#22c55e"></span>On Track (7)</span>
                <span><span class="di-legend-dot" style="background:#3b82f6"></span>Due Soon (1)</span>
                <span><span class="di-legend-dot" style="background:#8b5cf6"></span>Appt Risk (2)</span>
                <span><span class="di-legend-dot" style="background:#dc2626"></span>Late (1)</span>
                <span><span class="di-legend-dot" style="background:#14b8a6"></span>Delivered (1)</span>
              </div>
            </div>
            <div class="di-chart">
              <div class="di-chart-title">Monthly Case Volume</div>
              <div class="di-line-chart" style="height:110px;">
                <svg viewBox="0 0 240 120" role="img" aria-label="Monthly case volume chart showing cases in and out from January through March.">
                  <defs>
                    <linearGradient id="isAreaGradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stop-color="rgba(30,64,175,0.18)"></stop>
                      <stop offset="100%" stop-color="rgba(30,64,175,0)"></stop>
                    </linearGradient>
                  </defs>
                  <line class="di-line-grid" x1="0" y1="30" x2="240" y2="30"></line>
                  <line class="di-line-grid" x1="0" y1="60" x2="240" y2="60"></line>
                  <line class="di-line-grid" x1="0" y1="90" x2="240" y2="90"></line>
                  <path class="di-line-area" d="M0,120 L0,100 C40,95 80,90 120,70 S200,40 240,20 L240,120 Z" style="fill:url(#isAreaGradient)"></path>
                  <path class="di-line-path" d="M0,100 C40,95 80,90 120,70 S200,40 240,20" style="stroke-dashoffset:0"></path>
                  <circle class="di-line-dot" cx="0" cy="100" r="3" data-dot="1" style="opacity:1"></circle>
                  <circle class="di-line-dot" cx="120" cy="70" r="3" data-dot="2" style="opacity:1"></circle>
                  <circle class="di-line-dot" cx="240" cy="20" r="3" data-dot="3" style="opacity:1"></circle>
                  <text x="0" y="115" font-size="8" fill="#6b7280">Jan</text>
                  <text x="110" y="115" font-size="8" fill="#6b7280">Feb</text>
                  <text x="225" y="115" font-size="8" fill="#6b7280">Mar</text>
                </svg>
              </div>
            </div>
          </div>
          <div class="di-card is-lab">
            <h4>Average Lab Turnaround</h4>
            <div class="di-kpi-value"><span class="di-kpi-number" data-value="8.2">8.2</span> <small style="font-size:0.65rem;font-weight:500;color:var(--dt-ink-muted)">days</small></div>
          </div>
          <div class="di-smart is-smart">
            <div class="di-smart-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 1 7 7c0 2.38-1.19 4.5-3 5.74V17a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2v-2.26C6.19 13.5 5 11.38 5 9a7 7 0 0 1 7-7z"></path><line x1="12" y1="22" x2="12" y2="19"></line></svg>
            </div>
            <div>
              <h4>Recommendation</h4>
              <p>Hannah Lindqvist's crown is flagged Appointment Risk: her patient appointment is Mar 10 but the case is due Mar 12. Check whether Precision Dental Lab has confirmed receipt and what return date it has promised.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section ask">
    <div class="container">
      <div class="ask-grid" data-reveal>
        <div class="ask-panel" role="img" aria-label="<?php echo htmlspecialchars(t('marketing.ask.mock_aria')); ?>">
          <div class="ask-head">
            <strong><?php echo t('marketing.ask.name'); ?></strong>
            <kbd>Ctrl + /</kbd>
          </div>
          <div class="ask-demo" id="askDemo">
            <div class="ask-body" id="askBody" aria-live="off">
              <div class="ask-msg user" id="askUserMsg"></div>
              <div class="ask-dots" id="askDots" hidden><span></span><span></span><span></span></div>
              <div class="ask-msg ai" id="askAiMsg" hidden></div>
            </div>
            <div class="ask-input"><span id="askTyped" class="ask-typed"></span><span class="ask-caret" id="askCaret"></span></div>
            <div class="ask-demo-foot">
              <span class="ask-example"><?php echo t('marketing.askdemo.example_label'); ?></span>
              <span class="ask-demo-ctl">
                <button type="button" class="ask-ctl" id="askPrev" aria-label="<?php echo htmlspecialchars(t('marketing.askdemo.prev_aria')); ?>">‹</button>
                <button type="button" class="ask-ctl" id="askPause" aria-pressed="false"><?php echo t('marketing.askdemo.pause'); ?></button>
                <button type="button" class="ask-ctl" id="askNext" aria-label="<?php echo htmlspecialchars(t('marketing.askdemo.next_aria')); ?>">›</button>
              </span>
            </div>
          </div>
        </div>
        <div class="insights-copy">
          <span class="eyebrow"><?php echo t('marketing.ask.eyebrow'); ?></span>
          <h2><?php echo t('marketing.ask.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.ask.lead'); ?></p>
          <ul class="insights-callouts">
            <?php for ($i = 1; $i <= 3; $i++) : ?>
            <li>
              <strong><?php echo t('marketing.ask.features.' . $i . '_title'); ?></strong>
              <?php echo t('marketing.ask.features.' . $i . '_body'); ?>
            </li>
            <?php endfor; ?>
          </ul>
          <p class="section-note"><?php echo t('marketing.ask.note'); ?></p>
        </div>
      </div>
    </div>
  </section>

  <section class="section pms">
    <div class="container">
      <div class="pms-inner" data-reveal>
        <span class="eyebrow"><?php echo t('marketing.pms.eyebrow'); ?></span>
        <h2><?php echo t('marketing.pms.title'); ?></h2>
        <p class="lead"><?php echo t('marketing.pms.lead'); ?></p>
        <div class="pms-cards">
          <div class="pms-card">
            <div class="pms-card-label"><?php echo t('marketing.pms.card_od_cat'); ?></div>
            <h4><?php echo t('marketing.pms.card_od_title'); ?></h4>
            <p><?php echo t('marketing.pms.card_od_body'); ?></p>
          </div>
          <div class="pms-card">
            <div class="pms-card-label"><?php echo t('marketing.pms.card_scan_cat'); ?></div>
            <h4><?php echo t('marketing.pms.card_scan_title'); ?></h4>
            <p><?php echo t('marketing.pms.card_scan_body'); ?></p>
          </div>
        </div>
        <p class="pms-note"><?php echo t('marketing.pms.more'); ?></p>
        <p class="pms-plan-note"><?php echo t('marketing.pms.plan_note'); ?></p>
      </div>
    </div>
  </section>

  <section class="section langs">
    <div class="container" data-reveal>
      <span class="eyebrow"><?php echo t('marketing.languages.eyebrow'); ?></span>
      <h2><?php echo t('marketing.languages.title'); ?></h2>
      <p class="lead"><?php echo t('marketing.languages.lead'); ?></p>
      <div class="lang-grid">
        <span class="lang-chip">English</span>
        <span class="lang-chip">Deutsch</span>
        <span class="lang-chip">Espa&ntilde;ol</span>
        <span class="lang-chip">Fran&ccedil;ais</span>
        <span class="lang-chip">Fran&ccedil;ais (Canada)</span>
        <span class="lang-chip">Italiano</span>
        <span class="lang-chip">Portugu&ecirc;s (Brasil)</span>
        <span class="lang-chip">&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;</span>
        <span class="lang-chip">&#54620;&#44397;&#50612;</span>
      </div>
      <p class="lang-note"><?php echo t('marketing.languages.note'); ?></p>
    </div>
  </section>

  <section class="section founder">
    <div class="container">
      <div class="founder-grid">
        <div class="founder-card" data-reveal data-reveal-dir="left">
          <div class="founder-initials">D<span>r.</span> V</div>
          <div class="founder-name">Dr. William Verrillo</div>
          <div class="founder-role">Practicing Dentist &middot; DentaTrak Co-Founder</div>
        </div>
        <div class="founder-copy" data-reveal data-reveal-dir="right" data-reveal-delay="100">
          <span class="eyebrow"><?php echo t('marketing.founder.eyebrow'); ?></span>
          <h2><?php echo t('marketing.founder.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.founder.lead'); ?></p>
        </div>
      </div>
    </div>
  </section>

  <section id="security" class="section security">
    <div class="container">
      <div class="security-intro" data-reveal>
        <span class="eyebrow"><?php echo t('marketing.security_section.eyebrow'); ?></span>
        <h2><?php echo t('marketing.security_section.title'); ?></h2>
        <p class="lead"><?php echo t('marketing.security_section.lead'); ?></p>
        <a href="<?= $hipaaUrl ?>" class="btn btn-secondary security-link"><?php echo t('marketing.security_section.link'); ?> &rarr;</a>
      </div>

      <div class="security-panel" data-reveal>
        <div class="sec-illus" id="secIllus" aria-hidden="true">
          <div class="sec-piece sec-p-fields"><div class="sec-piece-head"><?php echo t('marketing.security_section.fld_patient'); ?></div><div class="sec-piece-line"></div><div class="sec-piece-line sec-w60"></div><div class="sec-piece-line sec-w80"></div></div>
          <div class="sec-piece sec-p-imgs"><div class="sec-thumb"></div><div class="sec-thumb"></div></div>
          <div class="sec-piece sec-p-doc"><svg viewBox="0 0 24 30" class="sec-doc-svg"><rect x="1" y="1" width="22" height="28" rx="3" fill="#fff" stroke="#1e3a5f" stroke-width="1.6"/><line x1="6" y1="8" x2="18" y2="8" stroke="#2563eb" stroke-width="1.8"/><line x1="6" y1="13" x2="18" y2="13" stroke="#8b9bb4" stroke-width="1.4"/><line x1="6" y1="18" x2="15" y2="18" stroke="#8b9bb4" stroke-width="1.4"/><line x1="6" y1="23" x2="17" y2="23" stroke="#0ea5a8" stroke-width="1.4"/></svg><span><?php echo t('marketing.security_section.fld_rx'); ?></span></div>
          <div class="sec-piece sec-p-chat"><div class="sec-bubble"></div><div class="sec-bubble sec-b2"></div></div>

          <div class="sec-case">
            <div class="sec-case-head"><b>Crown #14</b><span>Hannah Lindqvist</span></div>
            <div class="sec-case-body">
              <div class="sec-slot sec-s-fields"><div class="sec-piece-head"><?php echo t('marketing.security_section.fld_patient'); ?></div><div class="sec-piece-line"></div><div class="sec-piece-line sec-w60"></div></div>
              <div class="sec-slot sec-s-imgs"><div class="sec-thumb"></div><div class="sec-thumb"></div></div>
              <div class="sec-slot sec-s-doc"><svg viewBox="0 0 24 30" class="sec-doc-svg"><rect x="1" y="1" width="22" height="28" rx="3" fill="#fff" stroke="#1e3a5f" stroke-width="1.6"/><line x1="6" y1="8" x2="18" y2="8" stroke="#2563eb" stroke-width="1.8"/><line x1="6" y1="13" x2="18" y2="13" stroke="#8b9bb4" stroke-width="1.4"/></svg></div>
              <div class="sec-slot sec-s-chat"><div class="sec-bubble"></div><div class="sec-bubble sec-b2"></div></div>
            </div>
            <div class="sec-shield">
              <svg viewBox="0 0 44 52"><path d="M22 3 L40 11 V26 C40 38 32 46 22 50 C12 46 4 38 4 26 V11 Z" fill="#eef4fb" stroke="#1e3a8a" stroke-width="2.4"/><rect class="sec-lockbody" x="13" y="24" width="18" height="14" rx="3" fill="#2563eb"/><path class="sec-lockshackle" d="M16 24 V19 a6 6 0 0 1 12 0 v5" fill="none" stroke="#1e3a8a" stroke-width="2.6" stroke-linecap="round"/><circle cx="22" cy="31" r="2.4" fill="#fff"/></svg>
            </div>
            <svg class="sec-boundary" viewBox="0 0 400 240" preserveAspectRatio="none"><rect class="sec-bpath" x="4" y="4" width="392" height="232" rx="16"/></svg>
          </div>

          <div class="sec-member"><span class="sec-member-av">MC</span><span class="sec-member-info"><b>Maya Chen</b><i><?php echo t('marketing.security_section.member_role'); ?></i></span><span class="sec-member-check"><?php echo t('marketing.security_section.member_ok'); ?></span></div>
          <svg class="sec-link" viewBox="0 0 200 60" preserveAspectRatio="none"><path class="sec-linkpath" d="M100 58 C100 40 100 22 100 4" fill="none"/></svg>
        </div>
        <div class="sec-proofs">
          <span class="sec-proof"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><b><?php echo t('marketing.security_section.proof1'); ?></b><i><?php echo t('marketing.security_section.proof1_sub'); ?></i></span>
          <span class="sec-proof"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg><b><?php echo t('marketing.security_section.proof2'); ?></b><i><?php echo t('marketing.security_section.proof2_sub'); ?></i></span>
          <span class="sec-proof"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg><b><?php echo t('marketing.security_section.proof3'); ?></b><i><?php echo t('marketing.security_section.proof3_sub'); ?></i></span>
          <span class="sec-proof"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg><b><?php echo t('marketing.security_section.proof4'); ?></b><i><?php echo t('marketing.security_section.proof4_sub'); ?></i></span>
          <span class="sec-proof"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg><b><?php echo t('marketing.security_section.proof5'); ?></b><i><?php echo t('marketing.security_section.proof5_sub'); ?></i></span>
        </div>
      </div>
      </div>

      <?php
      $securityIcons = [
        1 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        2 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>',
        3 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        4 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        5 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
      ];
      ?>
      <div class="security-cards" data-reveal>
        <?php for ($i = 1; $i <= 5; $i++) : ?>
        <div class="security-card">
          <div class="security-card-icon" aria-hidden="true"><?php echo $securityIcons[$i]; ?></div>
          <h3><?php echo t('marketing.security_section.cards.' . $i . '_title'); ?></h3>
          <p><?php echo t('marketing.security_section.cards.' . $i . '_body'); ?></p>
        </div>
        <?php endfor; ?>
      </div>

    </div>
  </section>

  <section id="mobile" class="section mobile-dark">
    <div class="container">
      <div class="mobile-showcase" data-reveal>
        <div class="mobile-copy">
          <span class="eyebrow"><?php echo t('marketing.mobile_section.eyebrow'); ?></span>
          <h2><?php echo t('marketing.mobile_section.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.mobile_section.lead'); ?></p>
          <ul class="mobile-callouts">
            <?php for ($i = 1; $i <= 3; $i++) : ?>
            <li>
              <strong><?php echo t('marketing.mobile_section.features.' . $i . '_title'); ?></strong>
              <?php echo t('marketing.mobile_section.features.' . $i . '_body'); ?>
            </li>
            <?php endfor; ?>
          </ul>
          <p class="mobile-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            <?php echo t('marketing.mobile_section.pill'); ?>
          </p>
        </div>
        <div class="mobile-phones" aria-label="<?php echo htmlspecialchars(t('marketing.mock.phones_aria')); ?>">
          <div class="m-device m-device-primary" role="img" aria-label="<?php echo htmlspecialchars(t('marketing.mock.kanban_aria')); ?>">
            <div class="m-notch"></div>
            <div class="m-screen">
              <div class="m-appbar">
                <div class="m-appbar-left">
                  <span class="m-appbar-logo">DentaTrak</span>
                </div>
                <div class="m-appbar-right">
                  <span class="m-bell" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                  </span>
                  <span class="m-appbar-practice"><?php echo t('marketing.mock.demo_practice'); ?></span>
                </div>
              </div>
              <div class="m-searchbar">
                <span class="m-search-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <input type="text" placeholder="<?php echo htmlspecialchars(t('marketing.mock.search_cases')); ?>" aria-label="<?php echo htmlspecialchars(t('marketing.mock.search_cases')); ?>">
                <span class="m-filter-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/></svg>
                </span>
              </div>
              <div class="m-kanban-nav">
                <button type="button" class="m-kanban-prev" aria-label="<?php echo htmlspecialchars(t('marketing.mock.prev_column')); ?>" disabled>&lsaquo;</button>
                <select aria-label="<?php echo htmlspecialchars(t('marketing.mock.select_column')); ?>">
                  <option><?php echo t('cases.status.originated'); ?></option>
                  <option><?php echo t('cases.status.sent_to_external_lab'); ?></option>
                </select>
                <button type="button" class="m-kanban-next" aria-label="<?php echo htmlspecialchars(t('marketing.mock.next_column')); ?>">&rsaquo;</button>
              </div>
              <div class="m-board">
                <div class="m-board-header">
                  <span class="m-board-title"><?php echo t('cases.status.originated'); ?></span>
                  <span class="m-board-count">4</span>
                </div>
                <div class="m-board-cards">
                  <div class="m-case-card">
                    <div class="m-case-top">
                      <div>
                        <h4>Hannah Lindqvist</h4>
                        <span class="m-case-sub"><?php echo t('case_types.crown'); ?> &middot; Precision Dental Lab</span>
                      </div>
                      <span class="m-case-flag due"><?php echo t('marketing.mock.due_soon'); ?></span>
                    </div>
                    <div class="m-case-details">
                      <span><strong><?php echo t('marketing.mock.due_label'); ?></strong> Mar 9</span>
                      <span><strong><?php echo t('marketing.mock.assigned_label'); ?></strong> <?php echo t('marketing.mock.front_desk'); ?></span>
                    </div>
                    <div class="m-case-appt"><?php echo t('marketing.mock.appt_label'); ?> Mar 10</div>
                  </div>
                  <div class="m-case-card">
                    <div class="m-case-top">
                      <div>
                        <h4>Justin Vance</h4>
                        <span class="m-case-sub"><?php echo t('case_types.partial'); ?> &middot; SmileCraft Lab</span>
                      </div>
                      <span class="m-case-flag late"><?php echo t('marketing.mock.late'); ?></span>
                    </div>
                    <div class="m-case-details">
                      <span><strong><?php echo t('marketing.mock.due_label'); ?></strong> Mar 16</span>
                      <span><strong><?php echo t('marketing.mock.assigned_label'); ?></strong> <?php echo t('marketing.mock.back_office'); ?></span>
                    </div>
                  </div>
                  <div class="m-case-card">
                    <div class="m-case-top">
                      <div>
                        <h4>Sofia Patel</h4>
                        <span class="m-case-sub"><?php echo t('case_types.veneer'); ?> &middot; <?php echo t('marketing.mock.design_team'); ?></span>
                      </div>
                      <span class="m-case-flag appt"><?php echo t('marketing.mock.appt_risk'); ?></span>
                    </div>
                    <div class="m-case-details">
                      <span><strong><?php echo t('marketing.mock.due_label'); ?></strong> Mar 12</span>
                      <span><strong><?php echo t('marketing.mock.assigned_label'); ?></strong> Dr. Verrillo</span>
                    </div>
                    <div class="m-case-appt"><?php echo t('marketing.mock.appt_label'); ?> Mar 14</div>
                  </div>
                  <div class="m-case-card">
                    <div class="m-case-top">
                      <div>
                        <h4>Sarah Bennett</h4>
                        <span class="m-case-sub"><?php echo t('case_types.bridge'); ?> &middot; ReadyMADE Lab</span>
                      </div>
                      <span class="m-case-flag ready"><?php echo t('marketing.mock.ready'); ?></span>
                    </div>
                    <div class="m-case-details">
                      <span><strong><?php echo t('marketing.mock.due_label'); ?></strong> Mar 11</span>
                      <span><strong><?php echo t('marketing.mock.assigned_label'); ?></strong> <?php echo t('marketing.mock.front_desk'); ?></span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="m-column-peek" aria-hidden="true">
                <div class="m-peek-col"></div>
              </div>
            </div>
          </div>

          <div class="m-device m-device-secondary" role="img" aria-label="<?php echo htmlspecialchars(t('marketing.mock.modal_aria')); ?>">
            <div class="m-notch"></div>
            <div class="m-screen">
              <div class="m-modal-header">
                <span class="m-modal-back" aria-hidden="true">&larr;</span>
                <span class="m-modal-title"><?php echo t('marketing.mock.edit_case'); ?></span>
                <span class="m-modal-actions" aria-hidden="true">&middot;&middot;&middot;</span>
              </div>
              <div class="m-modal-tabs">
                <span class="m-modal-tab active"><?php echo t('marketing.mock.details'); ?></span>
                <span class="m-modal-tab"><?php echo t('marketing.mock.comments'); ?></span>
              </div>
              <div class="m-section-nav">
                <select aria-label="<?php echo htmlspecialchars(t('marketing.mock.jump_to_section')); ?>">
                  <option><?php echo t('marketing.mock.jump_to_section'); ?></option>
                </select>
              </div>
              <div class="m-modal-summary">
                <div class="m-modal-patient">Sofia Patel</div>
                <div class="m-modal-meta"><?php echo t('case_types.veneer'); ?> &middot; SmileCraft Lab</div>
                <span class="m-patient-status appt"><?php echo t('marketing.mock.appt_risk'); ?></span>
              </div>
              <div class="m-modal-body">
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.dentist'); ?></span>
                  <span class="m-field-value">Dr. Verrillo</span>
                </div>
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.due'); ?></span>
                  <span class="m-field-value">Mar 12</span>
                </div>
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.assigned'); ?></span>
                  <span class="m-field-value">Dr. Verrillo</span>
                </div>
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.appt'); ?></span>
                  <span class="m-field-value">Mar 14, 2:00 PM</span>
                </div>
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.lab_ack'); ?></span>
                  <span class="m-field-value"><?php echo t('marketing.mock.lab_ack_value'); ?></span>
                </div>
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.promised'); ?></span>
                  <span class="m-field-value">Mar 15</span>
                </div>
                <div class="m-field-row">
                  <span class="m-field-label"><?php echo t('marketing.mock.shipment'); ?></span>
                  <span class="m-field-value">Tracking #1Z999AA</span>
                </div>
                <div class="m-detail-block">
                  <div class="m-block-label"><?php echo t('marketing.mock.notes'); ?></div>
                  <p><?php echo t('marketing.mock.notes_body'); ?></p>
                </div>
                <div class="m-detail-block">
                  <div class="m-block-label"><?php echo t('marketing.mock.attachments'); ?></div>
                  <div class="m-attachment-row">
                    <span class="m-attachment">scan_0314.stl</span>
                    <span class="m-attachment-label"><?php echo t('marketing.mock.scan_3d'); ?></span>
                  </div>
                </div>
              </div>
              <div class="m-modal-footer">
                <button type="button" class="m-save-btn"><?php echo t('marketing.mock.save_changes'); ?></button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="pricing" class="section pricing">
    <div class="container">
      <div class="pricing-intro" data-reveal>
        <span class="eyebrow"><?php echo t('marketing.pricing.eyebrow'); ?></span>
        <h2><?php echo t('marketing.pricing.title'); ?></h2>
        <p class="lead"><?php echo t('marketing.pricing.lead'); ?></p>
        <p class="pricing-transparency"><strong><?php echo t('marketing.pricing.transparency_title'); ?></strong> <?php echo t('marketing.pricing.transparency_body'); ?></p>
      </div>
      <div class="plans">
        <div class="plan" data-reveal data-reveal-stagger="1" data-reveal-delay="150">
          <div class="plan-name"><?php echo t('marketing.pricing.operate'); ?></div>
          <div class="plan-price"><?php echo t('marketing.pricing.operate_price_month'); ?><span><?php echo t('marketing.pricing.per_month'); ?></span></div>
          <div class="plan-annual"><?php echo t('marketing.pricing.operate_annual'); ?></div>
          <a href="<?= $baseUrl ?>login.php" class="btn btn-primary" style="width:100%;"><?php echo t('marketing.pricing.start_free'); ?></a>
          <p><?php echo t('marketing.pricing.operate_description'); ?></p>
          <ul>
            <li><?php echo t('marketing.pricing.operate_features_1'); ?></li>
            <li><?php echo t('marketing.pricing.operate_features_attachments'); ?></li>
            <li><?php echo t('marketing.pricing.operate_features_4'); ?></li>
            <li><?php echo t('marketing.pricing.operate_features_5'); ?></li>
            <li><?php echo t('marketing.pricing.operate_features_6'); ?></li>
            <li><?php echo t('marketing.pricing.operate_features_2'); ?></li>
            <li><?php echo t('marketing.pricing.operate_features_3'); ?></li>
          </ul>
        </div>
        <div class="plan featured" data-reveal data-reveal-stagger="2" data-reveal-delay="150">
          <span class="plan-popular"><?php echo t('marketing.pricing.most_popular'); ?></span>
          <div class="plan-name"><?php echo t('marketing.pricing.control'); ?></div>
          <div class="plan-price"><?php echo t('marketing.pricing.control_price_month'); ?><span><?php echo t('marketing.pricing.per_month'); ?></span></div>
          <div class="plan-annual"><?php echo t('marketing.pricing.control_annual'); ?></div>
          <a href="<?= $baseUrl ?>login.php" class="btn btn-primary" style="width:100%;"><?php echo t('marketing.pricing.start_free'); ?></a>
          <p><?php echo t('marketing.pricing.control_description'); ?></p>
          <ul>
            <li><?php echo t('marketing.pricing.control_features_1'); ?></li>
            <li><?php echo t('marketing.pricing.control_features_2'); ?></li>
            <li><?php echo t('marketing.pricing.control_features_3'); ?></li>
            <li><?php echo t('marketing.pricing.control_features_4'); ?></li>
            <li><?php echo t('marketing.pricing.control_features_5'); ?></li>
            <li><?php echo t('marketing.pricing.control_features_6'); ?></li>
            <li><?php echo t('marketing.pricing.scanner_available'); ?></li>
          </ul>
        </div>
        <div class="plan" data-reveal data-reveal-stagger="3" data-reveal-delay="150">
          <div class="plan-name"><?php echo t('marketing.pricing.scale'); ?></div>
          <div class="plan-price"><?php echo t('marketing.pricing.scale_price_month'); ?><span><?php echo t('marketing.pricing.per_month'); ?></span></div>
          <div class="plan-annual"><?php echo t('marketing.pricing.scale_annual'); ?></div>
          <a href="<?= $baseUrl ?>login.php" class="btn btn-primary" style="width:100%;"><?php echo t('marketing.pricing.start_free'); ?></a>
          <p><?php echo t('marketing.pricing.scale_description'); ?></p>
          <ul>
            <li><?php echo t('marketing.pricing.scale_features_1'); ?></li>
            <li><?php echo t('marketing.pricing.scale_features_2'); ?></li>
            <li><?php echo t('marketing.pricing.scale_features_4'); ?></li>
            <li><?php echo t('marketing.pricing.scale_features_5'); ?></li>
            <li><?php echo t('marketing.pricing.scale_features_3'); ?></li>
          </ul>
          <div class="plan-extra"><strong><?php echo t('marketing.pricing.scale_addon_title'); ?></strong><br><?php echo t('marketing.pricing.scale_addon_month'); ?><br><?php echo t('marketing.pricing.scale_addon_year'); ?></div>
        </div>
      </div>
      <?php
      // Plan feature comparison - every row reflects an enforced gate:
      //   all plans       max_cases=0 / no tier check (cases, attachments,
      //                   workflow stages, views, comments, Ask DentaTrak)
      //   practices/users PLAN_INCLUDED_PRACTICES + tier max_users
      //   Control+        hasControlAccess() (Insights, Recommendations)
      //   Scale           hasScaleAccess() (consolidated view, org Insights)
      //   Control+        Open Dental (per pricing cards and in-app
      //                   locked_note; NOTE: hasIntegrationAccess() currently
      //                   also permits Operate at runtime - marketing shows
      //                   the intended placement)
      //   Control+        Primescan / DS Core (hasControlAccess() via the
      //                   Incoming Scans permission); other scanner vendors
      //                   remain coming soon.
      $planFeatureGroups = [
          ['label' => t('marketing.plan_features.cat_cases'), 'rows' => [
              ['label' => t('marketing.plan_features.unlimited_cases'), 'cells' => ['check', 'check', 'check']],
              ['label' => t('marketing.plan_features.unlimited_attachments'), 'cells' => ['check', 'check', 'check']],
              ['label' => t('marketing.plan_features.workflow_stages'), 'cells' => ['check', 'check', 'check']],
              ['label' => t('marketing.plan_features.views'), 'cells' => ['check', 'check', 'check']],
              ['label' => t('marketing.plan_features.comments'), 'cells' => ['check', 'check', 'check']],
              ['label' => t('marketing.plan_features.ask_dt'), 'info' => t('marketing.plan_features.ask_dt_info'), 'cells' => ['check', 'check', 'check']],
          ]],
          ['label' => t('marketing.plan_features.cat_practices'), 'rows' => [
              ['label' => t('marketing.plan_features.practices_included'), 'info' => t('marketing.plan_features.practices_included_info'), 'cells' => [t('marketing.plan_features.operate_practices'), t('marketing.plan_features.control_practices'), t('marketing.plan_features.scale_practices')]],
              ['label' => t('marketing.plan_features.team_members'), 'cells' => [t('marketing.plan_features.operate_users'), t('marketing.plan_features.unlimited'), t('marketing.plan_features.unlimited')]],
              ['label' => t('marketing.plan_features.consolidated_view'), 'info' => t('marketing.plan_features.consolidated_view_info'), 'cells' => ['dash', 'dash', 'check']],
          ]],
          ['label' => t('marketing.plan_features.cat_insights'), 'rows' => [
              ['label' => t('marketing.plan_features.practice_insights'), 'cells' => ['dash', 'check', 'check']],
              ['label' => t('marketing.plan_features.lab_insights'), 'cells' => ['dash', 'check', 'check']],
              ['label' => t('marketing.plan_features.recommendations'), 'cells' => ['dash', 'check', 'check']],
              ['label' => t('marketing.plan_features.org_insights'), 'info' => t('marketing.plan_features.org_insights_info'), 'cells' => ['dash', 'dash', 'check']],
          ]],
          ['label' => t('marketing.plan_features.cat_integrations'), 'rows' => [
              ['label' => t('marketing.plan_features.open_dental'), 'info' => t('marketing.plan_features.open_dental_info'), 'cells' => ['dash', 'check', 'check']],
              ['label' => t('marketing.plan_features.scanners'), 'info' => t('marketing.plan_features.scanners_info'), 'cells' => ['dash', 'check', 'check']],
          ]],
      ];
      ?>
      <div class="plan-compare" data-reveal>
        <h3><?php echo t('marketing.plan_features.title'); ?></h3>
        <p class="plan-compare-lead"><?php echo t('marketing.plan_features.lead'); ?></p>
        <div class="plan-compare-scroll">
          <table class="plan-table">
            <thead>
              <tr>
                <th scope="col" class="pf-first"><?php echo t('marketing.plan_features.feature_col'); ?></th>
                <th scope="col"><?php echo t('marketing.pricing.operate'); ?></th>
                <th scope="col"><?php echo t('marketing.pricing.control'); ?></th>
                <th scope="col"><?php echo t('marketing.pricing.scale'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($planFeatureGroups as $group): ?>
              <tr class="pf-cat"><th scope="rowgroup" colspan="4"><?php echo $group['label']; ?></th></tr>
              <?php foreach ($group['rows'] as $row): ?>
              <tr>
                <th scope="row">
                  <?php echo $row['label']; ?><?php if (!empty($row['info'])): ?><button type="button" class="pf-info" aria-expanded="false" aria-label="<?php echo htmlspecialchars(t('marketing.plan_features.more_info', ['feature' => $row['label']])); ?>"><span aria-hidden="true">i</span><span class="pf-tip" role="tooltip"><?php echo $row['info']; ?></span></button><?php endif; ?>
                </th>
                <?php foreach ($row['cells'] as $cell): ?>
                <td><?php
                  if ($cell === 'check') {
                      echo '<span class="pf-check" aria-hidden="true">&#10003;</span><span class="visually-hidden">' . t('marketing.plan_features.included') . '</span>';
                  } elseif ($cell === 'dash') {
                      echo '<span class="pf-dash" aria-hidden="true">&mdash;</span><span class="visually-hidden">' . t('marketing.plan_features.not_included') . '</span>';
                  } elseif ($cell === 'soon') {
                      echo '<span class="pf-soon">' . t('marketing.plan_features.coming_soon') . '</span>';
                  } else {
                      echo '<span class="pf-text">' . $cell . '</span>';
                  }
                ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="section final-cta">
    <div class="container">
      <h2 data-reveal><?php echo t('marketing.cta.final_title'); ?></h2>
      <p class="lead" data-reveal data-reveal-delay="75"><?php echo t('marketing.cta.final_lead'); ?></p>
      <div data-reveal data-reveal-delay="150">
        <a href="<?= $baseUrl ?>login.php" class="btn btn-primary"><?php echo t('marketing.cta.start_free'); ?></a>
        <a href="#demo-modal" class="btn btn-secondary" data-open-demo-modal id="open-demo-final"><?php echo t('marketing.demo.request_demo'); ?></a>
      </div>
      <p class="cta-note" data-reveal data-reveal-delay="200"><?php echo t('marketing.cta.no_credit_card'); ?></p>
      <p class="cta-signin" data-reveal data-reveal-delay="225"><a href="<?= $baseUrl ?>login.php"><?php echo t('marketing.cta.sign_in'); ?></a></p>
    </div>
  </section>

  <section class="trust">
    <div class="container">
      <div class="trust-items" data-reveal>
        <span><?php echo t('marketing.security.practice_based_access'); ?></span>
        <span><?php echo t('marketing.security.user_permissions'); ?></span>
        <span><?php echo t('marketing.security.encrypted_data_storage'); ?></span>
        <span><?php echo t('marketing.security.google_sign_in'); ?></span>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="container">
      <div class="footer-grid">
        <div class="footer-brand">
          <a href="<?= $baseUrl ?>" class="footer-wordmark" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><span class="denta">Denta</span><span class="trak">Trak</span></a>
          <p><?php echo t('marketing.footer.tagline'); ?></p>
        </div>
        <nav class="footer-nav" aria-label="Footer">
          <div>
            <h4><?php echo t('marketing.footer.product'); ?></h4>
            <a href="#how-it-works"><?php echo t('marketing.footer.how_it_works'); ?></a>
            <a href="#pricing"><?php echo t('marketing.footer.pricing'); ?></a>
            <a href="<?= $baseUrl ?>login.php"><?php echo t('marketing.footer.sign_in'); ?></a>
          </div>
          <div>
            <h4><?php echo t('marketing.footer.resources'); ?></h4>
            <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t('marketing.footer.resources'); ?></a>
            <a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>"><?php echo t('marketing.footer.about'); ?></a>
            <a href="mailto:<?php echo t('marketing.footer.support_email'); ?>"><?php echo t('marketing.footer.support'); ?></a>
          </div>
          <div>
            <h4><?php echo t('marketing.footer.legal'); ?></h4>
            <a href="<?php echo htmlspecialchars($hipaaUrl); ?>"><?php echo t('marketing.hipaa.h1'); ?></a>
            <a href="<?= $baseUrl ?>privacy.php"><?php echo t('marketing.footer.privacy'); ?></a>
            <a href="<?= $baseUrl ?>terms.php"><?php echo t('marketing.footer.terms'); ?></a>
          </div>
        </nav>
      </div>
      <div class="footer-bottom">
        <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. <?php echo t('marketing.footer.copyright'); ?></span>
        <span><?php echo t('marketing.footer.support_email'); ?></span>
      </div>
    </div>
  </footer>

  <script>
    (function() {
      'use strict';

      // ============================================================
      // Product preview demo: Cases / Insights
      // ============================================================
      var demoViews = document.getElementById('demo-views');
      var demoCases = document.getElementById('demo-cases');
      var demoInsights = document.getElementById('demo-insights');
      var tabCases = document.getElementById('demo-tab-cases');
      var tabInsights = document.getElementById('demo-tab-insights');
      var demoFilter = document.getElementById('demo-filter');
      var appTabs = document.querySelectorAll('.app-tab');
      var appViews = document.querySelectorAll('.app-view');
      var demoSwitching = false;
      var insightsAnimated = false;
      var demoInView = false;

      function setViewHeight() {
        if (!demoViews || !demoCases || !demoInsights) return;
        var active = document.querySelector('.app-view.active');
        if (!active) return;
        // Measure the active panel's natural height
        demoViews.style.height = active.scrollHeight + 'px';
      }

      function animateMetricValue(el, target, duration) {
        var start = 0;
        var startTime = null;
        function step(timestamp) {
          if (!startTime) startTime = timestamp;
          var progress = Math.min((timestamp - startTime) / duration, 1);
          var current = Math.floor(start + (target - start) * progress);
          el.textContent = current;
          if (progress < 1) {
            requestAnimationFrame(step);
          } else {
            el.textContent = target;
          }
        }
        requestAnimationFrame(step);
      }

      function animateDemoInsights() {
        if (insightsAnimated) return;
        insightsAnimated = true;

        // Animate metric values
        var metricValues = document.querySelectorAll('#di-metrics .di-metric-value');
        metricValues.forEach(function(el) {
          var target = parseInt(el.getAttribute('data-value'), 10) || 0;
          el.textContent = '0';
          if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            animateMetricValue(el, target, 700);
          } else {
            el.textContent = target;
          }
        });

        // Animate donut chart segments
        var donut = document.getElementById('di-donut');
        if (donut) {
          var segs = donut.querySelectorAll('.di-donut-seg');
          var cumulative = 0;
          var circumference = 2 * Math.PI * 40; // ~251.2
          segs.forEach(function(seg) {
            var pct = parseFloat(seg.getAttribute('data-pct')) || 0;
            var offset = -cumulative * circumference / 100;
            var visible = pct * circumference / 100;
            // Start hidden (dash length 0) with the correct offset so the
            // final arc starts at the right point around the circle.
            seg.style.strokeDasharray = '0 ' + circumference;
            seg.style.strokeDashoffset = offset;
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
              setTimeout(function() {
                seg.style.strokeDasharray = visible + ' ' + circumference;
              }, 50);
            } else {
              seg.style.strokeDasharray = visible + ' ' + circumference;
            }
            cumulative += pct;
          });
        }

        // Animate line charts
        var lineCharts = demoInsights.querySelectorAll('.di-line-chart');
        lineCharts.forEach(function(chart) {
          chart.classList.add('animated');
        });

        // Animate bars
        var fills = demoInsights.querySelectorAll('.di-bar-fill');
        fills.forEach(function(fill) {
          var width = fill.getAttribute('data-width') || '0';
          fill.style.width = '0';
          if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setTimeout(function() {
              fill.style.width = width + '%';
            }, 100);
          } else {
            fill.style.width = width + '%';
          }
        });
      }

      function switchDemoView(viewName) {
        if (demoSwitching) return;
        demoSwitching = true;

        var nextView = viewName === 'insights' ? demoInsights : demoCases;
        var nextTab = viewName === 'insights' ? tabInsights : tabCases;
        if (!nextView || !nextTab) return;

        if (demoFilter) {
          demoFilter.textContent = viewName === 'insights' ? 'Last 30 days' : 'Filters: All Cases';
        }
        appTabs.forEach(function(tab) {
          var isNext = tab === nextTab;
          tab.classList.toggle('active', isNext);
          tab.setAttribute('aria-selected', isNext ? 'true' : 'false');
          tab.setAttribute('tabindex', isNext ? '0' : '-1');
        });

        appViews.forEach(function(view) {
          var isNext = view === nextView;
          view.classList.toggle('active', isNext);
          view.setAttribute('aria-hidden', isNext ? 'false' : 'true');
        });

        setViewHeight();

        if (viewName === 'insights' && demoInView) {
          setTimeout(function() {
            animateDemoInsights();
          }, 100);
        }

        setTimeout(function() {
          demoSwitching = false;
        }, 450);
      }

      if (tabCases && tabInsights) {
        tabCases.addEventListener('click', function() { switchDemoView('cases'); });
        tabInsights.addEventListener('click', function() { switchDemoView('insights'); });

        // Keyboard support: Left/Right arrows, Home/End
        appTabs.forEach(function(tab) {
          tab.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
              e.preventDefault();
              switchDemoView(tab === tabCases ? 'insights' : 'cases');
            } else if (e.key === 'Home') {
              e.preventDefault();
              switchDemoView('cases');
            } else if (e.key === 'End') {
              e.preventDefault();
              switchDemoView('insights');
            }
          });
        });
      }

      // Board / List toggle inside the demo Cases panel
      var demoViewBoardBtn = document.getElementById('demo-view-board');
      var demoViewListBtn = document.getElementById('demo-view-list');
      var demoBoardView = document.getElementById('demo-board-view');
      var demoListView = document.getElementById('demo-list-view');

      function setDemoCaseView(mode) {
        if (!demoBoardView || !demoListView) return;
        var isList = mode === 'list';
        demoBoardView.hidden = isList;
        demoListView.hidden = !isList;
        if (demoViewBoardBtn) {
          demoViewBoardBtn.classList.toggle('active', !isList);
          demoViewBoardBtn.setAttribute('aria-pressed', isList ? 'false' : 'true');
        }
        if (demoViewListBtn) {
          demoViewListBtn.classList.toggle('active', isList);
          demoViewListBtn.setAttribute('aria-pressed', isList ? 'true' : 'false');
        }
        setViewHeight();
      }
      if (demoViewBoardBtn && demoViewListBtn) {
        demoViewBoardBtn.addEventListener('click', function() { setDemoCaseView('board'); });
        demoViewListBtn.addEventListener('click', function() { setDemoCaseView('list'); });
      }

      // Observe demo visibility to pause/resume animations
      if ('IntersectionObserver' in window) {
        var demoObserver = new IntersectionObserver(function(entries) {
          entries.forEach(function(entry) {
            demoInView = entry.isIntersecting;
            if (entry.isIntersecting && demoInsights && demoInsights.classList.contains('active') && !insightsAnimated) {
              animateDemoInsights();
            }
          });
        }, { threshold: 0.3 });
        if (demoViews) demoObserver.observe(demoViews);
      } else {
        demoInView = true;
      }

      // Set initial view height once fonts/rendering settle
      if (window.requestAnimationFrame) {
        requestAnimationFrame(function() {
          setViewHeight();
          if (demoInsights) {
            // Pre-animate on initial load if prefers-reduced-motion is on
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
              animateDemoInsights();
            }
          }
        });
      } else {
        setViewHeight();
      }
      window.addEventListener('resize', setViewHeight);

      // ============================================================
      // Problem illustration: fragmented information becomes one case
      // ============================================================
      var problemVisual = document.getElementById('problem-visual');
      var problemConnections = document.getElementById('problem-connections');
      var problemGroup = document.getElementById('problem-connections-group');
      var fragments = document.querySelectorAll('.fragment');
      var problemCase = document.querySelector('.problem-case');
      var illustrationInView = false;
      var hasAnimated = false;
      var hoverTimer = null;
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      function updateConnectionLines() {
        if (!problemVisual || !problemConnections || !problemCase || !problemGroup) return;
        // Skip on mobile where fragments are no longer absolute
        if (window.getComputedStyle(fragments[0]).position !== 'absolute') {
          problemGroup.innerHTML = '';
          return;
        }

        var visualRect = problemVisual.getBoundingClientRect();
        var caseRect = problemCase.getBoundingClientRect();
        var cx = (caseRect.left - visualRect.left) + caseRect.width / 2;
        var cy = (caseRect.top - visualRect.top) + caseRect.height / 2;

        var svgWidth = visualRect.width;
        var svgHeight = visualRect.height;
        problemConnections.setAttribute('viewBox', '0 0 ' + svgWidth + ' ' + svgHeight);

        var lines = [];
        fragments.forEach(function(fragment) {
          var rect = fragment.getBoundingClientRect();
          var fx = (rect.left - visualRect.left) + rect.width / 2;
          var fy = (rect.top - visualRect.top) + rect.height / 2;
          var color = fragment.getAttribute('data-color') || '#9ca3af';
          lines.push('<line x1="' + fx.toFixed(1) + '" y1="' + fy.toFixed(1) + '" x2="' + cx.toFixed(1) + '" y2="' + cy.toFixed(1) + '" stroke="' + color + '"></line>');
        });
        problemGroup.innerHTML = lines.join('');
      }

      function spreadFragments() {
        if (!problemVisual) return;
        problemVisual.classList.add('is-active');
      }

      function convergeFragments() {
        if (!problemVisual) return;
        problemVisual.classList.remove('is-active');
        if (reduceMotion) {
          problemVisual.classList.add('is-converging');
          updateConnectionLines();
          return;
        }
        problemVisual.classList.add('is-converging');
        // Update connection lines after the inward animation completes so the
        // lines draw from the converged positions and do not detach mid-motion.
        setTimeout(function() {
          if (!problemVisual) return;
          updateConnectionLines();
        }, 900);
      }

      function resetIllustration() {
        if (!problemVisual) return;
        problemVisual.classList.remove('is-active', 'is-converging');
      }

      function playIllustrationOnce() {
        if (hasAnimated || reduceMotion) return;
        spreadFragments();
        setTimeout(function() {
          convergeFragments();
        }, 400);
        hasAnimated = true;
      }

      function playHoverIn() {
        if (reduceMotion) {
          problemVisual.classList.add('is-converging');
          updateConnectionLines();
          return;
        }
        clearTimeout(hoverTimer);
        spreadFragments();
        hoverTimer = setTimeout(function() {
          convergeFragments();
        }, 400);
      }

      function playHoverOut() {
        clearTimeout(hoverTimer);
        resetIllustration();
      }

      if (problemVisual && !reduceMotion) {
        problemVisual.addEventListener('mouseenter', playHoverIn);
        problemVisual.addEventListener('mouseleave', playHoverOut);
        problemVisual.addEventListener('focus', playHoverIn, true);
        problemVisual.addEventListener('blur', playHoverOut, true);

        // Tap/click to replay on touch or keyboard-activated devices.
        // Click does not fire during scroll, so it will not replay while the
        // user is simply scrolling past the section.
        problemVisual.addEventListener('click', function() {
          if (problemVisual.classList.contains('is-active') || problemVisual.classList.contains('is-converging')) {
            resetIllustration();
            setTimeout(playHoverIn, 80);
          } else {
            playHoverIn();
          }
        });
      } else if (problemVisual && reduceMotion) {
        // Reduced motion: keep the resting layout; connection lines fade in on
        // hover/focus/click, but the cards themselves do not move.
      }

      // Trigger once when substantially in viewport on mobile/no-hover
      if ('IntersectionObserver' in window && problemVisual) {
        var problemObserver = new IntersectionObserver(function(entries) {
          entries.forEach(function(entry) {
            if (entry.isIntersecting && !illustrationInView) {
              illustrationInView = true;
              if (window.matchMedia('(pointer: coarse)').matches || reduceMotion) {
                playIllustrationOnce();
              }
            }
          });
        }, { threshold: 0.4 });
        problemObserver.observe(problemVisual);
      }

      // Recalculate connection lines on resize (debounced)
      var resizeTimer;
      window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
          if (problemVisual && problemVisual.classList.contains('is-converging')) {
            updateConnectionLines();
          }
        }, 150);
      });

      // ============================================================
      // Shared scroll reveal for remaining landing sections
      // ============================================================
      (function() {
        var revealSelector = '[data-reveal]';
        var revealElements = document.querySelectorAll(revealSelector);
        if (!revealElements.length) return;

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var isMobile = window.matchMedia('(max-width: 900px)').matches;
        var staggerStep = isMobile ? 80 : 140;
        var groupMultiplier = isMobile ? 0.5 : 1;
        var rootMargin = isMobile ? '0px 0px -12% 0px' : '0px 0px -25% 0px';

        function cleanRevealClasses(el) {
          el.classList.remove('reveal', 'reveal-up', 'reveal-left', 'reveal-right');
          el.classList.remove('reveal-stagger-1', 'reveal-stagger-2', 'reveal-stagger-3', 'reveal-stagger-4', 'reveal-stagger-5', 'reveal-stagger-6');
        }

        function revealAll() {
          revealElements.forEach(function(el) {
            cleanRevealClasses(el);
            el.classList.add('is-revealed');
            el.style.transitionDelay = '';
          });
        }

        function setRevealDelay(el) {
          var stagger = parseInt(el.getAttribute('data-reveal-stagger'), 10) || 0;
          var group = parseInt(el.getAttribute('data-reveal-delay'), 10) || 0;
          var delay = Math.round((group * groupMultiplier) + (stagger > 1 ? (stagger - 1) * staggerStep : 0));
          el.style.transitionDelay = delay + 'ms';
        }

        function onRevealTransitionEnd(el, cb) {
          function handler(e) {
            if (e.propertyName === 'transform') {
              el.removeEventListener('transitionend', handler);
              cleanRevealClasses(el);
              if (cb) cb();
            }
          }
          el.addEventListener('transitionend', handler);
        }

        function handleStepIcon(el) {
          if (!el.classList.contains('step')) return;
          onRevealTransitionEnd(el, function() {
            el.classList.add('step-icon-animated');
          });
        }

        function handleStatusPulse(el) {
          if (!el.classList.contains('case-card') || !el.closest('.attention-col')) return;
          onRevealTransitionEnd(el, function() {
            el.classList.add('status-pulse');
          });
        }

        function handlePricingEmphasis(el) {
          if (!el.classList.contains('plan')) return;
          onRevealTransitionEnd(el, function() {
            var plans = document.querySelectorAll('.plan');
            var allRevealed = true;
            plans.forEach(function(plan) {
              if (!plan.classList.contains('is-revealed')) allRevealed = false;
            });
            if (allRevealed) {
              var featured = document.querySelector('.plan.featured');
              if (featured) featured.classList.add('is-featured-emphasized');
            }
          });
        }

        if (reduceMotion || !('IntersectionObserver' in window)) {
          revealAll();
          return;
        }

        try {
          // Re-order pricing stagger indices by visual position so the featured
          // plan still leads naturally on mobile where .plan.featured has order:-1.
          var planEls = Array.prototype.slice.call(document.querySelectorAll('.plan'));
          if (planEls.length > 1) {
            planEls.sort(function(a, b) {
              var ar = a.getBoundingClientRect();
              var br = b.getBoundingClientRect();
              if (Math.abs(ar.top - br.top) > 2) return ar.top - br.top;
              return ar.left - br.left;
            });
            planEls.forEach(function(plan, i) {
              plan.setAttribute('data-reveal-stagger', i + 1);
            });
          }

          revealElements.forEach(function(el) {
            var dir = el.getAttribute('data-reveal-dir') || 'up';
            el.classList.add('reveal');
            if (dir !== 'up') el.classList.add('reveal-' + dir);
            setRevealDelay(el);
          });

          var observer = new IntersectionObserver(function(entries, obs) {
            entries.forEach(function(entry) {
              if (entry.isIntersecting) {
                var el = entry.target;
                el.classList.add('is-revealed');
                obs.unobserve(el);
                handleStepIcon(el);
                handleStatusPulse(el);
                handlePricingEmphasis(el);
              }
            });
          }, { threshold: 0, rootMargin: rootMargin });

          revealElements.forEach(function(el) {
            observer.observe(el);
          });
        } catch (e) {
          // If the reveal engine fails at runtime, make sure content remains visible.
          revealAll();
        }
      })();

      // ============================================================
      // Demo request modal
      // ============================================================
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startDemoModal);
      } else {
        startDemoModal();
      }

      function startDemoModal() {
        'use strict';

        var openTriggers = document.querySelectorAll('[data-open-demo-modal]');
        var overlay = document.getElementById('demo-modal-overlay');
        var modal = document.getElementById('demo-modal');
        var closeButtons = document.querySelectorAll('[data-close-demo-modal]');
        var form = document.getElementById('demo-form');
        var statusEl = document.getElementById('demo-form-status');
        var submitBtn = document.getElementById('demo-submit');
        var focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        var lastFocused = null;

        function getFocusable() {
          return Array.prototype.slice.call(modal.querySelectorAll(focusableSelector));
        }

        function openModal(e) {
          if (e) e.preventDefault();
          if (!overlay || !modal) return;
          lastFocused = document.activeElement;
          overlay.classList.add('is-open');
          overlay.hidden = false;
          document.body.style.overflow = 'hidden';
          setTimeout(function() {
            var focusable = getFocusable();
            var first = focusable[0];
            if (first) first.focus();
          }, 0);
        }

        function closeModal() {
          if (!overlay || !modal) return;
          overlay.classList.remove('is-open');
          overlay.hidden = true;
          document.body.style.overflow = '';
          if (lastFocused) lastFocused.focus();
        }

        function trapFocus(e) {
          if (e.key !== 'Tab') return;
          var focusable = getFocusable();
          if (focusable.length === 0) return;
          var first = focusable[0];
          var last = focusable[focusable.length - 1];
          if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
          } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        }

        function validate() {
          var valid = true;
          var errors = [];
          var required = ['name', 'email', 'practice'];
          required.forEach(function(name) {
            var el = form.elements[name];
            var field = el.closest('.demo-field');
            if (!el.value.trim()) {
              valid = false;
              field.classList.add('is-invalid');
              errors.push(name);
            } else {
              field.classList.remove('is-invalid');
            }
          });
          var email = form.elements['email'];
          var emailField = email.closest('.demo-field');
          var emailVal = email.value.trim();
          if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            valid = false;
            emailField.classList.add('is-invalid');
            errors.push('email');
          }
          return { valid: valid, errors: errors };
        }

        function showStatus(message, type) {
          statusEl.textContent = message;
          statusEl.className = 'demo-form-status ' + (type || '');
          statusEl.hidden = false;
        }

        function clearStatus() {
          statusEl.textContent = '';
          statusEl.className = 'demo-form-status';
          statusEl.hidden = true;
        }

        openTriggers.forEach(function(btn) {
          btn.addEventListener('click', openModal);
        });

        closeButtons.forEach(function(btn) {
          btn.addEventListener('click', closeModal);
        });

        overlay.addEventListener('click', function(e) {
          if (e.target === overlay) closeModal();
        });

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
            closeModal();
          }
        });

        modal.addEventListener('keydown', trapFocus);

        form.addEventListener('submit', function(e) {
          e.preventDefault();
          clearStatus();

          var result = validate();
          if (!result.valid) {
            showStatus(<?= json_encode(t('marketing.demo.invalid_fields')) ?>, 'error');
            return;
          }

          if (submitBtn.disabled) return;
          submitBtn.disabled = true;
          submitBtn.textContent = <?= json_encode(t('marketing.demo.sending')) ?>;

          var formData = new FormData(form);
          fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data && data.success) {
              showStatus(data.message || <?= json_encode(t('marketing.demo.success')) ?>, 'success');
              form.querySelectorAll('input:not([type=hidden]), textarea, select').forEach(function(el) { if (el.name !== 'csrf_token' && el.name !== 'website') el.disabled = true; });
              submitBtn.style.display = 'none';
            } else {
              showStatus(data.message || <?= json_encode(t('errors.generic')) ?>, 'error');
              submitBtn.disabled = false;
              submitBtn.textContent = <?= json_encode(t('marketing.demo.submit')) ?>;
            }
          })
          .catch(function(err) {
            showStatus(<?= json_encode(t('errors.generic')) ?>, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = <?= json_encode(t('marketing.demo.submit')) ?>;
          });
        });

        form.addEventListener('input', function(e) {
          if (e.target.closest('.demo-field')) {
            e.target.closest('.demo-field').classList.remove('is-invalid');
          }
        });
      }

      // Homepage Insights snapshot animation
      (function() {
        'use strict';
        var snapshot = document.querySelector('#insights .insights-snapshot');
        if (!snapshot || !('IntersectionObserver' in window)) return;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var animated = false;

        function animateFloatValue(el, target, duration) {
          var startTime = null;
          function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var current = (target * progress).toFixed(1);
            el.textContent = current;
            if (progress < 1) {
              requestAnimationFrame(step);
            } else {
              el.textContent = target.toFixed(1);
            }
          }
          requestAnimationFrame(step);
        }

        function start() {
          if (animated) return;
          animated = true;
          if (reduce) return;

          // Metric values
          var metrics = snapshot.querySelectorAll('.di-metric-value[data-value]');
          metrics.forEach(function(el, i) {
            var target = parseInt(el.getAttribute('data-value'), 10);
            el.textContent = '0';
            setTimeout(function() {
              animateMetricValue(el, target, 500);
            }, i * 80);
          });

          // Donut chart segments
          var donut = snapshot.querySelector('.di-donut');
          if (donut) {
            var segs = donut.querySelectorAll('.di-donut-seg');
            var cumulative = 0;
            var circumference = 2 * Math.PI * 40; // ~251.2
            segs.forEach(function(seg, i) {
              var pct = parseFloat(seg.getAttribute('data-pct')) || 0;
              var offset = -cumulative * circumference / 100;
              var visible = pct * circumference / 100;
              seg.style.strokeDasharray = '0 ' + circumference;
              seg.style.strokeDashoffset = offset;
              seg.style.transitionDelay = (i * 80) + 'ms';
              setTimeout(function() {
                seg.style.strokeDasharray = visible + ' ' + circumference;
              }, 30);
              cumulative += pct;
            });
          }

          // Line chart
          var chart = snapshot.querySelector('.di-line-chart');
          if (chart) {
            var path = chart.querySelector('.di-line-path');
            var dots = chart.querySelectorAll('.di-line-dot[data-dot]');
            if (path) {
              var length = path.getTotalLength();
              path.style.transition = 'none';
              path.style.strokeDasharray = length + ' ' + length;
              path.style.strokeDashoffset = length;
              path.getBoundingClientRect();
              setTimeout(function() {
                path.style.transition = 'stroke-dashoffset 0.8s ease';
                path.style.strokeDashoffset = '0';
              }, 250);
            }
            dots.forEach(function(dot, i) {
              dot.style.transition = 'none';
              dot.style.opacity = '0';
              dot.getBoundingClientRect();
              dot.style.transition = 'opacity 0.3s ease ' + (250 + (i * 180)) + 'ms';
              dot.style.opacity = '1';
            });
          }

          // Average Lab Turnaround
          var lab = snapshot.querySelector('.di-kpi-number[data-value]');
          if (lab) {
            var labTarget = parseFloat(lab.getAttribute('data-value'));
            setTimeout(function() {
              animateFloatValue(lab, labTarget, 500);
            }, 700);
          }

          // Recommendation
          var smart = snapshot.querySelector('.di-smart');
          if (smart) {
            smart.style.transition = 'none';
            smart.style.opacity = '0';
            smart.style.transform = 'translateY(8px)';
            smart.getBoundingClientRect();
            setTimeout(function() {
              smart.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
              smart.style.opacity = '1';
              smart.style.transform = 'translateY(0)';
            }, 1000);
          }
        }

        var io = new IntersectionObserver(function(entries) {
          if (entries[0].isIntersecting) {
            start();
            io.disconnect();
          }
        }, { threshold: 0.2 });
        io.observe(snapshot);
      })();
    })();
  </script>

  <div class="demo-modal-overlay" id="demo-modal-overlay">
    <div class="demo-modal" id="demo-modal" role="dialog" aria-modal="true" aria-labelledby="demo-modal-title" aria-describedby="demo-modal-desc">
      <div class="demo-modal-header">
        <h3 id="demo-modal-title"><?php echo t('marketing.demo.request_demo'); ?></h3>
        <button type="button" class="demo-modal-close" aria-label="<?php echo htmlspecialchars(t('marketing.demo.close_aria')); ?>" data-close-demo-modal>&times;</button>
      </div>
      <form class="demo-form" id="demo-form" action="<?= $baseUrl ?>api/demo-request.php" method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <p id="demo-modal-desc" class="demo-modal-desc"><?php echo t('marketing.demo.modal_desc'); ?></p>
        <div class="demo-form-grid">
          <div class="demo-field">
            <label for="demo-name"><?php echo t('marketing.demo.name'); ?> <span aria-label="<?php echo htmlspecialchars(t('marketing.demo.required_aria')); ?>">*</span></label>
            <input type="text" id="demo-name" name="name" required maxlength="100" autocomplete="name">
          </div>
          <div class="demo-field">
            <label for="demo-email"><?php echo t('marketing.demo.email'); ?> <span aria-label="<?php echo htmlspecialchars(t('marketing.demo.required_aria')); ?>">*</span></label>
            <input type="email" id="demo-email" name="email" required maxlength="254" autocomplete="email">
          </div>
          <div class="demo-field">
            <label for="demo-practice"><?php echo t('marketing.demo.practice'); ?> <span aria-label="<?php echo htmlspecialchars(t('marketing.demo.required_aria')); ?>">*</span></label>
            <input type="text" id="demo-practice" name="practice" required maxlength="120" autocomplete="organization">
          </div>
          <div class="demo-field">
            <label for="demo-phone"><?php echo t('marketing.demo.phone'); ?></label>
            <input type="tel" id="demo-phone" name="phone" maxlength="30" autocomplete="tel">
          </div>
          <div class="demo-field demo-field-wide">
            <label for="demo-preferred"><?php echo t('marketing.demo.preferred'); ?></label>
            <input type="text" id="demo-preferred" name="preferred" maxlength="120" autocomplete="off">
          </div>
          <div class="demo-field demo-field-wide">
            <label for="demo-message"><?php echo t('marketing.demo.message_label'); ?></label>
            <textarea id="demo-message" name="message" rows="3" maxlength="1000" autocomplete="off"></textarea>
          </div>
        </div>
        <div class="demo-form-actions">
          <button type="submit" class="btn btn-primary" id="demo-submit"><?php echo t('marketing.demo.submit'); ?></button>
          <button type="button" class="btn btn-secondary" data-close-demo-modal><?php echo t('marketing.demo.cancel'); ?></button>
        </div>
        <div class="demo-form-status" id="demo-form-status" role="status" aria-live="polite" hidden></div>
        <div class="demo-field hp" aria-hidden="true">
          <label for="demo-website"><?php echo t('marketing.demo.honeypot'); ?></label>
          <input type="text" name="website" id="demo-website" tabindex="-1" autocomplete="off">
        </div>
      </form>
    </div>
  </div>

  <script>
    // Plan-feature info tooltips: click toggles for touch; focus/hover are
    // handled in CSS; Escape or an outside tap dismisses.
    (function () {
      var tips = document.querySelectorAll('.pf-info');
      function closeAll(except) {
        tips.forEach(function (b) { if (b !== except) b.setAttribute('aria-expanded', 'false'); });
      }
      tips.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var open = btn.getAttribute('aria-expanded') === 'true';
          closeAll(btn);
          btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
      });
      document.addEventListener('click', function () { closeAll(null); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeAll(null); }
      });
    })();
  </script>

  <script>
    // Collapsed navigation: hamburger toggle, Escape closes and returns
    // focus, link selection closes, and resizing back to desktop clears
    // any open-menu state. Closed menu is `hidden`, so its items are
    // never keyboard-focusable.
    (function () {
      var toggle = document.getElementById('nav-menu-toggle');
      var menu = document.getElementById('nav-menu');
      if (!toggle || !menu) return;
      var labelOpen = <?php echo json_encode(t('marketing.navigation.menu_open')); ?>;
      var labelClose = <?php echo json_encode(t('marketing.navigation.menu_close')); ?>;
      var mq = window.matchMedia('(max-width: 1220px)');
      function closeMenu() {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', labelOpen);
      }
      toggle.addEventListener('click', function () {
        var open = menu.hidden;
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? labelClose : labelOpen);
      });
      menu.addEventListener('click', function (e) {
        if (e.target.closest('a')) closeMenu();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { closeMenu(); toggle.focus(); }
      });
      document.addEventListener('click', function (e) {
        if (!menu.hidden && !menu.contains(e.target) && !toggle.contains(e.target)) closeMenu();
      });
      (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(function (e) {
        if (!e.matches) closeMenu();
      });
    })();
  </script>

  <script>
  // Scattered-info illustration: pause offscreen + manual pause/resume.
  (function () {
    var stage = document.getElementById('problem-visual');
    var btn = document.getElementById('pvToggle');
    if (!stage || !btn) return;
    var userPaused = false;
    var labels = {
      pause: <?php echo json_encode(t('marketing.problem.anim_pause')); ?>,
      resume: <?php echo json_encode(t('marketing.problem.anim_resume')); ?>
    };
    function apply() {
      stage.classList.toggle('pv-paused', userPaused || stage.classList.contains('pv-offscreen'));
      btn.textContent = userPaused ? labels.resume : labels.pause;
      btn.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { userPaused = !userPaused; apply(); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        stage.classList.toggle('pv-offscreen', !entries[0].isIntersecting);
        apply();
      }, { threshold: 0.05 }).observe(stage);
    }
    apply();
  })();
  </script>

    <script>
  // How-it-works walkthrough: 7 scenes, autoplay once then hold, full controls.
  (function () {
    var wrap = document.getElementById('hwWalk');
    if (!wrap) return;
    var scenes = wrap.querySelectorAll('.hw-scene');
    var steps = wrap.querySelectorAll('.hw-step');
    var title = document.getElementById('hwSceneTitle');
    var copy = document.getElementById('hwSceneCopy');
    var dots = document.getElementById('hwDots');
    var btnPrev = document.getElementById('hwPrev');
    var btnNext = document.getElementById('hwNext');
    var btnPause = document.getElementById('hwPause');
    var btnReplay = document.getElementById('hwReplay');
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var labels = {
      pause: <?php echo json_encode(t('marketing.walkthrough.pause')); ?>,
      play: <?php echo json_encode(t('marketing.walkthrough.play')); ?>
    };
    var meta = <?php $o=[];$c=[];for($i=1;$i<=7;$i++){$o[$i]=t('marketing.walkthrough.step'.$i.'_title');$c[$i]=t('marketing.walkthrough.step'.$i.'_copy');}echo json_encode(['t'=>$o,'c'=>$c],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
    var DUR = {1:9500, 2:9800, 3:10600, 4:11000, 5:9800, 6:10000, 7:12000};
    var current = 1, playing = false, cssFrozen = false, timer = null, visible = false;
    for (var i = 1; i <= 7; i++) { var d = document.createElement('span'); d.className = 'hw-dot'; dots.appendChild(d); }
    var dotEls = dots.querySelectorAll('.hw-dot');
    function applyFreeze() {
      wrap.classList.toggle('hw-paused', cssFrozen || !visible || document.hidden);
    }
    function show(n, userInitiated) {
      current = n;
      scenes.forEach(function (s) {
        var on = +s.dataset.scene === n;
        s.classList.toggle('hw-active', on);
        if (on) s.removeAttribute('hidden'); else s.setAttribute('hidden', '');
      });
      steps.forEach(function (s) { s.setAttribute('aria-selected', +s.dataset.step === n ? 'true' : 'false'); });
      dotEls.forEach(function (d, i) { d.classList.toggle('on', i === n - 1); });
      title.textContent = meta.t[n]; copy.textContent = meta.c[n];
      wrap.classList.remove('hw-anim'); void wrap.offsetWidth;
      if (!reduced) wrap.classList.add('hw-anim');
      if (userInitiated) { playing = false; syncPause(); }
      queue();
    }
    function queue() {
      clearTimeout(timer);
      if (!playing || !visible) return;
      timer = setTimeout(function () {
        if (current >= 7) { finish(); return; }
        show(current + 1, false);
      }, DUR[current] || 9000);
    }
    function finish() { playing = false; clearTimeout(timer); btnReplay.hidden = false; btnPause.hidden = true; }
    function syncPause() {
      btnPause.textContent = playing ? labels.pause : labels.play;
      btnPause.setAttribute('aria-pressed', playing ? 'false' : 'true');
    }
    btnPrev.addEventListener('click', function () { show(current === 1 ? 7 : current - 1, true); });
    btnNext.addEventListener('click', function () { show(current === 7 ? 1 : current + 1, true); });
    btnPause.addEventListener('click', function () {
      cssFrozen = !cssFrozen; playing = !cssFrozen; syncPause(); applyFreeze();
      if (playing) queue(); else clearTimeout(timer);
    });
    btnReplay.addEventListener('click', function () {
      btnReplay.hidden = true; btnPause.hidden = false;
      cssFrozen = false; playing = visible && !reduced; syncPause(); applyFreeze();
      show(1, false);
    });
    steps.forEach(function (s) { s.addEventListener('click', function () { show(+s.dataset.step, true); }); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (e) {
        visible = e[0].isIntersecting;
        applyFreeze();
        if (visible && !playing && !reduced && !cssFrozen) { playing = true; syncPause(); queue(); }
        if (!visible) { playing = false; clearTimeout(timer); syncPause(); }
      }, { threshold: 0.35 }).observe(wrap);
    } else { visible = true; playing = !reduced; syncPause(); queue(); }
    document.addEventListener('visibilitychange', function () {
      applyFreeze();
      if (document.hidden) { clearTimeout(timer); } else if (playing && visible) { queue(); }
    });
    show(1, false);
    if (reduced) { btnPause.hidden = true; }
  })();
  </script>
  <script>
  // Ask DentaTrak demo: typed question -> bubble -> dots -> answer -> hold -> next.
  (function () {
    var root = document.getElementById('askDemo');
    if (!root) return;
    var userMsg = document.getElementById('askUserMsg');
    var aiMsg = document.getElementById('askAiMsg');
    var dots = document.getElementById('askDots');
    var typed = document.getElementById('askTyped');
    var caret = document.getElementById('askCaret');
    var btnPause = document.getElementById('askPause');
    var btnPrev = document.getElementById('askPrev');
    var btnNext = document.getElementById('askNext');
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var EX = <?php $a=[];for($i=1;$i<=6;$i++){$a[]=['q'=>t('marketing.askdemo.q'.$i),'a'=>t('marketing.askdemo.a'.$i)];}echo json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
    var labels = { pause: <?php echo json_encode(t('marketing.askdemo.pause')); ?>, play: <?php echo json_encode(t('marketing.askdemo.play')); ?> };
    var N = EX.length, cur = 0, timer = null, paused = false, visible = false, started = false;

    function setT(id, fn, ms) { timer = setTimeout(fn, ms); }
    function clearT() { clearTimeout(timer); timer = null; }
    function renderStatic(i) {
      clearT();
      userMsg.hidden = false; userMsg.textContent = EX[i].q;
      dots.hidden = true;
      aiMsg.hidden = false; aiMsg.textContent = EX[i].a;
      typed.textContent = '';
    }
    function play(i) {
      clearT();
      if (reduced) { renderStatic(i); return; }
      var q = EX[i].q, a = EX[i].a;
      userMsg.hidden = true; aiMsg.hidden = true; dots.hidden = true;
      userMsg.textContent = ''; aiMsg.textContent = '';
      typed.textContent = ''; caret.style.display = '';
      var p = 0;
      (function type() {
        if (p <= q.length) { typed.textContent = q.slice(0, p++); timer = setTimeout(type, 34); }
        else setT('t1', function () {
          userMsg.hidden = false; userMsg.textContent = q; typed.textContent = '';
          dots.hidden = false;
          setT('t2', function () {
            dots.hidden = true; aiMsg.hidden = false; aiMsg.textContent = a;
            setT('hold', function () { if (!paused && visible) next(); }, 5000 + Math.min(a.length * 28, 3000));
          }, 1000);
        }, 350);
      })();
    }
    function go(i) { cur = ((i % N) + N) % N; play(cur); }
    function next() { go(cur + 1); }
    function sync() {
      btnPause.textContent = paused ? labels.play : labels.pause;
      btnPause.setAttribute('aria-pressed', paused ? 'true' : 'false');
      root.classList.toggle('ask-paused', paused || !visible || document.hidden);
    }
    btnPause.addEventListener('click', function () {
      paused = !paused;
      if (paused) clearT(); else play(cur);
      sync();
    });
    btnPrev.addEventListener('click', function () { paused = true; sync(); go(cur - 1); });
    btnNext.addEventListener('click', function () { paused = true; sync(); next(); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (e) {
        visible = e[0].isIntersecting;
        if (visible && !started) { started = true; play(cur); }
        else if (visible && !paused && !timer) { play(cur); }
        else if (!visible) clearT();
        sync();
      }, { threshold: 0.3 }).observe(root);
    } else { visible = true; started = true; play(cur); }
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) clearT();
      else if (visible && !paused && started) play(cur);
      sync();
    });
    if (reduced) { renderStatic(0); }
    sync();
  })();
  </script>
</body>
</html>
