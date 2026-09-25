<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
$k = 'marketing.articles.dental_case_management_external_labs';
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

  <meta name="description" content="<?php echo htmlspecialchars(t("$k.seo.description")); ?>">
  <title><?php echo htmlspecialchars(t("$k.seo.title")); ?></title>

  <!-- Open Graph -->
  <meta property="og:title" content="<?php echo htmlspecialchars(t("$k.seo.title")); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars(t("$k.seo.description")); ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/dental-case-management-external-labs">
  <meta property="og:site_name" content="DentaTrak">
  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo htmlspecialchars(t("$k.seo.title")); ?>">
  <meta name="twitter:description" content="<?php echo htmlspecialchars(t("$k.seo.description")); ?>">
  <link rel="canonical" href="https://dentatrak.com/dental-case-management-external-labs">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">

  <!-- Structured Data: Article -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?php echo json_encode(t("$k.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "author": { "@id": "https://dentatrak.com/about#william-verrillo" },
    "publisher": { "@id": "https://dentatrak.com/#organization" },
    "datePublished": "2026-09-25",
    "dateModified": "2026-09-25",
    "mainEntityOfPage": "https://dentatrak.com/dental-case-management-external-labs"
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
      { "@type": "ListItem", "position": 3, "name": <?php echo json_encode(t("$k.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/dental-case-management-external-labs" }
    ]
  }
  </script>

  <!-- Structured Data: FAQPage -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
      <?php for ($i = 0; $i < 3; $i++): ?>
      {
        "@type": "Question",
        "name": <?php echo json_encode(t("$k.faq.items.$i.question"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        "acceptedAnswer": {
          "@type": "Answer",
          "text": <?php echo json_encode(t("$k.faq.items.$i.answer"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        }
      }<?php echo $i < 2 ? ',' : ''; ?>
      <?php endfor; ?>
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
      <li aria-current="page"><?php echo t("$k.h1"); ?></li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1><?php echo t("$k.h1"); ?></h1>

    <div class="article-meta">
      <span><?php echo t("$k.meta.by"); ?> <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span><?php echo t("$k.meta.published"); ?> <strong><?php echo t("$k.meta.date"); ?></strong></span>
    </div>

    <div class="answer-box">
      <p><?php echo t("$k.intro"); ?></p>
    </div>

    <h2><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.heading"); ?></h2>

    <p><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.body_1"); ?></p>

    <p><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.body_2"); ?></p>

    <ul>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.0"); ?></li>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.1"); ?></li>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.2"); ?></li>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.3"); ?></li>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.4"); ?></li>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.5"); ?></li>
      <li><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.fragmentation.6"); ?></li>
    </ul>

    <p><?php echo t("$k.sections.why_external_lab_cases_are_hard_to_manage.body_3"); ?></p>

    <h2><?php echo t("$k.sections.what_software_should_track.heading"); ?></h2>

    <p><?php echo t("$k.sections.what_software_should_track.body_1"); ?></p>

    <ul class="checklist">
      <?php for ($i = 0; $i < 11; $i++): ?>
      <li><?php echo t("$k.sections.what_software_should_track.items.$i"); ?></li>
      <?php endfor; ?>
    </ul>

    <h2><?php echo t("$k.sections.why_a_pms_alone_may_not_be_enough.heading"); ?></h2>

    <p><?php echo t("$k.sections.why_a_pms_alone_may_not_be_enough.body_1"); ?></p>

    <p>
      <?php
        $vsPmsUrl = $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms');
        $vsPmsLink = '<a href="' . $vsPmsUrl . '" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">' . t("$k.sections.why_a_pms_alone_may_not_be_enough.link_label") . '</a>';
        echo t("$k.sections.why_a_pms_alone_may_not_be_enough.body_2", ['link' => $vsPmsLink]);
      ?>
    </p>

    <h2><?php echo t("$k.sections.why_spreadsheets_and_email_break_down.heading"); ?></h2>

    <p><?php echo t("$k.sections.why_spreadsheets_and_email_break_down.body_1"); ?></p>

    <ul>
      <?php for ($i = 0; $i < 7; $i++): ?>
      <li><?php echo t("$k.sections.why_spreadsheets_and_email_break_down.items.$i"); ?></li>
      <?php endfor; ?>
    </ul>

    <p>
      <?php
        $vsSheetsUrl = $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets');
        $vsSheetsLink = '<a href="' . $vsSheetsUrl . '" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">' . t("$k.sections.why_spreadsheets_and_email_break_down.link_label") . '</a>';
        echo t("$k.sections.why_spreadsheets_and_email_break_down.body_2", ['link' => $vsSheetsLink]);
      ?>
    </p>

    <h2><?php echo t("$k.sections.capabilities_that_matter_most.heading"); ?></h2>

    <p><?php echo t("$k.sections.capabilities_that_matter_most.intro"); ?></p>

    <h3><?php echo t("$k.sections.capabilities_that_matter_most.flexible_workflow.heading"); ?></h3>
    <p><?php echo t("$k.sections.capabilities_that_matter_most.flexible_workflow.body"); ?></p>

    <h3><?php echo t("$k.sections.capabilities_that_matter_most.attention_signals.heading"); ?></h3>
    <p><?php echo t("$k.sections.capabilities_that_matter_most.attention_signals.body"); ?></p>

    <h3><?php echo t("$k.sections.capabilities_that_matter_most.communication.heading"); ?></h3>
    <p><?php echo t("$k.sections.capabilities_that_matter_most.communication.body"); ?></p>

    <h3><?php echo t("$k.sections.capabilities_that_matter_most.files_and_scans.heading"); ?></h3>
    <p><?php echo t("$k.sections.capabilities_that_matter_most.files_and_scans.body"); ?></p>

    <h3><?php echo t("$k.sections.capabilities_that_matter_most.remake_tracking.heading"); ?></h3>
    <p>
      <?php
        $remakeUrl = $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost');
        $remakeLink = '<a href="' . $remakeUrl . '" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">' . t("$k.sections.capabilities_that_matter_most.remake_tracking.link_label") . '</a>';
        echo t("$k.sections.capabilities_that_matter_most.remake_tracking.body", ['link' => $remakeLink]);
      ?>
    </p>

    <h3><?php echo t("$k.sections.capabilities_that_matter_most.performance_visibility.heading"); ?></h3>
    <p><?php echo t("$k.sections.capabilities_that_matter_most.performance_visibility.body"); ?></p>

    <h2><?php echo t("$k.sections.where_dentatrak_fits.heading"); ?></h2>

    <p><?php echo t("$k.sections.where_dentatrak_fits.body_1"); ?></p>

    <p><?php echo t("$k.sections.where_dentatrak_fits.body_2"); ?></p>

    <p><?php echo t("$k.sections.where_dentatrak_fits.body_3"); ?></p>

    <h2><?php echo t("$k.sections.open_dental_integration.heading"); ?></h2>

    <p><?php echo t("$k.sections.open_dental_integration.body_1"); ?></p>

    <p><?php echo t("$k.sections.open_dental_integration.body_2"); ?></p>

    <h2><?php echo t("$k.sections.questions_to_ask.heading"); ?></h2>

    <p><?php echo t("$k.sections.questions_to_ask.intro"); ?></p>

    <ul class="checklist">
      <li><?php echo t("$k.sections.questions_to_ask.items.0"); ?></li>
      <li><?php echo t("$k.sections.questions_to_ask.items.1"); ?></li>
      <li><?php echo t("$k.sections.questions_to_ask.items.2"); ?></li>
      <li><?php echo t("$k.sections.questions_to_ask.items.3"); ?></li>
      <li>
        <?php
          $hipaaUrl = $baseUrl . ($articleUrls['page_hipaa_security'] ?? 'hipaa-security');
          $hipaaLink = '<a href="' . $hipaaUrl . '" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">' . t("$k.sections.questions_to_ask.link_label") . '</a>';
          echo t("$k.sections.questions_to_ask.items.4", ['link' => $hipaaLink]);
        ?>
      </li>
      <li><?php echo t("$k.sections.questions_to_ask.items.5"); ?></li>
      <li><?php echo t("$k.sections.questions_to_ask.items.6"); ?></li>
      <li><?php echo t("$k.sections.questions_to_ask.items.7"); ?></li>
      <li><?php echo t("$k.sections.questions_to_ask.items.8"); ?></li>
    </ul>

    <h2><?php echo t("$k.sections.frequently_asked_questions.heading"); ?></h2>

    <?php for ($i = 0; $i < 3; $i++): ?>
    <div class="faq-item">
      <h3><?php echo t("$k.faq.items.$i.question"); ?></h3>
      <p><?php echo t("$k.faq.items.$i.answer"); ?></p>
    </div>
    <?php endfor; ?>

    <h2><?php echo t("$k.sections.conclusion.heading"); ?></h2>

    <p><?php echo t("$k.sections.conclusion.body_1"); ?></p>

    <div class="related-links">
      <h3><?php echo t("$k.related_resources.heading"); ?></h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>"><?php echo t("$k.related_resources.items.0"); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>"><?php echo t("$k.related_resources.items.1"); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>"><?php echo t("$k.related_resources.items.2"); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_visual_workflow'] ?? 'visual-dental-case-workflow') ?>"><?php echo t("$k.related_resources.items.3"); ?></a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2><?php echo t("$k.sections.see_whether_dentatrak_fits.heading"); ?></h2>
      <p><?php echo t("$k.cta.body"); ?></p>
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
