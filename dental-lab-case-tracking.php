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

  <meta name="description" content="Track every case sent to the dental lab — shipping dates, expected returns, overdue cases, and remakes — in one place. See how DentaTrak keeps lab cases visible.">
  <title>Dental Lab Case Tracking for Dental Practices | DentaTrak</title>
  <link rel="canonical" href="https://dentatrak.com/dental-lab-case-tracking">

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
    "headline": "Dental Lab Case Tracking for Dental Practices",
    "author": { "@type": "Person", "name": "Dr. William Verrillo" },
    "publisher": { "@type": "Organization", "name": "DentaTrak" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/dental-lab-case-tracking"
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
      { "@type": "ListItem", "position": 3, "name": "Dental Lab Case Tracking", "item": "https://dentatrak.com/dental-lab-case-tracking" }
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
        "name": "What is dental lab case tracking?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Dental lab case tracking is the practice of monitoring a case from the moment it's sent to an external lab through fabrication, return, and delivery to the patient — including sent date, expected return date, actual return, and whether it's overdue."
        }
      },
      {
        "@type": "Question",
        "name": "How is lab case tracking different from general dental case tracking?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Dental lab case tracking focuses specifically on the practice's relationship with external labs: shipping, turnaround time, and remakes. General dental case tracking also covers referrals, internal handoffs, and scheduling dependencies that don't involve a lab."
        }
      },
      {
        "@type": "Question",
        "name": "What causes dental lab cases to get lost or delayed?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Common causes include no record of when a case was sent or expected back, no alert when a case is overdue, and no visibility for the front desk when a case is ready for the patient to be scheduled."
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
      <li aria-current="page">Dental Lab Case Tracking</li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1>Dental Lab Case Tracking for Dental Practices</h1>

    <div class="article-meta">
      <span>By <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span>Published <strong>August 8, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p>
        Dental lab case tracking is the practice of monitoring a case from the moment it's sent to an external lab through fabrication, return, and delivery to the patient. It covers the sent date, expected return date, actual return, and whether a case is overdue — the specific information practices need to catch lab delays before they affect a patient's appointment.
      </p>
    </div>

    <h2>Why Lab Cases Need Their Own Tracking</h2>

    <p>
      Once a case leaves the practice for an external lab, it's out of direct control. Without a clear record of when it was sent and when it's expected back, a case sitting at the lab can go unnoticed until a patient arrives for an appointment and the restoration isn't ready.
    </p>

    <h2>What to Track for Every Lab Case</h2>

    <ul class="checklist">
      <li><strong>Sent date:</strong> When the case was shipped to the lab.</li>
      <li><strong>Expected return date:</strong> When the lab has committed to sending it back.</li>
      <li><strong>Actual return:</strong> When the case actually arrived back at the practice.</li>
      <li><strong>Overdue status:</strong> Whether the case has passed its expected return date without being flagged.</li>
      <li><strong>Remakes:</strong> Whether a case had to be sent back to the lab a second time, and why.</li>
      <li><strong>Patient scheduling dependency:</strong> Whether the front desk has been notified that a case is ready to schedule.</li>
    </ul>

    <h2>Signs Your Dental Lab Case Tracking Process Is Failing</h2>

    <ol class="workflow-steps">
      <li>
        <strong>The front desk finds out a case isn't ready when the patient arrives</strong>
        This is the clearest sign there's no visibility into lab status before the appointment.
      </li>
      <li>
        <strong>No one can say how many cases are currently at the lab</strong>
        If that number isn't known without checking multiple places, it isn't being tracked.
      </li>
      <li>
        <strong>Overdue cases are only caught by accident</strong>
        A case that's a week late should be visible on its own, not discovered when someone happens to ask about it.
      </li>
      <li>
        <strong>Remakes aren't recorded anywhere</strong>
        Without a record of remakes, a practice can't see whether a specific lab or case type is causing repeated problems.
      </li>
      <li>
        <strong>Handoffs between the practice and the lab rely on phone calls or email threads</strong>
        Information about a case's status lives in someone's inbox instead of a shared system.
      </li>
    </ol>

    <h2>How DentaTrak Tracks Lab Dependencies</h2>

    <p>
      DentaTrak tracks lab-dependent cases as part of its full case lifecycle: the sent date and expected return date are recorded when a case goes out, and cases that pass their expected return date are surfaced automatically rather than requiring someone to check. This is part of the same case record used to track ownership, status, and handoffs across the practice — see the full <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link">dental case tracking software</a> overview for how this fits together.
    </p>

    <p>
      Crown and bridge cases are among the most common lab-dependent case types in a general practice. See our guide to <a href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>" class="content-link">crown and bridge case tracking</a> for the specific workflow from prep to final seat.
    </p>

    <h2>Reducing Remakes Through Better Visibility</h2>

    <p>
      Not every remake is preventable, but some are the direct result of a breakdown in communication rather than a clinical issue — a shade mismatch that wasn't caught before a case went out, or a case that sat too long and needed to be redone because circumstances changed. Recording remakes alongside case status makes it possible to see whether a pattern exists.
    </p>

    <h2>Frequently Asked Questions</h2>

    <div class="faq-item">
      <h3>What is dental lab case tracking?</h3>
      <p>Dental lab case tracking is the practice of monitoring a case from the moment it's sent to an external lab through fabrication, return, and delivery to the patient — including sent date, expected return date, actual return, and whether it's overdue.</p>
    </div>

    <div class="faq-item">
      <h3>How is lab case tracking different from general dental case tracking?</h3>
      <p>Dental lab case tracking focuses specifically on the practice's relationship with external labs: shipping, turnaround time, and remakes. General dental case tracking also covers referrals, internal handoffs, and scheduling dependencies that don't involve a lab.</p>
    </div>

    <div class="faq-item">
      <h3>What causes dental lab cases to get lost or delayed?</h3>
      <p>Common causes include no record of when a case was sent or expected back, no alert when a case is overdue, and no visibility for the front desk when a case is ready for the patient to be scheduled.</p>
    </div>

    <div class="related-links">
      <h3>Related resources</h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>">Dental Case Tracking Software</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>">Crown and Bridge Case Tracking</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_comparison'] ?? 'dental-case-tracking-vs-spreadsheets') ?>">Dental Case Tracking Software vs. Spreadsheets</a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2>Stop losing track of lab cases</h2>
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
