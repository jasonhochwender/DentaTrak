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
      Dental case tracking software helps dental practices manage multi-step cases such as crowns, implants, and lab-based treatments by providing visibility into each step of the workflow.
    </p>
    
    <p>
      Dentatrak is a dental case tracking system designed to give every case a status, owner, and next step so nothing is lost between labs, referrals, and internal handoffs.
    </p>

    <h2>Why Dental Case Tracking Is Difficult</h2>
    
    <p>
      Most dental practices struggle to track complex cases because they lack a dedicated system. Instead, case information lives in scattered places—and problems only surface when it's too late.
    </p>
    
    <ul>
      <li><strong>Cases tracked in memory:</strong> The dentist or coordinator knows where things stand, but that knowledge isn't shared. When someone is out sick or busy, cases stall because no one else knows the status.</li>
      <li><strong>Lost between lab and front desk:</strong> A case ships to the lab, comes back, and sits waiting. The front desk doesn't know it's ready. The patient doesn't get scheduled. Days pass before anyone notices.</li>
      <li><strong>No ownership or accountability:</strong> Multiple people touch a case—hygienist, assistant, coordinator, dentist. But no one is clearly responsible for the next step. Handoffs become drop-offs.</li>
      <li><strong>Delays only noticed after impact:</strong> By the time someone realizes a case is stalled, the patient has already waited too long, the lab work may need to be redone, or chair time has been wasted.</li>
    </ul>
    
    <p>
      These aren't edge cases. They happen regularly in practices that handle crowns, implants, bridges, and other multi-step treatments. Without dental case tracking software, there's no reliable way to catch problems before they become costly.
    </p>

    <h2>What Dental Case Tracking Software Should Do</h2>
    
    <p>
      Effective dental case tracking software provides a clear, shared view of every active case. It should do the following:
    </p>
    
    <ul>
      <li><strong>Track case status:</strong> Know whether a case is in prep, at the lab, waiting on the patient, or ready for delivery. See the current state at a glance.</li>
      <li><strong>Assign ownership:</strong> Every case should have a responsible person. When ownership is clear, accountability follows.</li>
      <li><strong>Monitor lab dependencies:</strong> See which cases are waiting on external labs, how long they've been waiting, and whether expected return dates have passed.</li>
      <li><strong>Show stalled cases:</strong> Surface cases that haven't moved in too long. Make delays visible before they become problems.</li>
      <li><strong>Provide full lifecycle visibility:</strong> Track the case from initial treatment through final delivery. Nothing should disappear between steps.</li>
    </ul>
    
    <p>
      Dental case tracking software is not a replacement for your practice management system. It fills a gap that PMS software was never designed to address: managing the workflow of complex, multi-step cases.
    </p>

    <h2>How Dentatrak Works</h2>
    
    <p>
      Dentatrak is dental case tracking software built for dental practices. It provides a simple, structured workflow for tracking cases from start to finish:
    </p>
    
    <ol class="workflow-steps">
      <li>
        <strong>Enter the case</strong>
        When treatment begins, create a case record with patient details, case type (crown, implant, bridge, etc.), and lab information.
      </li>
      <li>
        <strong>Assign ownership</strong>
        Designate who is responsible for the case and what the next step is. Ownership stays clear through every handoff.
      </li>
      <li>
        <strong>Track dependencies</strong>
        See which cases are waiting on labs, referrals, or patient scheduling. Know how long they've been waiting.
      </li>
      <li>
        <strong>Monitor progress</strong>
        Follow the case through each stage. Update status as it moves from prep to lab to delivery.
      </li>
      <li>
        <strong>Intervene early</strong>
        Identify stalled cases before they affect scheduling, revenue, or patient satisfaction. Take action while there's still time.
      </li>
    </ol>

    <h2>Types of Cases Tracked</h2>
    
    <p>
      Dentatrak is designed to support common multi-step dental workflows that are difficult to track in traditional systems:
    </p>
    
    <ul>
      <li><strong>Crown and bridge cases:</strong> Track from prep through lab fabrication to final seating.</li>
      <li><strong>Implant workflows:</strong> Monitor surgical placement, healing periods, and restoration phases.</li>
      <li><strong>Lab-based restorations:</strong> See which cases are at the lab, when they're expected back, and whether they're overdue.</li>
      <li><strong>Referral-dependent treatments:</strong> Track cases that require coordination with specialists or external providers.</li>
      <li><strong>Multi-appointment procedures:</strong> Maintain visibility across treatments that span multiple visits over weeks or months.</li>
    </ul>

    <h2>Who Should Use Dental Case Tracking Software</h2>
    
    <p>
      Dental case tracking software benefits everyone involved in managing complex cases:
    </p>
    
    <ul>
      <li><strong>Practice owners:</strong> Get visibility into case flow without asking staff for updates. See where bottlenecks occur and identify patterns that affect revenue and efficiency.</li>
      <li><strong>Treatment coordinators:</strong> Know exactly which cases need attention today. Stop chasing down status updates and focus on moving cases forward.</li>
      <li><strong>Dental assistants:</strong> Understand what's coming up and what's waiting. Reduce confusion during handoffs and spend less time tracking down information.</li>
    </ul>
    
    <p>
      If your practice handles crowns, bridges, implants, dentures, or any lab-based restorations, dental case tracking software can help you maintain visibility and reduce delays.
    </p>

    <h2>How Dentatrak Fits with PMS Systems</h2>
    
    <p>
      Dentatrak does not replace your practice management software. Your PMS handles scheduling, billing, and patient records. Dentatrak handles something different: tracking the workflow of multi-step cases.
    </p>
    
    <p>
      Most PMS systems were not designed for dental case tracking. They don't show you which cases are stalled, who owns each case, or how long a case has been waiting on a lab. Dentatrak fills that gap.
    </p>
    
    <ul>
      <li><strong>Complements scheduling and billing:</strong> Use your PMS for appointments and payments. Use Dentatrak for case visibility.</li>
      <li><strong>No data migration required:</strong> Dentatrak works alongside your existing systems. Start tracking cases immediately without disrupting your current workflow.</li>
      <li><strong>Focuses specifically on case tracking:</strong> Instead of trying to do everything, Dentatrak does one thing well: giving you visibility into complex cases.</li>
    </ul>

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
