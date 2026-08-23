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
  
  <meta name="description" content="<?php echo htmlspecialchars(t("marketing.articles.visual_dental_case_workflow.seo.description")); ?>">
  <title><?php echo htmlspecialchars(t("marketing.articles.visual_dental_case_workflow.seo.title")); ?></title>

  <!-- Open Graph -->
  <meta property="og:title" content="<?php echo htmlspecialchars(t("marketing.articles.visual_dental_case_workflow.seo.title")); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars(t("marketing.articles.visual_dental_case_workflow.seo.description")); ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/visual-dental-case-workflow">
  <meta property="og:site_name" content="DentaTrak">
  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo htmlspecialchars(t("marketing.articles.visual_dental_case_workflow.seo.title")); ?>">
  <meta name="twitter:description" content="<?php echo htmlspecialchars(t("marketing.articles.visual_dental_case_workflow.seo.description")); ?>">
  <link rel="canonical" href="https://dentatrak.com/visual-dental-case-workflow">

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
    "headline": <?php echo json_encode(t("marketing.articles.visual_dental_case_workflow.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "author": { "@id": "https://dentatrak.com/about#william-verrillo" },
    "publisher": { "@id": "https://dentatrak.com/#organization" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/visual-dental-case-workflow"
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
      { "@type": "ListItem", "position": 3, "name": <?php echo json_encode(t('marketing.articles.visual_dental_case_workflow.h1'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/visual-dental-case-workflow" }
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

    .highlight-box ul {
      margin-bottom: 0;
    }

    .highlight-box li:last-child {
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
    <h1><?php echo t('marketing.articles.visual_dental_case_workflow.h1'); ?></h1>

    <div class="article-meta">
      <span><?php echo t('marketing.articles.visual_dental_case_workflow.meta.by'); ?> <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span><?php echo t('marketing.articles.visual_dental_case_workflow.meta.published'); ?> <strong>August 8, 2026</strong></span>
    </div>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections..body_1'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections..body_2'); ?> <em><?php echo t('marketing.articles.visual_dental_case_workflow.intro'); ?></em>.
    </p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections..body_3'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections..body_3'); ?></p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.complex_dental_cases_are_processes_not_records.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.complex_dental_cases_are_processes_not_records.body_4'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.complex_dental_cases_are_processes_not_records.body_5'); ?><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.complex_dental_cases_are_processes_not_records.body_6'); ?></strong>
    </p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.complex_dental_cases_are_processes_not_records.body_7'); ?></p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.body_8'); ?></p>

    <ul>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.1.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.2.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.3.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.4.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.5.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.6.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.7.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.list_items.8.body'); ?></li>
    </ul>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_a_complex_case_actually_involves.body_9'); ?></p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.body_10'); ?></p>

    <ul>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.list_items.9.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.list_items.10.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.list_items.11.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.list_items.12.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.list_items.13.body'); ?></li>
      <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.list_items.14.body'); ?></li>
    </ul>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.body_11'); ?> <em><?php echo t('marketing.articles.visual_dental_case_workflow.intro_2'); ?></em> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.body_12'); ?> <a href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>" style="color: var(--primary-color); text-decoration: none; font-weight: 500;"><?php echo t('marketing.articles.visual_dental_case_workflow.sections.having_information_is_not_the_same_as_having_visibility.links.1'); ?></a>.
    </p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_means.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_means.body_13'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_means.body_14'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_means.body_15'); ?></p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_16'); ?></p>

    <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.subheading_1'); ?></h3>
    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_17'); ?></p>

    <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.subheading_2'); ?></h3>
    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_18'); ?></p>

    <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.subheading_3'); ?></h3>
    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_19'); ?></p>

    <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.subheading_4'); ?></h3>
    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_20'); ?></p>

    <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.subheading_5'); ?></h3>
    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_21'); ?></p>

    <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.subheading_6'); ?></h3>
    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.why_this_works_especially_well_for_dental_cases.body_22'); ?></p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_does_not_replace.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_does_not_replace.body_23'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_does_not_replace.body_24'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_does_not_replace.body_25'); ?> <em><?php echo t('marketing.articles.visual_dental_case_workflow.intro_3'); ?></em> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_does_not_replace.body_26'); ?> <a href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>" style="color: var(--primary-color); text-decoration: none; font-weight: 500;"><?php echo t('marketing.articles.visual_dental_case_workflow.sections.what_visual_workflow_management_does_not_replace.links.2'); ?></a>.
    </p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.the_workflow_should_use_your_practice_s_language.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.the_workflow_should_use_your_practice_s_language.body_27'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.the_workflow_should_use_your_practice_s_language.body_28'); ?></p>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.body_29'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.body_30'); ?></p>

    <ol class="workflow-steps">
      <li><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.15.title'); ?></strong> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.15.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.16.title'); ?></strong> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.16.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.17.title'); ?></strong> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.17.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.18.title'); ?></strong> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.18.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.19.title'); ?></strong> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.19.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.20.title'); ?></strong> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.20.body'); ?></li>
    </ol>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.body_31'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.body_32'); ?></p>

    <div class="highlight-box">
      <h3><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.subheading_7'); ?></h3>
      <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.body_33'); ?></p>
      <ul>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.21.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.22.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.23.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.24.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.25.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.26.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.27.body'); ?></li>
        <li><?php echo t('marketing.articles.visual_dental_case_workflow.sections.how_dentatrak_applies_this_to_dental_cases.list_items.28.body'); ?></li>
      </ul>
    </div>

    <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.heading'); ?></h2>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.body_34'); ?></p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.body_35'); ?> <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" style="color: var(--primary-color); text-decoration: none; font-weight: 500;"><?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.links.3'); ?></a> <?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.body_36'); ?> <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>" style="color: var(--primary-color); text-decoration: none; font-weight: 500;"><?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.links.4'); ?></a>.
    </p>

    <p><?php echo t('marketing.articles.visual_dental_case_workflow.sections.where_to_start.body_37'); ?></p>

    <div class="cta-section">
      <h2><?php echo t('marketing.articles.visual_dental_case_workflow.sections.see_your_case_workflow_at_a_glance.heading'); ?></h2>
      <p><?php echo t('marketing.articles.visual_dental_case_workflow.cta.body'); ?></p>
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
