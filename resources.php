<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
$remakeCalcUrl = $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost');
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
  <style>
    html { scroll-behavior: smooth; }
    .content.resources-hub { max-width: 1000px; }

    .resources-hero { text-align: center; margin-bottom: 32px; padding: 24px 0 8px; }
    .resources-hero h1 { font-size: clamp(1.9rem, 5vw, 2.6rem); line-height: 1.15; margin-bottom: 10px; color: var(--text-primary); }
    .resources-hero p { color: var(--text-secondary); font-size: 1.05rem; max-width: 680px; margin: 0 auto; line-height: 1.6; }

    .section-lead { color: var(--text-secondary); margin-top: 4px; margin-bottom: 22px; max-width: 720px; line-height: 1.6; }

    /* Free Tools & Downloads */
    .tools-section { margin-bottom: 56px; padding: 28px; background: linear-gradient(180deg, #f0f6ff 0%, #f8fafc 100%); border: 1px solid #e2ebf8; border-radius: var(--radius-lg); }
    .tools-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; align-items: stretch; }

    .tool-card { position: relative; display: flex; flex-direction: column; height: 100%; border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 26px; background: #ffffff; text-decoration: none; color: inherit; transition: box-shadow 0.2s, transform 0.2s, border-color 0.2s; }
    .tool-card:hover { box-shadow: var(--shadow-medium); transform: translateY(-3px); border-color: var(--border-medium); }
    .tool-card__icon { width: 40px; height: 40px; margin-bottom: 12px; }
    .tool-card--download .tool-card__icon { color: var(--primary-color); }
    .tool-card--calculator .tool-card__icon { color: #0f766e; }
    .tool-card h3 { margin: 0 0 8px; font-size: 1.15rem; color: var(--text-primary); }
    .tool-card p { color: var(--text-secondary); font-size: 0.9rem; line-height: 1.6; margin: 0 0 16px; }
    .tool-cta { margin-top: auto; display: inline-flex; align-items: center; gap: 6px; font-size: 0.95rem; font-weight: 600; color: var(--primary-color); text-decoration: none; }
    .tool-cta::after { content: "→"; transition: transform 0.2s; }
    .tool-card:hover .tool-cta::after { transform: translateX(3px); }
    .tool-cta.disabled { color: var(--text-light); }
    .tool-cta.disabled::after { content: none; }
    .coming-soon { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-light); }

    .tool-privacy-callout { display: flex; gap: 10px; align-items: flex-start; margin-top: 22px; padding: 12px 16px; background: #ffffff; border: 1px solid var(--border-light); border-radius: var(--radius-md); }
    .tool-privacy-callout svg { flex-shrink: 0; width: 18px; height: 18px; color: var(--text-secondary); margin-top: 1px; }
    .tool-privacy-callout p { margin: 0; color: var(--text-light); font-size: 0.82rem; line-height: 1.55; }

    /* Resource badges */
    .resource-type { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.02em; padding: 4px 10px; border-radius: 100px; margin-bottom: 10px; text-transform: none; }
    .resource-type--download { color: #1e40af; background: #eff6ff; }
    .resource-type--calculator { color: #0f766e; background: #f0fdfa; }
    .resource-type--guide { color: #4f46e5; background: #eef2ff; }
    .resource-type--comparison { color: #7c3aed; background: #f3e8ff; }
    .resource-type--security { color: #0d5c63; background: #e6fffa; }

    /* Featured Resources */
    .featured-section { margin-bottom: 56px; }
    .featured-section .resource-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
    .featured-section .resource-card { background: #ffffff; }

    /* Browse Resources */
    .browse-section { margin-bottom: 56px; padding: 24px; background: #f8fafc; border: 1px solid #edf0f4; border-radius: var(--radius-lg); }
    .category-list { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 20px; list-style: none; padding: 0; }
    .category-list a { display: inline-flex; padding: 7px 14px; border: 1px solid var(--border-light); border-radius: 100px; font-size: 0.85rem; font-weight: 500; color: var(--text-secondary); text-decoration: none; background: #ffffff; transition: background 0.2s, border-color 0.2s, color 0.2s; }
    .category-list a:hover { border-color: var(--primary-color); color: var(--primary-color); background: rgba(30, 64, 175, 0.04); }
    .category-list a:focus { outline: 2px solid var(--primary-color); outline-offset: 2px; }

    .resource-category { margin-bottom: 28px; }
    .resource-category:last-child { margin-bottom: 0; }
    .resource-category h3 { font-size: 1.05rem; font-weight: 600; margin: 0 0 10px; color: var(--text-primary); }
    .resource-category h3:target { scroll-margin-top: 90px; }
    .resource-category .resource-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }

    /* Standard resource cards */
    .resource-card { display: flex; flex-direction: column; height: 100%; padding: 22px; border: 1px solid var(--border-light); border-radius: var(--radius-lg); background: #ffffff; text-decoration: none; color: inherit; transition: box-shadow 0.2s, transform 0.2s, border-color 0.2s; }
    .resource-card .resource-type { align-self: flex-start; }
    .resource-card h3 { margin: 0 0 6px; font-size: 1rem; color: var(--text-primary); }
    .resource-card p { color: var(--text-secondary); font-size: 0.88rem; line-height: 1.6; margin: 0; }
    .resource-card:hover { box-shadow: var(--shadow-medium); transform: translateY(-2px); border-color: var(--border-medium); }
    .resource-cta { margin-top: auto; padding-top: 14px; font-size: 0.88rem; font-weight: 600; color: var(--primary-color); text-decoration: none; }
    .resource-card:hover .resource-cta { text-decoration: underline; }

    .cta-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
    .btn-ghost { display: inline-flex; align-items: center; padding: 14px 32px; border: 1px solid rgba(255, 255, 255, 0.55); border-radius: var(--radius-md); color: #fff; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: background 0.2s, border-color 0.2s; }
    .btn-ghost:hover { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.85); }

    /* Specificity and spacing fixes */
    .content.resources-hub .resource-card { display: flex; flex-direction: column; height: 100%; }
    .content.resources-hub .resource-card .resource-type { align-self: flex-start; }
    .content.resources-hub .resource-cta { margin-top: auto; }

    .featured-section { margin-bottom: 64px; }
    .browse-section { margin-bottom: 56px; }
    .resource-category { margin-bottom: 28px; }
    .resource-category h3 { margin: 0 0 10px; }
    .resource-category .resource-grid { margin-top: 0; }
    .content.resources-hub .cta-section { margin-top: 64px; }

    @media (min-width: 881px) {
      .content.resources-hub .featured-section .resource-grid { grid-template-columns: repeat(3, 1fr); gap: 22px; }
      .content.resources-hub .resource-category .resource-grid { grid-template-columns: repeat(3, 1fr); gap: 18px; }
    }

    @media (max-width: 880px) {
      .featured-section .resource-grid { grid-template-columns: repeat(2, 1fr); }
      .resource-category .resource-grid { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 720px) {
      .content.resources-hub { padding-left: 20px; padding-right: 20px; }
      .resources-hero h1 { font-size: 1.8rem; }
      .tools-section { padding: 22px; }
      .tools-grid { grid-template-columns: 1fr; }
      .featured-section .resource-grid { grid-template-columns: 1fr; }
      .resource-category .resource-grid { grid-template-columns: 1fr; }
    }
  </style>
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
  <main class="content no-breadcrumb resources-hub">
    <div class="resources-hero">
      <h1><?php echo t('marketing.resources.h1'); ?></h1>
      <p><?php echo t('marketing.resources.intro'); ?></p>
    </div>

    <section class="tools-section" aria-labelledby="tools-heading">
      <h2 id="tools-heading"><?php echo t('marketing.resources.tools_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.resources.tools_desc'); ?></p>
      <div class="tools-grid">
        <a class="tool-card tool-card--download" href="<?= $baseUrl ?>resources/DentaTrak_Dental_Case_Tracking_Spreadsheet.xlsx" download="DentaTrak_Dental_Case_Tracking_Spreadsheet.xlsx">
          <span class="resource-type resource-type--download"><?php echo t('marketing.resources.tool_spreadsheet_type'); ?></span>
          <svg class="tool-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="18" height="18" rx="2"></rect>
            <path d="M3 9h18M9 21V9M15 21V9"></path>
          </svg>
          <h3><?php echo t('marketing.resources.tool_spreadsheet_title'); ?></h3>
          <p><?php echo t('marketing.resources.tool_spreadsheet_desc'); ?></p>
          <span class="tool-cta"><?php echo t('marketing.resources.tool_spreadsheet_cta'); ?></span>
        </a>
        <a class="tool-card tool-card--calculator" href="<?= $remakeCalcUrl ?>">
          <span class="resource-type resource-type--calculator"><?php echo t('marketing.resources.tool_calculator_type'); ?></span>
          <svg class="tool-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="5" y="3" width="14" height="18" rx="2"></rect>
            <path d="M8 7h8M8 11h2M8 15h2M8 19h2M14 11h2M14 15h2M14 19h2"></path>
          </svg>
          <h3><?php echo t('marketing.resources.tool_calculator_title'); ?></h3>
          <p><?php echo t('marketing.resources.tool_calculator_desc'); ?></p>
          <span class="tool-cta"><?php echo t('marketing.resources.tool_calculator_cta'); ?></span>
        </a>
      </div>
      <div class="tool-privacy-callout">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
        </svg>
        <p><?php echo t('marketing.resources.tool_spreadsheet_privacy'); ?></p>
      </div>
    </section>

    <section class="featured-section" aria-labelledby="featured-heading">
      <h2 id="featured-heading"><?php echo t('marketing.resources.featured_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.resources.featured_desc'); ?></p>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">
          <span class="resource-type resource-type--guide"><?php echo t('marketing.resources.type_guide'); ?></span>
          <h3><?php echo t('marketing.resources.card_dental_case_tracking_software_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_dental_case_tracking_software_desc'); ?></p>
          <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>">
          <span class="resource-type resource-type--guide"><?php echo t('marketing.resources.type_guide'); ?></span>
          <h3><?php echo t('marketing.resources.card_how_to_track_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_how_to_track_desc'); ?></p>
          <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_visual_workflow'] ?? 'visual-dental-case-workflow') ?>">
          <span class="resource-type resource-type--guide"><?php echo t('marketing.resources.type_guide'); ?></span>
          <h3><?php echo t('marketing.resources.card_visual_workflow_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_visual_workflow_desc'); ?></p>
          <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
        </a>
      </div>
    </section>

    <section class="browse-section" aria-labelledby="browse-heading">
      <h2 id="browse-heading"><?php echo t('marketing.resources.browse_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.resources.browse_desc'); ?></p>
      <ul class="category-list" aria-label="<?php echo htmlspecialchars(t('marketing.resources.browse_heading')); ?>">
        <li><a href="#cat-lab-workflows"><?php echo t('marketing.resources.group_lab_workflows'); ?></a></li>
        <li><a href="#cat-case-types"><?php echo t('marketing.resources.group_case_types'); ?></a></li>
        <li><a href="#cat-comparisons"><?php echo t('marketing.resources.group_comparisons'); ?></a></li>
        <li><a href="#cat-security-privacy"><?php echo t('marketing.resources.group_security_privacy'); ?></a></li>
      </ul>

      <div class="resource-category" id="cat-lab-workflows">
        <h3><?php echo t('marketing.resources.group_lab_workflows'); ?></h3>
        <div class="resource-grid">
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">
            <span class="resource-type resource-type--guide"><?php echo t('marketing.resources.type_guide'); ?></span>
            <h3><?php echo t('marketing.resources.card_lab_tracking_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_lab_tracking_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
        </div>
      </div>

      <div class="resource-category" id="cat-case-types">
        <h3><?php echo t('marketing.resources.group_case_types'); ?></h3>
        <div class="resource-grid">
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>">
            <span class="resource-type resource-type--guide"><?php echo t('marketing.resources.type_guide'); ?></span>
            <h3><?php echo t('marketing.resources.card_crown_bridge_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_crown_bridge_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>">
            <span class="resource-type resource-type--guide"><?php echo t('marketing.resources.type_guide'); ?></span>
            <h3><?php echo t('marketing.resources.card_implant_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_implant_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
        </div>
      </div>

      <div class="resource-category" id="cat-comparisons">
        <h3><?php echo t('marketing.resources.group_comparisons'); ?></h3>
        <div class="resource-grid">
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets') ?>">
            <span class="resource-type resource-type--comparison"><?php echo t('marketing.resources.type_comparison'); ?></span>
            <h3><?php echo t('marketing.resources.card_vs_spreadsheets_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_vs_spreadsheets_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>">
            <span class="resource-type resource-type--comparison"><?php echo t('marketing.resources.type_comparison'); ?></span>
            <h3><?php echo t('marketing.resources.card_vs_pms_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_vs_pms_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
        </div>
      </div>

      <div class="resource-category" id="cat-security-privacy">
        <h3><?php echo t('marketing.resources.group_security_privacy'); ?></h3>
        <div class="resource-grid">
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['page_hipaa_security'] ?? 'hipaa-security') ?>">
            <span class="resource-type resource-type--security"><?php echo t('marketing.resources.type_security'); ?></span>
            <h3><?php echo t('marketing.resources.card_hipaa_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_hipaa_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.card_hipaa_cta'); ?></span>
          </a>
        </div>
      </div>
    </section>

    <section class="cta-section" aria-labelledby="cta-heading">
      <h2 id="cta-heading"><?php echo t('marketing.resources.cta_heading'); ?></h2>
      <p><?php echo t('marketing.resources.cta_desc'); ?></p>
      <div class="cta-actions">
        <a href="<?= $baseUrl ?>login.php" class="btn-white"><?php echo t('marketing.navigation.start_free'); ?></a>
        <a href="<?= $baseUrl ?>#how-it-works" class="btn-ghost"><?php echo t('marketing.resources.cta_secondary'); ?></a>
      </div>
    </section>
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
