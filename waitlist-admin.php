<?php
/**
 * Waitlist Admin Page
 * View collected emails, send promo codes, add emails manually
 * Restricted to super admins only
 */

// Start session first before any other includes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in BEFORE loading heavy includes
if (!isset($_SESSION['db_user_id']) || !isset($_SESSION['user_email'])) {
    // Show stylized 404 - use inline version to avoid include issues
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - DentaTrak</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: white; }
        .container { text-align: center; padding: 40px; }
        .error-code { font-size: 120px; font-weight: 700; line-height: 1; opacity: 0.9; margin-bottom: 10px; }
        .error-title { font-size: 28px; font-weight: 600; margin-bottom: 15px; }
        .error-message { font-size: 16px; opacity: 0.8; margin-bottom: 30px; max-width: 400px; }
        .btn { display: inline-block; padding: 12px 30px; background-color: white; color: #1a365d; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .logo { margin-bottom: 40px; font-size: 24px; font-weight: 600; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">DentaTrak</div>
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-message">The page you're looking for doesn't exist or you don't have permission to access it.</p>
        <a href="/" class="btn">Go to Homepage</a>
    </div>
</body>
</html>
    <?php
    exit;
}

// Now load appConfig (which may start session again, but it's already started)
require_once __DIR__ . '/api/appConfig.php';

// Get user email from session
$userEmail = $_SESSION['user_email'];

