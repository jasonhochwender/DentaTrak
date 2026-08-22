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

  <meta name="description" content="<?php echo htmlspecialchars(t('marketing.seo.about.description')); ?>">
  <title><?php echo htmlspecialchars(t('marketing.seo.about.title')); ?></title>
  <link rel="canonical" href="https://dentatrak.com/about">

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
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><img src="images/main.png" alt="<?php echo htmlspecialchars(t('marketing.accessibility.logo_alt')); ?>" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login"><?php echo t('marketing.navigation.log_in'); ?></a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta"><?php echo t('marketing.navigation.start_trial'); ?></a>
        <?php echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false); ?>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="content no-breadcrumb">
    <h1><?php echo t('marketing.about.h1'); ?></h1>

    <h2><?php echo t('marketing.about.what_is_title'); ?></h2>

    <p>
      <?php echo t('marketing.about.what_is_body'); ?>
    </p>

    <h2><?php echo t('marketing.about.why_built_title'); ?></h2>

    <p>
      <?php echo t('marketing.about.why_built_body'); ?>
    </p>

    <h2><?php echo t('marketing.about.problem_title'); ?></h2>

    <p>
      Most dental practices manage complex, multi-step cases without a dedicated system. Case information ends up scattered across memory, sticky notes, spreadsheets, and notes buried in a practice management system. Problems are usually only discovered after they've already cost the practice time, chair capacity, or a patient's trust. DentaTrak exists to give every case a clear status and owner so delays are visible before they become costly. Read more in our <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link">dental case tracking software</a> overview.
    </p>

    <h2><?php echo t('marketing.about.who_for_title'); ?></h2>

    <ul>
      <li><?php echo t('marketing.about.who_for_1'); ?></li>
      <li><?php echo t('marketing.about.who_for_2'); ?></li>
    </ul>

    <p>
      <?php echo t('marketing.about.who_for_body'); ?>
    </p>

    <h2><?php echo t('marketing.about.differs_title'); ?></h2>

    <p>
      DentaTrak does not replace a practice management system (PMS). A PMS handles scheduling, billing, and patient records. DentaTrak focuses specifically on the workflow of multi-step cases (status, ownership, and stalled-case visibility) which most PMS platforms were not built to track. DentaTrak works alongside your existing PMS with no data migration required. See our full comparison in <a href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>" class="content-link">dental case tracking software vs. PMS</a>.
    </p>

    <h2><?php echo t('marketing.about.contact_title'); ?></h2>

    <p>
      <?php echo t('marketing.about.contact_body'); ?> <a href="mailto:<?php echo t('marketing.about.contact_email'); ?>" class="content-link"><?php echo t('marketing.about.contact_email'); ?></a>.
    </p>

    <div class="cta-section">
      <h2><?php echo t('marketing.about.cta_title'); ?></h2>
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
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>" class="footer-link"><?php echo t('marketing.footer.resources'); ?></a>
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link"><?php echo t('marketing.footer.privacy'); ?></a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link"><?php echo t('marketing.footer.terms'); ?></a>
        <a href="<?= $baseUrl ?>" class="footer-link"><?php echo t('marketing.footer.home'); ?></a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. <?php echo t('marketing.footer.copyright'); ?></span>
    </div>
  </footer>
</body>
</html>
