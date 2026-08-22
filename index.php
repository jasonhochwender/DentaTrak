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
  <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Structured Data: Organization -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": <?php echo json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "url": "https://dentatrak.com/",
    "email": <?php echo json_encode(t('marketing.footer.support_email'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  }
  </script>

  <!-- Structured Data: WebSite -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": <?php echo json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "url": "https://dentatrak.com/"
  }
  </script>

  <!-- Structured Data: SoftwareApplication (pricing mirrors the Pricing section on this page) -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": <?php echo json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "applicationCategory": "BusinessApplication",
    "operatingSystem": "Web",
    "description": <?php echo json_encode(t('marketing.seo.index.description'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "url": "https://dentatrak.com/",
    "featureList": [
      "Visual case workflow board with six built-in stages",
      "Customizable workflow stage names",
      "Case ownership and assignment",
      "Due dates and past-due visibility",
      "Lab and referral dependency tracking",
      "Case files and case information in one place",
      "Practice Insights and Smart Recommendations"
    ],
    "offers": [
      {
        "@type": "Offer",
        "name": <?php echo json_encode(t('marketing.pricing.operate'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "price": "249.00",
        "priceCurrency": "USD",
        "url": "https://dentatrak.com/#pricing"
      },
      {
        "@type": "Offer",
        "name": <?php echo json_encode(t('marketing.pricing.control'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "price": "499.00",
        "priceCurrency": "USD",
        "url": "https://dentatrak.com/#pricing"
      },
      {
        "@type": "Offer",
        "name": <?php echo json_encode(t('marketing.pricing.scale'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "price": "999.00",
        "priceCurrency": "USD",
        "url": "https://dentatrak.com/#pricing"
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
    .app-tab { padding: 6px 12px; border-radius: 8px; color: var(--dt-ink-muted); }
    .app-tab.active { background: #fff; color: var(--dt-ink); border: 1px solid var(--dt-border); }
    .app-filter { margin-left: auto; font-size: 0.78rem; color: var(--dt-ink-muted); font-weight: 500; }
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

    /* Problem */
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
        <a href="<?= $baseUrl ?>resources/"><?php echo t('marketing.navigation.resources'); ?></a>
        <a href="#pricing"><?php echo t('marketing.navigation.pricing'); ?></a>
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
        <div class="app-tabs">
          <span class="app-tab active">Cases</span>
          <span class="app-tab">Practice Insights</span>
          <span class="app-tab">Lab Insights</span>
          <span class="app-filter">Filters: All Cases</span>
        </div>
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
        <div class="problem-visual">
          <div class="fragment f-1"><strong>Patient Appointment</strong><small>Mar 19 &middot; 10:30 AM &middot; Hannah L.</small></div>
          <div class="fragment f-2"><strong>Lab Update</strong><small>Precision Dental Lab &middot; Revision 2</small></div>
          <div class="fragment f-3"><strong>Shipping</strong><small>Tracking 1Z84&hellip; &middot; Arrives Thu</small></div>
          <div class="fragment f-4"><strong>Case File</strong><small>Crown #14 &middot; Due Mar 18</small></div>
          <div class="fragment f-5"><strong>Team Message</strong><small>"Did the lab send this back yet?"</small></div>
          <div class="fragment f-6"><strong>Assigned To</strong><small>Front Desk</small></div>
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
      <span class="eyebrow"><?php echo t('marketing.workflow.eyebrow'); ?></span>
      <h2><?php echo t('marketing.workflow.title'); ?></h2>
      <p class="lead"><?php echo t('marketing.workflow.lead'); ?></p>
      <div class="steps-grid">
        <div class="step step-1">
          <div class="step-num">01</div>
          <svg class="step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
          <h3><?php echo t('marketing.workflow.step1_title'); ?></h3>
          <p><?php echo t('marketing.workflow.step1_body'); ?></p>
        </div>
        <div class="step step-2">
          <div class="step-num">02</div>
          <svg class="step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 16h5v5"></path></svg>
          <h3><?php echo t('marketing.workflow.step2_title'); ?></h3>
          <p><?php echo t('marketing.workflow.step2_body'); ?></p>
        </div>
        <div class="step step-3">
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
      <span class="eyebrow"><?php echo t('marketing.attention.eyebrow'); ?></span>
      <h2><?php echo t('marketing.attention.title'); ?></h2>
      <p class="lead"><?php echo t('marketing.attention.lead'); ?></p>
      <div class="attention-board">
        <div class="attention-col">
          <div class="attention-title late"><?php echo t('marketing.attention.late'); ?></div>
          <div class="case-card late">
            <h4>Justin Vance</h4>
            <div class="case-type">Partial</div>
            <div class="case-meta">Due: Mar 16</div>
            <div class="case-meta">Assigned: Front Desk</div>
            <div class="case-meta">Precision Dental Lab &middot; Revision 2</div>
            <span class="case-flag flag-late">Late</span>
          </div>
        </div>
        <div class="attention-col">
          <div class="attention-title due"><?php echo t('marketing.attention.due_soon'); ?></div>
          <div class="case-card due">
            <h4>Hannah Lindqvist</h4>
            <div class="case-type">Crown</div>
            <div class="case-meta">Due: Mar 12</div>
            <div class="case-meta">Assigned: Dr. Rivera</div>
            <div class="case-meta">Patient Appt: Mar 10</div>
            <span class="case-flag flag-due">Due Soon</span>
          </div>
        </div>
        <div class="attention-col">
          <div class="attention-title appt"><?php echo t('marketing.attention.appointment_risk'); ?></div>
          <div class="case-card appt">
            <h4>Sofia Patel</h4>
            <div class="case-type">Veneer</div>
            <div class="case-meta">Due: Mar 22</div>
            <div class="case-meta">Assigned: Design Team</div>
            <div class="case-meta">Patient Appt: Mar 14</div>
            <span class="case-flag flag-appt">Appt Risk</span>
          </div>
        </div>
        <div class="attention-col">
          <div class="attention-title ready"><?php echo t('marketing.attention.ready'); ?></div>
          <div class="case-card">
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
        <div class="founder-card">
          <div class="founder-initials">D<span>r.</span> V</div>
          <div class="founder-name">Dr. William Verrillo</div>
          <div class="founder-role">Practicing Dentist &middot; DentaTrak Co-Founder</div>
        </div>
        <div>
          <span class="eyebrow"><?php echo t('marketing.founder.eyebrow'); ?></span>
          <h2><?php echo t('marketing.founder.title'); ?></h2>
          <p class="lead"><?php echo t('marketing.founder.lead'); ?></p>
        </div>
      </div>
    </div>
  </section>

  <section id="pricing" class="section pricing">
    <div class="container">
      <div class="pricing-intro">
        <span class="eyebrow"><?php echo t('marketing.pricing.eyebrow'); ?></span>
        <h2><?php echo t('marketing.pricing.title'); ?></h2>
        <p class="lead"><?php echo t('marketing.pricing.lead'); ?></p>
      </div>
      <div class="plans">
        <div class="plan">
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
        <div class="plan featured">
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
        <div class="plan">
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
      <h2><?php echo t('marketing.cta.final_title'); ?></h2>
      <p class="lead"><?php echo t('marketing.cta.final_lead'); ?></p>
      <div>
        <a href="<?= $baseUrl ?>login.php" class="btn btn-primary"><?php echo t('marketing.cta.start_free'); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="btn btn-secondary"><?php echo t('marketing.cta.sign_in'); ?></a>
      </div>
    </div>
  </section>

  <section class="trust">
    <div class="container">
      <div class="trust-items">
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
            <a href="<?= $baseUrl ?>resources/"><?php echo t('marketing.footer.resources'); ?></a>
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

</body>
</html>
