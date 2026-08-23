<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
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
  
  <meta name="description" content="<?php echo htmlspecialchars(t("marketing.articles.dental_case_tracking_checklist.seo.description")); ?>">
  <title><?php echo htmlspecialchars(t("marketing.articles.dental_case_tracking_checklist.seo.title")); ?></title>

  <!-- Open Graph -->
  <meta property="og:title" content="<?php echo htmlspecialchars(t("marketing.articles.dental_case_tracking_checklist.seo.title")); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars(t("marketing.articles.dental_case_tracking_checklist.seo.description")); ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/dental-case-tracking-checklist">
  <meta property="og:site_name" content="DentaTrak">
  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo htmlspecialchars(t("marketing.articles.dental_case_tracking_checklist.seo.title")); ?>">
  <meta name="twitter:description" content="<?php echo htmlspecialchars(t("marketing.articles.dental_case_tracking_checklist.seo.description")); ?>">
  <link rel="canonical" href="https://dentatrak.com/dental-case-tracking-checklist">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Structured Data: Article (dates/author mirror the visible byline below) -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?php echo json_encode(t("marketing.articles.dental_case_tracking_checklist.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "publisher": { "@id": "https://dentatrak.com/#organization" },
    "datePublished": "2026-08-22",
    "dateModified": "2026-08-22",
    "mainEntityOfPage": "https://dentatrak.com/dental-case-tracking-checklist"
  }
  </script>

  <!-- Structured Data: BreadcrumbList -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": <?php echo json_encode(t('marketing.navigation.home'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/" },
      { "@type": "ListItem", "position": 2, "name": <?php echo json_encode(t('marketing.navigation.resources'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/resources" },
      { "@type": "ListItem", "position": 3, "name": <?php echo json_encode(t('marketing.articles.dental_case_tracking_checklist.h1'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/dental-case-tracking-checklist" }
    ]
  }
  </script>

  <style>
    :root {
      --primary-color: #1e40af;
      --primary-light: #2563eb;
      --primary-dark: #1e3a8a;
      --text-primary: #1e293b;
      --text-secondary: #475569;
      --text-light: #64748b;
      --background-white: #ffffff;
      --background-subtle: #f8fafc;
      --background-muted: #f1f5f9;
      --border-light: #e2e8f0;
      --border-medium: #cbd5e1;
      --shadow-small: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
      --shadow-medium: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
      --radius-sm: 6px;
      --radius-md: 8px;
      --radius-lg: 12px;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      color: var(--text-primary);
      line-height: 1.6;
      background: var(--background-white);
    }

    /* Navigation */
    .nav {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid var(--border-light);
      z-index: 100;
      padding: 0 24px;
    }

    .nav-inner {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 64px;
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .nav-logo {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--primary-color);
      text-decoration: none;
    }

    .nav-login {
      font-size: 0.9rem;
      font-weight: 500;
      color: var(--text-secondary);
      text-decoration: none;
      transition: color 0.2s;
    }

    .nav-login:hover { color: var(--primary-color); }

    @media (max-width: 540px) {
      .nav-login { display: none; }
    }

    .nav-cta {
      display: inline-flex;
      align-items: center;
      padding: 8px 20px;
      background: var(--primary-color);
      color: white;
      font-size: 0.875rem;
      font-weight: 600;
      border-radius: var(--radius-md);
      text-decoration: none;
      transition: background 0.2s;
    }

    .nav-cta:hover { background: var(--primary-light); }

    /* Content */
    .content {
      max-width: 800px;
      margin: 0 auto;
      padding: 120px 24px 80px;
    }

    .content h1 {
      font-size: 2.5rem;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: -0.03em;
      color: var(--text-primary);
      margin-bottom: 16px;
    }

    .article-meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px 16px;
      font-size: 0.875rem;
      color: var(--text-light);
      margin-bottom: 28px;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border-light);
    }

    .article-meta strong { color: var(--text-secondary); font-weight: 600; }
    .article-meta .meta-divider { color: var(--border-medium); }

    .content h2 {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-top: 48px;
      margin-bottom: 16px;
    }

    .content h3 {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-top: 32px;
      margin-bottom: 12px;
    }

    .content p {
      font-size: 1.05rem;
      color: var(--text-secondary);
      line-height: 1.8;
      margin-bottom: 20px;
    }

    .content ul {
      margin: 20px 0 20px 24px;
    }

    .content li {
      font-size: 1rem;
      color: var(--text-secondary);
      line-height: 1.8;
      margin-bottom: 12px;
    }

    .workflow-steps {
      list-style: none;
      margin: 24px 0;
      padding: 0;
      counter-reset: step;
    }

    .workflow-steps li {
      position: relative;
      padding-left: 48px;
      margin-bottom: 24px;
      counter-increment: step;
    }

    .workflow-steps li::before {
      content: counter(step);
      position: absolute;
      left: 0;
      top: 0;
      width: 32px;
      height: 32px;
      background: var(--primary-color);
      color: white;
      font-size: 0.875rem;
      font-weight: 600;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .workflow-steps strong {
      display: block;
      color: var(--text-primary);
      margin-bottom: 4px;
    }

    .highlight-box {
      background: var(--background-subtle);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 32px;
      margin: 32px 0;
    }

    .highlight-box h3 {
      margin-top: 0;
    }

    .highlight-box p {
      margin-bottom: 0;
    }

    .cta-section {
      background: var(--primary-color);
      border-radius: var(--radius-lg);
      padding: 48px;
      text-align: center;
      margin-top: 48px;
    }

    .cta-section h2 {
      color: white;
      margin-top: 0;
      margin-bottom: 16px;
    }

    .cta-section p {
      color: rgba(255, 255, 255, 0.85);
      margin-bottom: 24px;
    }

    .checklist-intro { margin-bottom: 24px; }

    .checklist-controls {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .btn-print {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      background: var(--primary-color);
      color: white;
      font-size: 0.95rem;
      font-weight: 600;
      border: none;
      border-radius: var(--radius-md);
      cursor: pointer;
      text-decoration: none;
      transition: background 0.2s;
    }

    .btn-print:hover { background: var(--primary-light); }

    .checklist { margin: 24px 0; }

    .checklist-group {
      background: var(--background-white);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 24px;
      margin-bottom: 20px;
      break-inside: avoid;
    }

    .checklist-group h3 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 1.15rem;
      color: var(--text-primary);
    }

    .checklist-note {
      font-size: 0.92rem;
      color: var(--text-light);
      margin-bottom: 16px;
      line-height: 1.6;
    }

    .checklist ul {
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .checklist-item {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      padding: 12px 0;
      border-bottom: 1px solid var(--border-light);
      font-size: 1rem;
      color: var(--text-secondary);
      line-height: 1.6;
    }

    .checklist-item:last-child { border-bottom: none; }

    .checklist-box {
      flex: 0 0 22px;
      width: 22px;
      height: 22px;
      border: 2px solid var(--border-medium);
      border-radius: 5px;
      margin-top: 2px;
    }

    .case-type-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 20px;
      margin: 24px 0;
    }

    .case-type-card {
      background: var(--background-subtle);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 24px;
    }

    .case-type-card h4 {
      font-size: 1.05rem;
      font-weight: 600;
      margin-bottom: 8px;
      color: var(--text-primary);
    }

    .case-type-card h4 a {
      color: var(--primary-color);
      text-decoration: none;
    }

    .case-type-card h4 a:hover { text-decoration: underline; }

    .case-type-card p {
      font-size: 0.98rem;
      color: var(--text-secondary);
      line-height: 1.7;
      margin-bottom: 0;
    }

    .related-links {
      margin-top: 40px;
      padding-top: 24px;
      border-top: 1px solid var(--border-light);
    }

    .related-links h3 {
      font-size: 1.1rem;
      margin-bottom: 12px;
      margin-top: 0;
    }

    .related-links ul {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-wrap: wrap;
      gap: 8px 24px;
    }

    .related-links a {
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 500;
    }

    .related-links a:hover { text-decoration: underline; }

    @media (max-width: 768px) {
      .case-type-grid { grid-template-columns: 1fr; }
      .checklist-controls { flex-direction: column; align-items: flex-start; }
    }

    @media print {
      .nav, .footer, .nav-cta, .btn-print, .cta-section, .related-links { display: none !important; }
      .content { padding: 24px; max-width: 100%; }
      .checklist-group { border: 1px solid #cbd5e1; page-break-inside: avoid; }
      .checklist-item { break-inside: avoid; }
      .case-type-grid { grid-template-columns: 1fr; gap: 12px; }
      .case-type-card { border: 1px solid #e2e8f0; }
      body { font-size: 11pt; color: #000; }
      h1, h2, h3, h4 { color: #000; }
      a { text-decoration: none; color: #000; }
    }

    .btn-white {
      display: inline-flex;
      align-items: center;
      padding: 14px 32px;
      background: white;
      color: var(--primary-color);
      font-size: 0.95rem;
      font-weight: 600;
      border-radius: var(--radius-md);
      text-decoration: none;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn-white:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-medium);
    }

    /* Footer */
    .footer {
      padding: 48px 24px;
      background: var(--background-subtle);
      border-top: 1px solid var(--border-light);
    }

    .footer-inner {
      max-width: 800px;
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 24px;
    }

    .footer-logo {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--primary-color);
    }

    .footer-links {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 32px;
    }

    .footer-link {
      font-size: 0.875rem;
      color: var(--text-secondary);
      text-decoration: none;
    }

    .footer-link:hover { color: var(--primary-color); }

    .footer-copy {
      font-size: 0.8rem;
      color: var(--text-light);
    }

    @media (max-width: 768px) {
      .content h1 { font-size: 1.75rem; }
      .content h2 { font-size: 1.25rem; }
      .footer-inner { flex-direction: column; text-align: center; }
      .footer-links { gap: 12px 20px; }
    }
  .footer-wordmark { display: inline-flex; align-items: center; gap: 0; font-size: 22px; font-weight: 700; text-decoration: none; line-height: 1.2; margin-bottom: 14px; color: var(--text-primary); }
  .footer-wordmark .denta { color: var(--text-primary); }
  .footer-wordmark .trak { color: #06b6d4; }
  @media (max-width: 640px) { .footer-wordmark { font-size: 18px; } }
  </style>
</head>
<body>
  <!-- Navigation -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><img src="<?= $baseUrl ?>images/main.png" alt="<?php echo htmlspecialchars(t('marketing.accessibility.logo_alt')); ?>" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login"><?php echo t("marketing.navigation.log_in"); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t("marketing.navigation.start_trial"); ?></a>
        <?php echo renderLanguageSelector("api/set-session-locale.php", getResolvedLocale(), false); ?>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="content">
    <h1><?php echo t('marketing.articles.dental_case_tracking_checklist.h1'); ?></h1>

    <p><?php echo t('marketing.articles.dental_case_tracking_checklist.intro'); ?></p>

    <h2><?php echo t('marketing.articles.dental_case_tracking_checklist.how_to_use.heading'); ?></h2>
    <p><?php echo t('marketing.articles.dental_case_tracking_checklist.how_to_use.body'); ?></p>

    <h2><?php echo t('marketing.articles.dental_case_tracking_checklist.checklist.heading'); ?></h2>

    <div class="checklist-controls no-print">
      <button class="btn-print" onclick="window.print();" type="button">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
        <?php echo t('marketing.articles.dental_case_tracking_checklist.checklist.print'); ?>
      </button>
    </div>

    <div class="checklist">
      <?php
        $localeData = loadLocale($activeLocale ?? 'en-US');
        $checklistGroups = $localeData['marketing']['articles']['dental_case_tracking_checklist']['checklist'] ?? [];
      ?>
      <?php foreach (['identification','current_workflow','timing','external_dependencies','exceptions','completion'] as $group): ?>
        <div class="checklist-group">
          <h3><?php echo t('marketing.articles.dental_case_tracking_checklist.checklist.' . $group . '.heading'); ?></h3>
          <?php
            $note = t('marketing.articles.dental_case_tracking_checklist.checklist.' . $group . '.note');
            if ($note):
          ?>
            <p class="checklist-note"><?php echo $note; ?></p>
          <?php endif; ?>
          <ul>
            <?php
              $items = $checklistGroups[$group]['items'] ?? [];
              foreach ($items as $item):
            ?>
              <li class="checklist-item"><span class="checklist-box" aria-hidden="true"></span><?php echo htmlspecialchars($item); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>

    <h2><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.heading'); ?></h2>
    <div class="case-type-grid">
      <div class="case-type-card">
        <h4><a href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.crown_bridge.title'); ?></a></h4>
        <p><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.crown_bridge.body'); ?></p>
      </div>
      <div class="case-type-card">
        <h4><a href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.implant.title'); ?></a></h4>
        <p><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.implant.body'); ?></p>
      </div>
      <div class="case-type-card">
        <h4><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.lab.title'); ?></a></h4>
        <p><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.lab.body'); ?></p>
      </div>
      <div class="case-type-card">
        <h4><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.referral.title'); ?></h4>
        <p><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.referral.body'); ?></p>
      </div>
      <div class="case-type-card">
        <h4><a href="<?= $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.remake.title'); ?></a></h4>
        <p><?php echo t('marketing.articles.dental_case_tracking_checklist.case_types.remake.body'); ?></p>
      </div>
    </div>

    <h2><?php echo t('marketing.articles.dental_case_tracking_checklist.does_not_replace.heading'); ?></h2>
    <p><?php echo t('marketing.articles.dental_case_tracking_checklist.does_not_replace.body'); ?></p>

    <h2><?php echo t('marketing.articles.dental_case_tracking_checklist.putting_into_practice.heading'); ?></h2>
    <p>
      <?php
        $spreadsheetsUrl = $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets');
        $spreadsheetsLink = '<a href="' . $spreadsheetsUrl . '" class="content-link">' . t('marketing.articles.dental_case_tracking_checklist.putting_into_practice.link_label_1') . '</a>';
        $categoryUrl = $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software');
        $categoryLink = '<a href="' . $categoryUrl . '" class="content-link">' . t('marketing.articles.dental_case_tracking_checklist.putting_into_practice.link_label_2') . '</a>';
        echo t('marketing.articles.dental_case_tracking_checklist.putting_into_practice.body_1', ['link' => $spreadsheetsLink]);
      ?>
    </p>
    <p>
      <?php
        echo t('marketing.articles.dental_case_tracking_checklist.putting_into_practice.body_2', ['link' => $categoryLink]);
      ?>
    </p>

    <div class="related-links no-print">
      <h3><?php echo t('marketing.articles.dental_case_tracking_checklist.related_resources.heading'); ?></h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.related_resources.items.0'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.related_resources.items.1'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_visual_workflow'] ?? 'visual-dental-case-workflow') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.related_resources.items.2'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.related_resources.items.3'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets') ?>"><?php echo t('marketing.articles.dental_case_tracking_checklist.related_resources.items.4'); ?></a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2><?php echo t('marketing.articles.dental_case_tracking_checklist.closing.heading'); ?></h2>
      <p><?php echo t('marketing.articles.dental_case_tracking_checklist.closing.body'); ?></p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white"><?php echo t('marketing.navigation.start_trial'); ?></a>
      <p style="margin-top: 16px; font-size: 0.9rem;"><a href="<?= $baseUrl ?>login.php" style="color: rgba(255,255,255,0.75); text-decoration: underline; text-underline-offset: 2px;"><?php echo t('marketing.cta.already_account'); ?></a></p>
    </div>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <a href="<?= $baseUrl ?>" class="footer-wordmark" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><span class="denta">Denta</span><span class="trak">Trak</span></a>
      <div class="footer-links">
        <a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>" class="footer-link"><?php echo t('marketing.footer.about'); ?></a>
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>" class="footer-link"><?php echo t('marketing.navigation.resources'); ?></a>
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link"><?php echo t('marketing.footer.privacy'); ?></a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link"><?php echo t('marketing.footer.terms'); ?></a>
        <a href="<?= $baseUrl ?>" class="footer-link"><?php echo t('marketing.navigation.home'); ?></a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. <?php echo t('marketing.footer.copyright'); ?></span>
    </div>
  </footer>
</body>
</html>
