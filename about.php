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

  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <!-- Structured Data: AboutPage, Organization, SoftwareApplication, Person -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        "@id": "https://dentatrak.com/#organization",
        "name": "DentaTrak",
        "url": "https://dentatrak.com/",
        "logo": "https://dentatrak.com/images/logo-large.png",
        "email": "support@dentatrak.com"
      },
      {
        "@type": "SoftwareApplication",
        "@id": "https://dentatrak.com/#software",
        "name": "DentaTrak",
        "applicationCategory": "BusinessApplication",
        "operatingSystem": "Web",
        "description": "DentaTrak is dental case tracking software for dental practices. See your entire case workflow at a glance and follow every crown, implant, and lab case from prep to delivery.",
        "url": "https://dentatrak.com/",
        "publisher": { "@id": "https://dentatrak.com/#organization" }
      },
      {
        "@type": "Person",
        "@id": "https://dentatrak.com/about#william-verrillo",
        "name": "Dr. William Verrillo",
        "jobTitle": "Practicing dentist",
        "url": "https://dentatrak.com/about"
      },
      {
        "@type": "AboutPage",
        "@id": "https://dentatrak.com/about",
        "url": "https://dentatrak.com/about",
        "name": "About DentaTrak",
        "mainEntity": { "@id": "https://dentatrak.com/#organization" },
        "about": { "@id": "https://dentatrak.com/about#william-verrillo" }
      }
    ]
  }
  </script>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $baseUrl ?>css/marketing.css">
  <style>
    .content.about-page { max-width: 780px; }

    .about-hero { text-align: center; margin-bottom: 56px; padding-top: 20px; }
    .about-hero h1 { font-size: clamp(2rem, 5vw, 3rem); line-height: 1.15; margin-bottom: 14px; }
    .about-hero-lead { font-size: 1.25rem; font-weight: 600; color: var(--text-primary); margin-bottom: 14px; }
    .about-hero p { color: var(--text-secondary); line-height: 1.7; max-width: 620px; margin: 0 auto; }

    .about-origin { background: linear-gradient(180deg, #f0f6ff 0%, #f8fafc 100%); border: 1px solid #e2ebf8; border-radius: var(--radius-lg); padding: 36px 40px; margin-bottom: 56px; }
    .about-origin h2 { font-size: 1.5rem; font-weight: 700; line-height: 1.25; margin-top: 0; margin-bottom: 20px; color: var(--text-primary); }
    .about-origin p { color: var(--text-secondary); line-height: 1.8; margin-bottom: 18px; max-width: 680px; }
    .about-origin p:last-child { margin-bottom: 0; }

    .about-section { margin-bottom: 56px; }
    .about-section h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 16px; color: var(--text-primary); line-height: 1.25; }
    .about-section p { color: var(--text-secondary); line-height: 1.8; margin-bottom: 16px; }
    .about-section p:last-child { margin-bottom: 0; }

    .content-link { color: var(--primary-color); text-decoration: none; font-weight: 500; }
    .content-link:hover { text-decoration: underline; }

    @media (max-width: 720px) {
      .content.about-page { padding-left: 20px; padding-right: 20px; }
      .about-hero h1 { font-size: 1.9rem; }
      .about-origin { padding: 28px 24px; }
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

  <main class="content no-breadcrumb about-page">
    <div class="about-hero">
      <h1><?php echo t('marketing.about.h1'); ?></h1>
      <p class="about-hero-lead"><?php echo t('marketing.about.hero_lead'); ?></p>
      <p><?php echo t('marketing.about.hero_intro'); ?></p>
    </div>

    <section class="about-origin" aria-labelledby="origin-heading">
      <h2 id="origin-heading"><?php echo t('marketing.about.origin_title'); ?></h2>
      <p><?php echo t('marketing.about.origin_body_1'); ?></p>
      <p><?php echo t('marketing.about.origin_body_2'); ?></p>
      <p><?php echo t('marketing.about.origin_body_3'); ?></p>
    </section>

    <section class="about-section" aria-labelledby="philosophy-heading">
      <h2 id="philosophy-heading"><?php echo t('marketing.about.philosophy_title'); ?></h2>
      <p><?php echo t('marketing.about.philosophy_body_1'); ?></p>
      <p><?php echo t('marketing.about.philosophy_body_2'); ?></p>
      <p><a href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>" class="content-link"><?php echo t('marketing.about.pms_cta'); ?></a></p>
    </section>

    <section class="about-section" aria-labelledby="closing-heading">
      <h2 id="closing-heading"><?php echo t('marketing.about.closing_title'); ?></h2>
      <p>
        <?php
          $aboutCatUrl = $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software');
          $aboutCatLink = '<a href="' . $aboutCatUrl . '" class="content-link">' . t('marketing.about.closing_link_label') . '</a>';
          echo t('marketing.about.closing_body', ['link' => $aboutCatLink]);
        ?>
      </p>
    </section>

    <div class="cta-section">
      <h2><?php echo t('marketing.about.cta_title'); ?></h2>
      <p><?php echo t('marketing.about.cta_lead'); ?></p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white"><?php echo t('marketing.navigation.start_trial'); ?></a>
    </div>
  </main>

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
