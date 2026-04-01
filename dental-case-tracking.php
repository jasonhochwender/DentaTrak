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
  
  <meta name="description" content="Dental case tracking software for dental practices. Track crowns, implants, and lab cases from prep to delivery. Prevent lost cases and reduce delays.">
  <title>Dental Case Tracking Software for Dental Practices | Dentatrak</title>
  <link rel="canonical" href="https://dentatrak.com/dental-case-tracking">
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
    <h1>Dental Case Tracking Software for Dental Practices</h1>
    
    <p>
      Dental case tracking software helps dental practices monitor multi-step cases from start to finish. Unlike practice management systems that focus on scheduling and billing, dental case tracking software is designed specifically to track crowns, implants, lab work, and referral-dependent treatments through every stage of completion.
    </p>

    <div class="highlight-box">
      <h3>What is dental case tracking?</h3>
      <p>
        Dental case tracking is the process of monitoring complex dental cases—such as crowns, bridges, implants, and lab-based restorations—from initial treatment through final delivery. It ensures every case has a clear status, an assigned owner, and a defined next step.
      </p>
    </div>

    <h2>Common Problems Without Dental Case Tracking</h2>
    
    <p>
      Most dental practices do not have a dedicated system for tracking cases. Instead, they rely on memory, sticky notes, or spreadsheets. This leads to predictable problems:
    </p>
    
    <ul>
      <li><strong>Lost cases:</strong> Cases get stuck between lab shipments, patient no-shows, or referral delays. Without visibility, no one notices until the patient calls or the case expires.</li>
      <li><strong>Lab delays:</strong> When a lab case is late, staff often don't know until the patient arrives. This wastes chair time and creates scheduling problems.</li>
      <li><strong>No clear ownership:</strong> When multiple people touch a case, it's unclear who is responsible for the next step. Cases fall through the cracks during handoffs.</li>
      <li><strong>Invisible bottlenecks:</strong> Without tracking, practices can't see patterns—like which labs are consistently late or which case types stall most often.</li>
    </ul>

    <h2>How Dental Case Tracking Software Solves These Problems</h2>
    
    <p>
      Dental case tracking software like Dentatrak gives every case a status, an owner, and a next step. This creates visibility across your entire workflow:
    </p>
    
    <ul>
      <li><strong>See all active cases in one place:</strong> Know exactly where every crown, implant, and lab case stands—without asking staff or digging through records.</li>
      <li><strong>Track external dependencies:</strong> See which cases are waiting on labs, referrals, or patients. Know how long they've been waiting.</li>
      <li><strong>Assign clear ownership:</strong> Every case has a responsible person. No confusion about who handles the next step.</li>
      <li><strong>Catch problems early:</strong> Identify stalled cases before they affect scheduling, revenue, or patient satisfaction.</li>
    </ul>

    <h2>How Dentatrak Works</h2>
    
    <p>
      Dentatrak is dental case tracking software built specifically for dental practices. Here's how it works:
    </p>
    
    <ol class="workflow-steps">
      <li>
        <strong>Enter the case when treatment begins</strong>
        Add patient details, case type (crown, implant, bridge, etc.), and lab information.
      </li>
      <li>
        <strong>Assign ownership and next step</strong>
        Every case gets a responsible person and a clear next action.
      </li>
      <li>
        <strong>Track external dependencies</strong>
        See which cases are waiting on labs, referrals, or patient scheduling.
      </li>
      <li>
        <strong>Monitor until delivery</strong>
        Follow the case through each stage until it's marked complete.
      </li>
      <li>
        <strong>Intervene early</strong>
        Identify stalled cases before they become costly problems.
      </li>
    </ol>

    <h2>Who Should Use Dental Case Tracking Software</h2>
    
    <p>
      Dental case tracking software is designed for practices that handle complex, multi-step cases:
    </p>
    
    <ul>
      <li><strong>Practice owners:</strong> See where cases stall without asking staff. Identify bottlenecks and reduce remakes before they cost you.</li>
      <li><strong>Treatment coordinators:</strong> Get a clear view of what needs attention today. Know exactly who owns each case and what's next.</li>
      <li><strong>Dental assistants:</strong> Fewer dropped handoffs between team members. Less time chasing down case status.</li>
    </ul>
    
    <p>
      If your practice handles crowns, bridges, implants, dentures, or any lab-based restorations, dental case tracking software can help you maintain visibility and reduce delays.
    </p>

    <div class="cta-section">
      <h2>Ready to track your cases?</h2>
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
