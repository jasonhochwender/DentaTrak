<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
$hipaaUrl = $baseUrl . ($articleUrls['page_hipaa_security'] ?? 'hipaa-security');
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <script async src="https://www.googletagmanager.com/gtag/js?id=G-MBJDENR3H2"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-MBJDENR3H2');
  </script>

  <meta name="description" content="<?php echo htmlspecialchars(t('marketing.seo.hipaa.description')); ?>">
  <title><?php echo htmlspecialchars(t('marketing.seo.hipaa.title')); ?></title>
  <link rel="canonical" href="https://dentatrak.com/hipaa-security">

  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">
  <style>
    .content.hipaa-page { max-width: 900px; }
    .hipaa-hero { text-align: center; margin-bottom: 56px; }
    .hipaa-hero h1 { font-size: clamp(2rem, 5vw, 3rem); line-height: 1.15; margin-bottom: 16px; }
    .hipaa-hero p { color: var(--text-secondary); font-size: 1.125rem; max-width: 720px; margin: 0 auto; line-height: 1.6; }

    .hipaa-section { margin-bottom: 56px; }
    .hipaa-section h2 { font-size: 1.4rem; font-weight: 700; margin-bottom: 14px; color: var(--text-primary); }
    .hipaa-section p { color: var(--text-secondary); line-height: 1.7; margin-bottom: 16px; }
    .hipaa-section p:last-child { margin-bottom: 0; }

    .hipaa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-top: 28px; }
    .hipaa-card { border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 24px; background: var(--background-white); }
    .hipaa-card h3 { font-size: 1.05rem; font-weight: 600; margin-top: 0; margin-bottom: 10px; color: var(--text-primary); }
    .hipaa-card p { font-size: 0.95rem; line-height: 1.6; color: var(--text-secondary); margin-bottom: 0; }

    .hipaa-callout { background: rgba(30, 64, 175, 0.06); border: 1px solid rgba(30, 64, 175, 0.15); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 56px; }
    .hipaa-callout h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 10px; color: var(--primary-color); }
    .hipaa-callout p { color: var(--text-secondary); line-height: 1.6; margin-bottom: 0; }

    .hipaa-list { margin: 0; padding-left: 20px; color: var(--text-secondary); line-height: 1.8; }
    .hipaa-list li { margin-bottom: 6px; }

    .hipaa-contact { color: var(--text-secondary); font-size: 0.95rem; margin-top: 12px; }

    .hipaa-cta { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
    .btn-ghost { display: inline-flex; align-items: center; padding: 14px 32px; border: 1px solid var(--border-medium); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.9rem; font-weight: 600; text-decoration: none; background: #fff; transition: background 0.2s, border-color 0.2s; }
    .btn-ghost:hover { border-color: var(--primary-color); color: var(--primary-color); }

    @media (max-width: 720px) {
      .content.hipaa-page { padding-left: 20px; padding-right: 20px; }
      .hipaa-hero h1 { font-size: 1.9rem; }
      .hipaa-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
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

  <main class="content no-breadcrumb hipaa-page">
    <div class="hipaa-hero">
      <h1><?php echo t('marketing.hipaa.h1'); ?></h1>
      <p><?php echo t('marketing.hipaa.intro'); ?></p>
    </div>

    <section class="hipaa-section" aria-labelledby="shared-heading">
      <h2 id="shared-heading"><?php echo t('marketing.hipaa.shared_responsibility_heading'); ?></h2>
      <p><?php echo t('marketing.hipaa.shared_responsibility_body'); ?></p>
    </section>

    <section class="hipaa-section" aria-labelledby="baa-heading">
      <h2 id="baa-heading"><?php echo t('marketing.hipaa.baa_heading'); ?></h2>
      <p><?php echo t('marketing.hipaa.baa_body'); ?></p>
      <p><?php echo t('marketing.hipaa.baa_existing_customers'); ?></p>
    </section>

    <section class="hipaa-section" aria-labelledby="safeguards-heading">
      <h2 id="safeguards-heading"><?php echo t('marketing.hipaa.safeguards_heading'); ?></h2>
      <div class="hipaa-grid">
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.data_protection_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.data_protection_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.access_controls_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.access_controls_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.files_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.files_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.audit_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.audit_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.sessions_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.sessions_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.retention_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.retention_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.headers_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.headers_body'); ?></p>
        </div>
      </div>
    </section>

    <div class="hipaa-callout" role="note">
      <h2><?php echo t('marketing.hipaa.baa_callout_heading'); ?></h2>
      <p><?php echo t('marketing.hipaa.baa_callout_body'); ?></p>
    </div>

    <section class="hipaa-section" aria-labelledby="responsibilities-heading">
      <h2 id="responsibilities-heading"><?php echo t('marketing.hipaa.practice_responsibilities_heading'); ?></h2>
      <p><?php echo t('marketing.hipaa.practice_responsibilities_body'); ?></p>
      <ul class="hipaa-list">
        <?php for ($i = 1; $i <= 5; $i++) : ?>
          <li><?php echo t('marketing.hipaa.responsibilities_item_' . $i); ?></li>
        <?php endfor; ?>
      </ul>
    </section>

    <section class="hipaa-section" aria-labelledby="contact-heading">
      <h2 id="contact-heading"><?php echo t('marketing.hipaa.cta_heading'); ?></h2>
      <p class="hipaa-contact"><?php echo t('marketing.hipaa.contact_body'); ?></p>
      <div class="hipaa-cta">
        <a href="<?= $baseUrl ?>login.php" class="nav-cta" style="padding: 14px 32px;"><?php echo t('marketing.hipaa.cta_primary'); ?></a>
        <a href="mailto:privacy@dentatrak.com" class="btn-ghost"><?php echo t('marketing.hipaa.cta_secondary'); ?></a>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="footer-inner">
      <a href="<?= $baseUrl ?>" class="footer-wordmark" aria-label="<?php echo htmlspecialchars(t('marketing.accessibility.home_aria')); ?>"><span class="denta">Denta</span><span class="trak">Trak</span></a>
      <div class="footer-links">
        <a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>" class="footer-link"><?php echo t('marketing.footer.about'); ?></a>
        <a href="<?= $baseUrl . ($articleUrls['page_hipaa_security'] ?? 'hipaa-security') ?>" class="footer-link"><?php echo t('marketing.hipaa.title'); ?></a>
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link"><?php echo t('marketing.footer.privacy'); ?></a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link"><?php echo t('marketing.footer.terms'); ?></a>
        <a href="<?= $baseUrl ?>" class="footer-link"><?php echo t('marketing.footer.home'); ?></a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. <?php echo t('marketing.footer.copyright'); ?></span>
    </div>
  </footer>
</body>
</html>
