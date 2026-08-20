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

  <meta name="description" content="Track dental implant cases from surgical placement through healing and final restoration. See how to keep multi-provider implant cases from stalling.">
  <title>Implant Case Tracking: From Surgical Placement to Final Restoration | DentaTrak</title>
  <link rel="canonical" href="https://dentatrak.com/implant-case-tracking">

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
    "headline": "Implant Case Tracking: From Surgical Placement to Final Restoration",
    "author": { "@type": "Person", "name": "Dr. William Verrillo" },
    "publisher": { "@type": "Organization", "name": "DentaTrak" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/implant-case-tracking"
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
      { "@type": "ListItem", "position": 3, "name": "Implant Case Tracking", "item": "https://dentatrak.com/implant-case-tracking" }
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
        "name": "Why are implant cases hard to track?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Implant cases typically involve multiple phases (surgical placement, healing, and restoration), often more than one provider, and can run over an extended period. Each of those factors makes it easier for a case to lose visibility between appointments."
        }
      },
      {
        "@type": "Question",
        "name": "Who is typically involved in an implant case?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Depending on the practice, an implant case may involve a surgeon or periodontist for placement and a restorative dentist for the final crown, in addition to a dental lab. Coordination between these parties is a common point of breakdown."
        }
      },
      {
        "@type": "Question",
        "name": "Does case tracking software provide implant treatment timelines?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "No. Healing and treatment timelines are clinical decisions made by the treating provider based on the individual patient. Case tracking software tracks the status and ownership of the case through whatever timeline the clinical team determines. It does not set or recommend timelines."
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
      <a href="<?= $baseUrl ?>" class="nav-logo" aria-label="DentaTrak home"><img src="<?= $baseUrl ?>images/main.png" alt="DentaTrak" style="height: auto; width: auto; max-width: 140px; object-fit: contain; display: block;"></a>
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
      <li aria-current="page">Implant Case Tracking</li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1>Implant Case Tracking: From Surgical Placement to Final Restoration</h1>

    <div class="article-meta">
      <span>By <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span>Published <strong>August 8, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p>
        Implant case tracking means following a case across its full arc, from surgical placement through the healing period, to the restorative phase and final restoration, with clear status and ownership at each stage. Implant cases are among the longest-running and most multi-provider case types in a practice, which makes them especially prone to losing visibility between appointments.
      </p>
    </div>

    <h2>Why Implant Cases Are Difficult to Track</h2>

    <p>
      An implant case is rarely a single, self-contained event. It typically spans multiple phases, may involve more than one provider, and can run for an extended period between visits. Each of those factors is a place where a case can quietly stall without anyone noticing until the patient calls to ask what's next.
    </p>

    <h2>The Implant Case Lifecycle</h2>

    <div class="table-wrap">
      <table class="comparison-table">
        <caption>Timing varies by case and is determined by the treating clinical team. This table describes typical phases, not fixed timelines</caption>
        <thead>
          <tr><th>Phase</th><th>What happens</th><th>Typically involves</th></tr>
        </thead>
        <tbody>
          <tr><td>1. Referral or surgical coordination</td><td>Case is planned and, where applicable, coordinated between the restorative dentist and the surgical provider.</td><td>Restorative dentist, surgeon/periodontist</td></tr>
          <tr><td>2. Placement</td><td>The implant is surgically placed.</td><td>Surgeon/periodontist</td></tr>
          <tr><td>3. Healing period</td><td>The case is inactive from a treatment standpoint while healing occurs, as determined by the clinical team.</td><td>Patient monitoring</td></tr>
          <tr><td>4. Restorative handoff</td><td>The case returns to the restorative dentist to begin the final restoration.</td><td>Restorative dentist</td></tr>
          <tr><td>5. Lab work</td><td>The final crown or restoration is fabricated.</td><td>Dental lab</td></tr>
          <tr><td>6. Final restoration</td><td>The restoration is seated and the case is closed.</td><td>Restorative dentist</td></tr>
        </tbody>
      </table>
    </div>

    <h2>Where Implant Cases Commonly Stall</h2>

    <ul class="checklist">
      <li><strong>The referral or surgical coordination step:</strong> A case sent for surgical placement can lose visibility if there's no clear record of its status while it's with the surgical provider. See our guide to <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link">dental case tracking software</a> for how referral-dependent cases fit into overall tracking.</li>
      <li><strong>The transition out of the healing period:</strong> Because this phase can run long, it's easy for a case to be forgotten until the patient happens to call.</li>
      <li><strong>The restorative handoff:</strong> When a case returns from the surgical provider, someone needs to know to restart the restorative process. This handoff is a common drop-off point.</li>
      <li><strong>Long-running cases in general:</strong> Any case that spans months rather than weeks is more likely to fall out of a team's working memory without a system tracking it.</li>
    </ul>

    <h2>How DentaTrak Tracks Implant Cases</h2>

    <p>
      DentaTrak gives an implant case a status and an owner across its full lifecycle, so a case that's waiting on a surgical provider, in a healing period, or waiting on lab work for the final restoration remains visible rather than falling out of view during a long timeline. This is part of the same underlying case tracking approach used for crown and bridge and lab-based cases.
    </p>

    <h2>Frequently Asked Questions</h2>

    <div class="faq-item">
      <h3>Why are implant cases hard to track?</h3>
      <p>Implant cases typically involve multiple phases (surgical placement, healing, and restoration), often more than one provider, and can run over an extended period. Each of those factors makes it easier for a case to lose visibility between appointments.</p>
    </div>

    <div class="faq-item">
      <h3>Who is typically involved in an implant case?</h3>
      <p>Depending on the practice, an implant case may involve a surgeon or periodontist for placement and a restorative dentist for the final crown, in addition to a dental lab. Coordination between these parties is a common point of breakdown.</p>
    </div>

    <div class="faq-item">
      <h3>Does case tracking software provide implant treatment timelines?</h3>
      <p>No. Healing and treatment timelines are clinical decisions made by the treating provider based on the individual patient. Case tracking software tracks the status and ownership of the case through whatever timeline the clinical team determines. It does not set or recommend timelines.</p>
    </div>

    <div class="related-links">
      <h3>Related resources</h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">Dental Case Tracking Software</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>">Crown and Bridge Case Tracking</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">Dental Lab Case Tracking</a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2>Keep long-running implant cases visible</h2>
      <p>Try DentaTrak free for 90 days. Set up your practice and begin tracking cases in minutes.</p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white">Start 90-Day Free Trial</a>
      <p style="margin-top: 16px; font-size: 0.9rem;"><a href="<?= $baseUrl ?>login.php" style="color: rgba(255,255,255,0.75); text-decoration: underline; text-underline-offset: 2px;">Already have an account? Log in</a></p>
    </div>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <a href="<?= $baseUrl ?>" class="footer-wordmark" aria-label="DentaTrak home"><span class="denta">Denta</span><span class="trak">Trak</span></a>
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
