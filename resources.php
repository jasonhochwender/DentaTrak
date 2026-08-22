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
    .content.resources-hub { max-width: 1000px; }

    .resources-hero { text-align: center; margin-bottom: 64px; }
    .resources-hero h1 { font-size: clamp(2rem, 5vw, 3rem); line-height: 1.15; margin-bottom: 16px; }
    .resources-hero p { color: var(--text-secondary); font-size: 1.125rem; max-width: 720px; margin: 0 auto; line-height: 1.6; }

    .section-lead { color: var(--text-secondary); margin-top: -8px; margin-bottom: 28px; max-width: 720px; line-height: 1.6; }

    .tools-section { margin-bottom: 72px; }
    .tools-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
    .tool-privacy-note { color: var(--text-secondary); font-size: 0.85rem; margin: 20px 0 0 0; line-height: 1.5; }

    .tool-card { position: relative; display: block; border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 28px; background: var(--background-white); transition: box-shadow 0.2s, transform 0.2s; text-decoration: none; color: inherit; }
    .tool-card:hover:not(.tool-card--disabled) { box-shadow: var(--shadow-medium); transform: translateY(-2px); }
    .tool-card--disabled { opacity: 0.7; cursor: not-allowed; }
    .tool-card h3 { margin-top: 0; margin-bottom: 10px; font-size: 1.25rem; color: var(--text-primary); }
    .tool-card p { color: var(--text-secondary); font-size: 0.95rem; line-height: 1.6; margin-bottom: 20px; }

    .resource-type { display: inline-block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary-color); background: rgba(30, 64, 175, 0.08); padding: 4px 10px; border-radius: 100px; margin-bottom: 12px; }

    .tool-cta { display: inline-flex; align-items: center; gap: 6px; font-size: 0.95rem; font-weight: 600; color: var(--primary-color); text-decoration: none; }
    .tool-cta::after { content: "→"; transition: transform 0.2s; }
    .tool-card:hover .tool-cta::after { transform: translateX(3px); }
    .tool-cta.disabled { color: var(--text-light); }
    .tool-cta.disabled::after { content: none; }
    .coming-soon { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-light); }

    .featured-section { margin-bottom: 72px; }
    .featured-section .resource-grid { margin-top: 8px; }

    .browse-section { margin-bottom: 72px; }
    .category-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; list-style: none; padding: 0; }
    .category-list a { display: inline-flex; padding: 8px 16px; border: 1px solid var(--border-light); border-radius: 100px; font-size: 0.85rem; font-weight: 500; color: var(--text-secondary); text-decoration: none; background: var(--background-white); transition: background 0.2s, border-color 0.2s, color 0.2s; }
    .category-list a:hover { border-color: var(--primary-color); color: var(--primary-color); background: rgba(30, 64, 175, 0.04); }
    .category-list a:focus { outline: 2px solid var(--primary-color); outline-offset: 2px; }

    .resource-category { margin-bottom: 40px; }
    .resource-category h3 { font-size: 1.15rem; font-weight: 600; margin-top: 0; margin-bottom: 16px; color: var(--text-primary); }
    .resource-category h3:target { scroll-margin-top: 80px; }

    .resource-card { display: flex; flex-direction: column; height: 100%; }
    .resource-card .resource-type { align-self: flex-start; }
    .resource-card h3 { margin-top: 0; margin-bottom: 8px; font-size: 1.05rem; color: var(--text-primary); }
    .resource-card p { color: var(--text-secondary); font-size: 0.9rem; line-height: 1.6; margin-bottom: 0; }
    .resource-cta { margin-top: auto; padding-top: 16px; font-size: 0.9rem; font-weight: 600; color: var(--primary-color); text-decoration: none; }
    .resource-card:hover .resource-cta { text-decoration: underline; }

    .cta-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
    .btn-ghost { display: inline-flex; align-items: center; padding: 14px 32px; border: 1px solid rgba(255, 255, 255, 0.55); border-radius: var(--radius-md); color: #fff; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: background 0.2s, border-color 0.2s; }
    .btn-ghost:hover { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.85); }

    @media (max-width: 720px) {
      .content.resources-hub { padding-left: 20px; padding-right: 20px; }
      .resources-hero h1 { font-size: 1.9rem; }
      .tools-grid { grid-template-columns: 1fr; }
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
        <a class="tool-card" href="<?= $baseUrl ?>resources/DentaTrak_Dental_Case_Tracking_Spreadsheet.xlsx" download="DentaTrak_Dental_Case_Tracking_Spreadsheet.xlsx">
          <span class="resource-type"><?php echo t('marketing.resources.tool_spreadsheet_type'); ?></span>
          <h3><?php echo t('marketing.resources.tool_spreadsheet_title'); ?></h3>
          <p><?php echo t('marketing.resources.tool_spreadsheet_desc'); ?></p>
          <span class="tool-cta"><?php echo t('marketing.resources.tool_spreadsheet_cta'); ?></span>
        </a>
        <a class="tool-card" href="<?= $remakeCalcUrl ?>">
          <span class="resource-type"><?php echo t('marketing.resources.tool_calculator_type'); ?></span>
          <h3><?php echo t('marketing.resources.tool_calculator_title'); ?></h3>
          <p><?php echo t('marketing.resources.tool_calculator_desc'); ?></p>
          <span class="tool-cta"><?php echo t('marketing.resources.tool_calculator_cta'); ?></span>
        </a>
      </div>
      <p class="tool-privacy-note"><?php echo t('marketing.resources.tool_spreadsheet_privacy'); ?></p>
    </section>

    <section class="featured-section" aria-labelledby="featured-heading">
      <h2 id="featured-heading"><?php echo t('marketing.resources.featured_heading'); ?></h2>
      <p class="section-lead"><?php echo t('marketing.resources.featured_desc'); ?></p>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">
          <span class="resource-type"><?php echo t('marketing.resources.type_guide'); ?></span>
          <h3><?php echo t('marketing.resources.card_dental_case_tracking_software_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_dental_case_tracking_software_desc'); ?></p>
          <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>">
          <span class="resource-type"><?php echo t('marketing.resources.type_guide'); ?></span>
          <h3><?php echo t('marketing.resources.card_how_to_track_title'); ?></h3>
          <p><?php echo t('marketing.resources.card_how_to_track_desc'); ?></p>
          <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_visual_workflow'] ?? 'visual-dental-case-workflow') ?>">
          <span class="resource-type"><?php echo t('marketing.resources.type_guide'); ?></span>
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
        <li><a href="#cat-security-privacy"><?php echo t('marketing.resources.group_security_privacy'); ?></a></li>
        <li><a href="#cat-lab-workflows"><?php echo t('marketing.resources.group_lab_workflows'); ?></a></li>
        <li><a href="#cat-case-types"><?php echo t('marketing.resources.group_case_types'); ?></a></li>
        <li><a href="#cat-comparisons"><?php echo t('marketing.resources.group_comparisons'); ?></a></li>
      </ul>

      <div class="resource-category" id="cat-lab-workflows">
        <h3><?php echo t('marketing.resources.group_lab_workflows'); ?></h3>
        <div class="resource-grid">
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">
            <span class="resource-type"><?php echo t('marketing.resources.type_guide'); ?></span>
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
            <span class="resource-type"><?php echo t('marketing.resources.type_guide'); ?></span>
            <h3><?php echo t('marketing.resources.card_crown_bridge_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_crown_bridge_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>">
            <span class="resource-type"><?php echo t('marketing.resources.type_guide'); ?></span>
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
            <span class="resource-type"><?php echo t('marketing.resources.type_comparison'); ?></span>
            <h3><?php echo t('marketing.resources.card_vs_spreadsheets_title'); ?></h3>
            <p><?php echo t('marketing.resources.card_vs_spreadsheets_desc'); ?></p>
            <span class="resource-cta"><?php echo t('marketing.resources.read_guide'); ?></span>
          </a>
          <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>">
            <span class="resource-type"><?php echo t('marketing.resources.type_comparison'); ?></span>
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
            <span class="resource-type"><?php echo t('marketing.resources.type_security'); ?></span>
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
