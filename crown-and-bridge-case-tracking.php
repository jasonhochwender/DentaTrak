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

  <meta name="description" content="Track crown and bridge cases from prep through lab fabrication to final seating. See how to catch delays and reduce remakes before they cost you.">
  <title>Crown and Bridge Case Tracking: From Prep to Final Seat | DentaTrak</title>
  <link rel="canonical" href="https://dentatrak.com/crown-and-bridge-case-tracking">

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
    "headline": "Crown and Bridge Case Tracking: From Prep to Final Seat",
    "author": { "@type": "Person", "name": "Dr. William Verrillo" },
    "publisher": { "@type": "Organization", "name": "DentaTrak" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/crown-and-bridge-case-tracking"
  }
  </script>

  <!-- Structured Data: BreadcrumbList -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://dentatrak.com/" },
      { "@type": "ListItem", "position": 2, "name": "Resources", "item": "https://dentatrak.com/resources" },
      { "@type": "ListItem", "position": 3, "name": "Crown and Bridge Case Tracking", "item": "https://dentatrak.com/crown-and-bridge-case-tracking" }
    ]
  }
  </script>

  <!-- Structured Data: FAQPage -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
      {
        "@type": "Question",
        "name": "What are the stages of a crown or bridge case?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "A typical crown or bridge case moves through preparation, temporary restoration, lab submission, fabrication, return from the lab, try-in where applicable, and final seating. Some cases require rework or a remake at any of these stages."
        }
      },
      {
        "@type": "Question",
        "name": "Where do crown and bridge cases most often stall?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Common stall points include the handoff between prep and lab submission, waiting on the lab to return the case, and the gap between a case being ready and the patient being scheduled for the seat appointment."
        }
      },
      {
        "@type": "Question",
        "name": "Does tracking crown and bridge cases require clinical software?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "No. Case tracking software focuses on workflow visibility — status, ownership, and timing — rather than clinical charting or treatment planning, which remain the role of your clinical and practice management systems."
        }
      }
    ]
  }
  </script>
</head>
<body>
  <!-- Navigation -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="<?= $baseUrl ?>" class="nav-logo"><?php echo htmlspecialchars($appName); ?></a>
      <div class="nav-actions">
        <a href="<?= $baseUrl ?>login.php" class="nav-login">Log In</a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta">Start 90-Day Free Trial</a>
      </div>
    </div>
  </nav>

  <!-- Breadcrumbs -->
  <div class="breadcrumb-bar">
    <ol class="breadcrumb">
      <li><a href="<?= $baseUrl ?>">Home</a></li>
      <li>/</li>
      <li><a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>">Resources</a></li>
      <li>/</li>
      <li aria-current="page">Crown and Bridge Case Tracking</li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1>Crown and Bridge Case Tracking: From Prep to Final Seat</h1>

    <div class="article-meta">
      <span>By <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span>Published <strong>August 8, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p>
        Crown and bridge case tracking means following a case from preparation through lab fabrication to final seating, with a clear record of status and ownership at each stage. This page focuses on workflow visibility and handoffs — not clinical technique or treatment recommendations.
      </p>
    </div>

    <h2>The Crown and Bridge Workflow</h2>

    <p>
      Crown and bridge cases are among the most common multi-step, lab-dependent cases in a general practice. A typical case moves through a sequence of stages, and each stage is a point where a case can either move forward cleanly or stall.
    </p>

    <div class="table-wrap">
      <table class="comparison-table">
        <thead>
          <tr><th>Stage</th><th>What happens</th></tr>
        </thead>
        <tbody>
          <tr><td>1. Preparation</td><td>The tooth is prepared and an impression or digital scan is taken.</td></tr>
          <tr><td>2. Temporary restoration</td><td>A temporary crown or bridge is placed while the final restoration is fabricated.</td></tr>
          <tr><td>3. Lab submission</td><td>The case is sent to the lab with the impression/scan and specifications.</td></tr>
          <tr><td>4. Fabrication</td><td>The lab fabricates the final restoration.</td></tr>
          <tr><td>5. Return</td><td>The completed restoration is returned to the practice.</td></tr>
          <tr><td>6. Try-in (where applicable)</td><td>Fit and appearance are checked before final cementation, particularly for bridges.</td></tr>
          <tr><td>7. Final seat</td><td>The restoration is permanently seated and the case is closed.</td></tr>
        </tbody>
      </table>
    </div>

    <h2>Common Remake and Rework Scenarios</h2>

    <p>
      Not every crown or bridge case goes through the sequence above cleanly. A restoration can come back with a shade or fit issue and need to be remade, or a temporary can need to be redone if a case is delayed. Tracking these scenarios alongside the normal workflow makes it possible to see whether a specific case type or lab has a pattern worth addressing. See our guide to <a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>" class="content-link">dental lab case tracking</a> for more on tracking lab turnaround and remakes generally.
    </p>

    <h2>Where Visibility Matters Most</h2>

    <ol class="workflow-steps">
      <li>
        <strong>Handoff from prep to lab submission</strong>
        The impression or scan needs to actually be sent, with clear ownership of who confirms it went out.
      </li>
      <li>
        <strong>Waiting on the lab</strong>
        Knowing the expected return date — and whether it's been missed — before the patient's appointment arrives.
      </li>
      <li>
        <strong>Case ready but not yet scheduled</strong>
        A completed case that hasn't been matched to a scheduled seat appointment can sit unnoticed.
      </li>
      <li>
        <strong>Remake decision and re-submission</strong>
        If a case needs to be redone, the record should reflect that clearly rather than restarting from scratch with no history.
      </li>
    </ol>

    <h2>How DentaTrak Tracks Crown and Bridge Cases</h2>

    <p>
      DentaTrak gives each crown and bridge case a status, an owner, and a next step from the initial prep through final seat, including whether it's currently waiting on the lab and whether that wait has gone on longer than expected. This uses the same underlying case record described in our <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link">dental case tracking software</a> overview.
    </p>

    <h2>Frequently Asked Questions</h2>

    <div class="faq-item">
      <h3>What are the stages of a crown or bridge case?</h3>
      <p>A typical crown or bridge case moves through preparation, temporary restoration, lab submission, fabrication, return from the lab, try-in where applicable, and final seating. Some cases require rework or a remake at any of these stages.</p>
    </div>

    <div class="faq-item">
      <h3>Where do crown and bridge cases most often stall?</h3>
      <p>Common stall points include the handoff between prep and lab submission, waiting on the lab to return the case, and the gap between a case being ready and the patient being scheduled for the seat appointment.</p>
    </div>

    <div class="faq-item">
      <h3>Does tracking crown and bridge cases require clinical software?</h3>
      <p>No. Case tracking software focuses on workflow visibility — status, ownership, and timing — rather than clinical charting or treatment planning, which remain the role of your clinical and practice management systems.</p>
    </div>

    <div class="related-links">
      <h3>Related resources</h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">Dental Case Tracking Software</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">Dental Lab Case Tracking</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>">Implant Case Tracking</a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2>Keep every crown and bridge case visible</h2>
      <p>Try DentaTrak free for 90 days. Set up your practice and begin tracking cases in minutes.</p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white">Start 90-Day Free Trial</a>
      <p style="margin-top: 16px; font-size: 0.9rem;"><a href="<?= $baseUrl ?>login.php" style="color: rgba(255,255,255,0.75); text-decoration: underline; text-underline-offset: 2px;">Already have an account? Log in</a></p>
    </div>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <span class="footer-logo"><?php echo htmlspecialchars($appName); ?></span>
      <div class="footer-links">
        <a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>" class="footer-link">About</a>
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
