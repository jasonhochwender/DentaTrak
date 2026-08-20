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

  <meta name="description" content="Guides on dental case tracking, dental lab workflows, case types like crowns, bridges, and implants, and how case tracking software compares to spreadsheets and PMS platforms.">
  <title>Dental Case Tracking Resources | DentaTrak</title>
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
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="DentaTrak home"><img src="images/main.png" alt="DentaTrak" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login">Log In</a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta">Start 90-Day Free Trial</a>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="content no-breadcrumb" style="max-width: 1000px;">
    <h1>Dental Case Tracking Resources</h1>
    <p>
      Practical guides on tracking dental cases across labs, referrals, and internal handoffs, organized by topic.
    </p>

    <div class="resource-group">
      <h2>Dental Case Tracking</h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">
          <h3>Dental Case Tracking Software</h3>
          <p>What dental case tracking software is, why practices need it, and how DentaTrak works.</p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>">
          <h3>How to Track Dental Cases Without Losing Them</h3>
          <p>A practical, step-by-step process for tracking cases from start to finish.</p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_visual_workflow'] ?? 'visual-dental-case-workflow') ?>">
          <h3>Why Visual Workflow Management Works for Complex Dental Cases</h3>
          <p>Why multi-stage cases are processes, not records, and how a visual workflow shows where every case stands.</p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2>Dental Lab Workflows</h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">
          <h3>Dental Lab Case Tracking</h3>
          <p>Tracking cases sent to external labs (shipping, returns, overdue cases, and remakes).</p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2>Case Types</h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>">
          <h3>Crown and Bridge Case Tracking</h3>
          <p>The crown and bridge workflow from prep to final seat, and where these cases stall.</p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>">
          <h3>Implant Case Tracking</h3>
          <p>Tracking implant cases across surgical placement, healing, and restoration.</p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2>Practice Operations</h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_dental_remake_cost'] ?? 'dental-remake-cost') ?>">
          <h3>What Dental Remakes Really Cost Your Practice</h3>
          <p>Calculate the hidden cost of remakes, from chair and staff time to lab and shipping expenses, and see what reducing your remake rate could mean for your practice.</p>
        </a>
      </div>
    </div>

    <div class="resource-group">
      <h2>Comparisons</h2>
      <div class="resource-grid">
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_vs_pms'] ?? 'dental-case-tracking-software-vs-pms') ?>">
          <h3>Dental Case Tracking Software vs. PMS</h3>
          <p>What a PMS manages, what dedicated case tracking manages, and whether you need both.</p>
        </a>
        <a class="resource-card" href="<?= $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets') ?>">
          <h3>Dental Case Tracking Software vs. Spreadsheets</h3>
          <p>When spreadsheets stop working and what dedicated software does differently.</p>
        </a>
      </div>
    </div>

    <div class="cta-section">
      <h2>Ready to track your cases?</h2>
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
        <a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>" class="footer-link">About</a>
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link">Privacy</a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link">Terms</a>
        <a href="<?= $baseUrl ?>" class="footer-link">Home</a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.</span>
    </div>
  </footer>
</body>
</html>
