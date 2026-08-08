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
  
  <meta name="description" content="DentaTrak is dental case tracking software for dental practices. Track every crown, implant, and lab case from prep to delivery. Start a 90-day free trial.">
  <title>DentaTrak - Dental Case Tracking Software for Dental Practices</title>
  <link rel="canonical" href="https://dentatrak.com/">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Structured Data: Organization -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "DentaTrak",
    "url": "https://dentatrak.com/",
    "email": "support@dentatrak.com"
  }
  </script>

  <!-- Structured Data: WebSite -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "DentaTrak",
    "url": "https://dentatrak.com/"
  }
  </script>

  <!-- Structured Data: SoftwareApplication (pricing mirrors the Pricing section on this page) -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": "DentaTrak",
    "applicationCategory": "BusinessApplication",
    "operatingSystem": "Web",
    "description": "DentaTrak is dental case tracking software for dental practices. It tracks multi-step cases such as crowns, implants, and lab work from preparation through delivery.",
    "url": "https://dentatrak.com/",
    "offers": [
      {
        "@type": "Offer",
        "name": "Operate",
        "price": "495.00",
        "priceCurrency": "USD",
        "url": "https://dentatrak.com/#pricing"
      },
      {
        "@type": "Offer",
        "name": "Control",
        "price": "795.00",
        "priceCurrency": "USD",
        "url": "https://dentatrak.com/#pricing"
      }
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
      --shadow-large: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
      --radius-sm: 6px;
      --radius-md: 8px;
      --radius-lg: 12px;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      color: var(--text-primary);
      line-height: 1.6;
      background: var(--background-white);
    }

    /* Inline text links */
    .content-link {
      color: var(--primary-color);
      text-decoration: underline;
      text-underline-offset: 2px;
      font-weight: 500;
      transition: color 0.2s;
    }

    .content-link:hover {
      color: var(--primary-dark);
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

    .nav-logo {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--primary-color);
      text-decoration: none;
      letter-spacing: -0.02em;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 32px;
    }

    .nav-link {
      font-size: 0.9rem;
      font-weight: 500;
      color: var(--text-secondary);
      text-decoration: none;
      transition: color 0.2s;
    }

    .nav-link:hover {
      color: var(--primary-color);
    }

    .nav-login {
      font-size: 0.9rem;
      font-weight: 500;
      color: var(--text-secondary);
      text-decoration: none;
      transition: color 0.2s;
    }

    .nav-login:hover {
      color: var(--primary-color);
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

    .nav-cta:hover {
      background: var(--primary-light);
    }

    /* Hero Section */
    .hero {
      padding: 140px 24px 100px;
      background: linear-gradient(180deg, var(--background-subtle) 0%, var(--background-white) 100%);
    }

    .hero-inner {
      max-width: 800px;
      margin: 0 auto;
      text-align: center;
    }

    .hero h1 {
      font-size: 2.75rem;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: -0.03em;
      color: var(--text-primary);
      margin-bottom: 24px;
    }

    .hero-subtitle {
      font-size: 1.125rem;
      color: var(--text-secondary);
      max-width: 600px;
      margin: 0 auto 40px;
      line-height: 1.7;
    }

    .hero-ctas {
      display: flex;
      justify-content: center;
      gap: 16px;
      flex-wrap: wrap;
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      padding: 14px 32px;
      background: var(--primary-color);
      color: white;
      font-size: 0.95rem;
      font-weight: 600;
      border-radius: var(--radius-md);
      text-decoration: none;
      transition: background 0.2s, transform 0.2s;
    }

    .btn-primary:hover {
      background: var(--primary-light);
      transform: translateY(-1px);
    }

    .btn-secondary {
      display: inline-flex;
      align-items: center;
      padding: 14px 32px;
      background: transparent;
      color: var(--text-secondary);
      font-size: 0.95rem;
      font-weight: 600;
      border: 1px solid var(--border-medium);
      border-radius: var(--radius-md);
      text-decoration: none;
      transition: all 0.2s;
    }

    .btn-secondary:hover {
      border-color: var(--primary-color);
      color: var(--primary-color);
    }

    /* Section Base */
    .section {
      padding: 100px 24px;
    }

    .section-inner {
      max-width: 1000px;
      margin: 0 auto;
    }

    .section-header {
      text-align: center;
      margin-bottom: 60px;
    }

    .section-label {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--primary-color);
      margin-bottom: 12px;
    }

    .section h2 {
      font-size: 2rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: var(--text-primary);
      margin-bottom: 16px;
    }

    .section-subtitle {
      font-size: 1.05rem;
      color: var(--text-secondary);
      max-width: 600px;
      margin: 0 auto;
    }

    /* Problem Section */
    .problem {
      background: var(--background-subtle);
    }

    .problem-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 24px;
    }

    .problem-card {
      background: var(--background-white);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 32px;
    }

    .problem-card h3 {
      font-size: 1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 12px;
    }

    .problem-card p {
      font-size: 0.925rem;
      color: var(--text-secondary);
      line-height: 1.7;
    }

    /* Consequence Bridge */
    .consequence-bridge {
      margin-top: 48px;
      padding: 24px 32px;
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      border-left: 4px solid #d97706;
      border-radius: 0 var(--radius-md) var(--radius-md) 0;
    }

    .consequence-bridge p {
      font-size: 1rem;
      color: #92400e;
      line-height: 1.7;
      margin: 0;
      font-weight: 500;
    }

    /* Solution Section */
    .solution-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 32px;
    }

    .solution-item {
      padding: 32px;
      background: var(--background-subtle);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-light);
    }

    .solution-icon {
      width: 48px;
      height: 48px;
      background: var(--primary-color);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
    }

    .solution-icon svg {
      width: 24px;
      height: 24px;
      stroke: white;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .solution-item h3 {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 12px;
    }

    .solution-item p {
      font-size: 0.925rem;
      color: var(--text-secondary);
      line-height: 1.7;
    }

    /* Credibility Section */
    .credibility {
      background: var(--background-subtle);
    }

    .credibility-content {
      max-width: 700px;
      margin: 0 auto;
      text-align: center;
    }

    .credibility-content h2 {
      font-size: 1.5rem;
      font-weight: 600;
      letter-spacing: -0.02em;
      color: var(--text-primary);
      margin-bottom: 20px;
    }

    .credibility-content p {
      font-size: 1rem;
      color: var(--text-secondary);
      line-height: 1.8;
      margin-bottom: 12px;
    }

    .credibility-content p:last-child {
      margin-bottom: 0;
    }

    /* Audience Sections */
    .audience {
      background: var(--background-white);
    }

    .audience-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
      gap: 40px;
    }

    .audience-card {
      background: var(--background-white);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 40px;
    }

    .audience-card h3 {
      font-size: 1.25rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 20px;
    }

    .audience-list {
      list-style: none;
    }

    .audience-list li {
      position: relative;
      padding-left: 24px;
      margin-bottom: 14px;
      font-size: 0.95rem;
      color: var(--text-secondary);
      line-height: 1.6;
    }

    .audience-list li::before {
      content: '';
      position: absolute;
      left: 0;
      top: 10px;
      width: 8px;
      height: 8px;
      background: var(--primary-color);
      border-radius: 50%;
    }

    /* Integration Section */
    .integration-content {
      max-width: 700px;
      margin: 0 auto;
      text-align: center;
    }

    .integration-content p {
      font-size: 1.05rem;
      color: var(--text-secondary);
      line-height: 1.8;
      margin-bottom: 20px;
    }

    .integration-note {
      display: inline-block;
      padding: 16px 28px;
      background: var(--background-subtle);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      font-size: 0.9rem;
      color: var(--text-secondary);
    }

    /* Pricing Section */
    .pricing {
      background: var(--background-subtle);
    }

    .pricing-intro {
      text-align: center;
      font-size: 1rem;
      color: var(--text-secondary);
      max-width: 600px;
      margin: -32px auto 48px;
      line-height: 1.7;
    }

    .pricing-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 32px;
      max-width: 840px;
      margin: 0 auto;
    }

    .pricing-card {
      background: var(--background-white);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 40px;
    }

    .pricing-card h3 {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 20px;
    }

    .pricing-price-primary {
      font-size: 2rem;
      font-weight: 700;
      color: var(--text-primary);
      letter-spacing: -0.02em;
      margin-bottom: 4px;
    }

    .pricing-price-annual {
      font-size: 0.9rem;
      color: var(--text-secondary);
      margin-bottom: 4px;
    }

    .pricing-price-note {
      font-size: 0.825rem;
      color: var(--primary-color);
      font-weight: 500;
      margin-bottom: 24px;
    }

    .pricing-divider {
      border: none;
      border-top: 1px solid var(--border-light);
      margin: 24px 0;
    }

    .pricing-card p {
      font-size: 0.925rem;
      color: var(--text-secondary);
      line-height: 1.7;
      margin-bottom: 24px;
    }

    .pricing-card .btn-primary {
      width: 100%;
      justify-content: center;
    }

    .pricing-card-featured {
      border: 2px solid var(--primary-color);
    }

    .pricing-card-featured h3 {
      color: var(--primary-color);
    }

    /* Trust Section */
    .trust-grid {
      display: flex;
      justify-content: center;
      gap: 48px;
      flex-wrap: wrap;
    }

    .trust-item {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.9rem;
      font-weight: 500;
      color: var(--text-secondary);
    }

    .trust-item svg {
      width: 20px;
      height: 20px;
      stroke: var(--primary-color);
      fill: none;
      stroke-width: 2;
    }

    /* Final CTA */
    .final-cta {
      background: var(--primary-color);
      text-align: center;
    }

    .final-cta h2 {
      color: white;
      margin-bottom: 16px;
    }

    .final-cta p {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1rem;
      margin-bottom: 32px;
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
      max-width: 1000px;
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
      gap: 32px;
    }

    .footer-link {
      font-size: 0.875rem;
      color: var(--text-secondary);
      text-decoration: none;
      transition: color 0.2s;
    }

    .footer-link:hover {
      color: var(--primary-color);
    }

    .footer-copy {
      font-size: 0.8rem;
      color: var(--text-light);
    }

    /* Hero supporting note */
    .hero-note {
      font-size: 0.9rem;
      color: var(--text-light);
      margin-top: 20px;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .hero h1 {
        font-size: 2rem;
      }

      .hero-subtitle {
        font-size: 1rem;
      }

      .section h2 {
        font-size: 1.5rem;
      }

      .nav-links {
        gap: 16px;
      }

      .nav-link,
      .nav-login {
        display: none;
      }

      .audience-grid {
        grid-template-columns: 1fr;
      }

      .footer-inner {
        flex-direction: column;
        text-align: center;
      }

    }
  </style>
