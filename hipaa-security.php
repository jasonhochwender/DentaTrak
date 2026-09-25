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
  <?php require_once __DIR__ . '/partials/clarity.php'; ?>

  <meta name="description" content="<?php echo htmlspecialchars(t('marketing.hipaa.description')); ?>">
  <title><?php echo htmlspecialchars(t('marketing.hipaa.title')); ?></title>
  <link rel="canonical" href="https://dentatrak.com/hipaa-security">

  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">
  <style>
    .content.hipaa-page { max-width: 920px; }

    .hipaa-hero { text-align: center; margin-bottom: 48px; }
    .hipaa-hero h1 { font-size: clamp(2rem, 5vw, 3rem); line-height: 1.15; margin-bottom: 12px; }
    .hipaa-hero p { color: var(--text-secondary); font-size: 1.05rem; max-width: 700px; margin: 0 auto; line-height: 1.6; }

    .hipaa-section { margin-bottom: 56px; }
    .hipaa-section h2 { font-size: 1.4rem; font-weight: 700; margin-bottom: 14px; color: var(--text-primary); }
    .hipaa-section h3 { font-size: 1.1rem; font-weight: 600; margin-bottom: 10px; color: var(--text-primary); }
    .hipaa-section p { color: var(--text-secondary); line-height: 1.7; margin-bottom: 14px; }
    .hipaa-section p:last-child { margin-bottom: 0; }
    .section-lead { color: var(--text-secondary); font-size: 1rem; line-height: 1.65; margin-bottom: 20px; }

    .hipaa-trust { margin-bottom: 56px; }
    .hipaa-trust h2 { font-size: 1.4rem; font-weight: 700; margin-bottom: 20px; color: var(--text-primary); text-align: center; }
    .hipaa-trust-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; }
    .hipaa-trust-item { display: flex; flex-direction: column; border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; background: #ffffff; }
    .hipaa-trust-icon { width: 28px; height: 28px; color: var(--primary-color); margin-bottom: 10px; }
    .hipaa-trust-item h3 { font-size: 0.95rem; font-weight: 600; margin: 0 0 6px; color: var(--text-primary); }
    .hipaa-trust-item p { font-size: 0.85rem; line-height: 1.55; color: var(--text-secondary); margin: 0; }

    .hipaa-callout { background: rgba(30, 64, 175, 0.06); border: 1px solid rgba(30, 64, 175, 0.15); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 56px; }
    .hipaa-callout h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 10px; color: var(--primary-color); }
    .hipaa-callout p { color: var(--text-secondary); line-height: 1.65; margin-bottom: 12px; }
    .hipaa-callout p:last-child { margin-bottom: 0; }

    .hipaa-safeguards { margin-bottom: 56px; }
    .hipaa-safeguards h2 { font-size: 1.4rem; font-weight: 700; margin-bottom: 10px; color: var(--text-primary); }
    .hipaa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 20px; margin-top: 20px; }
    .hipaa-card { border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 22px; background: #ffffff; }
    .hipaa-card h3 { font-size: 1.05rem; font-weight: 600; margin: 0 0 8px; color: var(--text-primary); }
    .hipaa-card p { font-size: 0.95rem; line-height: 1.6; color: var(--text-secondary); margin-bottom: 0; }

    .hipaa-safeguards-note { color: var(--text-light); font-size: 0.85rem; line-height: 1.6; margin-top: 18px; }

    .hipaa-list { margin: 12px 0 0 0; padding-left: 20px; color: var(--text-secondary); line-height: 1.75; }
    .hipaa-list li { margin-bottom: 6px; }

    .hipaa-contact { color: var(--text-secondary); font-size: 0.95rem; margin-top: 12px; }

    .hipaa-cta { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
    .btn-ghost { display: inline-flex; align-items: center; padding: 14px 32px; border: 1px solid var(--border-medium); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.9rem; font-weight: 600; text-decoration: none; background: #fff; transition: background 0.2s, border-color 0.2s; }
    .btn-ghost:hover { border-color: var(--primary-color); color: var(--primary-color); }

    .hipaa-contact-section { text-align: center; }

    @media (max-width: 720px) {
      .content.hipaa-page { padding-left: 20px; padding-right: 20px; }
      .hipaa-hero h1 { font-size: 1.9rem; }
      .hipaa-grid { grid-template-columns: 1fr; }
      .hipaa-trust-grid { grid-template-columns: 1fr; }
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
      <?php
        $hipaaCatUrl = $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software');
        $hipaaCatLink = '<a href="' . $hipaaCatUrl . '" class="content-link">' . t('marketing.hipaa.intro_link_label') . '</a>';
        echo t('marketing.hipaa.intro', ['link' => $hipaaCatLink]);
      ?>
    </div>

    <section class="hipaa-trust" aria-labelledby="trust-heading">
      <h2 id="trust-heading"><?php echo t('marketing.hipaa.trust_heading'); ?></h2>
      <div class="hipaa-trust-grid">
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
          <h3><?php echo t('marketing.hipaa.trust_encryption_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_encryption_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
          <h3><?php echo t('marketing.hipaa.trust_app_encryption_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_app_encryption_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
          <h3><?php echo t('marketing.hipaa.trust_2fa_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_2fa_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          <h3><?php echo t('marketing.hipaa.trust_isolation_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_isolation_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="17" y1="8" x2="22" y2="8"></line><line x1="22" y1="8" x2="19" y2="11"></line><line x1="22" y1="8" x2="19" y2="5"></line></svg>
          <h3><?php echo t('marketing.hipaa.trust_rbac_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_rbac_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
          <h3><?php echo t('marketing.hipaa.trust_revocation_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_revocation_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          <h3><?php echo t('marketing.hipaa.trust_audit_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_audit_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
          <h3><?php echo t('marketing.hipaa.trust_files_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_files_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          <h3><?php echo t('marketing.hipaa.trust_sessions_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_sessions_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
          <h3><?php echo t('marketing.hipaa.trust_backups_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_backups_body'); ?></p>
        </div>
        <div class="hipaa-trust-item">
          <svg class="hipaa-trust-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
          <h3><?php echo t('marketing.hipaa.trust_baa_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.trust_baa_body'); ?></p>
        </div>
      </div>
    </section>

    <section class="hipaa-safeguards" aria-labelledby="encryption-heading">
      <h2 id="encryption-heading"><?php echo t('marketing.hipaa.encryption_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.hipaa.encryption_lead'); ?></p>
      <div class="hipaa-grid">
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.encryption_app_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.encryption_app_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.encryption_infra_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.encryption_infra_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.encryption_transit_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.encryption_transit_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.encryption_passwords_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.encryption_passwords_body'); ?></p>
        </div>
      </div>
    </section>

    <section class="hipaa-safeguards" aria-labelledby="access-heading">
      <h2 id="access-heading"><?php echo t('marketing.hipaa.access_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.hipaa.access_lead'); ?></p>
      <div class="hipaa-grid">
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.twofa_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.twofa_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.isolation_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.isolation_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.roles_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.roles_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.revocation_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.revocation_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.sessions_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.sessions_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.signin_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.signin_body'); ?></p>
        </div>
      </div>
    </section>

    <section class="hipaa-safeguards" aria-labelledby="platform-heading">
      <h2 id="platform-heading"><?php echo t('marketing.hipaa.platform_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.hipaa.platform_lead'); ?></p>
      <div class="hipaa-grid">
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.files_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.files_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.audit_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.audit_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.application_safeguards_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.application_safeguards_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.infrastructure_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.infrastructure_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.backups_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.backups_body'); ?></p>
        </div>
        <div class="hipaa-card">
          <h3><?php echo t('marketing.hipaa.retention_heading'); ?></h3>
          <p><?php echo t('marketing.hipaa.retention_body'); ?></p>
        </div>
      </div>
    </section>

    <section class="hipaa-section" aria-labelledby="shared-heading">
      <h2 id="shared-heading"><?php echo t('marketing.hipaa.shared_responsibility_heading'); ?></h2>
      <?php echo t('marketing.hipaa.shared_responsibility_body'); ?>
      <h3 id="responsibilities-heading"><?php echo t('marketing.hipaa.practice_responsibilities_heading'); ?></h3>
      <p><?php echo t('marketing.hipaa.practice_responsibilities_body'); ?></p>
      <ul class="hipaa-list">
        <?php for ($i = 1; $i <= 11; $i++) : ?>
          <li><?php echo t('marketing.hipaa.responsibilities_item_' . $i); ?></li>
        <?php endfor; ?>
      </ul>
    </section>

    <section class="hipaa-section" aria-labelledby="baa-heading">
      <h2 id="baa-heading"><?php echo t('marketing.hipaa.baa_heading'); ?></h2>
      <?php echo t('marketing.hipaa.baa_body'); ?>
    </section>

    <section class="hipaa-section" aria-labelledby="assurance-heading">
      <h2 id="assurance-heading"><?php echo t('marketing.hipaa.assurance_heading'); ?></h2>
      <?php echo t('marketing.hipaa.assurance_body'); ?>
    </section>

    <section class="hipaa-section" aria-labelledby="report-heading">
      <h2 id="report-heading"><?php echo t('marketing.hipaa.report_heading'); ?></h2>
      <p><?php echo t('marketing.hipaa.report_body'); ?></p>
    </section>

    <section class="hipaa-section hipaa-contact-section" aria-labelledby="contact-heading">
      <h2 id="contact-heading"><?php echo t('marketing.hipaa.contact_heading'); ?></h2>
      <p class="hipaa-contact"><?php echo t('marketing.hipaa.contact_body'); ?></p>
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
