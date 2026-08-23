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

  <meta name="description" content="<?php echo htmlspecialchars(t("marketing.articles.dental_remake_cost.seo.description")); ?>">
  <title><?php echo htmlspecialchars(t("marketing.articles.dental_remake_cost.seo.title")); ?></title>
  <link rel="canonical" href="https://dentatrak.com/dental-remake-cost">

  <!-- Open Graph -->
  <meta property="og:title" content="<?php echo htmlspecialchars(t("marketing.articles.dental_remake_cost.seo.title")); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars(t("marketing.articles.dental_remake_cost.seo.description")); ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/dental-remake-cost">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">

  <!-- Structured Data: Article -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?php echo json_encode(t("marketing.articles.dental_remake_cost.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    "author": { "@id": "https://dentatrak.com/about#william-verrillo" },
    "publisher": { "@id": "https://dentatrak.com/#organization" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/dental-remake-cost"
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
      { "@type": "ListItem", "position": 3, "name": <?php echo json_encode(t("marketing.articles.dental_remake_cost.h1"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "item": "https://dentatrak.com/dental-remake-cost" }
    ]
  }
  </script>

  <style>
    .calculator {
      background: #fff;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 20px;
      padding: 28px;
      box-shadow: 0 12px 34px -16px rgba(0,0,0,0.1);
      margin: 32px 0;
    }
    .calculator-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
    }
    @media (max-width: 720px) {
      .calculator-grid { grid-template-columns: 1fr; }
    }
    .calc-field { display: flex; flex-direction: column; gap: 6px; }
    .calc-field label { font-size: 0.85rem; font-weight: 600; color: var(--dt-ink, #1e293b); }
    .calc-field .help { font-size: 0.75rem; color: var(--dt-ink-muted, #64748b); }
    .calc-field input {
      padding: 12px 14px;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 10px;
      font-size: 1rem;
      font-family: inherit;
      color: var(--dt-ink, #1e293b);
      background: #fff;
    }
    .calc-field input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .calc-results {
      background: #f8fafc;
      border-radius: 16px;
      padding: 24px;
      margin-top: 28px;
    }
    .primary-result {
      text-align: center;
      padding-bottom: 24px;
      border-bottom: 1px solid var(--dt-border, #e2e8f0);
      margin-bottom: 24px;
    }
    .primary-result .label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #7c3aed; }
    .primary-result .value { font-size: clamp(2rem, 5vw, 3rem); font-weight: 700; color: var(--dt-ink, #1e293b); margin: 8px 0; }
    .result-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
    }
    .result-item { background: #fff; border: 1px solid var(--dt-border, #e2e8f0); border-radius: 12px; padding: 16px; }
    .result-item .label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dt-ink-muted, #64748b); margin-bottom: 6px; }
    .result-item .value { font-size: 1.2rem; font-weight: 700; color: var(--dt-ink, #1e293b); }
    .savings {
      background: #fff;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 16px;
      padding: 20px;
    }
    .savings h4 { font-size: 1rem; margin-bottom: 14px; }
    .savings-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid var(--dt-border, #e2e8f0);
      font-size: 0.92rem;
    }
    .savings-row:last-child { border-bottom: none; }
    .savings-row .amount { font-weight: 700; color: #15803d; }
    .calculator-disclaimer { font-size: 0.8rem; color: var(--dt-ink-muted, #64748b); margin-top: 18px; line-height: 1.5; }
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

  <!-- Breadcrumbs -->
  <div class="breadcrumb-bar">
    <ol class="breadcrumb">
      <li><a href="<?= $baseUrl ?>"><?php echo t("marketing.navigation.home"); ?></a></li>
      <li>/</li>
      <li><a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>"><?php echo t("marketing.navigation.resources"); ?></a></li>
      <li>/</li>
      <li aria-current="page"><?php echo t("marketing.articles.dental_remake_cost.h1"); ?></li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1><?php echo t('marketing.articles.dental_remake_cost.h1'); ?></h1>

    <div class="article-meta">
      <span><?php echo t('marketing.articles.dental_remake_cost.meta.by'); ?> <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span><?php echo t('marketing.articles.dental_remake_cost.meta.published'); ?> <strong>August 8, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p><?php echo t('marketing.articles.dental_remake_cost.intro'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.heading'); ?></h2>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.body_1'); ?></p>

    <ul>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.1.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.1.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.2.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.2.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.3.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.3.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.4.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.4.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.5.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.5.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.6.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.list_items.6.body'); ?></li>
    </ul>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.the_lab_bill_is_not_the_whole_cost.body_2'); ?></p>

    <h2><?php echo t('marketing.articles.dental_remake_cost.sections.chair_time_has_a_cost.heading'); ?></h2>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.chair_time_has_a_cost.body_3'); ?></p>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.chair_time_has_a_cost.body_4'); ?></p>

    <h2><?php echo t('marketing.articles.dental_remake_cost.sections.staff_time_adds_up_too.heading'); ?></h2>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.staff_time_adds_up_too.body_5'); ?></p>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.staff_time_adds_up_too.body_6'); ?></p>

    <h2><?php echo t('marketing.articles.dental_remake_cost.sections.calculate_your_practice_s_remake_cost.heading'); ?></h2>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.calculate_your_practice_s_remake_cost.body_7'); ?></p>

    <div class="calculator">
      <div class="calculator-grid">
        <div class="calc-field">
          <label for="cases"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.cases.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.cases.help'); ?></span>
          <input type="number" id="cases" value="150" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="rate"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.rate.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.rate.help'); ?></span>
          <input type="number" id="rate" value="5" min="0" step="0.1">
        </div>
        <div class="calc-field">
          <label for="dentistMins"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.dentistMins.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.dentistMins.help'); ?></span>
          <input type="number" id="dentistMins" value="45" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="dentistHourly"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.dentistHourly.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.dentistHourly.help'); ?></span>
          <input type="number" id="dentistHourly" value="500" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="staffMins"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.staffMins.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.staffMins.help'); ?></span>
          <input type="number" id="staffMins" value="20" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="staffHourly"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.staffHourly.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.staffHourly.help'); ?></span>
          <input type="number" id="staffHourly" value="35" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="labCharge"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.labCharge.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.labCharge.help'); ?></span>
          <input type="number" id="labCharge" value="75" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="shipping"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.shipping.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.shipping.help'); ?></span>
          <input type="number" id="shipping" value="15" min="0" step="1">
        </div>
        <div class="calc-field" style="grid-column: 1 / -1;">
          <label for="other"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.other.label'); ?></label>
          <span class="help"><?php echo t('marketing.articles.dental_remake_cost.calculator.fields.other.help'); ?></span>
          <input type="number" id="other" value="20" min="0" step="1">
        </div>
      </div>

      <div class="calc-results">
        <div class="primary-result">
          <div class="label"><?php echo t('marketing.articles.dental_remake_cost.calculator.primary_result.label'); ?></div>
          <div class="value" id="annualCost">$0</div>
        </div>

        <div class="result-grid">
          <div class="result-item">
            <div class="label"><?php echo t('marketing.articles.dental_remake_cost.calculator.results.1.label'); ?></div>
            <div class="value" id="remakesMonth">0</div>
          </div>
          <div class="result-item">
            <div class="label"><?php echo t('marketing.articles.dental_remake_cost.calculator.results.2.label'); ?></div>
            <div class="value" id="dentistCost">$0</div>
          </div>
          <div class="result-item">
            <div class="label"><?php echo t('marketing.articles.dental_remake_cost.calculator.results.3.label'); ?></div>
            <div class="value" id="staffCost">$0</div>
          </div>
          <div class="result-item">
            <div class="label"><?php echo t('marketing.articles.dental_remake_cost.calculator.results.4.label'); ?></div>
            <div class="value" id="costPerRemake">$0</div>
          </div>
          <div class="result-item">
            <div class="label"><?php echo t('marketing.articles.dental_remake_cost.calculator.results.5.label'); ?></div>
            <div class="value" id="monthlyCost">$0</div>
          </div>
        </div>

        <div class="savings">
          <h4><?php echo t('marketing.articles.dental_remake_cost.calculator.savings.heading'); ?></h4>
          <p class="calculator-disclaimer"><?php echo t('marketing.articles.dental_remake_cost.calculator.savings.disclaimer'); ?></p>
          <div class="savings-row" id="savings-0.5"></div>
          <div class="savings-row" id="savings-1"></div>
          <div class="savings-row" id="savings-2"></div>
        </div>
      </div>

      <p class="calculator-disclaimer"><?php echo t('marketing.articles.dental_remake_cost.calculator.disclaimer'); ?></p>
    </div>

    <h2><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.heading'); ?></h2>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.body_8'); ?></p>

    <ul class="checklist">
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.7.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.7.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.8.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.8.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.9.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.9.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.10.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.10.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.11.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.11.body'); ?></li>
      <li><strong><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.12.title'); ?></strong> <?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.list_items.12.body'); ?></li>
    </ul>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.the_number_that_matters_is_not_just_your_remake_rate.body_9'); ?></p>

    <h2><?php echo t('marketing.articles.dental_remake_cost.sections.reducing_remake_cost_starts_with_understanding_why_remakes_happen.heading'); ?></h2>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.reducing_remake_cost_starts_with_understanding_why_remakes_happen.body_10'); ?></p>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.reducing_remake_cost_starts_with_understanding_why_remakes_happen.body_11'); ?></p>

    <p><?php echo t('marketing.articles.dental_remake_cost.sections.reducing_remake_cost_starts_with_understanding_why_remakes_happen.body_12'); ?></p>

    <div class="related-links">
      <h3><?php echo t('marketing.articles.dental_remake_cost.related_resources.heading'); ?></h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>"><?php echo t('marketing.articles.dental_remake_cost.related_resources.items.0'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>"><?php echo t('marketing.articles.dental_remake_cost.related_resources.items.1'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>"><?php echo t('marketing.articles.dental_remake_cost.related_resources.items.2'); ?></a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>"><?php echo t('marketing.articles.dental_remake_cost.related_resources.items.3'); ?></a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2><?php echo t('marketing.articles.dental_remake_cost.sections.know_what_your_remakes_are_costing_then_understand_why_they_are_happening.heading'); ?></h2>
      <p><?php echo t('marketing.articles.dental_remake_cost.cta.body'); ?></p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white"><?php echo t('marketing.articles.dental_remake_cost.sections.know_what_your_remakes_are_costing_then_understand_why_they_are_happening.links.1'); ?></a>
      <p style="margin-top: 16px; font-size: 0.9rem;"><?php echo t('marketing.articles.dental_remake_cost.cta.body'); ?></p>
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

  <script>
    const calcLabels = {
      from_to: <?php echo json_encode(t('marketing.articles.dental_remake_cost.calculator.savings.from_to'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
      per_year: <?php echo json_encode(t('marketing.articles.dental_remake_cost.calculator.savings.per_year'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };
    const fields = ['cases','rate','dentistMins','dentistHourly','staffMins','staffHourly','labCharge','shipping','other'];
    const fmtCurrency = (n) => {
      const v = Number(n);
      if (!isFinite(v) || isNaN(v)) return '$0';
      const dollars = Math.round(v);
      return '$' + dollars.toLocaleString('en-US');
    };
    const fmtNumber = (n, d) => {
      const v = Number(n);
      if (!isFinite(v) || isNaN(v)) return '0';
      return v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: d });
    };

    function calculate() {
      const get = (id) => Math.max(0, parseFloat(document.getElementById(id).value) || 0);

      const cases = get('cases');
      const rate = get('rate');
      const dentistMins = get('dentistMins');
      const dentistHourly = get('dentistHourly');
      const staffMins = get('staffMins');
      const staffHourly = get('staffHourly');
      const labCharge = get('labCharge');
      const shipping = get('shipping');
      const other = get('other');

      const rateDecimal = rate / 100;
      const remakesMonth = cases * rateDecimal;
      const dentistCost = (dentistMins / 60) * dentistHourly;
      const staffCost = (staffMins / 60) * staffHourly;
      const costPerRemake = dentistCost + staffCost + labCharge + shipping + other;
      const monthlyCost = remakesMonth * costPerRemake;
      const annualCost = monthlyCost * 12;

      document.getElementById('remakesMonth').textContent = fmtNumber(remakesMonth, 1);
      document.getElementById('dentistCost').textContent = fmtCurrency(dentistCost);
      document.getElementById('staffCost').textContent = fmtCurrency(staffCost);
      document.getElementById('costPerRemake').textContent = fmtCurrency(costPerRemake);
      document.getElementById('monthlyCost').textContent = fmtCurrency(monthlyCost);
      document.getElementById('annualCost').textContent = fmtCurrency(annualCost);

      const reductions = [0.5, 1, 2];
      reductions.forEach(r => {
        const newRate = Math.max(0, rate - r);
        const newRemakesMonth = cases * (newRate / 100);
        const avoidedMonth = Math.max(0, remakesMonth - newRemakesMonth);
        const avoidedAnnual = avoidedMonth * costPerRemake * 12;
        const row = document.getElementById('savings-' + (r % 1 === 0 ? r : '0.5'));
        if (row) {
          row.innerHTML = '<span>' + calcLabels.from_to.replace('{rate}', fmtNumber(rate, 1)).replace('{newRate}', fmtNumber(newRate, 1)) + '</span><span class="amount">' + fmtCurrency(avoidedAnnual) + ' ' + calcLabels.per_year + '</span>';
        }
      });
    }

    fields.forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', calculate);
      }
    });

    calculate();
  </script>
</body>
</html>