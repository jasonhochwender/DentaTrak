<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/brand-assets.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
$scorecardUrl = $baseUrl . 'resource-assets/DentaTrak_Dental_Lab_Performance_Scorecard.xlsx';
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

  <meta name="description" content="<?php echo htmlspecialchars(t("marketing.articles.lab_performance_metrics.seo.description")); ?>">
  <title><?php echo htmlspecialchars(t("marketing.articles.lab_performance_metrics.seo.title")); ?></title>
  <link rel="canonical" href="https://dentatrak.com/how-to-measure-dental-lab-performance">

  <!-- Open Graph -->
  <meta property="og:title" content="<?php echo htmlspecialchars(t("marketing.articles.lab_performance_metrics.seo.title")); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars(t("marketing.articles.lab_performance_metrics.seo.description")); ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/how-to-measure-dental-lab-performance">

  <!-- Favicon / App Icons -->
  <?php require __DIR__ . '/partials/brand-icons.php'; ?>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">

  <!-- Structured Data: Article -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?php echo json_encode(t("marketing.articles.lab_performance_metrics.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "author": { "@id": "https://dentatrak.com/about#william-verrillo" },
    "publisher": { "@id": "https://dentatrak.com/#organization" },
    "datePublished": "2026-11-11",
    "dateModified": "2026-11-11",
    "mainEntityOfPage": "https://dentatrak.com/how-to-measure-dental-lab-performance"
  }
  </script>

  <!-- Structured Data: BreadcrumbList -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": <?php echo json_encode(t("marketing.navigation.home"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/" },
      { "@type": "ListItem", "position": 2, "name": <?php echo json_encode(t("marketing.navigation.resources"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/resources" },
      { "@type": "ListItem", "position": 3, "name": <?php echo json_encode(t("marketing.articles.lab_performance_metrics.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/how-to-measure-dental-lab-performance" }
    ]
  }
  </script>

  <style>
    .metric-example {
      background: #f0fdfa;
      border: 1px solid #ccfbf1;
      border-left: 4px solid #0d9488;
      border-radius: 12px;
      padding: 18px 22px;
      margin: 20px 0;
    }
    .metric-example .example-label {
      display: block;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #0f766e;
      margin-bottom: 6px;
    }
    .metric-example p { margin: 0; font-size: 0.95rem; line-height: 1.65; color: var(--dt-ink, #1e293b); }
    .scorecard-cta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
      background: #fff;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 16px;
      padding: 24px 28px;
      margin: 28px 0;
      box-shadow: 0 8px 24px -14px rgba(15, 23, 42, 0.15);
    }
    .scorecard-cta .scorecard-cta-text { flex: 1 1 320px; }
    .scorecard-cta .scorecard-cta-text strong { display: block; font-size: 1.05rem; margin-bottom: 4px; color: var(--dt-ink, #1e293b); }
    .scorecard-cta .scorecard-cta-text span { font-size: 0.9rem; color: var(--dt-ink-secondary, #475569); line-height: 1.55; }
    .scorecard-cta .btn-download {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 13px 26px;
      background: #0f766e;
      color: #fff;
      font-weight: 600;
      font-size: 0.95rem;
      text-decoration: none;
      border-radius: 10px;
      white-space: nowrap;
      transition: background 0.15s ease;
    }
    .scorecard-cta .btn-download:hover { background: #115e59; }
    .scorecard-cta .btn-download:focus-visible { outline: 2px solid #0f766e; outline-offset: 3px; }
    @media (max-width: 600px) {
      .scorecard-cta { padding: 20px; }
      .scorecard-cta .btn-download { width: 100%; justify-content: center; }
    }
  </style>
</head>
<body>
  <!-- Navigation -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><img src="<?= htmlspecialchars(brandAssetUrl('logo_standard', $baseUrl)) ?>" alt="<?php echo htmlspecialchars(t('marketing.accessibility.logo_alt')); ?>" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login"><?php echo t("marketing.navigation.log_in"); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t("marketing.navigation.start_trial"); ?></a>
        <?php echo renderLanguageSelector("api/set-session-locale.php", getResolvedLocale(), false); ?>
      </div>
    </div>
  </nav>

  <!-- Breadcrumbs -->
  <div class="breadcrumb-bar">
    <ol class="breadcrumb">
      <li><a href="<?= $baseUrl ?>"><?php echo t("marketing.navigation.home"); ?></a></li>
      <li>/</li>
      <li><a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t("marketing.navigation.resources"); ?></a></li>
      <li>/</li>
      <li aria-current="page"><?php echo t("marketing.articles.lab_performance_metrics.h1"); ?></li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1><?php echo t('marketing.articles.lab_performance_metrics.h1'); ?></h1>

    <div class="article-meta">
      <span><?php echo t('marketing.articles.lab_performance_metrics.meta.by'); ?> <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span><?php echo t('marketing.articles.lab_performance_metrics.meta.published'); ?> <strong>November 11, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p><?php echo t('marketing.articles.lab_performance_metrics.intro'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.why_track.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.why_track.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.why_track.body_2'); ?></p>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.turnaround.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.turnaround.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.turnaround.body_2'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.turnaround.body_3'); ?></p>

    <div class="metric-example">
      <span class="example-label"><?php echo t('marketing.articles.lab_performance_metrics.example_label'); ?></span>
      <p><?php echo t('marketing.articles.lab_performance_metrics.sections.turnaround.example'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.on_time.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.on_time.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.on_time.body_2'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.on_time.body_3'); ?></p>

    <div class="metric-example">
      <span class="example-label"><?php echo t('marketing.articles.lab_performance_metrics.example_label'); ?></span>
      <p><?php echo t('marketing.articles.lab_performance_metrics.sections.on_time.example'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.appointment.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.appointment.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.appointment.body_2'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.appointment.body_3'); ?></p>

    <div class="metric-example">
      <span class="example-label"><?php echo t('marketing.articles.lab_performance_metrics.example_label'); ?></span>
      <p><?php echo t('marketing.articles.lab_performance_metrics.sections.appointment.example'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.remake.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.remake.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.remake.body_2'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.remake.body_3'); ?></p>
    <p><a href="<?= $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost') ?>"><?php echo t('marketing.articles.lab_performance_metrics.sections.remake.link_text'); ?></a></p>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.overdue.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.overdue.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.overdue.body_2'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.overdue.body_3'); ?></p>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.body_1'); ?></p>

    <ul class="checklist">
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.1.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.1.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.2.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.2.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.3.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.3.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.4.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.4.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.5.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.5.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.6.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.list_items.6.body'); ?></li>
    </ul>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.comparing.body_2'); ?></p>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.action.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.action.body_1'); ?></p>

    <ul class="checklist">
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.1.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.1.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.2.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.2.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.3.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.3.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.4.title'); ?></strong> <?php echo t('marketing.articles.lab_performance_metrics.sections.action.list_items.4.body'); ?></li>
    </ul>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.action.body_2'); ?></p>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.scorecard.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.scorecard.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.scorecard.body_2'); ?></p>

    <div class="scorecard-cta">
      <div class="scorecard-cta-text">
        <strong><?php echo t('marketing.articles.lab_performance_metrics.scorecard_cta.title'); ?></strong>
        <span><?php echo t('marketing.articles.lab_performance_metrics.scorecard_cta.subtitle'); ?></span>
      </div>
      <a class="btn-download" href="<?= htmlspecialchars($scorecardUrl) ?>" download="DentaTrak_Dental_Lab_Performance_Scorecard.xlsx">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <?php echo t('marketing.articles.lab_performance_metrics.scorecard_cta.button'); ?>
      </a>
    </div>

    <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.dentatrak.heading'); ?></h2>

    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.dentatrak.body_1'); ?></p>
    <p><?php echo t('marketing.articles.lab_performance_metrics.sections.dentatrak.body_2'); ?></p>

    <div class="related-links">
      <h3><?php echo t('marketing.articles.lab_performance_metrics.related_resources.heading'); ?></h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>"><?php echo t('marketing.articles.lab_performance_metrics.related_resources.items.0'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_case_mgmt_external_labs'] ?? 'dental-case-management-external-labs') ?>"><?php echo t('marketing.articles.lab_performance_metrics.related_resources.items.1'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost') ?>"><?php echo t('marketing.articles.lab_performance_metrics.related_resources.items.2'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>"><?php echo t('marketing.articles.lab_performance_metrics.related_resources.items.3'); ?></a></li>
        <li><a href="<?= htmlspecialchars($scorecardUrl) ?>" download="DentaTrak_Dental_Lab_Performance_Scorecard.xlsx"><?php echo t('marketing.articles.lab_performance_metrics.related_resources.items.4'); ?></a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2><?php echo t('marketing.articles.lab_performance_metrics.sections.dentatrak.heading'); ?></h2>
      <p><?php echo t('marketing.articles.lab_performance_metrics.cta.body'); ?></p>
      <a href="<?= $baseUrl ?>#insights" class="btn-white"><?php echo t('marketing.articles.lab_performance_metrics.cta.button'); ?></a>
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
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. <?php echo t('marketing.footer.copyright'); ?></span>
    </div>
  </footer>
</body>
</html>
