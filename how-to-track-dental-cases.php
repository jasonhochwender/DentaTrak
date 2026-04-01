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
  
  <meta name="description" content="Learn how to track dental cases without losing them. A practical guide to managing crowns, implants, and lab cases from prep to delivery.">
  <title>How to Track Dental Cases Without Losing Them | Dentatrak</title>
  <link rel="canonical" href="https://dentatrak.com/how-to-track-dental-cases">
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

    .content h3 {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-top: 32px;
      margin-bottom: 12px;
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

    .highlight-box {
      background: var(--background-subtle);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 32px;
      margin: 32px 0;
    }

    .highlight-box h3 {
      margin-top: 0;
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
    <h1>How to Track Dental Cases Without Losing Them</h1>
    
    <p>
      Tracking dental cases across labs, referrals, and multiple appointments is one of the most common breakdowns in dental practices.
    </p>
    
    <p>
      Cases get lost between handoffs. Staff rely on memory or scattered notes to know where things stand. Delays are discovered too late—often when the patient is already in the chair or calling to ask what happened.
    </p>
    
    <p>
      This isn't a rare problem. It happens in practices of all sizes, especially those that handle crowns, implants, bridges, and other multi-step treatments. The issue isn't carelessness. It's that most practices don't have a reliable way to track dental cases through every stage of the workflow.
    </p>

    <h2>Why Dental Cases Get Lost</h2>
    
    <p>
      Cases don't disappear because of one big mistake. They slip through the cracks gradually, usually for predictable reasons:
    </p>
    
    <ul>
      <li><strong>No centralized system:</strong> Case information lives in different places—the dentist's head, a coordinator's notes, the PMS, a spreadsheet. No one has the full picture.</li>
      <li><strong>No clear ownership:</strong> Multiple people touch a case over its lifecycle. When no one is clearly responsible for the next step, handoffs become drop-offs.</li>
      <li><strong>Lab delays aren't visible:</strong> A case ships to the lab and enters a black box. Staff don't know it's late until the patient arrives and the case isn't ready.</li>
      <li><strong>Patient reschedules break flow:</strong> A patient cancels or reschedules, and the case falls off the radar. Weeks later, no one remembers where it stands.</li>
      <li><strong>Practice management systems don't track case workflows:</strong> PMS software handles scheduling and billing well, but it wasn't designed to track dental cases through multi-step treatments. It shows appointments, not case status.</li>
    </ul>

    <h2>How Dental Cases Are Typically Tracked (and Why It Fails)</h2>
    
    <p>
      Without a dedicated system, practices improvise. These workarounds seem reasonable at first, but they break down as volume increases or staff changes.
    </p>
    
    <h3>Sticky notes and paper lists</h3>
    <p>
      Quick to create, easy to lose. Sticky notes work for one person tracking a few cases, but they don't scale. Information isn't shared, and notes get buried, thrown away, or forgotten.
    </p>
    
    <h3>Spreadsheets</h3>
    <p>
      Better than paper, but still fragile. Spreadsheets require manual updates, and they're only as current as the last person who edited them. They don't alert you when something stalls. They just sit there, waiting to be checked.
    </p>
    
    <h3>Notes in the PMS</h3>
    <p>
      Some practices add case notes to patient records in their practice management software. But PMS systems aren't built to track dental cases across stages. Notes get buried in the patient chart, and there's no way to see all active cases at once or filter by status.
    </p>
    
    <h3>Verbal communication</h3>
    <p>
      "Did the lab send that back?" "Where are we with the Smith implant?" These conversations happen constantly because there's no shared source of truth. When someone is out sick or busy, the information disappears.
    </p>

    <h2>What Effective Dental Case Tracking Looks Like</h2>
    
    <p>
      To track dental cases reliably, you need a system where every case has clear, visible information that anyone on the team can access:
    </p>
    
    <ul>
      <li><strong>Every case has a status:</strong> Is it in prep? At the lab? Waiting on the patient? Ready for delivery? The current state should be obvious at a glance.</li>
      <li><strong>Every case has an owner:</strong> Someone is responsible for the next step. When ownership is clear, accountability follows.</li>
      <li><strong>Every case has a next step:</strong> Not just "in progress" but a specific action: "Schedule seating" or "Follow up with lab" or "Call patient to confirm."</li>
      <li><strong>External dependencies are visible:</strong> You can see which cases are waiting on labs, referrals, or patient scheduling—and how long they've been waiting.</li>
      <li><strong>Stalled cases are easy to identify:</strong> Cases that haven't moved in too long should surface automatically, not require someone to remember to check.</li>
    </ul>
    
    <p>
      This is what dental case tracking should provide: a shared, current view of every active case so nothing falls through the cracks. Using dedicated <a href="/dental-case-tracking" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">dental case tracking software</a> makes this process consistent and reliable.
    </p>

    <h2>Step-by-Step Process to Track Dental Cases</h2>
    
    <p>
      Here's a practical process for tracking dental cases from start to finish:
    </p>
    
    <ol class="workflow-steps">
      <li>
        <strong>Enter the case when treatment begins</strong>
        As soon as a multi-step case starts—crown prep, implant placement, bridge work—create a record. Include patient details, case type, lab information, and expected timeline.
      </li>
      <li>
        <strong>Assign responsibility</strong>
        Designate who owns the case at each stage. This might be the treatment coordinator, a dental assistant, or the front desk. The point is clarity: someone is accountable for the next step.
      </li>
      <li>
        <strong>Track lab and referral dependencies</strong>
        When a case goes to an external lab or specialist, note the expected return date. Monitor whether it comes back on time. If it's late, you should know before the patient's appointment.
      </li>
      <li>
        <strong>Monitor progress over time</strong>
        Check case status regularly. Update it as the case moves through stages. Look for cases that haven't moved—they're the ones most likely to cause problems.
      </li>
      <li>
        <strong>Follow through to delivery</strong>
        Track the case until it's complete. Final seating, patient confirmation, case closed. Don't let cases linger in an ambiguous state.
      </li>
    </ol>
    
    <p>
      This process works whether you're using software, a spreadsheet, or a whiteboard. The key is consistency: every case gets tracked the same way, every time.
    </p>

    <h2>Where Dentatrak Fits</h2>
    
    <p>
      Dentatrak is a dental case tracking system designed to manage this exact process.
    </p>
    
    <p>
      It gives every case a status, an owner, and a next step. It tracks cases across their full lifecycle—from initial treatment through final delivery. It makes delays visible early, so you can intervene before they become costly problems.
    </p>
    
    <p>
      Dentatrak doesn't replace your practice management software. It fills a gap that PMS systems weren't designed to address: managing the workflow of complex, multi-step dental cases.
    </p>
    
    <div class="highlight-box">
      <h3>What Dentatrak provides</h3>
      <p>
        A dedicated system to track dental cases across labs, referrals, and internal handoffs. Clear ownership and accountability at every stage. Visibility into stalled cases before they affect patients or revenue.
      </p>
    </div>

    <h2>Who Benefits from Dental Case Tracking</h2>
    
    <p>
      When you track dental cases systematically, everyone on the team benefits:
    </p>
    
    <ul>
      <li><strong>Practice owners:</strong> See where cases stall without asking staff for updates. Identify patterns that affect efficiency and revenue. Reduce remakes and wasted chair time.</li>
      <li><strong>Treatment coordinators:</strong> Know exactly which cases need attention today. Stop chasing down status updates and focus on moving cases forward.</li>
      <li><strong>Dental assistants:</strong> Understand what's coming up and what's waiting. Reduce confusion during handoffs. Spend less time tracking down information and more time on patient care.</li>
    </ul>
    
    <p>
      If your practice handles crowns, bridges, implants, or any lab-based restorations, a reliable way to track dental cases can reduce delays, prevent lost work, and improve the patient experience.
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
