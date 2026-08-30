<?php
/**
 * Admin Practices Management Page
 * 
 * Practice & Subscription Management for system administrators
 * - View all practices with compliance status
 * - Activate/deactivate practices
 * - View PHI access logs
 * - Data retention management
 */

require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

// Generate a CSRF token for state-changing admin actions
$csrfToken = generateCsrfToken();

// Check if user is logged in
if (empty($_SESSION['db_user_id'])) {
    header('Location: login.php');
    exit;
}

// Load dev tools access control
require_once __DIR__ . '/api/dev-tools-access.php';

// Check if current user can access admin pages. In production/UAT/Cloud Run
// only configured super users are allowed. The 'development' exception applies
// only to the local MAMP environment and must never be used for production
// deployments or exposed to the network.
$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? 'Admin';
$userEmail = $_SESSION['user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title><?php echo htmlspecialchars(t('admin_practices.title')); ?> - <?php echo htmlspecialchars($appConfig['appName']); ?></title>

    <!-- Favicon / App Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/app.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: #f3f4f6;
            margin: 0;
            padding: 20px;
        }
        
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding: 20px 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .admin-header h1 {
            margin: 0;
            font-size: 1.5rem;
            color: #1f2937;
        }
        
        .admin-header .subtitle {
            color: #6b7280;
            font-size: 0.9rem;
            margin-top: 4px;
        }
        
        .back-link {
            color: #3b82f6;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card .label {
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }
        
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .stat-card.warning .value {
            color: #d97706;
        }
        
        .stat-card.danger .value {
            color: #dc2626;
        }
        
        .stat-card.success .value {
            color: #059669;
        }
        
        .practices-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: visible;
            min-width: 0;
        }
        
        .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h2 {
            margin: 0;
            font-size: 1.1rem;
            color: #1f2937;
        }
        
        .practices-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .practices-table th,
        .practices-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
            min-width: 0;
            white-space: normal;
            word-wrap: normal;
            overflow-wrap: normal;
        }

        .practices-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
            line-height: 1.2;
        }

        .practices-table th.col-practice,
        .practices-table td.col-practice {
            width: 40%;
            min-width: 180px;
        }

        .practices-table th.col-account,
        .practices-table td.col-account {
            width: 24%;
            min-width: 120px;
        }

        .practices-table th.col-usage,
        .practices-table td.col-usage {
            width: 24%;
            min-width: 120px;
        }

        .practices-table th.col-actions,
        .practices-table td.col-actions {
            width: 100px;
            min-width: 100px;
            overflow: visible;
        }

        .practice-name {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.3;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .practice-legal {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #6b7280;
            font-size: 0.75rem;
            margin-top: 2px;
        }

        .account-detail {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #6b7280;
            font-size: 0.75rem;
            margin-top: 4px;
            max-width: 100%;
        }

        .trial-urgent,
        .trial-expired {
            color: #dc2626;
            font-weight: 500;
        }

        .usage-primary,
        .usage-secondary {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
            font-size: 0.85rem;
        }

        .usage-secondary {
            color: #6b7280;
            font-size: 0.75rem;
            margin-top: 2px;
        }
        
        .practices-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .practices-table tr:hover {
            background: #f9fafb;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            flex-wrap: nowrap;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 500;
            white-space: nowrap;
            max-width: 100%;
        }
        
        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.success {
            background: #d1fae5;
            color: #065f46;
        }

        .baa-badge {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: normal;
            max-width: 100%;
        }
        
        .baa-badge.accepted {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .baa-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn.primary {
            background: #3b82f6;
            color: white;
        }
        
        .action-btn.primary:hover {
            background: #2563eb;
        }
        
        .action-btn.danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .action-btn.danger:hover {
            background: #fecaca;
        }
        
        .action-btn.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .action-btn.success:hover {
            background: #a7f3d0;
        }
        
        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 24px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #1f2937;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
        }
        
        .compliance-detail {
            margin-bottom: 16px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .compliance-detail .label {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .compliance-detail .value {
            font-weight: 600;
            color: #1f2937;
        }
        
        .retention-warning {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }
        
        .retention-warning h4 {
            margin: 0 0 8px 0;
            color: #92400e;
        }
        
        .retention-warning p {
            margin: 0;
            color: #78350f;
            font-size: 0.9rem;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .phi-log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            table-layout: fixed;
        }
        
        .phi-log-table th,
        .phi-log-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .phi-log-table th {
            background: #f3f4f6;
            font-weight: 600;
        }
        
        .phi-log-table th:nth-child(1),
        .phi-log-table td:nth-child(1) { width: 22%; } /* Date/Time */
        .phi-log-table th:nth-child(2),
        .phi-log-table td:nth-child(2) { width: 30%; } /* User */
        .phi-log-table th:nth-child(3),
        .phi-log-table td:nth-child(3) { width: 15%; } /* Action */
        .phi-log-table th:nth-child(4),
        .phi-log-table td:nth-child(4) { width: 18%; } /* Case */
        .phi-log-table th:nth-child(5),
        .phi-log-table td:nth-child(5) { width: 15%; } /* IP */

        .table-scroll {
            overflow-x: auto;
            width: 100%;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            table-layout: auto;
        }

        .users-table th,
        .users-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
            vertical-align: top;
        }

        .users-table th {
            background: #f3f4f6;
            font-weight: 600;
        }

        .users-table td.permission,
        .users-table th.permission {
            text-align: center;
        }

        .users-table .text-muted {
            color: #6b7280;
        }

        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #374151;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-family: inherit;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .main-content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        @media (max-width: 1200px) {
            .main-content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .detail-panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            min-width: 0;
        }
        
        .detail-panel-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            color: #9ca3af;
            font-size: 0.95rem;
        }
        
        .detail-panel-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        
        .detail-panel-header h3 {
            margin: 0 0 4px 0;
            font-size: 1.1rem;
            color: #1f2937;
        }
        
        .detail-panel-header .subtitle {
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        .detail-tabs {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .detail-tab {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            color: #6b7280;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
        }
        
        .detail-tab:hover {
            color: #374151;
        }
        
        .detail-tab.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
            font-weight: 500;
        }
        
        .detail-content {
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .practice-row {
            cursor: pointer;
            transition: background 0.15s;
        }
        
        .practice-row:hover {
            background: #f3f4f6 !important;
        }
        
        .practice-row.selected {
            background: #eff6ff !important;
        }
        
        .col-actions {
            position: relative;
            text-align: right;
        }

        .actions-toggle {
            padding: 4px 8px;
            font-size: 0.75rem;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            white-space: nowrap;
        }

        .actions-toggle:hover,
        .actions-toggle:focus-visible {
            background: #e5e7eb;
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        .actions-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            min-width: 170px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            padding: 4px;
            text-align: left;
        }

        .actions-menu.active {
            display: block;
        }

        .action-menu-item {
            display: block;
            width: 100%;
            padding: 8px 12px;
            background: none;
            border: none;
            border-radius: 4px;
            text-align: left;
            font-size: 0.85rem;
            color: #374151;
            cursor: pointer;
            white-space: nowrap;
        }

        .action-menu-item:hover,
        .action-menu-item:focus-visible {
            background: #f3f4f6;
            outline: none;
        }

        .action-menu-item--danger:hover,
        .action-menu-item--danger:focus-visible {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .view-toggle {
            display: flex;
            gap: 8px;
        }
        
        .view-toggle .action-btn {
            background: #f3f4f6;
            color: #374151;
        }
        
        .view-toggle .action-btn.active {
            background: #3b82f6;
            color: white;
        }
        
        .action-btn.secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .action-btn.secondary:hover {
            background: #d1d5db;
        }
        
        .action-btn.warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .action-btn.warning:hover {
            background: #fde68a;
        }
        
        .settings-card {
            background: #f9fafb;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 14px;
        }
        
        .settings-card h4 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 0.95rem;
        }
        
        .settings-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.85rem;
        }
        
        .settings-row:last-child {
            border-bottom: none;
        }
        
        .settings-row .settings-label {
            color: #6b7280;
        }
        
        .settings-row .settings-value {
            font-weight: 500;
            color: #1f2937;
        }
        
        .settings-list {
            margin: 0;
            padding-left: 18px;
            font-size: 0.85rem;
        }
        
        .settings-list li {
            margin-bottom: 4px;
        }
        /* Compact UI overrides */
        .admin-header {
            margin-bottom: 16px;
            padding: 14px 18px;
        }
        .admin-header h1 {
            font-size: 1.25rem;
        }
        .admin-header .subtitle {
            font-size: 0.8rem;
        }
        .stats-grid {
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            padding: 12px 14px;
            border-radius: 8px;
        }
        .stat-card .label {
            font-size: 0.75rem;
            margin-bottom: 4px;
        }
        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        .table-header {
            padding: 10px 14px;
        }
        .table-header h2 {
            font-size: 0.95rem;
        }
        .practices-table th,
        .practices-table td {
            padding: 8px 10px;
        }
        .practices-table th {
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
        }

        .practices-table strong {
            font-size: 0.9rem;
            font-weight: 600;
        }
        .practices-table small {
            font-size: 0.75rem;
        }
        .status-badge,
        .baa-badge {
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .detail-panel-header {
            padding: 12px 14px;
        }
        .detail-panel-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }
        .detail-panel-header .subtitle {
            font-size: 0.8rem;
        }
        .detail-tab {
            padding: 8px 12px;
            font-size: 0.8rem;
        }
        .detail-content {
            padding: 14px;
        }
        .compliance-detail {
            padding: 8px 10px;
            margin-bottom: 10px;
        }
        .compliance-detail .label {
            font-size: 0.75rem;
            margin-bottom: 2px;
        }
        .compliance-detail .value {
            font-size: 0.85rem;
            font-weight: 500;
        }
        .settings-card {
            padding: 10px 12px;
            margin-bottom: 10px;
        }
        .settings-card h4 {
            font-size: 0.85rem;
            margin: 0 0 6px 0;
        }
        .settings-row {
            padding: 4px 0;
            font-size: 0.8rem;
        }
        .phi-log-table th,
        .phi-log-table td,
        .users-table th,
        .users-table td {
            padding: 6px 8px;
            font-size: 0.75rem;
        }
        .loading, .empty-state {
            padding: 24px;
        }
        .trial-urgent {
            color: #dc2626;
            font-weight: 600;
        }
        .trial-expired {
            color: #991b1b;
            font-weight: 700;
        }
        .trial-normal {
            color: #1f2937;
            font-weight: 500;
        }
        .baa-badge.pending {
            background: #fee2e2;
            color: #991b1b;
        }
        .hidden-note {
            color: #6b7280;
            font-size: 0.75rem;
            margin-left: 6px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php if ($isDev && !isSuperUser($appConfig, $userEmail)): ?>
        <div class="dev-mode-notice" style="background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500;">
            Development environment: admin tools are open to any authenticated user for local testing. In production, only super users can access this page.
        </div>
        <?php endif; ?>
        <div class="admin-header">
            <div>
                <h1>🏥 <?php echo t('admin_practices.title'); ?></h1>
                <div class="subtitle"><?php echo t('admin_practices.subtitle'); ?></div>
            </div>
            <a href="main.php" class="back-link">
                ← <?php echo t('admin_practices.back_to_dashboard'); ?>
            </a>
        </div>
        
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="label"><?php echo t('admin_practices.stats_total'); ?></div>
                <div class="value" id="totalPractices">-</div>
            </div>
            <div class="stat-card success">
                <div class="label"><?php echo t('admin_practices.stats_active'); ?></div>
                <div class="value" id="activePractices">-</div>
            </div>
            <div class="stat-card warning">
                <div class="label"><?php echo t('admin_practices.stats_inactive'); ?></div>
                <div class="value" id="inactivePractices">-</div>
            </div>
            <div class="stat-card danger">
                <div class="label"><?php echo t('admin_practices.stats_deletion_eligible'); ?></div>
                <div class="value" id="deletionEligible">-</div>
            </div>
        </div>
        
        <!-- Two-panel layout -->
        <div class="main-content-grid">
            <!-- Left panel: Practice list -->
            <div class="practices-table-container">
                <div class="table-header">
                    <h2 id="practicesTableTitle"><?php echo t('admin_practices.view_all'); ?></h2>
                    <div class="view-toggle" id="viewToggle">
                        <button class="action-btn active" id="viewAllBtn" onclick="setView('all')"><?php echo t('admin_practices.view_all'); ?></button>
                        <button class="action-btn" id="viewHiddenBtn" onclick="setView('hidden')"><?php echo t('admin_practices.view_hidden'); ?></button>
                        <button class="action-btn primary" onclick="loadPractices()">↻ <?php echo t('admin_practices.refresh'); ?></button>
                    </div>
                </div>
                <div id="practicesTableBody">
                    <div class="loading"><?php echo t('admin_practices.loading'); ?></div>
                </div>
            </div>
            
            <!-- Right panel: Practice details & PHI log -->
            <div class="detail-panel" id="detailPanel">
                <div class="detail-panel-empty">
                    <p><?php echo t('admin_practices.select_prompt'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Compliance Details Modal -->
    <div class="modal" id="complianceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><?php echo t('admin_practices.tabs_compliance'); ?></h3>
                <button class="modal-close" onclick="closeModal('complianceModal')">&times;</button>
            </div>
            <div id="complianceDetails">
                <div class="loading"><?php echo t('common.loading'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- PHI Access Log Modal -->
    <div class="modal" id="phiLogModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3><?php echo t('admin_practices.tabs_phi_log'); ?></h3>
                <button class="modal-close" onclick="closeModal('phiLogModal')">&times;</button>
            </div>
            <div id="phiLogContent">
                <div class="loading"><?php echo t('common.loading'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Deactivate Practice Modal -->
    <div class="modal" id="deactivateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><?php echo t('admin_practices.deactivate_title'); ?></h3>
                <button class="modal-close" onclick="closeModal('deactivateModal')">&times;</button>
            </div>
            <div id="deactivateContent">
                <p><?php echo t('admin_practices.deactivate_prompt'); ?></p>
                <p><strong><?php echo t('admin_practices.practice_label'); ?>:</strong> <span id="deactivatePracticeName"></span></p>
                
                <div class="retention-warning">
                    <h4>⚠️ <?php echo t('admin_practices.data_retention_title'); ?></h4>
                    <p><?php echo t('admin_practices.retention_warning'); ?></p>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label for="deactivateReason"><?php echo t('admin_practices.deactivate_reason_label'); ?></label>
                    <textarea id="deactivateReason" rows="3" placeholder="<?php echo t('admin_practices.deactivate_reason_placeholder'); ?>"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button class="action-btn" onclick="closeModal('deactivateModal')"><?php echo t('common.cancel'); ?></button>
                    <button class="action-btn danger" id="confirmDeactivateBtn" onclick="confirmDeactivate()"><?php echo t('admin_practices.deactivate_button'); ?></button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        window.csrfToken = <?php echo json_encode($csrfToken); ?>;
    </script>
    <?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
    <script>
        const yesNo = value => value ? t('common.yes') : t('common.no');

        function postJson(url, payload) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify(Object.assign({}, payload, { csrf_token: window.csrfToken || '' }))
            });
        }

        function formatRelativeTimestamp(dateStr, emptyLabel = '—') {
            if (!dateStr || dateStr === '0000-00-00 00:00:00') {
                return '<span style="color: #9ca3af;">' + escapeHtml(emptyLabel) + '</span>';
            }

            const date = new Date(dateStr);
            if (isNaN(date.getTime())) {
                return '<span style="color: #9ca3af;">' + escapeHtml(emptyLabel) + '</span>';
            }

            const now = new Date();
            now.setHours(0, 0, 0, 0);
            const activityDate = new Date(date);
            activityDate.setHours(0, 0, 0, 0);
            const msPerDay = 1000 * 60 * 60 * 24;
            const diffDays = Math.round((now.getTime() - activityDate.getTime()) / msPerDay);

            let text;
            if (diffDays < 0) {
                text = formatDate(dateStr); // future (clock skew)
            } else if (diffDays === 0) {
                text = t('common.today');
            } else if (diffDays === 1) {
                text = t('common.yesterday');
            } else if (diffDays <= 6) {
                text = t('common.days_ago', {count: diffDays});
            } else if (diffDays <= 90) {
                text = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            } else {
                text = date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            }

            return '<span title="' + escapeHtml(formatDateTime(dateStr)) + '">' + escapeHtml(text) + '</span>';
        }

        function adminSubscriptionStatusClass(status) {
            switch (status) {
                case 'active': return 'success';
                case 'trialing': return 'warning';
                case 'trial_expired': return 'danger';
                case 'past_due':
                case 'unpaid': return 'danger';
                case 'canceled':
                case 'incomplete_expired': return 'inactive';
                case 'incomplete': return 'warning';
                default: return 'inactive';
            }
        }

        let practices = [];
        let selectedPracticeId = null;
        let currentView = 'all';

        // Counter and helper to prevent stale tab requests (slower/older responses
        // from a previously selected practice or tab cannot overwrite the current view).
        let lastTabRequestId = 0;

        function isCurrentTabRequest(requestId, practiceId) {
            return requestId === lastTabRequestId && practiceId === selectedPracticeId;
        }

        function loadTab(url, renderFn, tabName, practiceId) {
            const requestId = ++lastTabRequestId;

            fetch(url, { credentials: 'same-origin' })
                .then(response => {
                    if (!isCurrentTabRequest(requestId, practiceId)) return null;
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (!isCurrentTabRequest(requestId, practiceId)) return;
                    if (data && data.success) {
                        renderFn(data);
                    } else {
                        const message = data && data.message ? data.message : 'Unknown error';
                        document.getElementById('detailContent').innerHTML =
                            '<div class="empty-state">Error loading ' + escapeHtml(tabName) + ': ' + escapeHtml(message) +
                            '<br><button type="button" class="action-btn" style="margin-top: 12px;" onclick="showTab(\'' + tabName + '\', ' + practiceId + ')">Retry</button></div>';
                    }
                })
                .catch(error => {
                    if (!isCurrentTabRequest(requestId, practiceId)) return;
                    console.error('[' + tabName + ' tab] ' + error.message);
                    document.getElementById('detailContent').innerHTML =
                        '<div class="empty-state">Failed to load ' + escapeHtml(tabName) + ': ' + escapeHtml(error.message) +
                        '<br><button type="button" class="action-btn" style="margin-top: 12px;" onclick="showTab(\'' + tabName + '\', ' + practiceId + ')">Retry</button></div>';
                });
        }
        
        // Load practices on page load
        document.addEventListener('DOMContentLoaded', loadPractices);
        
        function loadPractices() {
            document.getElementById('practicesTableBody').innerHTML = '<div class="loading">' + t('admin_practices.loading') + '</div>';
            
            fetch('api/admin-practices.php?action=list', { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        practices = data.practices;
                        renderPractices();
                        updateStats();
                    } else {
                        document.getElementById('practicesTableBody').innerHTML = 
                            '<div class="empty-state">Error loading practices: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('practicesTableBody').innerHTML = 
                        '<div class="empty-state">Error loading practices: ' + error.message + '</div>';
                });
        }
        
        function setView(view) {
            currentView = view;
            document.getElementById('viewAllBtn').classList.toggle('active', view === 'all');
            document.getElementById('viewHiddenBtn').classList.toggle('active', view === 'hidden');
            document.getElementById('practicesTableTitle').textContent = view === 'all' ? t('admin_practices.view_all') : t('admin_practices.view_hidden');
            renderPractices();
            updateStats();
        }
        
        function visiblePractices() {
            if (currentView === 'hidden') {
                return practices.filter(p => p.is_hidden);
            }
            return practices.filter(p => !p.is_hidden);
        }
        
        function updateStats() {
            const visible = visiblePractices();
            const total = visible.length;
            const active = visible.filter(p => p.is_active === true || p.is_active === '1' || p.is_active === 1).length;
            const inactive = total - active;
            const deletionEligible = visible.filter(p => p.can_delete).length;
            
            document.getElementById('totalPractices').textContent = total;
            document.getElementById('activePractices').textContent = active;
            document.getElementById('inactivePractices').textContent = inactive;
            document.getElementById('deletionEligible').textContent = deletionEligible;
        }
        
        function renderPractices() {
            const visible = visiblePractices();
            if (visible.length === 0) {
                document.getElementById('practicesTableBody').innerHTML =
                    '<div class="empty-state">' + t('admin_practices.no_practices_view') + '</div>';
                return;
            }

            let html = '<table class="practices-table"><thead><tr>' +
                '<th class="col-practice">' + t('admin_practices.table_practice_name') + '</th>' +
                '<th class="col-account">Account</th>' +
                '<th class="col-usage">Usage</th>' +
                '<th class="col-actions">' + t('admin_practices.table_actions') + '</th>' +
                '</tr></thead><tbody>';

            visible.forEach(practice => {
                const isActive = practice.is_active === true || practice.is_active === '1' || practice.is_active === 1;
                const statusClass = isActive ? 'active' : 'inactive';
                const statusText = isActive ? t('admin_practices.status_active') : t('admin_practices.status_inactive');

                const sub = practice.subscription || {};
                const subscriptionStatusClass = adminSubscriptionStatusClass(sub.status);
                const subscriptionStatusText = escapeHtml(sub.status_display || t('admin_practices.no_subscription'));

                let accountDetailRaw;
                let accountDetailClass = '';
                if (!sub.has_subscription) {
                    accountDetailRaw = subscriptionStatusText;
                } else if (sub.is_trialing || sub.status === 'trial_expired') {
                    accountDetailRaw = escapeHtml(sub.trial_line || '');
                    accountDetailClass = sub.trial_class || '';
                } else if (sub.plan_display && sub.plan_display !== '—') {
                    accountDetailRaw = escapeHtml(sub.plan_display) + ' · ' + subscriptionStatusText;
                } else {
                    accountDetailRaw = subscriptionStatusText;
                }

                const accountDetailTitle = accountDetailRaw.replace(/"/g, '&quot;');

                const userCount = practice.user_count || 0;
                const totalCases = practice.adoption?.total_cases || 0;
                const userLabel = userCount === 1 ? '1 user' : userCount + ' users';
                const caseLabel = totalCases === 1 ? '1 case' : totalCases + ' cases';
                const usagePrimaryText = userLabel + ' · ' + caseLabel;
                const usagePrimaryTitle = escapeHtml(usagePrimaryText).replace(/"/g, '&quot;');
                const lastActivity = formatRelativeTimestamp(practice.adoption?.last_activity, t('admin_practices.no_activity_recorded'));

                const practiceNameRaw = practice.practice_name || practice.legal_name || t('admin_practices.unnamed');
                const practiceName = escapeHtml(practiceNameRaw).replace(/'/g, "\\'");
                const practiceTitle = escapeHtml(practiceNameRaw).replace(/"/g, '&quot;');
                const legalNameRaw = practice.legal_name || '';
                const legalName = legalNameRaw && legalNameRaw !== practiceNameRaw ? escapeHtml(legalNameRaw) : '';
                const legalNameTitle = legalName ? escapeHtml(legalNameRaw).replace(/"/g, '&quot;') : '';
                const selectedClass = selectedPracticeId === practice.id ? 'selected' : '';

                const reactivateItem = !isActive
                    ? '<button type="button" class="action-menu-item" role="menuitem" onclick="reactivatePractice(' + practice.id + ', \'' + practiceName + '\'); closeAllActionsMenus();">' + t('admin_practices.reactivate_button') + '</button>'
                    : '';
                const deactivateItem = isActive
                    ? '<button type="button" class="action-menu-item action-menu-item--danger" role="menuitem" onclick="deactivatePractice(' + practice.id + ', \'' + practiceName + '\'); closeAllActionsMenus();">' + t('admin_practices.deactivate_button') + '</button>'
                    : '';
                const hideUnhideItem = practice.is_hidden
                    ? '<button type="button" class="action-menu-item" role="menuitem" onclick="confirmUnhidePractice(' + practice.id + ', \'' + practiceName + '\'); closeAllActionsMenus();">' + t('admin_practices.unhide_button') + '</button>'
                    : '<button type="button" class="action-menu-item" role="menuitem" onclick="confirmHidePractice(' + practice.id + ', \'' + practiceName + '\'); closeAllActionsMenus();">' + t('admin_practices.hide_button') + '</button>';

                html += '<tr class="practice-row ' + selectedClass + '" onclick="selectPractice(' + practice.id + ')" data-practice-id="' + practice.id + '">' +
                    '<td class="col-practice">' +
                        '<strong class="practice-name" title="' + practiceTitle + '">' + escapeHtml(practiceNameRaw) + '</strong>' +
                        (legalName ? '<small class="practice-legal" title="' + legalNameTitle + '">' + legalName + '</small>' : '') +
                    '</td>' +
                    '<td class="col-account">' +
                        '<span class="status-badge ' + statusClass + '">' + statusText + '</span>' +
                        '<small class="account-detail ' + accountDetailClass + '" title="' + accountDetailTitle + '">' + escapeHtml(accountDetailRaw) + '</small>' +
                    '</td>' +
                    '<td class="col-usage">' +
                        '<div class="usage-primary" title="' + usagePrimaryTitle + '">' + usagePrimaryText + '</div>' +
                        '<div class="usage-secondary">Last: ' + lastActivity + '</div>' +
                    '</td>' +
                    '<td class="col-actions" onclick="event.stopPropagation()">' +
                        '<button class="action-btn actions-toggle" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Actions for ' + practiceName + '" onclick="toggleActionsMenu(event, ' + practice.id + ')">' + t('admin_practices.table_actions') + '</button>' +
                        '<div class="actions-menu" id="actionsMenu-' + practice.id + '" role="menu" aria-label="Actions for ' + practiceName + '">' +
                            reactivateItem +
                            deactivateItem +
                            hideUnhideItem +
                        '</div>' +
                    '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            document.getElementById('practicesTableBody').innerHTML = html;
        }

        function toggleActionsMenu(event, practiceId) {
            event.stopPropagation();
            const menu = document.getElementById('actionsMenu-' + practiceId);
            if (!menu) return;
            const wasOpen = menu.classList.contains('active');
            closeAllActionsMenus();
            if (!wasOpen) {
                menu.classList.add('active');
                const toggle = menu.previousElementSibling;
                if (toggle) toggle.setAttribute('aria-expanded', 'true');
            }
        }

        function closeAllActionsMenus() {
            document.querySelectorAll('.actions-menu.active').forEach(menu => {
                menu.classList.remove('active');
                const toggle = menu.previousElementSibling;
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            });
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.col-actions')) {
                closeAllActionsMenus();
            }
        });

        function selectPractice(practiceId) {
            selectedPracticeId = practiceId;
            
            // Update row selection
            document.querySelectorAll('.practice-row').forEach(row => {
                row.classList.remove('selected');
                if (parseInt(row.dataset.practiceId) === practiceId) {
                    row.classList.add('selected');
                }
            });
            
            // Find practice data
            const practice = practices.find(p => p.id === practiceId);
            if (!practice) return;
            
            // Show detail panel with tabs
            const isActive = practice.is_active === true || practice.is_active === '1' || practice.is_active === 1;
            
            document.getElementById('detailPanel').innerHTML =
                '<div class="detail-panel-header">' +
                    '<div style="display: flex; justify-content: space-between; align-items: flex-start;">' +
                        '<div>' +
                            '<h3>' + escapeHtml(practice.practice_name || practice.legal_name || t('admin_practices.unnamed')) + '</h3>' +
                            '<div class="subtitle">' + (isActive ? '✅ ' + t('admin_practices.status_active') : '❌ ' + t('admin_practices.status_inactive')) + ' • ' + (practice.user_count || 0) + ' ' + t('admin_practices.table_users').toLowerCase() + ' • ' + (practice.case_count || 0) + ' ' + t('navigation.cases').toLowerCase() + '</div>' +
                        '</div>' +
                        '<button class="action-btn primary" onclick="printPracticeDetails(' + practiceId + ')" style="flex-shrink: 0;">🖨️ ' + t('admin_practices.print') + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="detail-tabs">' +
                    '<button class="detail-tab active" onclick="showTab(\'compliance\', ' + practiceId + ')">' + t('admin_practices.tabs_compliance') + '</button>' +
                    '<button class="detail-tab" onclick="showTab(\'usage\', ' + practiceId + ')">' + t('admin_practices.tabs_usage') + '</button>' +
                    '<button class="detail-tab" onclick="showTab(\'subscription\', ' + practiceId + ')">' + t('admin_practices.tabs_subscription') + '</button>' +
                    '<button class="detail-tab" onclick="showTab(\'phi\', ' + practiceId + ')">' + t('admin_practices.tabs_phi_log') + '</button>' +
                    '<button class="detail-tab" onclick="showTab(\'users\', ' + practiceId + ')">' + t('admin_practices.tabs_users') + '</button>' +
                    '<button class="detail-tab" onclick="showTab(\'settings\', ' + practiceId + ')">' + t('admin_practices.tabs_settings') + '</button>' +
                '</div>' +
                '<div class="detail-content" id="detailContent">' +
                    '<div class="loading">' + t('common.loading') + '</div>' +
                '</div>';
            
            // Load compliance tab by default
            loadComplianceTab(practiceId);
        }
        
        function showTab(tab, practiceId) {
            // Update tab buttons
            document.querySelectorAll('.detail-tab').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            document.getElementById('detailContent').innerHTML = '<div class="loading">' + t('common.loading') + '</div>';
            
            if (tab === 'compliance') {
                loadComplianceTab(practiceId);
            } else if (tab === 'usage') {
                loadAdoptionTab(practiceId);
            } else if (tab === 'subscription') {
                loadSubscriptionTab(practiceId);
            } else if (tab === 'phi') {
                loadPHITab(practiceId);
            } else if (tab === 'users') {
                loadUsersTab(practiceId);
            } else if (tab === 'settings') {
                loadSettingsTab(practiceId);
            }
        }
        
        function loadComplianceTab(practiceId) {
            loadTab('api/admin-practices.php?action=compliance&practice_id=' + practiceId, data => renderComplianceTab(data.compliance), 'compliance', practiceId);
        }
        
        function renderComplianceTab(compliance) {
            let html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">' +
                '<div class="compliance-detail">' +
                    '<div class="label">Legal Name</div>' +
                    '<div class="value">' + escapeHtml(compliance.legal_name || 'N/A') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                    '<div class="label">BAA Status</div>' +
                    '<div class="value">' + (compliance.baa_accepted 
                        ? '✅ v' + compliance.baa_version + ' (' + formatDate(compliance.baa_accepted_at) + ')'
                        : '⚠️ Not Accepted') + '</div>' +
                '</div>' +
                '<div class="compliance-detail" style="grid-column: 1 / -1;">' +
                    '<div class="label">BAA Practice Address</div>' +
                    '<div class="value">' + (compliance.practice_address ? escapeHtml(compliance.practice_address).replace(/\n/g, '<br>') : 'Not provided') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                    '<div class="label">Created</div>' +
                    '<div class="value">' + formatDate(compliance.created_at) + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                    '<div class="label">Last Activity</div>' +
                    '<div class="value">' + (compliance.last_activity_at ? formatDate(compliance.last_activity_at) : 'Never') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                    '<div class="label">Total Cases</div>' +
                    '<div class="value">' + compliance.total_cases + ' (' + compliance.archived_cases + ' archived)</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                    '<div class="label">PHI Access (30 days)</div>' +
                    '<div class="value">' + Object.values(compliance.phi_access_last_30_days || {}).reduce((a, b) => a + b, 0) + 
                    ' events</div>' +
                '</div>' +
                '</div>';
            
            if (!compliance.is_active && compliance.deactivated_at) {
                html += '<div class="retention-warning" style="margin-top: 16px;">' +
                    '<h4>⚠️ Data Retention</h4>' +
                    '<p><strong>Deactivated:</strong> ' + formatDate(compliance.deactivated_at) + '</p>' +
                    '<p><strong>Deletion Eligible:</strong> ' + formatDate(compliance.data_deletion_eligible_at) + '</p>' +
                    '</div>';
            }
            
            document.getElementById('detailContent').innerHTML = html;
        }

        function loadSubscriptionTab(practiceId) {
            const practice = practices.find(p => p.id === practiceId);
            if (!practice) {
                document.getElementById('detailContent').innerHTML = '<div class="empty-state">Practice not found</div>';
                return;
            }
            renderSubscriptionTab(practice.subscription || {});
        }

        function renderSubscriptionTab(subscription) {
            let html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">';

            const row = (label, value) => {
                return '<div class="compliance-detail">' +
                    '<div class="label">' + escapeHtml(label) + '</div>' +
                    '<div class="value">' + (value !== null && value !== undefined && value !== '' ? escapeHtml(value) : '—') + '</div>' +
                    '</div>';
            };

            html += row('Plan', subscription.plan_display);
            html += row('Subscription Status', subscription.status_display);
            html += row('Trial Status', subscription.trial_status || (subscription.has_subscription ? 'Not on Trial' : '—'));
            html += row('Trial End Date', subscription.trial_ends_at ? formatDate(subscription.trial_ends_at) : '—');
            html += row('Trial Time Remaining', subscription.trial_time_remaining || subscription.trial_display || '—');
            html += row('Subscription Owner', subscription.owner_email || '—');
            html += row('Practices Using Subscription', subscription.owned_practice_count !== null ? String(subscription.owned_practice_count) : '—');
            html += row('Capacity', subscription.capacity_display || '—');
            html += row('Stripe Customer ID', subscription.stripe_customer_id || '—');
            html += row('Stripe Subscription ID', subscription.stripe_subscription_id || '—');
            html += row('Current Period Ends', subscription.current_period_ends_at ? formatDate(subscription.current_period_ends_at) : '—');
            html += row('Billing Interval', subscription.billing_interval ? (subscription.billing_interval === 'year' ? 'Yearly' : 'Monthly') : '—');
            html += row('Cancel at Period End', subscription.cancel_at_period_end ? 'Yes' : 'No');

            html += '</div>';

            if (subscription.is_trialing) {
                html += '<div style="margin-top: 16px;">' +
                    '<button class="action-btn primary" id="extendTrialBtn" onclick="openExtendTrialModal()">' + t('admin_practices.extend_trial_button') + '</button>' +
                    '</div>';
            }

            if (!subscription.has_subscription) {
                html += '<div class="retention-warning" style="margin-top: 16px;">' +
                    '<h4>' + t('admin_practices.no_subscription_record_title') + '</h4>' +
                    '<p>' + t('admin_practices.no_subscription_record_text') + '</p>' +
                    '</div>';
            }

            document.getElementById('detailContent').innerHTML = html;
        }

        let currentExtendTrialState = { practiceId: null, subscription: null, sending: false };

        function openExtendTrialModal() {
            const practice = practices.find(p => p.id === selectedPracticeId);
            const subscription = practice ? (practice.subscription || {}) : {};
            if (!subscription.is_trialing || !subscription.trial_ends_at) {
                alert(t('admin_practices.extend_trial_not_active'));
                return;
            }

            currentExtendTrialState = { practiceId: selectedPracticeId, subscription: subscription, sending: false, affectedPractices: [] };

            const select = document.getElementById('extendTrialLength');
            select.innerHTML = '';
            for (let i = 1; i <= 24; i++) {
                const option = document.createElement('option');
                option.value = String(i);
                option.textContent = i + ' ' + (i === 1 ? t('admin_practices.month_singular') : t('admin_practices.month_plural'));
                select.appendChild(option);
            }

            const emailGroup = document.getElementById('extendTrialEmailGroup');
            if (subscription.owner_email) {
                emailGroup.style.display = 'block';
                document.getElementById('extendTrialSendEmail').checked = false;
                document.getElementById('extendTrialRecipient').textContent = t('admin_practices.extend_trial_recipient') + ' ' + subscription.owner_email;
            } else {
                emailGroup.style.display = 'none';
            }

            const affectedGroup = document.getElementById('extendTrialAffectedGroup');
            affectedGroup.style.display = 'none';

            fetch('api/admin-practices.php?action=affected_practices&practice_id=' + selectedPracticeId, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.affected_practices) {
                        currentExtendTrialState.affectedPractices = data.affected_practices;
                        renderAffectedPractices(data.affected_practices);
                    }
                });

            updateExtendTrialPreview();
            document.getElementById('extendTrialModal').classList.add('active');
        }

        function renderAffectedPractices(affected) {
            const group = document.getElementById('extendTrialAffectedGroup');
            if (!group) return;

            const names = affected.map(ap => escapeHtml(ap.name));
            const intro = document.getElementById('extendTrialAffectedIntro');
            const list = document.getElementById('extendTrialAffectedList');

            if (affected.length === 0) {
                group.style.display = 'none';
                return;
            }

            group.style.display = 'block';

            if (affected.length === 1) {
                intro.textContent = t('admin_practices.extend_trial_affected_single') + ' ' + names[0] + '.';
                list.innerHTML = '';
                list.style.display = 'none';
            } else {
                intro.textContent = t('admin_practices.extend_trial_affected_multiple');
                list.innerHTML = names.map(name => '<li>' + name + '</li>').join('');
                list.style.display = 'block';
            }
        }

        function updateExtendTrialPreview() {
            const subscription = currentExtendTrialState.subscription || {};
            if (!subscription.trial_ends_at) return;

            const months = parseInt(document.getElementById('extendTrialLength').value || '1', 10);
            const currentEnd = parseDateUTC(subscription.trial_ends_at);
            // Calendar-month addition in JS; prefer the last day of the target month.
            const sourceYear = currentEnd.getUTCFullYear();
            const sourceMonth = currentEnd.getUTCMonth();
            const sourceDay = currentEnd.getUTCDate();
            const sourceFirst = new Date(Date.UTC(sourceYear, sourceMonth, 1));
            const sourceLast = new Date(Date.UTC(sourceYear, sourceMonth + 1, 0)).getUTCDate();
            const isLastDayOfSource = sourceDay === sourceLast;

            const targetMonth = sourceMonth + months;
            const targetYear = sourceYear + Math.floor(targetMonth / 12);
            const targetMonthIndex = ((targetMonth % 12) + 12) % 12;
            const targetFirst = new Date(Date.UTC(targetYear, targetMonthIndex, 1));
            const targetLast = new Date(Date.UTC(targetYear, targetMonthIndex + 1, 0)).getUTCDate();
            const targetDay = (isLastDayOfSource || sourceDay > targetLast) ? targetLast : sourceDay;
            const newEnd = new Date(Date.UTC(targetYear, targetMonthIndex, targetDay));

            currentExtendTrialState.previewNewEnd = newEnd.toISOString();
            document.getElementById('extendTrialPreviewDate').textContent = formatDate(currentExtendTrialState.previewNewEnd);
        }

        function toggleExtendTrialEmail() {
            const checked = document.getElementById('extendTrialSendEmail').checked;
            currentExtendTrialState.sendEmail = checked;
        }

        function confirmExtendTrial() {
            if (currentExtendTrialState.sending) return;

            const months = parseInt(document.getElementById('extendTrialLength').value || '0', 10);
            if (!months || months < 1 || months > 24) {
                alert(t('admin_practices.extend_trial_invalid_months'));
                return;
            }

            currentExtendTrialState.sending = true;
            const btn = document.getElementById('confirmExtendTrialBtn');
            if (btn) btn.textContent = t('admin_practices.extend_trial_processing');

            const sendEmail = !!document.getElementById('extendTrialSendEmail').checked;

            postJson('api/admin-practices.php?action=extend_trial', {
                practice_id: currentExtendTrialState.practiceId,
                extension_months: months,
                send_email: sendEmail
            })
            .then(response => response.json().then(data => ({ response, data })))
            .then(({ response, data }) => {
                currentExtendTrialState.sending = false;
                if (btn) btn.textContent = t('admin_practices.extend_trial_button');

                if (response.ok && data.success) {
                    closeModal('extendTrialModal');

                    // Update the cached subscription for every affected practice.
                    const affectedIds = data.affected_practice_ids || [];
                    if (data.subscription) {
                        practices.forEach(function(p) {
                            if (affectedIds.indexOf(p.id) !== -1) {
                                p.subscription = data.subscription;
                            }
                        });
                    }

                    renderPractices();
                    loadSubscriptionTab(currentExtendTrialState.practiceId);

                    showAdminToast(data.message);
                } else {
                    alert(data.message || t('admin_practices.extend_trial_failed'));
                }
            })
            .catch(error => {
                currentExtendTrialState.sending = false;
                if (btn) btn.textContent = t('admin_practices.extend_trial_button');
                alert(t('admin_practices.extend_trial_failed') + ': ' + error.message);
            });
        }

        function loadAdoptionTab(practiceId) {
            loadTab('api/admin-practices.php?action=adoption&practice_id=' + practiceId, data => renderAdoptionTab(data.adoption), 'usage', practiceId);
        }

        function renderAdoptionTab(adoption) {
            const sub = (adoption.subscription || (practices.find(p => p.id === selectedPracticeId) || {}).subscription) || {};

            const summaryHelp = {
                'Recent case activity': 'Case activity recorded within the last 30 days',
                'Historical case activity': 'Case activity exists, but none within the last 30 days',
                'No recorded case activity': 'No recorded case creation or workflow activity'
            };

            let html = '<div class="compliance-detail" style="margin-bottom: 14px; grid-column: 1 / -1;">' +
                '<div class="label">Adoption Summary</div>' +
                '<div class="value" style="font-size: 1.1rem; font-weight: 600;">' + escapeHtml(adoption.summary) + '</div>' +
                '<div style="font-size: 0.8rem; color: #6b7280; margin-top: 4px;">' + escapeHtml(summaryHelp[adoption.summary] || '') + '</div>' +
                '</div>';

            if (sub.plan_display) {
                html += '<div class="compliance-detail" style="margin-bottom: 14px; grid-column: 1 / -1;">' +
                    '<div class="label">Subscription Context</div>' +
                    '<div class="value">' + escapeHtml(sub.plan_display || '—') + ' • ' + escapeHtml(sub.status_display || '—') +
                    (sub.is_trialing && sub.trial_display ? ' • ' + escapeHtml(sub.trial_display) : '') +
                    '</div>' +
                    '</div>';
            }

            const hasActivity = adoption.total_users > 0 || adoption.active_cases > 0 || adoption.created_last_30_days > 0 || adoption.delivered_last_30_days > 0 || adoption.last_activity;
            if (!hasActivity) {
                html += '<div class="empty-state" style="margin-bottom: 16px; text-align: left;">No usage or adoption data has been recorded for this practice yet.<br><small>Metrics will appear once users log in and cases are created or updated.</small></div>';
            }

            const metric = (label, value) => {
                return '<div class="compliance-detail">' +
                    '<div class="label">' + escapeHtml(label) + '</div>' +
                    '<div class="value" style="font-size: 1.2rem;">' + (value !== null && value !== undefined && value !== '' ? value : '—') + '</div>' +
                    '</div>';
            };

            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">';
            html += metric('Total Users', adoption.total_users);
            html += metric('Users With Recorded Login', adoption.users_with_login);
            html += metric('No Recorded Login', adoption.users_without_login);
            html += metric('Most Recent Login', formatRelativeTimestamp(adoption.most_recent_login, 'No recorded login'));
            html += metric('Active Cases', adoption.active_cases);
            html += metric('Cases Created Last 30 Days', adoption.created_last_30_days);
            html += metric((adoption.terminal_label || 'Delivered') + ' Last 30 Days', adoption.delivered_last_30_days);
            html += metric('Last Activity', formatRelativeTimestamp(adoption.last_activity, 'No activity recorded'));

            if (adoption.demo_case_count > 0) {
                html += '<div class="compliance-detail" style="grid-column: 1 / -1;">' +
                    '<div class="label">Demo Data</div>' +
                    '<div class="value">' + adoption.demo_case_count + ' demo case' + (adoption.demo_case_count === 1 ? '' : 's') + '</div>' +
                    '</div>';
            }

            html += '</div>';

            document.getElementById('detailContent').innerHTML = html;
        }

        function loadPHITab(practiceId) {
            loadTab('api/admin-practices.php?action=phi_log&practice_id=' + practiceId + '&limit=100', data => renderPHITab(data.log), 'phi', practiceId);
        }
        
        function renderPHITab(log) {
            if (!log || log.length === 0) {
                document.getElementById('detailContent').innerHTML = 
                    '<div class="empty-state">No PHI access records found.<br><small>Records are created when users view or print cases.</small></div>';
                return;
            }
            
            let html = '<table class="phi-log-table"><thead><tr>' +
                '<th>Date/Time</th>' +
                '<th>User</th>' +
                '<th>Action</th>' +
                '<th>Case</th>' +
                '<th>IP</th>' +
                '</tr></thead><tbody>';
            
            log.forEach(entry => {
                const accessType = entry.access_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const fullCaseId = entry.case_id || '-';
                const displayCaseId = entry.case_id ? entry.case_id.substring(0, 8) + '...' : '-';
                const fullIp = entry.ip_address || '-';
                html += '<tr>' +
                    '<td style="white-space: nowrap;">' + formatDateTime(entry.accessed_at) + '</td>' +
                    '<td>' + escapeHtml(entry.user_email || 'Unknown') + '</td>' +
                    '<td>' + escapeHtml(accessType) + '</td>' +
                    '<td class="truncated-cell" title="' + escapeHtml(fullCaseId) + '" style="font-family: monospace; font-size: 0.8rem; cursor: help;">' + escapeHtml(displayCaseId) + '</td>' +
                    '<td class="truncated-cell" title="' + escapeHtml(fullIp) + '" style="font-family: monospace; font-size: 0.8rem; cursor: help;">' + escapeHtml(fullIp) + '</td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            document.getElementById('detailContent').innerHTML = html;
        }
        
        function loadUsersTab(practiceId) {
            loadTab('api/admin-practices.php?action=users&practice_id=' + practiceId, data => {
                currentPracticeUsers = data.users || [];
                renderUsersTab(data.users);
            }, 'users', practiceId);
        }
        
        function renderUsersTab(users) {
            if (!users || users.length === 0) {
                document.getElementById('detailContent').innerHTML =
                    '<div class="empty-state">No users found</div>';
                return;
            }

            const total = users.length;
            const withLogin = users.filter(u => u.last_login).length;
            const withoutLogin = total - withLogin;

            let html = '<div style="margin-bottom: 16px; color: #6b7280; font-size: 0.9rem;">' +
                escapeHtml(total + ' User' + (total === 1 ? '' : 's')) + ' · ' +
                withLogin + ' with recorded login' + (withLogin === 1 ? '' : 's') +
                (withoutLogin > 0 ? ' · ' + withoutLogin + ' with no login recorded' : '') +
                '</div>';

            html += '<div class="table-scroll"><table class="users-table" id="usersTable"><thead><tr>' +
                '<th data-sort="name" style="cursor: pointer;">User ↕</th>' +
                '<th data-sort="role" style="cursor: pointer;">Role ↕</th>' +
                '<th data-sort="last-login" style="cursor: pointer;">Last Login ↕</th>' +
                '<th data-sort="created-at" style="cursor: pointer;">Account Created ↕</th>' +
                '<th data-sort="status" style="cursor: pointer;">Status ↕</th>' +
                '<th>Actions</th>' +
                '</tr></thead><tbody>';

            users.forEach(user => {
                const name = [user.first_name, user.last_name].filter(Boolean).join(' ') || user.email;
                const isOwner = user.is_owner;
                const isAdmin = !isOwner && user.role === 'admin';
                const assignedOnly = !isOwner && user.limited_visibility;
                let role = 'User';
                if (isOwner) role = 'Owner';
                else if (isAdmin) role = 'Admin';
                else if (assignedOnly) role = 'Assigned Only';

                const login = formatRelativeLogin(user.last_login);
                const loginClass = user.last_login ? '' : ' style="color: #9ca3af;"';

                let status = 'Active';
                let statusClass = '';
                if (user.is_active === false || user.is_active === 0 || user.is_active === '0') {
                    status = 'Disabled';
                    statusClass = ' style="color: #dc2626; font-weight: 500;"';
                } else if (user.email_verified === false || user.email_verified === 0 || user.email_verified === '0') {
                    status = 'Pending Verification';
                    statusClass = ' style="color: #d97706; font-weight: 500;"';
                }

                html += '<tr>' +
                    '<td data-name="' + escapeHtml(name.toLowerCase()) + '"><strong>' + escapeHtml(name) + '</strong><br><small class="text-muted">' + escapeHtml(user.email) + '</small></td>' +
                    '<td data-role="' + escapeHtml(role) + '">' + escapeHtml(role) + '</td>' +
                    '<td data-last-login="' + (user.last_login || '') + '" title="' + escapeHtml(login.title) + '"' + loginClass + '>' + escapeHtml(login.text) + '</td>' +
                    '<td data-created-at="' + (user.user_created_at || '') + '">' + formatDate(user.user_created_at) + '</td>' +
                    '<td data-status="' + escapeHtml(status) + '"' + statusClass + '>' + escapeHtml(status) + '</td>' +
                    '<td><button class="action-btn primary" onclick="openEmailModal(event, ' + selectedPracticeId + ', ' + user.id + ')">Email</button></td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>';
            document.getElementById('detailContent').innerHTML = html;

            // Attach lightweight column sorting
            document.querySelectorAll('#usersTable th[data-sort]').forEach(th => {
                th.addEventListener('click', () => sortUsersTable(th.dataset.sort));
            });
        }

        let currentUsersSort = { column: 'name', direction: 'asc' };

        function sortUsersTable(column) {
            const tbody = document.querySelector('#usersTable tbody');
            if (!tbody) return;

            if (currentUsersSort.column === column) {
                currentUsersSort.direction = currentUsersSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentUsersSort.column = column;
                currentUsersSort.direction = 'asc';
            }

            const rows = Array.from(tbody.querySelectorAll('tr'));
            const dir = currentUsersSort.direction === 'asc' ? 1 : -1;

            rows.sort((a, b) => {
                const aCell = a.querySelector('td[data-' + column + ']');
                const bCell = b.querySelector('td[data-' + column + ']');
                let aVal = aCell ? (aCell.getAttribute('data-' + column) || '') : '';
                let bVal = bCell ? (bCell.getAttribute('data-' + column) || '') : '';

                if (column === 'last-login' || column === 'created-at') {
                    // Empty/no-login sorts to the bottom for newest-first, top for oldest-first
                    if (!aVal) aVal = '0000-00-00 00:00:00';
                    if (!bVal) bVal = '0000-00-00 00:00:00';
                    return dir * aVal.localeCompare(bVal);
                }

                return dir * aVal.toLowerCase().localeCompare(bVal.toLowerCase());
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        let currentPracticeUsers = [];
        let currentEmailState = { practiceId: null, userId: null, user: null, sending: false };

        function openEmailModal(event, practiceId, userId) {
            if (event) {
                event.stopPropagation();
            }

            const practice = practices.find(p => p.id === practiceId);
            const user = currentPracticeUsers ? currentPracticeUsers.find(u => u.id === userId) : null;
            if (!user || !practice) {
                alert('User or practice not found');
                return;
            }

            currentEmailState = { practiceId, userId, user, sending: false };
            const sub = practice.subscription || {};
            const isTrialing = sub.status === 'trialing';

            const name = [user.first_name, user.last_name].filter(Boolean).join(' ') || user.email;
            const trialOption = isTrialing
                ? '<option value="trial_reminder">Trial Reminder</option>'
                : '';

            const html =
                '<div class="form-group">' +
                    '<label>To</label>' +
                    '<p style="margin: 0; color: #1f2937;"><strong>' + escapeHtml(name) + '</strong><br><small class="text-muted">' + escapeHtml(user.email) + '</small></p>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>Practice</label>' +
                    '<p style="margin: 0; color: #1f2937;">' + escapeHtml(practice.practice_name || practice.legal_name || 'Unnamed') + '</p>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="emailType">Email Type</label>' +
                    '<select id="emailType" class="form-control" onchange="updateEmailPreview()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">' +
                        '<option value="getting_started">Getting Started</option>' +
                        '<option value="user_guide">User Guide</option>' +
                        trialOption +
                        '<option value="custom">Custom Support Message</option>' +
                    '</select>' +
                '</div>' +
                '<div id="customFields" style="display: none;">' +
                    '<div class="form-group">' +
                        '<label for="customEmailSubject">Subject</label>' +
                        '<input type="text" id="customEmailSubject" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" placeholder="e.g. A quick follow-up">' +
                    '</div>' +
                    '<div class="form-group">' +
                        '<label for="customEmailMessage">Message</label>' +
                        '<textarea id="customEmailMessage" rows="5" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" placeholder="Plain text only"></textarea>' +
                    '</div>' +
                '</div>' +
                '<div class="form-group" id="emailPreviewGroup">' +
                    '<label>Preview</label>' +
                    '<div id="emailPreview" style="background: #f9fafb; padding: 12px; border-radius: 8px; font-size: 0.9rem; color: #374151; border: 1px solid #e5e7eb; white-space: pre-line;">' + t('common.loading') + '</div>' +
                '</div>' +
                '<div class="modal-actions" style="margin-top: 20px;">' +
                    '<button class="action-btn" onclick="closeModal(\'emailModal\')">Cancel</button>' +
                    '<button class="action-btn primary" id="confirmEmailBtn" onclick="sendAdminEmail()">Send Email</button>' +
                '</div>';

            document.getElementById('emailContent').innerHTML = html;
            document.getElementById('emailModal').classList.add('active');
            updateEmailPreview();
        }

        function updateEmailPreview() {
            const type = document.getElementById('emailType').value;
            const customFields = document.getElementById('customFields');
            const preview = document.getElementById('emailPreview');

            if (!customFields || !preview) return;

            customFields.style.display = (type === 'custom') ? 'block' : 'none';

            const user = currentEmailState.user || {};
            const practice = practices.find(p => p.id === currentEmailState.practiceId) || {};
            const firstName = user.first_name || '';
            const practiceName = practice.practice_name || practice.legal_name || 'Your Practice';
            const sub = practice.subscription || {};

            let text = '';
            if (type === 'getting_started') {
                text = 'Hi ' + (firstName || 'there') + ',\n\n';
                text += 'You have access to DentaTrak for ' + practiceName + '.\n\n';
                text += 'DentaTrak helps dental practices track cases across the office, labs, and referrals.\n\n';
                text += 'Subject: Getting started with DentaTrak';
            } else if (type === 'user_guide') {
                text = 'Hi ' + (firstName || 'there') + ',\n\n';
                text += 'Here is a link to the DentaTrak User Guide for ' + practiceName + '.\n\n';
                text += 'Subject: DentaTrak User Guide';
            } else if (type === 'trial_reminder') {
                text = 'Hi ' + (firstName || 'there') + ',\n\n';
                text += 'Reminder: the DentaTrak trial for ' + practiceName + ' (' + (sub.plan_display || '—') + ') is ending soon.\n';
                text += 'Trial end: ' + (sub.trial_ends_at ? formatDate(sub.trial_ends_at) : '—') + ' (' + (sub.trial_display || '') + ').\n\n';
                text += 'Subject: Your DentaTrak trial is ending soon';
            } else if (type === 'custom') {
                const subject = document.getElementById('customEmailSubject').value.trim() || '(no subject yet)';
                const message = document.getElementById('customEmailMessage').value.trim() || '(no message yet)';
                text = 'Hi ' + (firstName || 'there') + ',\n\n';
                text += escapeHtml(message) + '\n\n';
                text += 'Subject: ' + escapeHtml(subject);
            }

            preview.textContent = text;
        }

        function sendAdminEmail() {
            if (currentEmailState.sending) return;

            const type = document.getElementById('emailType').value;
            const customSubject = document.getElementById('customEmailSubject')?.value.trim() || '';
            const customMessage = document.getElementById('customEmailMessage')?.value.trim() || '';

            currentEmailState.sending = true;
            const btn = document.getElementById('confirmEmailBtn');
            if (btn) btn.textContent = 'Sending...';

            const payload = {
                practice_id: currentEmailState.practiceId,
                user_id: currentEmailState.userId,
                email_type: type
            };
            if (type === 'custom') {
                payload.custom_subject = customSubject;
                payload.custom_message = customMessage;
            }

            postJson('api/admin-practices.php?action=send_email', payload)
                .then(response => response.json().then(data => ({ response, data })))
                .then(({ response, data }) => {
                    currentEmailState.sending = false;
                    if (btn) btn.textContent = 'Send Email';

                    if (response.ok && data.success) {
                        showAdminToast(data.message || 'Email sent');
                        closeModal('emailModal');
                    } else {
                        alert(data.message || 'Failed to send email');
                    }
                })
                .catch(error => {
                    currentEmailState.sending = false;
                    if (btn) btn.textContent = 'Send Email';
                    alert('Error: ' + error.message);
                });
        }

        function showAdminToast(message) {
            const existing = document.getElementById('adminToast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.id = 'adminToast';
            toast.style.cssText = 'position: fixed; bottom: 24px; right: 24px; background: #065f46; color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 2000; font-size: 0.9rem;';
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => toast.remove(), 3000);
        }
        
        function loadSettingsTab(practiceId) {
            loadTab('api/admin-practices.php?action=settings&practice_id=' + practiceId, data => renderSettingsTab(data.settings), 'settings', practiceId);
        }
        
        function renderSettingsTab(settings) {
            let html = '<div class="settings-card">' +
                '<h4>Case Management</h4>' +
                '<div class="settings-row"><span class="settings-label">Allow Archiving Individual Cases</span><span class="settings-value">' + yesNo(settings.case_management.allow_archiving_individual_cases) + '</span></div>' +
                '<div class="settings-row"><span class="settings-label">Auto-Archive Delivered Cases</span><span class="settings-value">' + yesNo(settings.case_management.auto_archive_delivered_cases) + '</span></div>' +
                (settings.case_management.archive_delivered_cases_after_days > 0
                    ? '<div class="settings-row"><span class="settings-label">Archive Delivered After Days</span><span class="settings-value">' + settings.case_management.archive_delivered_cases_after_days + '</span></div>'
                    : '') +
                '</div>';
            
            html += '<div class="settings-card">' +
                '<h4>Due Date Highlighting</h4>' +
                '<div class="settings-row"><span class="settings-label">Highlight Past Due</span><span class="settings-value">' + yesNo(settings.due_date_highlighting.highlight_past_due) + '</span></div>' +
                (settings.due_date_highlighting.highlight_past_due
                    ? '<div class="settings-row"><span class="settings-label">Past Due Threshold (days)</span><span class="settings-value">' + settings.due_date_highlighting.past_due_days + '</span></div>'
                    : '') +
                '<div class="settings-row"><span class="settings-label">Highlight Coming Due</span><span class="settings-value">' + yesNo(settings.due_date_highlighting.highlight_coming_due) + '</span></div>' +
                (settings.due_date_highlighting.highlight_coming_due
                    ? '<div class="settings-row"><span class="settings-label">Coming Due Window (days)</span><span class="settings-value">' + settings.due_date_highlighting.coming_due_days + '</span></div>'
                    : '') +
                '</div>';
            
            html += '<div class="settings-card">' +
                '<h4>Workflow Stages</h4>' +
                '<ol class="settings-list">' +
                Object.values(settings.workflow_stages || {}).map(label => '<li>' + escapeHtml(label) + '</li>').join('') +
                '</ol>' +
                '</div>';
            
            html += '<div class="settings-card">' +
                '<h4>Users</h4>';
            if (settings.users && settings.users.length > 0) {
                html += '<div class="table-scroll"><table class="users-table"><thead><tr>' +
                    '<th>Name</th>' +
                    '<th>Email</th>' +
                    '<th>Role</th>' +
                    '<th class="permission">Admin</th>' +
                    '<th class="permission">Assigned Only</th>' +
                    '<th class="permission">Insights</th>' +
                    '<th class="permission">Edit Cases</th>' +
                    '<th class="permission">Lab</th>' +
                    '<th class="permission">Active</th>' +
                    '</tr></thead><tbody>';
                settings.users.forEach(user => {
                    html += '<tr>' +
                        '<td><strong>' + escapeHtml(user.name || '-') + '</strong></td>' +
                        '<td>' + escapeHtml(user.email || '-') + '</td>' +
                        '<td>' + escapeHtml(user.role || '-') + '</td>' +
                        '<td class="permission">' + yesNo(user.admin) + '</td>' +
                        '<td class="permission">' + yesNo(user.assigned_only) + '</td>' +
                        '<td class="permission">' + yesNo(user.insights) + '</td>' +
                        '<td class="permission">' + yesNo(user.edit_cases) + '</td>' +
                        '<td class="permission">' + yesNo(user.lab) + '</td>' +
                        '<td class="permission">' + yesNo(user.active) + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p style="font-size: 0.85rem; color: #6b7280;">No users found</p>';
            }
            html += '</div>';
            
            html += '<div class="settings-card">' +
                '<h4>Assignment Labels</h4>';
            if (settings.assignment_labels && settings.assignment_labels.length > 0) {
                html += '<table class="phi-log-table"><thead><tr><th>Label</th><th>Lab</th></tr></thead><tbody>';
                settings.assignment_labels.forEach(label => {
                    html += '<tr>' +
                        '<td>' + escapeHtml(label.label || '-') + '</td>' +
                        '<td>' + yesNo(label.is_lab) + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<p style="font-size: 0.85rem; color: #6b7280;">No assignment labels found</p>';
            }
            html += '</div>';
            
            html += '<div class="settings-card">' +
                '<h4>Security</h4>' +
                '<div class="settings-row"><span class="settings-label">Two-Factor Authentication Enabled</span><span class="settings-value">' + yesNo(settings.security.two_factor_authentication_enabled) + '</span></div>' +
                '</div>';

            const selected = practices.find(p => p.id === selectedPracticeId);
            if (selected) {
                const isHidden = !!selected.is_hidden;
                const pName = escapeHtml(selected.practice_name || selected.legal_name || t('admin_practices.unnamed')).replace(/'/g, "\\'");
                const visibilityLabel = isHidden ? 'This practice is hidden from the default All Practices list.' : 'This practice is visible in the default All Practices list.';
                const visibilityButton = isHidden
                    ? '<button class="action-btn success" onclick="confirmUnhidePractice(' + selectedPracticeId + ', \'' + pName + '\')">' + t('admin_practices.unhide_button') + '</button>'
                    : '<button class="action-btn secondary" onclick="confirmHidePractice(' + selectedPracticeId + ', \'' + pName + '\')">' + t('admin_practices.hide_button') + '</button>';
                html += '<div class="settings-card">' +
                    '<h4>Practice Visibility</h4>' +
                    '<p style="font-size: 0.85rem; color: #6b7280; margin: 0 0 12px;">' + escapeHtml(visibilityLabel) + ' Hiding does not affect access, cases, subscriptions, notifications, or PHI records.</p>' +
                    '<div class="settings-row">' + visibilityButton + '</div>' +
                    '</div>';
            }

            document.getElementById('detailContent').innerHTML = html;
        }
        
        function viewCompliance(practiceId) {
            selectedPracticeId = practiceId;
            document.getElementById('complianceDetails').innerHTML = '<div class="loading">' + t('common.loading') + '</div>';
            document.getElementById('complianceModal').classList.add('active');
            
            fetch('api/admin-practices.php?action=compliance&practice_id=' + practiceId, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderComplianceDetails(data.compliance);
                    } else {
                        document.getElementById('complianceDetails').innerHTML =
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('[compliance modal]', error);
                    document.getElementById('complianceDetails').innerHTML =
                        '<div class="empty-state">Failed to load compliance details: ' + escapeHtml(error.message) + '</div>';
                });
        }
        
        function renderComplianceDetails(compliance) {
            let html = '<div class="compliance-detail">' +
                '<div class="label">Practice Name</div>' +
                '<div class="value">' + escapeHtml(compliance.practice_name || 'N/A') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">Legal Name</div>' +
                '<div class="value">' + escapeHtml(compliance.legal_name || 'N/A') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">Status</div>' +
                '<div class="value">' + (compliance.is_active ? '✅ Active' : '❌ Inactive') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">BAA Status</div>' +
                '<div class="value">' + (compliance.baa_accepted 
                    ? '✅ Accepted on ' + formatDate(compliance.baa_accepted_at) + ' (v' + compliance.baa_version + ')'
                    : '⚠️ Not Accepted') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">BAA Practice Address</div>' +
                '<div class="value">' + (compliance.practice_address ? escapeHtml(compliance.practice_address).replace(/\n/g, '<br>') : 'Not provided') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">Created</div>' +
                '<div class="value">' + formatDate(compliance.created_at) + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">Last Activity</div>' +
                '<div class="value">' + (compliance.last_activity_at ? formatDate(compliance.last_activity_at) : 'Never') + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">Users</div>' +
                '<div class="value">' + compliance.user_count + '</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">Total Cases</div>' +
                '<div class="value">' + compliance.total_cases + ' (' + compliance.archived_cases + ' archived)</div>' +
                '</div>' +
                '<div class="compliance-detail">' +
                '<div class="label">PHI Access (Last 30 Days)</div>' +
                '<div class="value">' + Object.values(compliance.phi_access_last_30_days || {}).reduce((a, b) => a + b, 0) + 
                ' events by ' + compliance.unique_phi_accessors_last_30_days + ' users</div>' +
                '</div>';
            
            if (!compliance.is_active && compliance.deactivated_at) {
                html += '<div class="retention-warning">' +
                    '<h4>Data Retention Information</h4>' +
                    '<p><strong>Deactivated:</strong> ' + formatDate(compliance.deactivated_at) + '</p>' +
                    '<p><strong>Deletion Eligible:</strong> ' + formatDate(compliance.data_deletion_eligible_at) + '</p>' +
                    '<p><strong>Retention Period:</strong> ' + compliance.data_retention_years + ' years</p>' +
                    '</div>';
            }
            
            document.getElementById('complianceDetails').innerHTML = html;
        }
        
        function viewPHILog(practiceId) {
            selectedPracticeId = practiceId;
            document.getElementById('phiLogContent').innerHTML = '<div class="loading">' + t('common.loading') + '</div>';
            document.getElementById('phiLogModal').classList.add('active');
            
            fetch('api/admin-practices.php?action=phi_log&practice_id=' + practiceId + '&limit=100', { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderPHILog(data.log);
                    } else {
                        document.getElementById('phiLogContent').innerHTML =
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('[phi log modal]', error);
                    document.getElementById('phiLogContent').innerHTML =
                        '<div class="empty-state">Failed to load PHI log: ' + escapeHtml(error.message) + '</div>';
                });
        }
        
        function renderPHILog(log) {
            if (!log || log.length === 0) {
                document.getElementById('phiLogContent').innerHTML = 
                    '<div class="empty-state">No PHI access records found</div>';
                return;
            }
            
            let html = '<table class="phi-log-table"><thead><tr>' +
                '<th>Date/Time</th>' +
                '<th>User</th>' +
                '<th>Access Type</th>' +
                '<th>Case ID</th>' +
                '<th>IP Address</th>' +
                '</tr></thead><tbody>';
            
            log.forEach(entry => {
                html += '<tr>' +
                    '<td>' + formatDateTime(entry.accessed_at) + '</td>' +
                    '<td>' + escapeHtml(entry.user_email || 'Unknown') + '</td>' +
                    '<td>' + escapeHtml(entry.access_type) + '</td>' +
                    '<td>' + (entry.case_id ? escapeHtml(entry.case_id.substring(0, 8)) + '...' : '-') + '</td>' +
                    '<td>' + escapeHtml(entry.ip_address || '-') + '</td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            document.getElementById('phiLogContent').innerHTML = html;
        }
        
        function deactivatePractice(practiceId, practiceName) {
            selectedPracticeId = practiceId;
            document.getElementById('deactivatePracticeName').textContent = practiceName;
            document.getElementById('deactivateReason').value = '';
            document.getElementById('deactivateModal').classList.add('active');
        }
        
        function confirmDeactivate() {
            const reason = document.getElementById('deactivateReason').value.trim() || 'Deactivated by administrator';
            const btn = document.getElementById('confirmDeactivateBtn');
            btn.disabled = true;
            btn.textContent = 'Deactivating...';
            
            postJson('api/admin-practices.php?action=deactivate', {
                practice_id: selectedPracticeId,
                reason: reason
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = 'Deactivate Practice';
                
                if (data.success) {
                    closeModal('deactivateModal');
                    loadPractices();
                    alert('Practice deactivated successfully. Data will be retained until ' + data.deletion_eligible_at);
                } else {
                    alert('Error: ' + (data.message || 'Failed to deactivate practice'));
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.textContent = 'Deactivate Practice';
                alert('Error: ' + error.message);
            });
        }
        
        function reactivatePractice(practiceId, practiceName) {
            const name = practiceName || 'this practice';
            if (!window.confirm('Reactivate practice "' + name + '"?\n\nUsers will be able to log in again.')) {
                return;
            }
            
            postJson('api/admin-practices.php?action=reactivate', { practice_id: practiceId })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPractices();
                    alert('Practice reactivated successfully');
                } else {
                    alert('Error: ' + (data.message || 'Failed to reactivate practice'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }
        
        function confirmHidePractice(practiceId, practiceName) {
            const message = 'Hide practice "' + practiceName + '"?\n\nThis only removes it from the default All Practices list. It does NOT delete, deactivate, archive, or affect access, cases, subscriptions, notifications, or PHI records.';
            if (window.confirm(message)) {
                hidePractice(practiceId);
            }
        }

        function confirmUnhidePractice(practiceId, practiceName) {
            const message = 'Unhide practice "' + practiceName + '"?\n\nIt will return to the default All Practices list.';
            if (window.confirm(message)) {
                unhidePractice(practiceId);
            }
        }

        function hidePractice(practiceId) {
            postJson('api/admin-practices.php?action=hide', { practice_id: practiceId })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAdminToast(data.message || 'Practice hidden');
                    loadPractices();
                } else {
                    alert('Error: ' + (data.message || 'Failed to hide practice'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }

        function unhidePractice(practiceId) {
            postJson('api/admin-practices.php?action=unhide', { practice_id: practiceId })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAdminToast(data.message || 'Practice unhidden');
                    loadPractices();
                } else {
                    alert('Error: ' + (data.message || 'Failed to unhide practice'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function formatRelativeLogin(dateStr) {
            if (!dateStr || dateStr === '0000-00-00 00:00:00') {
                return { text: 'Never', title: 'No recorded login' };
            }

            const date = new Date(dateStr);
            if (isNaN(date.getTime())) {
                return { text: 'No login recorded', title: 'Timestamp unavailable' };
            }

            const now = new Date();
            now.setHours(0, 0, 0, 0);
            const loginDate = new Date(date);
            loginDate.setHours(0, 0, 0, 0);
            const msPerDay = 1000 * 60 * 60 * 24;
            const diffDays = Math.round((now.getTime() - loginDate.getTime()) / msPerDay);

            let text;
            if (diffDays < 0) {
                text = formatDate(dateStr); // future (clock skew)
            } else if (diffDays === 0) {
                text = 'Today';
            } else if (diffDays === 1) {
                text = 'Yesterday';
            } else if (diffDays <= 6) {
                text = diffDays + ' days ago';
            } else if (diffDays <= 90) {
                text = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            } else {
                text = date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            }

            return { text: text, title: formatDateTime(dateStr) };
        }

        function parseDateUTC(dateStr) {
            if (!dateStr) return null;
            // Already a full ISO 8601 / RFC 3339 timestamp (e.g. 2026-08-29T22:19:09Z)
            if (/Z$|[+-]\d{2}:?\d{2}$/.test(String(dateStr))) {
                return new Date(dateStr);
            }
            // MySQL / DATETIME with optional time: parse as UTC so the display
            // matches the server-side calendar-day calculation.
            const parts = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/);
            if (!parts) return new Date(dateStr);
            return new Date(Date.UTC(+parts[1], +parts[2] - 1, +parts[3], +(parts[4] || 0), +(parts[5] || 0), +(parts[6] || 0)));
        }

        function formatDate(dateStr) {
            const date = parseDateUTC(dateStr);
            if (!date) return 'N/A';
            return date.toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric',
                timeZone: 'UTC'
            });
        }

        function formatDateTime(dateStr) {
            const date = parseDateUTC(dateStr);
            if (!date) return 'N/A';
            return date.toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit',
                timeZone: 'UTC'
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Print practice details
        async function printPracticeDetails(practiceId) {
            const practice = practices.find(p => p.id === practiceId);
            if (!practice) return;
            
            // Fetch all data for printing
            const [complianceRes, phiRes, usersRes] = await Promise.all([
                fetch('api/admin-practices.php?action=compliance&practice_id=' + practiceId, { credentials: 'same-origin' }).then(r => r.json()),
                fetch('api/admin-practices.php?action=phi_log&practice_id=' + practiceId + '&limit=500', { credentials: 'same-origin' }).then(r => r.json()),
                fetch('api/admin-practices.php?action=users&practice_id=' + practiceId, { credentials: 'same-origin' }).then(r => r.json())
            ]);
            
            const compliance = complianceRes.compliance || {};
            const phiLog = phiRes.log || [];
            const users = usersRes.users || [];
            const isActive = practice.is_active === true || practice.is_active === '1' || practice.is_active === 1;
            
            // Build print content
            let printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Practice Report - ${escapeHtml(practice.practice_name || 'Practice')}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; font-size: 12px; }
                        h1 { font-size: 18px; margin-bottom: 5px; }
                        h2 { font-size: 14px; margin-top: 20px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
                        .header-info { color: #666; margin-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
                        th { background: #f5f5f5; font-weight: bold; }
                        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
                        .detail-item { padding: 8px; background: #f9f9f9; border-radius: 4px; }
                        .detail-label { font-size: 10px; color: #666; }
                        .detail-value { font-weight: bold; }
                        .status-active { color: green; }
                        .status-inactive { color: red; }
                        @media print { body { padding: 0; } }
                    </style>
                </head>
                <body>
                    <h1>🏥 Practice Compliance Report</h1>
                    <div class="header-info">
                        Generated: ${new Date().toLocaleString()} | Practice ID: ${practiceId}
                    </div>
                    
                    <h2>Practice Information</h2>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Practice Name</div>
                            <div class="detail-value">${escapeHtml(practice.practice_name || 'N/A')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Legal Name</div>
                            <div class="detail-value">${escapeHtml(compliance.legal_name || 'N/A')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value ${isActive ? 'status-active' : 'status-inactive'}">${isActive ? 'Active' : 'Inactive'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">BAA Status</div>
                            <div class="detail-value">${compliance.baa_accepted ? 'Accepted (v' + compliance.baa_version + ')' : 'Not Accepted'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Created</div>
                            <div class="detail-value">${formatDate(compliance.created_at)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Last Activity</div>
                            <div class="detail-value">${compliance.last_activity_at ? formatDate(compliance.last_activity_at) : 'Never'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Total Cases</div>
                            <div class="detail-value">${compliance.total_cases || 0} (${compliance.archived_cases || 0} archived)</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Users</div>
                            <div class="detail-value">${users.length}</div>
                        </div>
                    </div>
                    
                    <h2>Users (${users.length})</h2>
                    <table>
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Admin</th><th>Assigned Only</th><th>Insights</th><th>Edit Cases</th><th>Lab</th><th>Active</th></tr></thead>
                        <tbody>
                            ${users.length === 0 ? '<tr><td colspan="9" style="text-align:center;">No users</td></tr>' :
                              users.map(u => {
                                const isOwner = u.is_owner;
                                const isAdmin = isOwner || u.role === 'admin';
                                const assignedOnly = !isOwner && u.limited_visibility;
                                const insights = isOwner || u.can_view_analytics;
                                const editCases = isOwner || u.can_edit_cases;
                                const lab = u.is_lab;
                                return `<tr>
                                  <td>${escapeHtml([u.first_name, u.last_name].filter(Boolean).join(' ') || '-')}</td>
                                  <td>${escapeHtml(u.email)}</td>
                                  <td>${isOwner ? 'Owner' : 'User'}</td>
                                  <td style="text-align:center;">${yesNo(isAdmin)}</td>
                                  <td style="text-align:center;">${yesNo(assignedOnly)}</td>
                                  <td style="text-align:center;">${yesNo(insights)}</td>
                                  <td style="text-align:center;">${yesNo(editCases)}</td>
                                  <td style="text-align:center;">${yesNo(lab)}</td>
                                  <td style="text-align:center;">${yesNo(u.is_active)}</td>
                                </tr>`;
                              }).join('')}
                        </tbody>
                    </table>
                    
                    <h2>PHI Access Log (Last ${phiLog.length} entries)</h2>
                    <table>
                        <thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Case ID</th><th>IP Address</th></tr></thead>
                        <tbody>
                            ${phiLog.length === 0 ? '<tr><td colspan="5" style="text-align:center;">No PHI access records</td></tr>' :
                              phiLog.map(e => `<tr>
                                <td>${formatDateTime(e.accessed_at)}</td>
                                <td>${escapeHtml(e.user_email || 'Unknown')}</td>
                                <td>${escapeHtml(e.access_type.replace(/_/g, ' '))}</td>
                                <td style="font-family: monospace;">${escapeHtml(e.case_id || '-')}</td>
                                <td style="font-family: monospace;">${escapeHtml(e.ip_address || '-')}</td>
                              </tr>`).join('')}
                        </tbody>
                    </table>
                </body>
                </html>
            `;
            
            // Open print window
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => printWindow.print(), 250);
        }
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
        
        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>

    <!-- Extend Trial Modal -->
    <div class="modal" id="extendTrialModal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3><?php echo t('admin_practices.extend_trial_title'); ?></h3>
                <button class="modal-close" onclick="closeModal('extendTrialModal')">&times;</button>
            </div>
            <div id="extendTrialContent">
                <div class="form-group">
                    <label for="extendTrialLength"><?php echo t('admin_practices.extend_trial_length_label'); ?></label>
                    <select id="extendTrialLength" class="form-control" onchange="updateExtendTrialPreview()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;"></select>
                </div>
                <div class="form-group">
                    <p style="margin: 0; color: #1f2937;">
                        <?php echo t('admin_practices.extend_trial_preview_prefix'); ?>
                        <strong id="extendTrialPreviewDate"></strong>
                    </p>
                </div>
                <div class="form-group" id="extendTrialAffectedGroup" style="display: none;">
                    <label><?php echo t('admin_practices.extend_trial_affected_label'); ?></label>
                    <p style="margin: 0; color: #1f2937;" id="extendTrialAffectedIntro"></p>
                    <ul style="margin: 8px 0 0; padding-left: 20px; color: #6b7280; font-size: 0.85rem;" id="extendTrialAffectedList"></ul>
                </div>
                <div class="form-group" id="extendTrialEmailGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="extendTrialSendEmail" onchange="toggleExtendTrialEmail()">
                        <?php echo t('admin_practices.extend_trial_send_email'); ?>
                    </label>
                    <p style="margin: 8px 0 0; color: #6b7280; font-size: 0.85rem;" id="extendTrialRecipient"></p>
                </div>
                <div class="modal-actions" style="margin-top: 20px;">
                    <button class="action-btn" onclick="closeModal('extendTrialModal')"><?php echo t('common.cancel'); ?></button>
                    <button class="action-btn primary" id="confirmExtendTrialBtn" onclick="confirmExtendTrial()"><?php echo t('admin_practices.extend_trial_button'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Send Email Modal -->
    <div class="modal" id="emailModal">
        <div class="modal-content" style="max-width: 620px;">
            <div class="modal-header">
                <h3>Send Email</h3>
                <button class="modal-close" onclick="closeModal('emailModal')">&times;</button>
            </div>
            <div id="emailContent">
                <div class="loading">' + t('common.loading') + '</div>
            </div>
        </div>
    </div>
</body>

</html>
