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

  <meta name="description" content="<?php echo htmlspecialchars(t('marketing.seo.resources.description')); ?>">
  <title><?php echo htmlspecialchars(t('marketing.seo.resources.title')); ?></title>
  <link rel="canonical" href="https://dentatrak.com/resources">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">
</head>
<body>
  <!-- Navigation -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><img src="<?= $baseUrl ?>images/main.png" alt="<?php echo htmlspecialchars(t('marketing.accessibility.logo_alt')); ?>" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login"><?php echo t('marketing.navigation.log_in'); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t('marketing.navigation.start_trial'); ?></a>
        <?php echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false); ?>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="content no-breadcrumb" style="max-width: 1000px;">
    <h1><?php echo t('marketing.resources.h1'); ?></h1>
    <p>
      <?php echo t('marketing.resources.intro'); ?>
    </p>

    <div class="resource-group">
      <h2><?php echo t('marketing.resources.group_dental_case_tracking'); ?></h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">
          <h3><?php echo t('marketing.resources.card_dental_case_tracking_software_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_dental_case_tracking_software_desc'); ?></p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>">
          <h3><?php echo t('marketing.resources.card_how_to_track_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_how_to_track_desc'); ?></p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_visual_workflow'] ?? 'visual-dental-case-workflow') ?>">
          <h3><?php echo t('marketing.resources.card_visual_workflow_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_visual_workflow_desc'); ?></p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2><?php echo t('marketing.resources.group_lab_workflows'); ?></h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">
          <h3><?php echo t('marketing.resources.card_lab_tracking_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_lab_tracking_desc'); ?></p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2><?php echo t('marketing.resources.group_case_types'); ?></h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>">
          <h3><?php echo t('marketing.resources.card_crown_bridge_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_crown_bridge_desc'); ?></p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>">
          <h3><?php echo t('marketing.resources.card_implant_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_implant_desc'); ?></p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2><?php echo t('marketing.resources.group_practice_operations'); ?></h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost') ?>">
          <h3><?php echo t('marketing.resources.card_remake_cost_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_remake_cost_desc'); ?></p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2><?php echo t('marketing.resources.group_comparisons'); ?></h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>">
          <h3><?php echo t('marketing.resources.card_vs_pms_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_vs_pms_desc'); ?></p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets') ?>">
          <h3><?php echo t('marketing.resources.card_vs_spreadsheets_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_vs_spreadsheets_desc'); ?></p>
        </a>
      </div>
    </div>

    <div class="cta-section">
      <h2><?php echo t('marketing.resources.cta_title'); ?></h2>
      <p><?php echo t('marketing.about.cta_lead'); ?></p>
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
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link"><?php echo t('marketing.footer.privacy'); ?></a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link"><?php echo t('marketing.footer.terms'); ?></a>
        <a href="<?= $baseUrl ?>" class="footer-link"><?php echo t('marketing.footer.home'); ?></a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. <?php echo t('marketing.footer.copyright'); ?></span>
    </div>
  </footer>
</body>
</html>
