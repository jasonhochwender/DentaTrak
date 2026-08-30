<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/feature-flags.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$showLabInsights = isFeatureEnabled('SHOW_LAB_INSIGHTS');
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
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

  <meta name="description" content="<?php echo htmlspecialchars(t('marketing.seo.index.description')); ?>">
  <title><?php echo htmlspecialchars(t('marketing.seo.index.title')); ?></title>
  <link rel="canonical" href="https://dentatrak.com/">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

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
        "featureList": [
          "Visual case workflow board with six built-in stages",
          "Customizable workflow stage names",
          "Case ownership and assignment",
          "Due dates and past-due visibility",
          "Lab and referral dependency tracking",
          "Case files and case information in one place",
          "Practice Insights and Smart Recommendations"
        ]
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
    body { font-family: 'Poppins', system-ui, -apple-system, sans-serif; background: var(--dt-warm); color: var(--dt-ink); line-height: 1.6; }
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
    .site-nav { display: flex; align-items: center; gap: 28px; }
    .site-nav a { font-size: 0.9rem; font-weight: 500; color: var(--dt-ink-secondary); }
    .site-nav a:hover { color: var(--dt-blue); }
    .site-nav a.nav-cta,
    .site-nav a.nav-cta:visited,
    .site-nav a.nav-cta:hover,
    .site-nav a.nav-cta:focus,
    .site-nav a.nav-cta:active { background: var(--dt-blue); color: #fff; padding: 9px 20px; border-radius: 100px; font-weight: 600; }
    .site-nav a.nav-cta:hover { background: var(--dt-blue-light); }

    /* Hero */
    .hero { padding: 150px 0 80px; text-align: center; }
    .hero h1 { font-size: clamp(2.6rem, 6vw, 4.6rem); font-weight: 700; line-height: 1.08; letter-spacing: -0.03em; max-width: 900px; margin: 0 auto 24px; }
    .hero .lead { margin: 0 auto 34px; }
    .hero-actions { display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
    .hero-trial { font-size: 0.9rem; color: var(--dt-ink-muted); margin-bottom: 56px; }

    /* Product board */
    .product-preview { background: #fff; border: 1px solid var(--dt-border); border-radius: 24px; box-shadow: var(--dt-shadow); overflow: hidden; text-align: left; }
    .app-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: #fafafa; border-bottom: 1px solid var(--dt-border); }
    .app-brand { display: flex; align-items: center; gap: 10px; font-size: 1rem; font-weight: 700; }
    .app-brand img { height: auto; width: auto; max-width: 105px; object-fit: contain; display: block; }
    .app-practice { font-size: 0.78rem; color: var(--dt-ink-muted); padding: 4px 10px; background: #fff; border: 1px solid var(--dt-border); border-radius: 100px; }
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

    /* How it works */
    .steps-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 48px; }
    .step { background: #fff; border: 1px solid var(--dt-border); border-radius: var(--dt-radius); padding: 32px; position: relative; border-bottom: 3px solid var(--dt-border); }
    .step-1 { border-bottom-color: #3b82f6; }
    .step-2 { border-bottom-color: #8b5cf6; }
    .step-3 { border-bottom-color: #14b8a6; }
    .step-num { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 0.85rem; font-weight: 700; margin-bottom: 18px; color: #fff; }
    .step-1 .step-num { background: #3b82f6; }
    .step-2 .step-num { background: #8b5cf6; }
    .step-3 .step-num { background: #14b8a6; }
    .step-icon { position: absolute; top: 28px; right: 28px; width: 22px; height: 22px; color: var(--dt-ink-muted); }
    .step-1 .step-icon { color: #3b82f6; }
    .step-2 .step-icon { color: #8b5cf6; }
    .step-3 .step-icon { color: #14b8a6; }
    .step h3 { font-size: 1.2rem; font-weight: 700; margin-bottom: 10px; }
    .step p { font-size: 0.95rem; color: var(--dt-ink-secondary); line-height: 1.6; }

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
    .plan-extra { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--dt-border); font-size: 0.85rem; color: var(--dt-ink-secondary); }

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
      .site-nav a:not(.nav-cta) { display: none; }
      .site-nav { gap: 16px; }
      .problem-grid, .founder-grid { grid-template-columns: 1fr; text-align: center; }
      .problem .lead, .founder .lead { margin-left: auto; margin-right: auto; }
      .problem-visual { min-height: 320px; order: -1; }
      .fragment { position: relative; inset: auto !important; top: auto !important; left: auto !important; right: auto !important; margin: 8px; }
      .problem-visual { display: flex; flex-wrap: wrap; justify-content: center; }
      .problem-case { width: 100%; max-width: 320px; }
      .problem-connections { display: none; }
      .problem-visual.is-active .fragment,
      .problem-visual.is-converging .problem-case { transform: none !important; }
      .di-metrics, .di-charts, .di-split { grid-template-columns: 1fr; }
      .di-metric-value { font-size: 1.45rem; }
      .steps-grid { grid-template-columns: 1fr; }
      .attention-board { grid-template-columns: 1fr; }
      .plans { grid-template-columns: 1fr; max-width: 600px; margin: 0 auto; }
      .plan.featured { order: -1; }
    }

    @media (max-width: 720px) {
      .section { padding: 72px 0; }
      .hero { padding: 120px 0 60px; }
      .hero h1 { font-size: 2.2rem; }
      .hero .lead { font-size: 1rem; }
      .footer-nav { flex-direction: column; gap: 32px; }
      .board { grid-template-columns: repeat(3, 1fr); }
      .board-col:nth-child(n+4) { display: none; }
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
    .step-1.step-icon-animated .step-icon { animation-name: stepPlus; }
    .step-2.step-icon-animated .step-icon { animation-name: stepRefresh; }
    .step-3.step-icon-animated .step-icon { animation-name: stepAlert; }

    @keyframes stepPlus {
      from { opacity: 0; transform: scale(0.6); }
      to { opacity: 1; transform: scale(1); }
    }
    @keyframes stepRefresh {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
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
  </style>
</head>
<body>

  <header class="site-header">
    <div class="container">
      <a href="<?= $baseUrl ?>" class="site-logo" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>">
        <img src="<?= $baseUrl ?>images/main.png" alt="<?php echo htmlspecialchars(t('marketing.accessibility.logo_alt')); ?>">
      </a>
      <nav class="site-nav" aria-label="Primary">
        <a href="#problem"><?php echo t('marketing.navigation.problem'); ?></a>
        <a href="#how-it-works"><?php echo t('marketing.navigation.how_it_works'); ?></a>
        <a href="#pricing"><?php echo t('marketing.navigation.pricing'); ?></a>
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t('marketing.navigation.resources'); ?></a>
        <a href="<?= $baseUrl ?>login.php"><?php echo t('marketing.navigation.sign_in'); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t('marketing.navigation.start_free'); ?></a>
        <?php echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false); ?>
      </nav>
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
      <p class="hero-trial"><?php echo t('marketing.hero.trial_note'); ?></p>

      <div class="product-preview">
        <div class="app-header">
          <div class="app-brand">
            <img src="images/main.png" alt="" aria-hidden="true">
          </div>
          <span class="app-practice">Demo Dental Practice</span>
        </div>
        <div class="app-tabs" role="tablist" aria-label="Product preview">
          <button class="app-tab active" id="demo-tab-cases" role="tab" aria-selected="true" aria-controls="demo-cases">Cases</button>
          <button class="app-tab" id="demo-tab-insights" role="tab" aria-selected="false" aria-controls="demo-insights" tabindex="-1">Insights</button>
          <span class="app-filter" id="demo-filter">Filters: All Cases</span>
        </div>
        <div class="app-views" id="demo-views">
          <div class="app-view active" id="demo-cases" role="tabpanel" aria-labelledby="demo-tab-cases">
            <div class="app-board">
              <div class="board">
                <div class="board-col">
                  <div class="board-stage stage-originated"><span>Originated</span><span class="stage-count">2</span></div>
                  <div class="case-card due">
                    <h4>Hannah Lindqvist</h4>
                    <div class="case-type">Crown</div>
                    <div class="case-meta">Due: Mar 12</div>
                    <div class="case-meta">Patient Appt: Mar 10</div>
                    <div class="case-meta">Dr. Rivera</div>
                    <span class="case-flag flag-due">Due Soon</span>
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
                <div class="di-metric due" data-target="2" data-prefix="">
                  <div class="di-metric-value" data-value="2">0</div>
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
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#3b82f6" stroke-dasharray="0 251.2" data-pct="17" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#8b5cf6" stroke-dasharray="0 251.2" data-pct="8" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#dc2626" stroke-dasharray="0 251.2" data-pct="8" transform="rotate(-90 50 50)"></circle>
                      <circle class="di-donut-seg" cx="50" cy="50" r="40" stroke="#14b8a6" stroke-dasharray="0 251.2" data-pct="8" transform="rotate(-90 50 50)"></circle>
                    </svg>
                  </div>
                  <div class="di-legend" aria-hidden="true">
                    <span><span class="di-legend-dot" style="background:#22c55e"></span>On Track (7)</span>
                    <span><span class="di-legend-dot" style="background:#3b82f6"></span>Due Soon (2)</span>
                    <span><span class="di-legend-dot" style="background:#8b5cf6"></span>Appt Risk (1)</span>
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
                      <div class="di-kpi-value">1</div>
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
                  <h4>Smart Recommendation</h4>
                  <p>Hannah Lindqvist's crown is due soon and her patient appointment is Mar 10. Confirm Precision Dental Lab will deliver by Mar 9 to avoid a chair-side delay.</p>
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
        <div class="problem-visual" id="problem-visual" tabindex="0" aria-label="Illustration: scattered case information becomes one coordinated case record. Tap to replay.">
          <svg class="problem-connections" aria-hidden="true" preserveAspectRatio="none" id="problem-connections">
            <g id="problem-connections-group"></g>
          </svg>
          <div class="fragment f-1" data-color="#8b5cf6"><strong>Patient Appointment</strong><small>Mar 19 &middot; 10:30 AM &middot; Hannah L.</small></div>
          <div class="fragment f-2" data-color="#3b82f6"><strong>Lab Update</strong><small>Precision Dental Lab &middot; Revision 2</small></div>
          <div class="fragment f-3" data-color="#f59e0b"><strong>Shipping</strong><small>Tracking 1Z84&hellip; &middot; Arrives Thu</small></div>
          <div class="fragment f-4" data-color="#22c55e"><strong>Case File</strong><small>Crown #14 &middot; Due Mar 18</small></div>
          <div class="fragment f-5" data-color="#64748b"><strong>Team Message</strong><small>"Did the lab send this back yet?"</small></div>
          <div class="fragment f-6" data-color="#06b6d4"><strong>Assigned To</strong><small>Front Desk</small></div>
          <div class="problem-case">
            <h3>Hannah Lindqvist</h3>
            <p>Crown #14 &middot; Due Mar 18<br>Dr. Rivera &middot; Precision Dental Lab<br>Patient appt Mar 19 &middot; Revision 2</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="how-it-works" class="section">
    <div class="container">
      <span class="eyebrow" data-reveal><?php echo t('marketing.workflow.eyebrow'); ?></span>
      <h2 data-reveal><?php echo t('marketing.workflow.title'); ?></h2>
      <p class="lead" data-reveal><?php echo t('marketing.workflow.lead'); ?></p>
      <p class="section-body" data-reveal>
        <?php
          $catUrl = $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software');
          $catLink = '<a href="' . $catUrl . '" class="content-link">' . t('marketing.workflow.link_label') . '</a>';
          echo t('marketing.workflow.body', ['link' => $catLink]);
        ?>
      </p>
      <div class="steps-grid">
        <div class="step step-1" data-reveal data-reveal-stagger="1" data-reveal-delay="150">
          <div class="step-num">01</div>
          <svg class="step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
          <h3><?php echo t('marketing.workflow.step1_title'); ?></h3>
          <p><?php echo t('marketing.workflow.step1_body'); ?></p>
        </div>
        <div class="step step-2" data-reveal data-reveal-stagger="2" data-reveal-delay="150">
          <div class="step-num">02</div>
          <svg class="step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 16h5v5"></path></svg>
          <h3><?php echo t('marketing.workflow.step2_title'); ?></h3>
          <p><?php echo t('marketing.workflow.step2_body'); ?></p>
        </div>
        <div class="step step-3" data-reveal data-reveal-stagger="3" data-reveal-delay="150">
          <div class="step-num">03</div>
          <svg class="step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
          <h3><?php echo t('marketing.workflow.step3_title'); ?></h3>
          <p><?php echo t('marketing.workflow.step3_body'); ?></p>
        </div>
      </div>
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
            <h4>Hannah Lindqvist</h4>
            <div class="case-type">Crown</div>
            <div class="case-meta">Due: Mar 12</div>
            <div class="case-meta">Assigned: Dr. Rivera</div>
            <div class="case-meta">Patient Appt: Mar 10</div>
            <span class="case-flag flag-due">Due Soon</span>
          </div>
        </div>
        <div class="attention-col" data-reveal data-reveal-stagger="3" data-reveal-delay="150">
          <div class="attention-title appt"><?php echo t('marketing.attention.appointment_risk'); ?></div>
          <div class="case-card appt" data-reveal data-reveal-stagger="3" data-reveal-delay="250">
            <h4>Sofia Patel</h4>
            <div class="case-type">Veneer</div>
            <div class="case-meta">Due: Mar 22</div>
            <div class="case-meta">Assigned: Design Team</div>
            <div class="case-meta">Patient Appt: Mar 14</div>
            <span class="case-flag flag-appt">Appt Risk</span>
          </div>
        </div>
        <div class="attention-col" data-reveal data-reveal-stagger="4" data-reveal-delay="150">
          <div class="attention-title ready"><?php echo t('marketing.attention.ready'); ?></div>
          <div class="case-card" data-reveal data-reveal-stagger="4" data-reveal-delay="250">
            <h4>Sarah Bennett</h4>
            <div class="case-type">Bridge</div>
            <div class="case-meta">Received: Mar 4</div>
            <div class="case-meta">Assigned: Dr. Chen</div>
            <div class="case-meta">Precision Dental Lab</div>
          </div>
        </div>
      </div>
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

  <section id="pricing" class="section pricing">
    <div class="container">
      <div class="pricing-intro" data-reveal>
        <span class="eyebrow"><?php echo t('marketing.pricing.eyebrow'); ?></span>
        <h2><?php echo t('marketing.pricing.title'); ?></h2>
        <p class="lead"><?php echo t('marketing.pricing.lead'); ?></p>
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
            <li><?php echo t('marketing.pricing.scale_features_3'); ?></li>
          </ul>
          <div class="plan-extra"><strong><?php echo t('marketing.pricing.scale_addon_title'); ?></strong><br><?php echo t('marketing.pricing.scale_addon_month'); ?><br><?php echo t('marketing.pricing.scale_addon_year'); ?></div>
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
        <a href="<?= $baseUrl ?>login.php" class="btn btn-secondary"><?php echo t('marketing.cta.sign_in'); ?></a>
      </div>
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
    })();
  </script>

</body>
</html>