</head>
<body>
  <!-- Navigation -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="<?= $baseUrl ?>" class="nav-logo">DentaTrak</a>
      <div class="nav-links">
        <a href="#problem" class="nav-link">The Problem</a>
        <a href="#how-it-works" class="nav-link">How It Works</a>
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>" class="nav-link">Resources</a>
        <a href="#pricing" class="nav-link">Pricing</a>
        <a href="<?= $baseUrl ?>login.php" class="nav-login">Log In</a>
        <a href="<?= $baseUrl ?>login.php" class="nav-cta">Start 90-Day Free Trial</a>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section id="hero" class="hero">
    <div class="hero-inner">
      <h1>Dental case tracking software for dental practices</h1>
      <p class="hero-subtitle">
        Track every crown, implant, and lab case from prep to delivery so nothing gets lost between labs, referrals, and patient scheduling.
      </p>
      <p class="hero-subtitle" style="margin-top: 8px;">
        DentaTrak gives every case a status, owner, and next step, helping your practice identify delays before they disrupt patient care, scheduling, or revenue.
      </p>
      <div class="hero-ctas">
        <a href="<?= $baseUrl ?>login.php" class="btn-primary">Start 90-Day Free Trial</a>
        <a href="<?= $baseUrl ?>login.php" class="btn-secondary">Log In</a>
      </div>
      <p class="hero-note">Try DentaTrak free for 90 days. Set up your practice and begin tracking cases in minutes.</p>
    </div>
  </section>

  <!-- Problem Section -->
  <section id="problem" class="section problem">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">The Problem</p>
        <h2>Complex cases fail in predictable ways</h2>
        <p class="section-subtitle">
          Dental practices do not have a reliable system for <a href="<?= $baseUrl . ($articleUrls['article_how_to_track'] ?? 'how-to-track-dental-cases') ?>" class="content-link">tracking multi-step cases</a> across labs, referrals, and internal handoffs. Implants, prosthodontics, orthodontics—these cases require coordination, yet most practices lack proper dental case tracking and rely on systems that weren't built for multi-step workflows.
        </p>
      </div>
      <div class="problem-grid">
        <div class="problem-card">
          <h3>Tracked in memory</h3>
          <p>Cases live in the dentist's head or a coordinator's notes. When someone is out, knowledge disappears—and cases stall.</p>
        </div>
        <div class="problem-card">
          <h3>Lost between handoffs</h3>
          <p>Lab sends it back. Patient reschedules. Referral delays. Each handoff is a chance for a case to slip through the cracks.</p>
        </div>
        <div class="problem-card">
          <h3>Invisible until it's expensive</h3>
          <p>By the time you notice a stalled case, you've already lost chair time, delayed revenue, or triggered a remake.</p>
        </div>
        <div class="problem-card">
          <h3>PMS wasn't built for this</h3>
          <p>Practice management software handles scheduling and billing—not dental lab case tracking or multi-step workflows with external dependencies.</p>
        </div>
      </div>
      <!-- Consequence Bridge -->
      <div class="consequence-bridge">
        <p>Every stalled case is revenue waiting to be collected. Every missed handoff risks a remake. These problems compound quietly—until they show up in your schedule, your lab bills, or your patient's frustration.</p>
      </div>
    </div>
  </section>

  <!-- Solution Section -->
  <section id="solution" class="section">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">How DentaTrak Helps</p>
        <h2>Catch problems before they cost you</h2>
        <p class="section-subtitle">
          DentaTrak is dental case tracking software designed to manage crown cases, implant workflows, and lab coordination across the full lifecycle of treatment. Every case has a status, an owner, and a clear next step. Nothing slips through.
        </p>
      </div>
      <div class="solution-grid">
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/><path d="M9 12l2 2 4-4"/></svg>
          </div>
          <h3>Full lifecycle visibility</h3>
          <p>See every case from submission to delivery. Know exactly where it is, how long it's been there, and what's next.</p>
        </div>
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
          </div>
          <h3>Clear ownership</h3>
          <p>Every case has an owner and a next action. No confusion about who's responsible or what needs to happen.</p>
        </div>
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          </div>
          <h3>External dependency tracking</h3>
          <p>Know which cases are waiting on labs, referrals, or patients—and for how long. Intervene before delays become problems.</p>
        </div>
      </div>
      <p style="text-align: center; margin-top: 32px; font-size: 0.95rem; color: var(--text-secondary);">
        For a deeper look at how dental case tracking software works, see our <a href="<?= $baseUrl . ($articleUrls['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software') ?>" class="content-link">detailed guide on dental case tracking software</a>.
      </p>
    </div>
  </section>

  <!-- How It Works Section -->
  <section id="how-it-works" class="section">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">How DentaTrak Works</p>
        <h2>A simple workflow for tracking multi-step dental cases from prep to delivery</h2>
      </div>
      <div class="solution-grid">
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
          </div>
          <h3>1. Enter the case when treatment begins</h3>
          <p>Add the patient, case type, and relevant lab or referral details.</p>
        </div>
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          </div>
          <h3>2. Assign ownership and the next step</h3>
          <p>Give every case a responsible owner and a clear next action.</p>
        </div>
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
          </div>
          <h3>3. Track external dependencies</h3>
          <p>See which cases are waiting on labs, referrals, patients, or other outside actions.</p>
        </div>
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
          </div>
          <h3>4. Monitor through delivery</h3>
          <p>Follow each case through its full workflow and mark it complete when delivered.</p>
        </div>
        <div class="solution-item">
          <div class="solution-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
          </div>
          <h3>5. Intervene early</h3>
          <p>Identify cases that are stalled or at risk before they affect scheduling, patient care, or revenue.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Credibility Section -->
  <section class="section credibility">
    <div class="section-inner">
      <div class="credibility-content">
        <h2>Built by a dentist, for dentists</h2>
        <p>
          DentaTrak was developed by Dr. William Verrillo, a practicing dentist based in Georgia, to solve real breakdowns in case tracking between labs, referrals, and delivery.
        </p>
        <p>
          This product is designed from real clinical workflows, not generic software assumptions.
        </p>
      </div>
    </div>
  </section>

  <!-- Audience Section -->
  <section class="section audience">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">Who It's For</p>
        <h2>Built for practices that do complex work</h2>
      </div>
      <div class="audience-grid">
        <div class="audience-card">
          <h3>For practice owners</h3>
          <ul class="audience-list">
            <li>See where cases stall without asking staff</li>
            <li>Identify bottlenecks across your workflow</li>
            <li>Reduce remakes and delays before they cost you</li>
            <li>Know which cases are at risk right now</li>
            <li>Control and awareness, not micromanagement</li>
          </ul>
        </div>
        <div class="audience-card">
          <h3>For coordinators and staff</h3>
          <ul class="audience-list">
            <li>Clear view of what needs attention today</li>
            <li>Know exactly who owns each case</li>
            <li>Fewer dropped handoffs between team members</li>
            <li>Accountability without confusion</li>
            <li>Less time chasing down case status</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- Case Types Section -->
  <section class="section">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">Cases DentaTrak Helps Track</p>
        <h2>Built for complex dental workflows</h2>
        <p class="section-subtitle">
          DentaTrak is designed to support common multi-step dental workflows that are difficult to track in traditional systems.
        </p>
      </div>
      <div class="audience-grid">
        <div class="audience-card">
          <ul class="audience-list">
            <li>Crown and bridge cases</li>
            <li>Implant workflows</li>
            <li>Lab-based restorations</li>
            <li>Referral-dependent treatments</li>
            <li>Multi-appointment procedures</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- Integration Section -->
  <section class="section">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">How It Fits</p>
        <h2>Works alongside your existing systems</h2>
      </div>
      <div class="integration-content">
        <p>
          DentaTrak provides a dedicated system for dental case tracking that complements existing practice management software by focusing specifically on multi-step case workflows.
        </p>
        <p>
          DentaTrak does not replace your practice management software. It fills a gap that PMS systems were never designed to address: dental workflow tracking for multi-step cases across labs, referrals, and internal handoffs.
        </p>
        <p>
          Your PMS handles scheduling and billing. DentaTrak handles case visibility.
        </p>
        <div class="integration-note">
          No data migration required. Start tracking cases immediately.
        </div>
      </div>
    </div>
  </section>

  <!-- Pricing Section -->
  <section id="pricing" class="section pricing">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">Pricing</p>
        <h2>Two plans, one clear view of every case</h2>
      </div>
      <p class="pricing-intro">Start with a 90-day free trial. Choose monthly billing or save the equivalent of two months with annual billing.</p>
      <div class="pricing-grid">
        <div class="pricing-card">
          <h3>Operate</h3>
          <p class="pricing-price-primary">$495<span style="font-size: 1rem; font-weight: 500; color: var(--text-secondary);">/month</span></p>
          <p class="pricing-price-annual">or $4,950/year billed annually</p>
          <p class="pricing-price-note">Two months free with annual billing</p>
          <hr class="pricing-divider">
          <p>For coordinators and teams that need a reliable system for tracking cases, assigning ownership, managing handoffs, and knowing what needs attention today.</p>
          <ul style="list-style: none; padding: 0; margin: 12px 0 20px; text-align: left; font-size: 0.95rem; color: var(--text-secondary);">
            <li style="padding: 4px 0;">&#10003;&nbsp; Unlimited cases</li>
            <li style="padding: 4px 0;">&#10003;&nbsp; Up to 5 users</li>
          </ul>
          <a href="<?= $baseUrl ?>login.php" class="btn-primary">Start 90-Day Free Trial</a>
        </div>
        <div class="pricing-card pricing-card-featured">
          <h3>Control</h3>
          <p class="pricing-price-primary">$795<span style="font-size: 1rem; font-weight: 500; color: var(--text-secondary);">/month</span></p>
          <p class="pricing-price-annual">or $7,950/year billed annually</p>
          <p class="pricing-price-note">Two months free with annual billing</p>
          <hr class="pricing-divider">
          <p>For practice owners who need greater visibility into stalled cases, workflow risks, and bottlenecks, with Insights and Smart Recommendations that help identify where to intervene before delays become costly.</p>
          <ul style="list-style: none; padding: 0; margin: 12px 0 20px; text-align: left; font-size: 0.95rem; color: var(--text-secondary);">
            <li style="padding: 4px 0;">&#10003;&nbsp; Unlimited cases</li>
            <li style="padding: 4px 0;">&#10003;&nbsp; Unlimited users</li>
            <li style="padding: 4px 0;">&#10003;&nbsp; Insights and Smart Recommendations</li>
          </ul>
          <a href="<?= $baseUrl ?>login.php" class="btn-primary">Start 90-Day Free Trial</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Trust Section -->
  <section class="section">
    <div class="section-inner">
      <div class="section-header">
        <p class="section-label">Trust & Security</p>
        <h2>Built for healthcare practices, by a healthcare professional</h2>
      </div>
      <div class="trust-grid">
        <div class="trust-item">
          <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>HIPAA-aligned data handling</span>
        </div>
        <div class="trust-item">
          <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          <span>Encrypted data storage</span>
        </div>
        <div class="trust-item">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Google OAuth supported</span>
        </div>
      </div>
    </div>
  </section>

  <!-- Final CTA -->
  <section class="section final-cta">
    <div class="section-inner">
      <h2>Bring every case into view</h2>
      <p>Stop relying on memory, disconnected notes, and manual follow-up. Give your team one place to see what is happening, who owns the next step, and which cases need attention.</p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white">Start Your 90-Day Free Trial</a>
      <p style="margin-top: 20px; font-size: 0.9rem;">
        <a href="<?= $baseUrl ?>login.php" style="color: rgba(255,255,255,0.75); text-decoration: underline; text-underline-offset: 2px;">Already have an account? Log in</a>
      </p>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <div class="footer-logo">DentaTrak</div>
      <div class="footer-links">
        <a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>" class="footer-link">About</a>
        <a href="<?= $baseUrl . ($articleUrls['page_resources'] ?? 'resources') ?>" class="footer-link">Resources</a>
        <a href="<?= $baseUrl ?>privacy.php" class="footer-link">Privacy Policy</a>
        <a href="<?= $baseUrl ?>terms.php" class="footer-link">Terms of Service</a>
        <a href="mailto:support@dentatrak.com" class="footer-link">Contact</a>
      </div>
      <div class="footer-copy">&copy; <?php echo date('Y'); ?> DentaTrak. All rights reserved.</div>
    </div>
  </footer>

</body>
</html>
