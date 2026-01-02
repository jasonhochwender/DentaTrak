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
  
  <title>Privacy Policy - Dentatrak</title>
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
      <h1>Privacy Policy</h1>
      <p class="app-name">Dentatrak</p>
    </div>
    
    <div class="policy-content">
      <p><strong>Effective Date:</strong> December 22, 2025</p>
      
      <p>This Privacy Policy explains how Dentatrak ("we," "us," or "our") collects, uses, and protects your information when you use our dental case tracking service ("Service"). Dentatrak is a case management tool designed for dental practices.</p>
      
      <h4>1. Information We Collect</h4>
      <p>We collect the following types of information:</p>
      <ul>
        <li><strong>Account Information:</strong> Your name and email address, used to create and manage your account</li>
        <li><strong>Authentication Data:</strong> Information necessary to verify your identity when you sign in</li>
        <li><strong>Practice and Case Data:</strong> Information you and your team enter into the Service, including patient case details, notes, due dates, and related practice information</li>
      </ul>
      
      <h4>2. Google OAuth Disclosure</h4>
      <p>Dentatrak uses Google OAuth solely for authentication purposes. When you sign in with Google:</p>
      <ul>
        <li>We access only your basic profile information (name and email address)</li>
        <li>We use this information only to identify your account and enable sign-in</li>
        <li>We do not sell, share, or transfer your Google user data to any third parties</li>
        <li>We do not use your Google data for advertising or any purpose unrelated to providing the Service</li>
      </ul>
      
      <h4>3. How We Use Your Information</h4>
      <p>We use your information to:</p>
      <ul>
        <li>Provide access to your account and the Service</li>
        <li>Enable your dental practice to track and manage cases</li>
        <li>Deliver the core functionality of the Service</li>
        <li>Communicate with you about your account or the Service</li>
      </ul>
      
      <h4>4. Data Protection</h4>
      <p>We take reasonable measures to protect your information, including:</p>
      <ul>
        <li>Encryption of data during transmission and storage</li>
        <li>Access controls to limit who can view your data</li>
        <li>Regular review of our security practices</li>
      </ul>
      
      <h4>5. Data Sharing</h4>
      <p>We do not sell your information. We share your data only:</p>
      <ul>
        <li>With service providers who help us operate the Service (under appropriate agreements)</li>
        <li>When required by law or to protect our rights</li>
        <li>With your consent</li>
      </ul>
      
      <h4>6. Your Rights</h4>
      <p>You may:</p>
      <ul>
        <li><strong>Access your data:</strong> Request a copy of the information we hold about you</li>
        <li><strong>Correct your data:</strong> Update inaccurate information in your account</li>
        <li><strong>Delete your data:</strong> Request deletion of your account and associated data</li>
        <li><strong>Revoke access:</strong> Remove Dentatrak's access to your Google account at any time through your Google account settings</li>
      </ul>
      <p>To exercise these rights, contact us at <a href="mailto:privacy@dentatrak.com">privacy@dentatrak.com</a>.</p>
      
      <h4>7. Data Retention</h4>
      <p>We retain your data while your account is active. If you request account deletion, we will remove your data within 30 days, unless we are required to retain it for legal reasons.</p>
      
      <h4>8. Changes to This Policy</h4>
      <p>We may update this Privacy Policy from time to time. We will post changes on this page and update the effective date. Continued use of the Service after changes constitutes acceptance of the updated policy.</p>
      
      <h4>9. Contact Us</h4>
      <p>If you have questions about this Privacy Policy or our data practices, please contact us at:</p>
      <p><a href="mailto:privacy@dentatrak.com">privacy@dentatrak.com</a></p>
    </div>
    
    <div class="policy-footer">
      &copy; <?php echo date('Y'); ?> Dentatrak. All rights reserved.
    </div>
  </div>
</body>
</html>
