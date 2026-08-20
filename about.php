<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'DentaTrak';
$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$articleUrls = $appConfig['public_urls'] ?? [];
?><!DOCTYPE html>
<html lang="en">
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

  <meta name="description" content="Learn why DentaTrak was created and how it helps dental practices keep crowns, implants, lab cases, referrals, and other multi-step cases visible from start to finish.">
  <title>About DentaTrak | Dental Case Tracking Software</title>
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
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="DentaTrak home"><img src="images/main.png" alt="DentaTrak" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login">Log In</a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta">Start 90-Day Free Trial</a>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="content no-breadcrumb">
    <h1>About DentaTrak</h1>

    <h2>What DentaTrak Is</h2>

    <p>
      DentaTrak is dental case tracking software for dental practices. It gives every multi-step case (crowns, implants, lab work, referrals, and other treatments that span multiple visits) a status, an owner, and a next step, so nothing gets lost between handoffs.
    </p>

    <h2>Why It Was Built</h2>

    <p>
      DentaTrak was developed by Dr. William Verrillo, a practicing dentist based in Georgia, to solve real breakdowns in case tracking between labs, referrals, and delivery. It was designed from real clinical workflows rather than generic software assumptions, built by someone who has managed these cases from inside a practice, not just studied the problem from the outside.
    </p>

    <h2>The Problem It Solves</h2>

    <p>
      Most dental practices manage complex, multi-step cases without a dedicated system. Case information ends up scattered across memory, sticky notes, spreadsheets, and notes buried in a practice management system. Problems are usually only discovered after they've already cost the practice time, chair capacity, or a patient's trust. DentaTrak exists to give every case a clear status and owner so delays are visible before they become costly. Read more in our <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link">dental case tracking software</a> overview.
    </p>

    <h2>Who It's For</h2>

    <ul>
      <li><strong>Practice owners:</strong> See where cases stall without asking staff for updates, and identify patterns that affect efficiency and revenue.</li>
      <li><strong>Treatment coordinators and staff:</strong> Know exactly which cases need attention today, with clear ownership and fewer dropped handoffs between team members.</li>
    </ul>

    <p>
      DentaTrak is built for practices that handle crowns, bridges, implants, dentures, or other lab-based or referral-dependent treatments: the cases most likely to lose visibility between appointments.
    </p>

    <h2>How It Differs from General Practice-Management Tools</h2>

    <p>
      DentaTrak does not replace a practice management system (PMS). A PMS handles scheduling, billing, and patient records. DentaTrak focuses specifically on the workflow of multi-step cases (status, ownership, and stalled-case visibility) which most PMS platforms were not built to track. DentaTrak works alongside your existing PMS with no data migration required. See our full comparison in <a href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>" class="content-link">dental case tracking software vs. PMS</a>.
    </p>

    <h2>Contact and Support</h2>

    <p>
      For questions about DentaTrak, contact <a href="mailto:support@dentatrak.com" class="content-link">support@dentatrak.com</a>.
    </p>

    <div class="cta-section">
      <h2>See DentaTrak in action</h2>
      <p>Try DentaTrak free for 90 days. Set up your practice and begin tracking cases in minutes.</p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white">Start 90-Day Free Trial</a>
      <p style="margin-top: 16px; font-size: 0.9rem;"><a href="<?= $baseUrl ?>login.php" style="color: rgba(255,255,255,0.75); text-decoration: underline; text-underline-offset: 2px;">Already have an account? Log in</a></p>
    </div>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <img src="images/main.png" alt="DentaTrak" class="footer-logo" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;">
      <div class="footer-links">
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>" class="footer-link">Resources</a>
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link">Privacy</a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link">Terms</a>
        <a href="<?= $baseUrl ?>" class="footer-link">Home</a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.</span>
    </div>
  </footer>
</body>
</html>
