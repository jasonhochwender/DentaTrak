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

  <meta name="description" content="Estimate the real cost of dental remakes to your practice, including chair time, staff time, lab fees, shipping, and lost capacity, with an interactive dental remake cost calculator.">
  <title>What Dental Remakes Really Cost Your Practice | DentaTrak</title>
  <link rel="canonical" href="https://dentatrak.com/resources/dental-remake-cost">

  <!-- Open Graph -->
  <meta property="og:title" content="What Dental Remakes Really Cost Your Practice">
  <meta property="og:description" content="Calculate the hidden cost of dental remakes, from chair time and staff coordination to lab fees and shipping.">
  <meta property="og:type" content="article">
  <meta property="og:url" content="https://dentatrak.com/resources/dental-remake-cost">

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
    "headline": "What Dental Remakes Really Cost Your Practice",
    "author": { "@type": "Person", "name": "Dr. William Verrillo" },
    "publisher": { "@type": "Organization", "name": "DentaTrak" },
    "datePublished": "2026-08-08",
    "dateModified": "2026-08-08",
    "mainEntityOfPage": "https://dentatrak.com/resources/dental-remake-cost"
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
      { "@type": "ListItem", "position": 3, "name": "What Dental Remakes Really Cost Your Practice", "item": "https://dentatrak.com/resources/dental-remake-cost" }
    ]
  }
  </script>

  <style>
    .calculator {
      background: #fff;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 20px;
      padding: 28px;
      box-shadow: 0 12px 34px -16px rgba(0,0,0,0.1);
      margin: 32px 0;
    }
    .calculator-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
    }
    @media (max-width: 720px) {
      .calculator-grid { grid-template-columns: 1fr; }
    }
    .calc-field { display: flex; flex-direction: column; gap: 6px; }
    .calc-field label { font-size: 0.85rem; font-weight: 600; color: var(--dt-ink, #1e293b); }
    .calc-field .help { font-size: 0.75rem; color: var(--dt-ink-muted, #64748b); }
    .calc-field input {
      padding: 12px 14px;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 10px;
      font-size: 1rem;
      font-family: inherit;
      color: var(--dt-ink, #1e293b);
      background: #fff;
    }
    .calc-field input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .calc-results {
      background: #f8fafc;
      border-radius: 16px;
      padding: 24px;
      margin-top: 28px;
    }
    .primary-result {
      text-align: center;
      padding-bottom: 24px;
      border-bottom: 1px solid var(--dt-border, #e2e8f0);
      margin-bottom: 24px;
    }
    .primary-result .label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #7c3aed; }
    .primary-result .value { font-size: clamp(2rem, 5vw, 3rem); font-weight: 700; color: var(--dt-ink, #1e293b); margin: 8px 0; }
    .result-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
    }
    .result-item { background: #fff; border: 1px solid var(--dt-border, #e2e8f0); border-radius: 12px; padding: 16px; }
    .result-item .label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dt-ink-muted, #64748b); margin-bottom: 6px; }
    .result-item .value { font-size: 1.2rem; font-weight: 700; color: var(--dt-ink, #1e293b); }
    .savings {
      background: #fff;
      border: 1px solid var(--dt-border, #e2e8f0);
      border-radius: 16px;
      padding: 20px;
    }
    .savings h4 { font-size: 1rem; margin-bottom: 14px; }
    .savings-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid var(--dt-border, #e2e8f0);
      font-size: 0.92rem;
    }
    .savings-row:last-child { border-bottom: none; }
    .savings-row .amount { font-weight: 700; color: #15803d; }
    .calculator-disclaimer { font-size: 0.8rem; color: var(--dt-ink-muted, #64748b); margin-top: 18px; line-height: 1.5; }
  </style>
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
      <li aria-current="page">What Dental Remakes Really Cost Your Practice</li>
    </ol>
  </div>

  <!-- Main Content -->
  <main class="content">
    <h1>What Dental Remakes Really Cost Your Practice</h1>

    <div class="article-meta">
      <span>By <strong>Dr. William Verrillo</strong></span>
      <span class="meta-divider">&middot;</span>
      <span>Published <strong>August 8, 2026</strong></span>
    </div>

    <div class="answer-box">
      <p>
        A dental remake costs more than a second lab bill. Depending on the case, a remake may also consume dentist chair time, assistant and staff time, an additional patient appointment, scheduling capacity, shipping or courier fees, administrative coordination, and time spent diagnosing what went wrong.
      </p>
    </div>

    <h2>The Lab Bill Is Not the Whole Cost</h2>

    <p>
      When a crown, bridge, or implant restoration does not fit or needs to be redone, the obvious expense is the new lab charge. That cost is easy to see on an invoice. The less obvious costs are spread across the practice and are harder to assign to one specific case.
    </p>

    <ul>
      <li><strong>Dentist chair time:</strong> Seating or rescanning a remake takes time that could have been used for another patient or another procedure.</li>
      <li><strong>Assistant and staff time:</strong> Staff may need to reappoint the patient, contact the lab, locate files, send new impressions or scans, and update records.</li>
      <li><strong>Additional appointments:</strong> A remake often means bringing the patient back, which uses a chair and blocks another slot on the schedule.</li>
      <li><strong>Shipping and courier costs:</strong> Returning a case and receiving the replacement may add shipping or same-day delivery fees.</li>
      <li><strong>Time spent finding the cause:</strong> Someone has to determine whether the issue was a prep problem, an impression issue, a lab error, or a communication breakdown.</li>
      <li><strong>Lost capacity:</strong> Even if the practice does not write a check for the dentist's time, that chair is no longer available for productive work during the remake visit.</li>
    </ul>

    <p>
      Not every remake incurs every one of these costs. Some are resolved with a quick phone call. Others require multiple appointments, new scans, and significant back and forth. The purpose of this article is to help a practice estimate its own financial exposure based on its own volume and workflow.
    </p>

    <h2>Chair Time Has a Cost</h2>

    <p>
      A dentist's chair time has value whether or not the practice bills specifically for it. When a remake requires a second appointment, the practice gives up an opportunity to see a different patient in that same slot. The value of that time depends on the practice, the type of work typically scheduled in that chair, and the local fee structure.
    </p>

    <p>
      For example, a 45-minute remake appointment for a crown has a clear time cost. If a dentist's productive chair time is valued at $500 per hour, the chair time alone for that appointment is over $375. That is in addition to any lab fee or shipping charge.
    </p>

    <h2>Staff Time Adds Up Too</h2>

    <p>
      Staff time may be less visible than dentist time, but it is still a real cost. Someone has to coordinate the remake with the patient, communicate with the lab, manage files and records, and update the schedule. For a busy practice, these small tasks accumulate quickly.
    </p>

    <p>
      If a staff member spends 20 minutes on a remake at $35 per hour, that is roughly $12 in direct labor per remake. Across dozens of remakes a year, the total becomes meaningful. And that time is also time not spent on other productive or patient-facing work.
    </p>

    <h2>Calculate Your Practice's Remake Cost</h2>

    <p>
      Use the calculator below to estimate the annual cost of remakes to your practice. The default values are examples only. Change them to reflect your own case volume, remake rate, and costs.
    </p>

    <div class="calculator">
      <div class="calculator-grid">
        <div class="calc-field">
          <label for="cases">Cases per month</label>
          <span class="help">Total cases your practice sends to the lab in a typical month.</span>
          <input type="number" id="cases" value="150" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="rate">Estimated remake rate (%)</label>
          <span class="help">The percentage of cases that require a remake.</span>
          <input type="number" id="rate" value="5" min="0" step="0.1">
        </div>
        <div class="calc-field">
          <label for="dentistMins">Average dentist chair time per remake (minutes)</label>
          <span class="help">Time the dentist spends on the remake appointment.</span>
          <input type="number" id="dentistMins" value="45" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="dentistHourly">Estimated value of dentist chair time ($/hour)</label>
          <span class="help">Approximate productive value of one hour of chair time.</span>
          <input type="number" id="dentistHourly" value="500" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="staffMins">Average staff time per remake (minutes)</label>
          <span class="help">Scheduling, coordination, and record keeping per remake.</span>
          <input type="number" id="staffMins" value="20" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="staffHourly">Average staff cost ($/hour)</label>
          <span class="help">Hourly wage or loaded cost for staff time.</span>
          <input type="number" id="staffHourly" value="35" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="labCharge">Additional lab/remake charge ($/remake)</label>
          <span class="help">Use 0 if your lab remakes without an additional fee.</span>
          <input type="number" id="labCharge" value="75" min="0" step="1">
        </div>
        <div class="calc-field">
          <label for="shipping">Additional shipping/courier cost ($/remake)</label>
          <span class="help">Round trip or rush shipping for the remade case.</span>
          <input type="number" id="shipping" value="15" min="0" step="1">
        </div>
        <div class="calc-field" style="grid-column: 1 / -1;">
          <label for="other">Other estimated cost ($/remake)</label>
          <span class="help">Imaging, additional materials, or other costs not captured above.</span>
          <input type="number" id="other" value="20" min="0" step="1">
        </div>
      </div>

      <div class="calc-results">
        <div class="primary-result">
          <div class="label">Estimated Annual Remake Cost</div>
          <div class="value" id="annualCost">$0</div>
        </div>

        <div class="result-grid">
          <div class="result-item">
            <div class="label">Remakes per month</div>
            <div class="value" id="remakesMonth">0</div>
          </div>
          <div class="result-item">
            <div class="label">Dentist/chair cost per remake</div>
            <div class="value" id="dentistCost">$0</div>
          </div>
          <div class="result-item">
            <div class="label">Staff cost per remake</div>
            <div class="value" id="staffCost">$0</div>
          </div>
          <div class="result-item">
            <div class="label">Total cost per remake</div>
            <div class="value" id="costPerRemake">$0</div>
          </div>
          <div class="result-item">
            <div class="label">Estimated monthly cost</div>
            <div class="value" id="monthlyCost">$0</div>
          </div>
        </div>

        <div class="savings">
          <h4>What if you reduced remakes?</h4>
          <p class="calculator-disclaimer">These scenarios show estimated annual avoided costs if your practice reduced its remake rate. They do not assume any specific cause of reduction.</p>
          <div class="savings-row" id="savings-0.5"></div>
          <div class="savings-row" id="savings-1"></div>
          <div class="savings-row" id="savings-2"></div>
        </div>
      </div>

      <p class="calculator-disclaimer">
        This calculator is for estimation only. Actual costs vary by practice, location, case type, lab relationship, and workflow. It is not financial or accounting advice.
      </p>
    </div>

    <h2>The Number That Matters Is Not Just Your Remake Rate</h2>

    <p>
      Knowing that a practice has remakes is less useful than understanding the context around them. A percentage alone does not tell you where to act.
    </p>

    <ul class="checklist">
      <li><strong>How often remakes occur:</strong> Is the rate steady, seasonal, or increasing?</li>
      <li><strong>Which case types are involved:</strong> Are remakes concentrated in crowns, implants, dentures, or another type?</li>
      <li><strong>Which labs are involved:</strong> Does one lab account for more remakes than others?</li>
      <li><strong>Why the remake occurred:</strong> Was it a prep issue, an impression problem, a shade mismatch, a lab error, or a communication gap?</li>
      <li><strong>Whether certain reasons repeat:</strong> Are the same one or two issues coming up again and again?</li>
      <li><strong>Whether the trend is improving or worsening:</strong> Are recent changes to the workflow helping?</li>
    </ul>

    <p>
      Answering these questions is what makes a practice able to reduce remakes, or at least reduce the cost and disruption associated with them.
    </p>

    <h2>Reducing Remake Cost Starts with Understanding Why Remakes Happen</h2>

    <p>
      DentaTrak cannot eliminate every remake. It can give a practice a clearer record of what is happening across its cases so the team can identify patterns, improve coordination, and make better decisions.
    </p>

    <p>
      DentaTrak keeps case history with the case. Practices can record remake and revision activity, note reasons for remakes, associate cases with specific labs, track case types, monitor due dates and patient appointments, and see practice and lab insights that reveal recurring patterns over time.
    </p>

    <p>
      That visibility is the foundation for improvement. Once the team can see where remakes are clustering and why, it becomes much easier to adjust workflows, train staff, clarify lab instructions, or catch problems earlier in the process.
    </p>

    <div class="related-links">
      <h3>Related resources</h3>
      <ul>
        <li><a href="<?= $baseUrl . ($articleUrls['article_lab_tracking'] ?? 'dental-lab-case-tracking') ?>">Dental Lab Case Tracking</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_crown_bridge'] ?? 'crown-and-bridge-case-tracking') ?>">Crown and Bridge Case Tracking</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['article_implant'] ?? 'implant-case-tracking') ?>">Implant Case Tracking</a></li>
        <li><a href="<?= $baseUrl . ($articleUrls['page_about'] ?? 'about') ?>">About DentaTrak</a></li>
      </ul>
    </div>

    <div class="cta-section">
      <h2>Know what your remakes are costing. Then understand why they are happening.</h2>
      <p>DentaTrak gives your practice one place to track cases, remakes, and the details that help your team improve coordination.</p>
      <a href="<?= $baseUrl ?>login.php" class="btn-white">Start Free</a>
      <p style="margin-top: 16px; font-size: 0.9rem;">90-day free trial. No credit card required.</p>
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
      </div>
      <span class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.</span>
    </div>
  </footer>

  <script>
    const fields = ['cases','rate','dentistMins','dentistHourly','staffMins','staffHourly','labCharge','shipping','other'];
    const fmtCurrency = (n) => {
      const v = Number(n);
      if (!isFinite(v) || isNaN(v)) return '$0';
      const dollars = Math.round(v);
      return '$' + dollars.toLocaleString('en-US');
    };
    const fmtNumber = (n, d) => {
      const v = Number(n);
      if (!isFinite(v) || isNaN(v)) return '0';
      return v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: d });
    };

    function calculate() {
      const get = (id) => Math.max(0, parseFloat(document.getElementById(id).value) || 0);

      const cases = get('cases');
      const rate = get('rate');
      const dentistMins = get('dentistMins');
      const dentistHourly = get('dentistHourly');
      const staffMins = get('staffMins');
      const staffHourly = get('staffHourly');
      const labCharge = get('labCharge');
      const shipping = get('shipping');
      const other = get('other');

      const rateDecimal = rate / 100;
      const remakesMonth = cases * rateDecimal;
      const dentistCost = (dentistMins / 60) * dentistHourly;
      const staffCost = (staffMins / 60) * staffHourly;
      const costPerRemake = dentistCost + staffCost + labCharge + shipping + other;
      const monthlyCost = remakesMonth * costPerRemake;
      const annualCost = monthlyCost * 12;

      document.getElementById('remakesMonth').textContent = fmtNumber(remakesMonth, 1);
      document.getElementById('dentistCost').textContent = fmtCurrency(dentistCost);
      document.getElementById('staffCost').textContent = fmtCurrency(staffCost);
      document.getElementById('costPerRemake').textContent = fmtCurrency(costPerRemake);
      document.getElementById('monthlyCost').textContent = fmtCurrency(monthlyCost);
      document.getElementById('annualCost').textContent = fmtCurrency(annualCost);

      const reductions = [0.5, 1, 2];
      reductions.forEach(r => {
        const newRate = Math.max(0, rate - r);
        const newRemakesMonth = cases * (newRate / 100);
        const avoidedMonth = Math.max(0, remakesMonth - newRemakesMonth);
        const avoidedAnnual = avoidedMonth * costPerRemake * 12;
        const row = document.getElementById('savings-' + (r % 1 === 0 ? r : '0.5'));
        if (row) {
          row.innerHTML = '<span>From ' + fmtNumber(rate, 1) + '% to ' + fmtNumber(newRate, 1) + '%</span><span class="amount">' + fmtCurrency(avoidedAnnual) + ' per year</span>';
        }
      });
    }

    fields.forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', calculate);
      }
    });

    calculate();
  </script>
</body>
</html>