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

  <meta name="description" content="<?php echo htmlspecialchars(t("marketing.articles.crown_and_bridge_case_tracking.seo.description")); ?>">
  <title><?php echo htmlspecialchars(t("marketing.articles.crown_and_bridge_case_tracking.seo.title")); ?></title>

  <!-- Open Graph -->
  <meta property="og:title" content="<?php echo htmlspecialchars(t("marketing.articles.crown_and_bridge_case_tracking.seo.title")); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars(t("marketing.articles.crown_and_bridge_case_tracking.seo.description")); ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/crown-and-bridge-case-tracking">
  <meta property="og:site_name" content="DentaTrak">
  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo htmlspecialchars(t("marketing.articles.crown_and_bridge_case_tracking.seo.title")); ?>">
  <meta name="twitter:description" content="<?php echo htmlspecialchars(t("marketing.articles.crown_and_bridge_case_tracking.seo.description")); ?>">
  <link rel="canonical" href="https://dentatrak.com/crown-and-bridge-case-tracking">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">

  <!-- Structured Data: Article -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "author": { "@id": "https://dentatrak.com/about#william-verrillo" },
    "publisher": { "@id": "https://dentatrak.com/#organization" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/crown-and-bridge-case-tracking"
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
      { "@type": "ListItem", "position": 3, "name": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/crown-and-bridge-case-tracking" }
    ]
  }
  </script>

  <!-- Structured Data: FAQPage -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
      {
        "@type": "Question",
        "name": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.faq.items.0.question"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "acceptedAnswer": {
          "@type": "Answer",
          "text": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.faq.items.0.answer"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        }
      },
      {
        "@type": "Question",
        "name": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.faq.items.1.question"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "acceptedAnswer": {
          "@type": "Answer",
          "text": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.faq.items.1.answer"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        }
      },
      {
        "@type": "Question",
        "name": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.faq.items.2.question"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "acceptedAnswer": {
          "@type": "Answer",
          "text": <?php echo json_encode(t("marketing.articles.crown_and_bridge_case_tracking.faq.items.2.answer"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        }
      }
    ]
  }
  </script>
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

  <!-- Breadcrumbs -->
  <div class="breadcrumb-bar">
    <ol class="breadcrumb">
      <li><a href="<?= $baseUrl ?>"><?php echo t("marketing.navigation.home"); ?></a></li>
      <li>/</li>
      <li><a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t("marketing.navigation.resources"); ?></a></li>
      <li>/</li>
      <li aria-current="page"><?php echo t("marketing.articles.crown_and_bridge_case_tracking.h1"); ?></li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1><?php echo t('marketing.articles.crown_and_bridge_case_tracking.h1'); ?></h1>

    <div class="article-meta">
      <span><?php echo t('marketing.articles.crown_and_bridge_case_tracking.meta.by'); ?> <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span><?php echo t('marketing.articles.crown_and_bridge_case_tracking.meta.published'); ?> <strong>August 8, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.intro'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.the_crown_and_bridge_workflow.heading'); ?></h2>

    <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.the_crown_and_bridge_workflow.body_1'); ?></p>

    <div class="table-wrap">
      <table class="comparison-table">
        <thead>
          <tr><th><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.headers.0'); ?></th><th><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.headers.1'); ?></th></tr>
        </thead>
        <tbody>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.1.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.1.what_happens'); ?></td></tr>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.2.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.2.what_happens'); ?></td></tr>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.3.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.3.what_happens'); ?></td></tr>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.4.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.4.what_happens'); ?></td></tr>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.5.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.5.what_happens'); ?></td></tr>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.6.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.6.what_happens'); ?></td></tr>
          <tr><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.7.stage'); ?></td><td><?php echo t('marketing.articles.crown_and_bridge_case_tracking.table.rows.7.what_happens'); ?></td></tr>
        </tbody>
      </table>
    </div>

    <h2><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.common_remake_and_rework_scenarios.heading'); ?></h2>

    <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.common_remake_and_rework_scenarios.body_2'); ?> <a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>" class="content-link"><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.common_remake_and_rework_scenarios.links.1'); ?></a> <?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.common_remake_and_rework_scenarios.body_3'); ?></p>

    <h2><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.heading'); ?></h2>

    <ol class="workflow-steps">
      <li>
        <strong><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.1.title'); ?></strong> <?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.1.body'); ?></li>
      <li>
        <strong><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.2.title'); ?></strong> <?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.2.body'); ?></li>
      <li>
        <strong><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.3.title'); ?></strong> <?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.3.body'); ?></li>
      <li>
        <strong><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.4.title'); ?></strong> <?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.where_visibility_matters_most.list_items.4.body'); ?></li>
    </ol>

    <h2><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.how_dentatrak_tracks_crown_and_bridge_cases.heading'); ?></h2>

    <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.how_dentatrak_tracks_crown_and_bridge_cases.body_4'); ?> <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link"><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.how_dentatrak_tracks_crown_and_bridge_cases.links.2'); ?></a> <?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.how_dentatrak_tracks_crown_and_bridge_cases.body_5'); ?></p>

    <h2><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.frequently_asked_questions.heading'); ?></h2>

    <div class="faq-item">
      <h3><?php echo t('marketing.articles.crown_and_bridge_case_tracking.faq.items.0.question'); ?></h3>
      <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.faq.items.0.answer'); ?></p>
    </div>

    <div class="faq-item">
      <h3><?php echo t('marketing.articles.crown_and_bridge_case_tracking.faq.items.1.question'); ?></h3>
      <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.faq.items.1.answer'); ?></p>
    </div>

    <div class="faq-item">
      <h3><?php echo t('marketing.articles.crown_and_bridge_case_tracking.faq.items.2.question'); ?></h3>
      <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.faq.items.2.answer'); ?></p>
    </div>

    <div class="related-links">
      <h3><?php echo t('marketing.articles.crown_and_bridge_case_tracking.related_resources.heading'); ?></h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>"><?php echo t('marketing.articles.crown_and_bridge_case_tracking.related_resources.items.0'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>"><?php echo t('marketing.articles.crown_and_bridge_case_tracking.related_resources.items.1'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>"><?php echo t('marketing.articles.crown_and_bridge_case_tracking.related_resources.items.2'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost') ?>"><?php echo t('marketing.articles.crown_and_bridge_case_tracking.related_resources.items.3'); ?></a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2><?php echo t('marketing.articles.crown_and_bridge_case_tracking.sections.keep_every_crown_and_bridge_case_visible.heading'); ?></h2>
      <p><?php echo t('marketing.articles.crown_and_bridge_case_tracking.cta.body'); ?></p>
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
