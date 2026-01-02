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
  
  <title>Terms of Service - Dentatrak</title>
  <link rel="stylesheet" href="css/app.css">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
      line-height: 1.6;
      color: #333;
      background: #f5f7fa;
      margin: 0;
      padding: 0;
    }
    .policy-container {
      max-width: 800px;
      margin: 0 auto;
      padding: 40px 20px;
    }
    .policy-header {
      text-align: center;
      margin-bottom: 40px;
    }
    .policy-header h1 {
      color: #2563eb;
      margin-bottom: 10px;
    }
    .policy-header .app-name {
      font-size: 0.9rem;
      color: #666;
    }
    .policy-content {
      background: white;
      border-radius: 12px;
      padding: 40px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .policy-content h3 {
      color: #666;
      font-weight: normal;
      margin-top: 0;
    }
    .policy-content h4 {
      color: #2563eb;
      margin-top: 30px;
      margin-bottom: 15px;
    }
    .policy-content ul {
      padding-left: 20px;
    }
    .policy-content li {
      margin-bottom: 8px;
    }
    .policy-footer {
      text-align: center;
      margin-top: 40px;
      color: #666;
      font-size: 0.9rem;
    }
    .back-link {
      display: inline-block;
      margin-bottom: 20px;
      color: #2563eb;
      text-decoration: none;
    }
    .back-link:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="policy-container">
    <a href="index.php" class="back-link">← Back to Sign In</a>
    
    <div class="policy-header">
      <h1>Terms of Service</h1>
      <p class="app-name">Dentatrak</p>
    </div>
    
    <div class="policy-content">
      <p><strong>Effective Date:</strong> December 22, 2025</p>
      
      <p>These Terms of Service ("Terms") govern your use of Dentatrak ("Service"), a case tracking application provided to dental practices. By using the Service, you agree to these Terms.</p>
      
      <h4>1. Service Description</h4>
      <p>Dentatrak is a case management tool that helps dental practices track and manage dental cases. The Service is intended for use by dental professionals and their authorized staff.</p>
      
      <h4>2. Account Security</h4>
      <p>You are responsible for:</p>
      <ul>
        <li>Keeping your login credentials secure</li>
        <li>All activity that occurs under your account</li>
        <li>Notifying us promptly if you suspect unauthorized access to your account</li>
        <li>Ensuring that only authorized personnel in your practice access the Service</li>
      </ul>
      
      <h4>3. Acceptable Use</h4>
      <p>You agree to use the Service only for its intended purpose of dental case management. You agree not to:</p>
      <ul>
        <li>Attempt to gain unauthorized access to the Service or its systems</li>
        <li>Use the Service for any unlawful purpose</li>
        <li>Interfere with or disrupt the Service</li>
        <li>Copy, modify, or distribute any part of the Service</li>
        <li>Use the Service in any way that could harm other users</li>
      </ul>
      
      <h4>4. Your Data</h4>
      <p>You retain ownership of all data you enter into the Service. You are responsible for the accuracy and legality of the information you store. You are also responsible for complying with all applicable laws regarding patient data and privacy.</p>
      
      <h4>5. Disclaimer of Warranties</h4>
      <p>The Service is provided "as is" without warranties of any kind, express or implied. We do not warrant that the Service will be uninterrupted, error-free, or completely secure. We do not provide legal, medical, or compliance advice.</p>
      
      <h4>6. Limitation of Liability</h4>
      <p>To the maximum extent permitted by law, Dentatrak and its operators shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of the Service. This includes, but is not limited to:</p>
      <ul>
        <li>Loss of data or unauthorized access to your data</li>
        <li>Service interruptions or downtime</li>
        <li>Your failure to comply with applicable laws or regulations</li>
        <li>Any decisions made based on information in the Service</li>
      </ul>
      <p>Our total liability for any claims related to the Service shall not exceed the amount you paid us, if any, in the twelve months preceding the claim.</p>
      
      <h4>7. Termination</h4>
      <p>We may suspend or terminate your access to the Service at any time, with or without cause. You may also stop using the Service at any time. Upon termination:</p>
      <ul>
        <li>Your right to use the Service ends immediately</li>
        <li>You may request export of your data within 30 days</li>
        <li>We may delete your data after 30 days</li>
      </ul>
      
      <h4>8. Changes to Terms</h4>
      <p>We may update these Terms from time to time. We will post changes on this page and update the effective date. Continued use of the Service after changes constitutes acceptance of the updated Terms.</p>
      
      <h4>9. Governing Law</h4>
      <p>These Terms are governed by the laws of the United States. Any disputes shall be resolved in accordance with applicable federal and state law.</p>
      
      <h4>10. Contact Us</h4>
      <p>If you have questions about these Terms, please contact us at:</p>
      <p><a href="mailto:support@dentatrak.com">support@dentatrak.com</a></p>
    </div>
    
    <div class="policy-footer">
      &copy; <?php echo date('Y'); ?> Dentatrak. All rights reserved.
    </div>
  </div>
</body>
</html>
