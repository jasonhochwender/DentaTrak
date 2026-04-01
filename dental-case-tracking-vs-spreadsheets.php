<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'Dentatrak';
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
  
  <meta name="description" content="Compare dental case tracking software vs spreadsheets. Learn when to move beyond manual tracking methods for crowns, implants, and lab cases.">
  <title>Dental Case Tracking Software vs Spreadsheets | Dentatrak</title>
  <link rel="canonical" href="https://dentatrak.com/dental-case-tracking-vs-spreadsheets">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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

    .nav-logo {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--primary-color);
      text-decoration: none;
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
      margin-bottom: 24px;
    }

    .content h2 {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-top: 48px;
      margin-bottom: 16px;
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

    .highlight-box {
      background: var(--background-subtle);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 32px;
      margin: 32px 0;
    }

    .highlight-box h3 {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 12px;
    }

    .highlight-box p {
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
    }
  </style>
</head>
<body>
  <!-- Navigation -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="/" class="nav-logo"><?php echo htmlspecialchars($appName); ?></a>
      <a href="/#waitlist" class="nav-cta">Get launch updates</a>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="content">
    <h1>Dental Case Tracking Software vs Spreadsheets</h1>
    
    <p>
      Many dental practices use spreadsheets or notes to track cases. It's a natural starting point—spreadsheets are familiar, flexible, and free. But as case volume grows and workflows become more complex, these methods start to break down.
    </p>
    
    <p>
      This page compares spreadsheet-based tracking with dedicated dental case tracking software, and explains when it makes sense to move beyond manual methods.
    </p>

    <h2>How Spreadsheets Are Used for Case Tracking</h2>
    
    <p>
      Spreadsheets are often the first tool practices reach for when they need to track cases outside their PMS. Common approaches include:
    </p>
    
    <ul>
      <li><strong>Basic tracking:</strong> A simple list of active cases with columns for patient name, case type, lab, and status. Someone updates it when things change.</li>
      <li><strong>Manual updates:</strong> Staff add rows when cases start and update status as cases move through stages. This requires discipline and consistency.</li>
      <li><strong>Shared documents:</strong> The spreadsheet lives in Google Sheets or a shared drive so multiple people can access it. In theory, everyone sees the same information.</li>
    </ul>
    
    <p>
      For a small practice with a handful of active cases, this can work. The problems emerge when volume increases, more people are involved, or cases span longer timeframes.
    </p>

    <h2>Where Spreadsheets Fail</h2>
    
    <p>
      Spreadsheets weren't designed for case tracking. They're general-purpose tools, and that flexibility becomes a liability when you need structure and reliability.
    </p>
    
    <ul>
      <li><strong>No ownership:</strong> A spreadsheet shows data, but it doesn't enforce accountability. There's no clear assignment of who is responsible for each case or each step.</li>
      <li><strong>No real-time visibility:</strong> Spreadsheets are only as current as the last update. If someone forgets to update a row, the information is stale—and no one knows it.</li>
      <li><strong>Easy to forget updates:</strong> Updating a spreadsheet is a manual task that competes with everything else. When things get busy, updates slip. Cases fall out of sync with reality.</li>
      <li><strong>No alerting for delays:</strong> A spreadsheet won't tell you that a case has been sitting in the same status for two weeks. It just displays whatever was entered. You have to notice problems yourself.</li>
      <li><strong>Breaks with multi-step workflows:</strong> Complex cases move through multiple stages with different owners and dependencies. Spreadsheets flatten this into rows and columns, losing the structure that makes tracking useful.</li>
    </ul>
    
    <p>
      The result: cases slip through the cracks, delays go unnoticed, and staff spend time chasing down information instead of moving cases forward.
    </p>

    <h2>What Dental Case Tracking Software Does Differently</h2>
    
    <p>
      Dental case tracking software is built specifically for managing multi-step dental cases. Instead of a blank grid, it provides structure that matches how cases actually move through a practice.
    </p>
    
    <ul>
      <li><strong>Structured case tracking:</strong> Cases have defined fields, statuses, and stages. The system knows what a case looks like and what information matters.</li>
      <li><strong>Ownership and accountability:</strong> Every case has an assigned owner. When a case changes hands, ownership transfers explicitly. There's no ambiguity about who is responsible.</li>
      <li><strong>Visibility across the lifecycle:</strong> See all active cases in one view. Filter by status, case type, or owner. Know exactly where things stand without asking anyone.</li>
      <li><strong>Tracks lab dependencies:</strong> See which cases are waiting on labs, when they were sent, and when they're expected back. Know immediately when something is overdue.</li>
      <li><strong>Identifies stalled cases:</strong> Cases that haven't moved in too long surface automatically. You don't have to remember to check—the system shows you what needs attention.</li>
    </ul>
    
    <p>
      The difference isn't just features. It's that dental case tracking software is designed around the problem, while spreadsheets require you to build and maintain the solution yourself.
    </p>

    <h2>When to Move Beyond Spreadsheets</h2>
    
    <p>
      Spreadsheets can work for simple situations, but certain signals suggest it's time for a dedicated system:
    </p>
    
    <ul>
      <li><strong>Increasing case volume:</strong> More cases mean more rows, more updates, and more chances for things to fall through. If your spreadsheet is getting unwieldy, that's a sign.</li>
      <li><strong>More lab coordination:</strong> If you're working with multiple labs and tracking shipments, returns, and delays, a spreadsheet quickly becomes insufficient.</li>
      <li><strong>More staff involved:</strong> When multiple people need to update and reference case status, a shared spreadsheet creates confusion. Who updated what? Is this current?</li>
      <li><strong>Frequent delays or remakes:</strong> If cases are regularly stalling, getting lost, or requiring rework, the tracking method itself may be part of the problem.</li>
    </ul>
    
    <p>
      Moving to dental case tracking software isn't about abandoning what works. It's about recognizing when the current approach has reached its limits.
    </p>

    <h2>Where Dentatrak Fits</h2>
    
    <p>
      Dentatrak is designed to replace informal tracking methods with a structured system built specifically for dental case workflows.
    </p>
    
    <p>
      It gives every case a status, an owner, and a next step. It tracks cases across their full lifecycle—from prep to lab to delivery. It makes delays visible before they become problems.
    </p>
    
    <div class="highlight-box">
      <h3>Dentatrak vs spreadsheets</h3>
      <p>
        Spreadsheets require you to build and maintain your own tracking system. Dentatrak provides a ready-made structure designed for dental case workflows, with ownership, visibility, and accountability built in.
      </p>
    </div>
    
    <p>
      If your practice has outgrown spreadsheets—or if you're seeing cases slip through the cracks—dental case tracking software like Dentatrak can provide the structure and visibility you need.
    </p>
    
    <p>
      For more on how dental case tracking works, see our <a href="/dental-case-tracking" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">detailed guide</a>.
    </p>

    <div class="cta-section">
      <h2>Ready to move beyond spreadsheets?</h2>
      <p>Dentatrak is launching soon. Join the waitlist to get early access.</p>
      <a href="/#waitlist" class="btn-white">Get launch updates</a>
    </div>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-inner">
      <span class="footer-logo"><?php echo htmlspecialchars($appName); ?></span>
      <div class="footer-links">
        <a href="/privacy.php" class="footer-link">Privacy</a>
        <a href="/terms.php" class="footer-link">Terms</a>
        <a href="/" class="footer-link">Home</a>
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.</span>
    </div>
  </footer>
</body>
</html>
