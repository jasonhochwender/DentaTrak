<?php
/**
 * Admin Practices Management Page
 * 
 * HIPAA Compliance Dashboard for system administrators
 * - View all practices with compliance status
 * - Activate/deactivate practices
 * - View PHI access logs
 * - Data retention management
 */

require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

// Check if user is logged in
if (empty($_SESSION['db_user_id'])) {
    header('Location: login.php');
    exit;
}

// Load dev tools access control
require_once __DIR__ . '/api/dev-tools-access.php';

// Check if current user can access admin pages (super user OR dev environment)
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Practice Administration - <?php echo htmlspecialchars($appConfig['appName']); ?></title>
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
            overflow: hidden;
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
        }
        
        .practices-table th,
        .practices-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        
        .practices-table td:first-child {
            max-width: 180px;
            word-wrap: break-word;
        }
        
        .practices-table td:last-child {
            white-space: nowrap;
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
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 500;
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
        
        .baa-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
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
        
        .compact-actions {
            display: flex;
            gap: 6px;
        }
        
        .compact-actions .action-btn {
            padding: 4px 8px;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1>🏥 Practice Administration</h1>
                <div class="subtitle">HIPAA Compliance Dashboard • Data Retention: 7 Years</div>
            </div>
            <a href="main.php" class="back-link">
                ← Back to Dashboard
            </a>
        </div>
        
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="label">Total Practices </div>
                <div class="value" id="totalPractices">-</div>
            </div>
            <div class="stat-card success">
                <div class="label">Active Practices</div>
                <div class="value" id="activePractices">-</div>
            </div>
            <div class="stat-card warning">
                <div class="label">Inactive Practices</div>
                <div class="value" id="inactivePractices">-</div>
            </div>
            <div class="stat-card danger">
                <div class="label">Deletion Eligible</div>
                <div class="value" id="deletionEligible">-</div>
            </div>
        </div>
        
        <!-- Two-panel layout -->
        <div class="main-content-grid">
            <!-- Left panel: Practice list -->
            <div class="practices-table-container">
                <div class="table-header">
                    <h2>All Practices</h2>
                    <div>
                        <button class="action-btn primary" onclick="loadPractices()">↻ Refresh</button>
                    </div>
                </div>
                <div id="practicesTableBody">
                    <div class="loading">Loading practices...</div>
                </div>
            </div>
            
            <!-- Right panel: Practice details & PHI log -->
            <div class="detail-panel" id="detailPanel">
                <div class="detail-panel-empty">
                    <p>Select a practice to view details and PHI access log</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Compliance Details Modal -->
    <div class="modal" id="complianceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Practice Compliance Details</h3>
                <button class="modal-close" onclick="closeModal('complianceModal')">&times;</button>
            </div>
            <div id="complianceDetails">
                <div class="loading">Loading...</div>
            </div>
        </div>
    </div>
    
    <!-- PHI Access Log Modal -->
    <div class="modal" id="phiLogModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3>PHI Access Log</h3>
                <button class="modal-close" onclick="closeModal('phiLogModal')">&times;</button>
            </div>
            <div id="phiLogContent">
                <div class="loading">Loading...</div>
            </div>
        </div>
    </div>
    
    <!-- Deactivate Practice Modal -->
    <div class="modal" id="deactivateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Deactivate Practice</h3>
                <button class="modal-close" onclick="closeModal('deactivateModal')">&times;</button>
            </div>
            <div id="deactivateContent">
                <p>Are you sure you want to deactivate this practice?</p>
                <p><strong>Practice:</strong> <span id="deactivatePracticeName"></span></p>
                
                <div class="retention-warning">
                    <h4>⚠️ Data Retention Policy</h4>
                    <p>Per HIPAA requirements, all practice data will be retained for 7 years after deactivation. 
                       Users will not be able to log in, but data will remain accessible for compliance purposes.</p>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label for="deactivateReason">Reason for Deactivation</label>
                    <textarea id="deactivateReason" rows="3" placeholder="Enter reason for deactivation..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button class="action-btn" onclick="closeModal('deactivateModal')">Cancel</button>
                    <button class="action-btn danger" id="confirmDeactivateBtn" onclick="confirmDeactivate()">Deactivate Practice</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let practices = [];
        let selectedPracticeId = null;
        
        // Load practices on page load
        document.addEventListener('DOMContentLoaded', loadPractices);
        
        function loadPractices() {
            document.getElementById('practicesTableBody').innerHTML = '<div class="loading">Loading practices...</div>';
            
            fetch('/api/admin-practices.php?action=list', { credentials: 'same-origin' })
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
        
        function updateStats() {
            const total = practices.length;
            const active = practices.filter(p => p.is_active === true || p.is_active === '1' || p.is_active === 1).length;
            const inactive = total - active;
            const deletionEligible = practices.filter(p => p.can_delete).length;
            
            document.getElementById('totalPractices').textContent = total;
            document.getElementById('activePractices').textContent = active;
            document.getElementById('inactivePractices').textContent = inactive;
            document.getElementById('deletionEligible').textContent = deletionEligible;
        }
        
        function renderPractices() {
            if (practices.length === 0) {
                document.getElementById('practicesTableBody').innerHTML = 
                    '<div class="empty-state">No practices found</div>';
                return;
            }
            
            let html = '<table class="practices-table"><thead><tr>' +
                '<th>Practice Name</th>' +
                '<th>Status</th>' +
                '<th>BAA</th>' +
                '<th>Users</th>' +
                '<th>Cases</th>' +
                '<th>Actions</th>' +
                '</tr></thead><tbody>';
            
            practices.forEach(practice => {
                const isActive = practice.is_active === true || practice.is_active === '1' || practice.is_active === 1;
                const statusClass = isActive ? 'active' : 'inactive';
                const statusText = isActive ? 'Active' : 'Inactive';
                
                const baaClass = practice.baa_accepted ? 'accepted' : 'pending';
                const baaText = practice.baa_accepted ? 'Accepted' : 'Pending';
                
                const selectedClass = selectedPracticeId === practice.id ? 'selected' : '';
                
                html += '<tr class="practice-row ' + selectedClass + '" onclick="selectPractice(' + practice.id + ')" data-practice-id="' + practice.id + '">' +
                    '<td>' +
                        '<strong>' + escapeHtml(practice.practice_name || practice.legal_name || 'Unnamed') + '</strong>' +
                        (practice.legal_name && practice.legal_name !== practice.practice_name 
                            ? '<br><small style="color: #6b7280;">' + escapeHtml(practice.legal_name) + '</small>' 
                            : '') +
                    '</td>' +
                    '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span>' +
                        (!isActive && practice.years_inactive > 0 
                            ? '<br><small style="color: #6b7280;">' + practice.years_inactive + ' yrs</small>' 
                            : '') +
                    '</td>' +
                    '<td><span class="baa-badge ' + baaClass + '">' + baaText + '</span></td>' +
                    '<td>' + (practice.user_count || 0) + '</td>' +
                    '<td>' + (practice.case_count || 0) + '</td>' +
                    '<td class="compact-actions" onclick="event.stopPropagation()">' +
                        (isActive 
                            ? '<button class="action-btn danger" onclick="deactivatePractice(' + practice.id + ', \'' + escapeHtml(practice.practice_name || '').replace(/'/g, "\\'") + '\')">Deactivate</button>'
                            : '<button class="action-btn success" onclick="reactivatePractice(' + practice.id + ')">Reactivate</button>') +
                    '</td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            document.getElementById('practicesTableBody').innerHTML = html;
        }
        
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
                            '<h3>' + escapeHtml(practice.practice_name || practice.legal_name || 'Unnamed Practice') + '</h3>' +
                            '<div class="subtitle">' + (isActive ? '✅ Active' : '❌ Inactive') + ' • ' + (practice.user_count || 0) + ' users • ' + (practice.case_count || 0) + ' cases</div>' +
                        '</div>' +
                        '<button class="action-btn primary" onclick="printPracticeDetails(' + practiceId + ')" style="flex-shrink: 0;">🖨️ Print</button>' +
                    '</div>' +
                '</div>' +
                '<div class="detail-tabs">' +
                    '<button class="detail-tab active" onclick="showTab(\'compliance\', ' + practiceId + ')">Compliance Details</button>' +
                    '<button class="detail-tab" onclick="showTab(\'phi\', ' + practiceId + ')">PHI Access Log</button>' +
                    '<button class="detail-tab" onclick="showTab(\'users\', ' + practiceId + ')">Users</button>' +
                '</div>' +
                '<div class="detail-content" id="detailContent">' +
                    '<div class="loading">Loading...</div>' +
                '</div>';
            
            // Load compliance tab by default
            loadComplianceTab(practiceId);
        }
        
        function showTab(tab, practiceId) {
            // Update tab buttons
            document.querySelectorAll('.detail-tab').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            document.getElementById('detailContent').innerHTML = '<div class="loading">Loading...</div>';
            
            if (tab === 'compliance') {
                loadComplianceTab(practiceId);
            } else if (tab === 'phi') {
                loadPHITab(practiceId);
            } else if (tab === 'users') {
                loadUsersTab(practiceId);
            }
        }
        
        function loadComplianceTab(practiceId) {
            fetch('/api/admin-practices.php?action=compliance&practice_id=' + practiceId, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderComplianceTab(data.compliance);
                    } else {
                        document.getElementById('detailContent').innerHTML = 
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
                });
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
        
        function loadPHITab(practiceId) {
            fetch('/api/admin-practices.php?action=phi_log&practice_id=' + practiceId + '&limit=100', { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderPHITab(data.log);
                    } else {
                        document.getElementById('detailContent').innerHTML = 
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
                });
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
            fetch('/api/admin-practices.php?action=users&practice_id=' + practiceId, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderUsersTab(data.users);
                    } else {
                        document.getElementById('detailContent').innerHTML = 
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
                });
        }
        
        function renderUsersTab(users) {
            if (!users || users.length === 0) {
                document.getElementById('detailContent').innerHTML = 
                    '<div class="empty-state">No users found</div>';
                return;
            }
            
            let html = '<table class="phi-log-table"><thead><tr>' +
                '<th>User</th>' +
                '<th>Role</th>' +
                '<th>Last Login</th>' +
                '</tr></thead><tbody>';
            
            users.forEach(user => {
                const name = [user.first_name, user.last_name].filter(Boolean).join(' ') || user.email;
                const roleLabel = user.is_owner ? 'Owner' : (user.role === 'admin' ? 'Admin' : 'User');
                html += '<tr>' +
                    '<td><strong>' + escapeHtml(name) + '</strong><br><small style="color: #6b7280;">' + escapeHtml(user.email) + '</small></td>' +
                    '<td><span class="status-badge ' + (user.is_owner || user.role === 'admin' ? 'active' : '') + '">' + roleLabel + '</span></td>' +
                    '<td>' + (user.last_login ? formatDateTime(user.last_login) : 'Never') + '</td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            document.getElementById('detailContent').innerHTML = html;
        }
        
        function viewCompliance(practiceId) {
            selectedPracticeId = practiceId;
            document.getElementById('complianceDetails').innerHTML = '<div class="loading">Loading...</div>';
            document.getElementById('complianceModal').classList.add('active');
            
            fetch('/api/admin-practices.php?action=compliance&practice_id=' + practiceId, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderComplianceDetails(data.compliance);
                    } else {
                        document.getElementById('complianceDetails').innerHTML = 
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
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
            document.getElementById('phiLogContent').innerHTML = '<div class="loading">Loading...</div>';
            document.getElementById('phiLogModal').classList.add('active');
            
            fetch('/api/admin-practices.php?action=phi_log&practice_id=' + practiceId + '&limit=100', { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderPHILog(data.log);
                    } else {
                        document.getElementById('phiLogContent').innerHTML = 
                            '<div class="empty-state">Error: ' + (data.message || 'Unknown error') + '</div>';
                    }
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
            
            fetch('/api/admin-practices.php?action=deactivate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    practice_id: selectedPracticeId,
                    reason: reason
                })
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
        
        function reactivatePractice(practiceId) {
            if (!confirm('Are you sure you want to reactivate this practice? Users will be able to log in again.')) {
                return;
            }
            
            fetch('/api/admin-practices.php?action=reactivate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ practice_id: practiceId })
            })
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
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }
        
        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
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
                fetch('/api/admin-practices.php?action=compliance&practice_id=' + practiceId, { credentials: 'same-origin' }).then(r => r.json()),
                fetch('/api/admin-practices.php?action=phi_log&practice_id=' + practiceId + '&limit=500', { credentials: 'same-origin' }).then(r => r.json()),
                fetch('/api/admin-practices.php?action=users&practice_id=' + practiceId, { credentials: 'same-origin' }).then(r => r.json())
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
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last Login</th></tr></thead>
                        <tbody>
                            ${users.length === 0 ? '<tr><td colspan="4" style="text-align:center;">No users</td></tr>' : 
                              users.map(u => `<tr>
                                <td>${escapeHtml([u.first_name, u.last_name].filter(Boolean).join(' ') || '-')}</td>
                                <td>${escapeHtml(u.email)}</td>
                                <td>${u.is_owner ? 'Owner' : (u.role === 'admin' ? 'Admin' : 'User')}</td>
                                <td>${u.last_login ? formatDateTime(u.last_login) : 'Never'}</td>
                              </tr>`).join('')}
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
</body>
</html>