// Check if user is a super admin
$superUsers = $appConfig['super_users'] ?? [];
if (!in_array($userEmail, $superUsers)) {
    // Show stylized 404 for non-super-admins
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - DentaTrak</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: white; }
        .container { text-align: center; padding: 40px; }
        .error-code { font-size: 120px; font-weight: 700; line-height: 1; opacity: 0.9; margin-bottom: 10px; }
        .error-title { font-size: 28px; font-weight: 600; margin-bottom: 15px; }
        .error-message { font-size: 16px; opacity: 0.8; margin-bottom: 30px; max-width: 400px; }
        .btn { display: inline-block; padding: 12px 30px; background-color: white; color: #1a365d; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .logo { margin-bottom: 40px; font-size: 24px; font-weight: 600; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">DentaTrak</div>
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-message">The page you're looking for doesn't exist or you don't have permission to access it.</p>
        <a href="/" class="btn">Go to Homepage</a>
    </div>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waitlist Admin - DentaTrak</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <style>
        :root {
            --primary: #1a365d;
            --primary-light: #2d5a87;
            --success: #38a169;
            --warning: #d69e2e;
            --danger: #e53e3e;
            --gray-50: #f7fafc;
            --gray-100: #edf2f7;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e0;
            --gray-500: #718096;
            --gray-600: #4a5568;
            --gray-700: #2d3748;
            --gray-800: #1a202c;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-700);
            line-height: 1.5;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .header-actions a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 14px;
        }
        
        .header-actions a:hover {
            color: white;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card .label {
            font-size: 13px;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .card-body {
            padding: 20px;
        }
        
        .add-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .add-form input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 14px;
        }
        
        .add-form input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(45, 90, 135, 0.1);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            opacity: 0.9;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        
        th {
            background-color: var(--gray-50);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
        }
        
        tr:hover {
            background-color: var(--gray-50);
        }
        
        .email-cell {
            font-weight: 500;
            color: var(--gray-800);
        }
        
        .date-cell {
            color: var(--gray-500);
            font-size: 13px;
        }
        
        .source-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .source-landing_page {
            background-color: #ebf8ff;
            color: #2b6cb0;
        }
        
        .source-manual_add {
            background-color: #faf5ff;
            color: #805ad5;
        }
        
        .promo-count {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .promo-count .count {
            background-color: var(--gray-100);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        
        .toast.success {
            background-color: var(--success);
        }
        
        .toast.error {
            background-color: var(--danger);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }
        
        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--gray-500);
        }
        
        .spinner {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <header class="header">
        <h1>📧 Waitlist Admin</h1>
        <div class="header-actions">
            <span>Logged in as <?php echo htmlspecialchars($userEmail); ?></span>
            <a href="main.php">← Back to App</a>
        </div>
    </header>
    
    <div class="container">
        <div class="stats-row">
            <div class="stat-card">
                <div class="label">Total Signups</div>
                <div class="value" id="totalCount">-</div>
            </div>
            <div class="stat-card">
                <div class="label">Promo Emails Sent</div>
                <div class="value" id="promosSent">-</div>
            </div>
            <div class="stat-card">
                <div class="label">Pending (No Promo)</div>
                <div class="value" id="pendingCount">-</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2>Add Email Manually</h2>
            </div>
            <div class="card-body">
                <form class="add-form" id="addForm" onsubmit="return addEmail(event)">
                    <input type="email" id="newEmail" placeholder="Enter email address" required>
                    <button type="submit" class="btn btn-primary">Add Email</button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2>Waitlist Entries</h2>
                <button class="btn btn-sm btn-primary" onclick="loadWaitlist()">Refresh</button>
            </div>
            <div class="card-body" style="padding: 0;">
                <div id="tableContainer">
                    <div class="loading">
                        <div class="spinner"></div>
                        <p style="margin-top: 10px;">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="toast" id="toast"></div>
    
    <script>
        let waitlistData = [];
        
        async function loadWaitlist() {
            const container = document.getElementById('tableContainer');
            container.innerHTML = '<div class="loading"><div class="spinner"></div><p style="margin-top: 10px;">Loading...</p></div>';
            
            try {
                const response = await fetch('api/waitlist-admin.php');
                const data = await response.json();
                
                if (data.success) {
                    waitlistData = data.entries;
                    renderTable(data.entries);
                    updateStats(data.entries);
                } else {
                    container.innerHTML = '<div class="empty-state"><p>Error: ' + (data.error || 'Failed to load') + '</p></div>';
                }
            } catch (error) {
                container.innerHTML = '<div class="empty-state"><p>Error loading waitlist</p></div>';
            }
        }
        
        function renderTable(entries) {
            const container = document.getElementById('tableContainer');
            
            if (entries.length === 0) {
                container.innerHTML = '<div class="empty-state"><p>No waitlist entries yet</p></div>';
                return;
            }
            
            let html = `
                <table>
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Date Added</th>
                            <th>Source</th>
                            <th>Promos Sent</th>
                            <th>Last Sent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            entries.forEach(entry => {
                const dateAdded = new Date(entry.created_at).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                const lastSent = entry.last_promo_sent_at 
                    ? new Date(entry.last_promo_sent_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    })
                    : '-';
                
                const sourceClass = 'source-' + (entry.source || 'landing_page');
                const sourceLabel = entry.source === 'manual_add' ? 'Manual' : 'Landing Page';
                
                html += `
                    <tr id="row-${entry.id}">
                        <td class="email-cell">${escapeHtml(entry.email)}</td>
                        <td class="date-cell">${dateAdded}</td>
                        <td><span class="source-badge ${sourceClass}">${sourceLabel}</span></td>
                        <td>
                            <span class="promo-count">
                                <span class="count">${entry.promo_emails_sent || 0}</span>
                            </span>
                        </td>
                        <td class="date-cell">${lastSent}</td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="sendPromo(${entry.id})" id="btn-${entry.id}">
                                Send Promo
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }
        
        function updateStats(entries) {
            document.getElementById('totalCount').textContent = entries.length;
            
            const promosSent = entries.reduce((sum, e) => sum + (e.promo_emails_sent || 0), 0);
            document.getElementById('promosSent').textContent = promosSent;
            
            const pending = entries.filter(e => !e.promo_emails_sent || e.promo_emails_sent === 0).length;
            document.getElementById('pendingCount').textContent = pending;
        }
        
        async function addEmail(event) {
            event.preventDefault();
            
            const emailInput = document.getElementById('newEmail');
            const email = emailInput.value.trim();
            
            if (!email) return false;
            
            try {
                const response = await fetch('api/waitlist-admin.php?action=add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast('Email added successfully!', 'success');
                    emailInput.value = '';
                    loadWaitlist();
                } else {
                    showToast(data.error || 'Failed to add email', 'error');
                }
            } catch (error) {
                showToast('Error adding email', 'error');
            }
            
            return false;
        }
        
        async function sendPromo(id) {
            const btn = document.getElementById('btn-' + id);
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Sending...';
            
            try {
                const response = await fetch('api/waitlist-admin.php?action=send-promo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast('Promo email sent!', 'success');
                    // Update the row with new data
                    if (data.entry) {
                        updateRow(data.entry);
                        // Update stats
                        const idx = waitlistData.findIndex(e => e.id === id);
                        if (idx >= 0) {
                            waitlistData[idx] = data.entry;
                            updateStats(waitlistData);
                        }
                    }
                } else {
                    showToast(data.error || 'Failed to send email', 'error');
                }
            } catch (error) {
                showToast('Error sending email', 'error');
            }
            
            btn.disabled = false;
            btn.textContent = originalText;
        }
        
        function updateRow(entry) {
            const row = document.getElementById('row-' + entry.id);
            if (!row) return;
            
            const cells = row.querySelectorAll('td');
            
            // Update promo count
            cells[3].innerHTML = `<span class="promo-count"><span class="count">${entry.promo_emails_sent || 0}</span></span>`;
            
            // Update last sent
            const lastSent = entry.last_promo_sent_at 
                ? new Date(entry.last_promo_sent_at).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })
                : '-';
            cells[4].textContent = lastSent;
        }
        
        function showToast(message, type) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type + ' show';
            
            setTimeout(() => {
                toast.className = 'toast';
            }, 3000);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Load on page load
        loadWaitlist();
    </script>
</body>
</html>
