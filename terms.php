<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();
$appName = $appConfig['appName'] ?? 'DentaTrak';
$loginUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/login.php';
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
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

  <title><?php echo htmlspecialchars(t('legal.terms.title')) . ' - ' . htmlspecialchars($appName); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars(t('legal.terms.meta_description')); ?>">
  <link rel="canonical" href="https://dentatrak.com/terms">

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

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
    <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="back-link">← <?php echo t('common.back_to_sign_in'); ?></a>

    <div class="policy-header">
      <h1><?php echo t('legal.terms.title'); ?></h1>
      <p class="app-name"><?php echo htmlspecialchars($appName); ?></p>
    </div>

    <?php require_once __DIR__ . '/partials/legal-terms.php'; ?>

    <div class="policy-footer">
      &copy; <?php echo date('Y') . ' ' . htmlspecialchars($appName) . '. ' . t('common.all_rights_reserved'); ?>
    </div>
  </div>
</body>
</html>
