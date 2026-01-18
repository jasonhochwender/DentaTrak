<?php
/**
 * Stylized 404 Page
 */
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - DentaTrak</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .container {
            text-align: center;
            padding: 40px;
        }
        
        .error-code {
            font-size: 120px;
            font-weight: 700;
            line-height: 1;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .error-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .error-message {
            font-size: 16px;
            opacity: 0.8;
            margin-bottom: 30px;
            max-width: 400px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background-color: white;
            color: #1a365d;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .logo {
            margin-bottom: 40px;
            font-size: 24px;
            font-weight: 600;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">DentaTrak</div>
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-message">
            The page you're looking for doesn't exist or you don't have permission to access it.
        </p>
        <a href="/" class="btn">Go to Homepage</a>
    </div>
</body>
</html>
