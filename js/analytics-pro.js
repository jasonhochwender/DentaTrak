/**
 * Analytics Pro - Premium Analytics Dashboard
 * Provides enhanced analytics with refined UI and same data as regular analytics
 */

(function() {
  'use strict';

  // Chart instances for cleanup
  const apCharts = {};
  let apDataLoaded = false;
  let currentBillingTier = null;

  /**
   * Apply tier-based visibility to Insights sections
   * - Evaluate: Full access (trial)
   * - Operate: Core operational visibility (some sections blurred)
   * - Control: Full access with advanced features
   */
  function applyTierBasedVisibility(tier) {
    currentBillingTier = tier || 'evaluate';
    
    // Determine if user has Control-level access
    // Evaluate (trial) and Control both get full access
    const hasControlAccess = currentBillingTier === 'control' || currentBillingTier === 'evaluate';
    
    // Elements with blur overlay for Control-only features
    const controlOnlyFeatures = document.querySelectorAll('[data-control-feature]');
    
    controlOnlyFeatures.forEach(function(section) {
      if (hasControlAccess) {
        section.classList.remove('ap-locked');
      } else {
        section.classList.add('ap-locked');
      }
    });
    
    // Legacy: Handle old data-tier-required sections (hide completely)
    const controlOnlySections = document.querySelectorAll('[data-tier-required="control"]');
    controlOnlySections.forEach(function(section) {
      section.style.display = hasControlAccess ? '' : 'none';
    });
    
    // Legacy: Show/hide placeholders for Operate users
    const controlPlaceholders = document.querySelectorAll('[data-tier-placeholder="control"]');
    controlPlaceholders.forEach(function(placeholder) {
      placeholder.style.display = hasControlAccess ? 'none' : '';
    });
  }

  /**
   * Fetch billing tier and apply visibility
   */
  function loadBillingTierAndApplyVisibility() {
    fetch('api/billing.php', { credentials: 'same-origin' })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data && data.billing_tier) {
          applyTierBasedVisibility(data.billing_tier);
        }
      })
      .catch(function() {
        // Default to evaluate (full access) on error
        applyTierBasedVisibility('evaluate');
      });
  }

  // Destroy a chart instance
  function destroyChart(chartId) {
    if (apCharts[chartId]) {
      apCharts[chartId].destroy();
      delete apCharts[chartId];
    }
  }

  // Destroy all charts
  function destroyAllCharts() {
    Object.keys(apCharts).forEach(destroyChart);
  }

  // Premium color palette
  const colors = {
    primary: '#1e40af',
    primaryLight: '#3b82f6',
    secondary: '#f97316',
    success: '#10b981',
    warning: '#f59e0b',
    danger: '#ef4444',
    purple: '#8b5cf6',
    cyan: '#06b6d4',
    pink: '#ec4899',
    slate: '#64748b',
    chartColors: [
      '#1e40af', '#3b82f6', '#06b6d4', '#10b981', 
      '#f59e0b', '#f97316', '#ef4444', '#8b5cf6',
      '#ec4899', '#64748b'
    ],
    gradients: {
      blue: ['rgba(30, 64, 175, 0.8)', 'rgba(59, 130, 246, 0.6)'],
      green: ['rgba(16, 185, 129, 0.8)', 'rgba(52, 211, 153, 0.6)'],
      orange: ['rgba(249, 115, 22, 0.8)', 'rgba(251, 146, 60, 0.6)']
    }
  };

  // Restore saved filter values from localStorage
  function restoreSavedFilters() {
    const filters = {
      'apTeamPeriod': localStorage.getItem('ap_team_period') || '12',
      'apTeamFilter': localStorage.getItem('ap_team_filter') || 'both',
      'apVolumePeriod': localStorage.getItem('ap_volume_period') || '12',
      'apStatusPeriod': localStorage.getItem('ap_status_period') || 'active',
      'apTypePeriod': localStorage.getItem('ap_type_period') || 'active',
      'apDurationPeriod': localStorage.getItem('ap_duration_period') || 'active'
    };
    
    Object.keys(filters).forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.value = filters[id];
      }
    });
  }

  // Load Analytics Pro data
  function loadAnalyticsPro() {
    const teamPeriod = document.getElementById('apTeamPeriod')?.value || '12';
    const teamFilter = document.getElementById('apTeamFilter')?.value || 'both';
    const volumePeriod = document.getElementById('apVolumePeriod')?.value || '12';
    const statusPeriod = document.getElementById('apStatusPeriod')?.value || 'active';
    const typePeriod = document.getElementById('apTypePeriod')?.value || 'active';
    const durationPeriod = document.getElementById('apDurationPeriod')?.value || 'active';

    const apiUrl = `api/get-analytics.php?team_period=${teamPeriod}&team_filter=${teamFilter}&volume_period=${volumePeriod}&status_period=${statusPeriod}&type_period=${typePeriod}&duration_period=${durationPeriod}`;

    fetch(apiUrl, {
      credentials: 'same-origin'
    })
      .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(data => {
        if (data && data.success) {
          const payload = data.data || {};
          renderAnalyticsPro(payload);
          apDataLoaded = true;
        } else {
          console.error('Analytics Pro API error:', data?.message);
        }
      })
      .catch(error => {
        console.error('Analytics Pro fetch error:', error);
      });
  }

  // Render all Analytics Pro components
  function renderAnalyticsPro(data) {
    const metrics = data.metrics || {};
    const charts = data.charts || {};
    const insights = data.advancedInsights || {};

    // Update metrics
    updateElement('apCasesThisMonth', metrics.casesThisMonth || 0);
    updateElement('apActiveCases', metrics.totalActiveCases || 0);
    updateElement('apDelivered', metrics.totalDeliveredCases || 0);
    updateElement('apPastDue', metrics.casesPastDue || 0);
    updateElement('apArchived', metrics.totalArchivedCases || 0);

    // Update operational status
    const completion = insights.completion || {};
    updateElement('apOnTrack', completion.onTrack || 0);
    updateElement('apAtRisk', completion.atRisk || 0);
    updateElement('apDelayed', completion.delayed || 0);

    // Update trends insights
    const trends = insights.trends || {};
    updateElement('apPeakMonth', trends.peakMonth || '-');
    updateElement('apGrowthRate', (trends.growthRate || 0) + '%');
    updateElement('apNextPeak', trends.nextPeak || '-');

    // Update lifecycle metrics
    const lifecycle = charts.lifecycle || {};
    updateElement('apAvgLifecycle', (lifecycle.avg_total_days || 0) + ' days');
    updateElement('apFastestCase', (lifecycle.min_total_days || 0) + ' days');
    updateElement('apSlowestCase', (lifecycle.max_total_days || 0) + ' days');

    // Destroy existing charts before creating new ones
    destroyAllCharts();

    // Render charts
    renderStatusChart(charts.statusDistribution || []);
    renderTypeChart(charts.caseTypeBreakdown || []);
    renderVolumeChart(charts.monthlyVolume || []);
    renderTeamChart(charts.teamPerformance || []);
    renderDurationChart(charts.statusDuration || []);
    renderLifecycleChart(charts.lifecycle || {});
    renderTrendsChart(trends);

    // Show AI recommendations section if there are cases, then load recommendations
    const totalCases = (metrics.totalActiveCases || 0) + (metrics.totalDeliveredCases || 0) + (metrics.totalArchivedCases || 0);
    const aiSection = document.getElementById('aiRecommendationsSection');
    
    if (totalCases > 0) {
      if (aiSection) aiSection.style.display = 'block';
      loadAIRecommendations();
    } else {
      if (aiSection) aiSection.style.display = 'none';
    }
  }

  // Helper to update element text
  function updateElement(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  // Status Distribution Chart (Doughnut)
  function renderStatusChart(data) {
    const ctx = document.getElementById('apStatusChart')?.getContext('2d');
    if (!ctx) return;

    const statusData = {};
    (data || []).forEach(item => {
      const status = item.status || 'Unknown';
      statusData[status] = (statusData[status] || 0) + Number(item.count || 0);
    });

    if (Object.keys(statusData).length === 0) return;

    apCharts['apStatusChart'] = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: Object.keys(statusData),
        datasets: [{
          data: Object.values(statusData),
          backgroundColor: colors.chartColors.slice(0, Object.keys(statusData).length),
          borderWidth: 0,
          hoverOffset: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 16,
              usePointStyle: true,
              pointStyle: 'circle',
              font: { size: 11, family: "'Poppins', sans-serif" }
            }
          }
        }
      }
    });
  }

  // Case Type Chart (Bar)
  function renderTypeChart(data) {
    const ctx = document.getElementById('apTypeChart')?.getContext('2d');
    if (!ctx) return;

    const typeData = {};
    (data || []).forEach(item => {
      const type = item.case_type || 'Unspecified';
      typeData[type] = (typeData[type] || 0) + Number(item.count || 0);
    });

    if (Object.keys(typeData).length === 0) return;

    apCharts['apTypeChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: Object.keys(typeData),
        datasets: [{
          label: 'Cases',
          data: Object.values(typeData),
          backgroundColor: colors.secondary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          }
        }
      }
    });
  }

  // Monthly Volume Chart (Line)
  function renderVolumeChart(data) {
    const ctx = document.getElementById('apVolumeChart')?.getContext('2d');
    if (!ctx) return;

    const labels = [];
    const created = [];
    const delivered = [];

    (data || []).forEach(item => {
      labels.push(item.month || '');
      created.push(Number(item.cases_created || 0));
      delivered.push(Number(item.cases_delivered || 0));
    });

    if (labels.length === 0) return;

    apCharts['apVolumeChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Created',
            data: created,
            borderColor: colors.primary,
            backgroundColor: 'rgba(30, 64, 175, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6
          },
          {
            label: 'Delivered',
            data: delivered,
            borderColor: colors.success,
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 16,
              usePointStyle: true,
              font: { size: 11, family: "'Poppins', sans-serif" }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          }
        }
      }
    });
  }

  // Team Performance Chart (Horizontal Bar)
  function renderTeamChart(data) {
    const ctx = document.getElementById('apTeamChart')?.getContext('2d');
    if (!ctx) return;

    const teamData = {};
    (data || []).forEach(item => {
      const assignee = item.assignee || 'Unassigned';
      teamData[assignee] = Number(item.cases_count || 0);
    });

    if (Object.keys(teamData).length === 0) return;

    apCharts['apTeamChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: Object.keys(teamData),
        datasets: [{
          label: 'Cases',
          data: Object.values(teamData),
          backgroundColor: colors.primaryLight,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          },
          y: {
            grid: { display: false },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          }
        }
      }
    });
  }

  // Year-over-Year Trends Chart
  function renderTrendsChart(trendsData) {
    const ctx = document.getElementById('apTrendsChart')?.getContext('2d');
    if (!ctx || !trendsData) return;

    const monthlyData = trendsData.monthlyData || [];
    const currentYear = trendsData.currentYear || new Date().getFullYear();
    const lastYear = currentYear - 1;

    const labels = [];
    const currentYearData = [];
    const lastYearData = [];

    monthlyData.forEach(item => {
      if (item.month) {
        // API returns month as short name like "Jan", "Feb", etc.
        if (!labels.includes(item.month)) {
          labels.push(item.month);
          currentYearData.push(item.currentYear || 0);
          lastYearData.push(item.lastYear || 0);
        }
      }
    });

    if (labels.length === 0) return;

    apCharts['apTrendsChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: currentYear.toString(),
            data: currentYearData,
            borderColor: colors.primary,
            backgroundColor: 'rgba(30, 64, 175, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 2
          },
          {
            label: lastYear.toString(),
            data: lastYearData,
            borderColor: colors.slate,
            backgroundColor: 'rgba(100, 116, 139, 0.05)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 2,
            borderDash: [5, 5]
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 16,
              usePointStyle: true,
              font: { size: 11, family: "'Poppins', sans-serif" }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          }
        }
      }
    });
  }

  // Load AI Recommendations
  function loadAIRecommendations() {
    const container = document.getElementById('apRecommendations');
    const loadingEl = document.getElementById('apAILoading');
    
    if (!container) return;
    if (loadingEl) loadingEl.style.display = 'flex';

    // Clear previous recommendations
    const existingItems = container.querySelectorAll('.ap-recommendation-item');
    existingItems.forEach(item => item.remove());

    fetch('api/ai-recommendations.php')
      .then(response => response.json())
      .then(data => {
        if (loadingEl) loadingEl.style.display = 'none';

        if (data.error) {
          showAIError(container, data.error);
          return;
        }

        if (data.success && data.recommendations && data.recommendations.length > 0) {
          displayRecommendations(container, data.recommendations);
        } else {
          showAIError(container, 'No recommendations available at this time.');
        }
      })
      .catch(error => {
        console.error('AI Recommendations error:', error);
        if (loadingEl) loadingEl.style.display = 'none';
        showAIError(container, 'Failed to load recommendations. Please try again.');
      });
  }

  // Display AI Recommendations
  function displayRecommendations(container, recommendations) {
    recommendations.forEach(rec => {
      const item = document.createElement('div');
      item.className = 'ap-recommendation-item';

      const iconClass = rec.category || 'efficiency';
      const iconSvg = getCategoryIcon(iconClass);

      item.innerHTML = `
        <div class="ap-recommendation-icon ${iconClass}">
          ${iconSvg}
        </div>
        <div class="ap-recommendation-content">
          <div class="ap-recommendation-header">
            <h4 class="ap-recommendation-title">${escapeHtml(rec.title)}</h4>
            <span class="ap-recommendation-priority ${rec.priority || 'medium'}">${rec.priority || 'medium'}</span>
          </div>
          <p class="ap-recommendation-description">${escapeHtml(rec.description)}</p>
        </div>
      `;

      container.appendChild(item);
    });
  }

  // Get category icon SVG
  function getCategoryIcon(category) {
    const icons = {
      efficiency: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
      quality: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      scheduling: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
      workload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
      communication: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
    };
    return icons[category] || icons.efficiency;
  }

  // Show AI error
  function showAIError(container, message) {
    const errorEl = document.createElement('div');
    errorEl.className = 'ap-empty-state';
    errorEl.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <p>${escapeHtml(message)}</p>
    `;
    container.appendChild(errorEl);
  }

  // Escape HTML
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Expose load function globally
  window.loadAnalyticsProData = function() {
    initializeEventListeners();
    loadAnalyticsPro();
  };

  // Setup event listeners for dropdowns
  function initializeEventListeners() {
    // Restore saved filter values from localStorage (always do this)
    restoreSavedFilters();
    
    // Refresh button
    const refreshBtn = document.getElementById('apRefreshData');
    if (refreshBtn && !refreshBtn.hasAttribute('data-ap-listener')) {
      refreshBtn.addEventListener('click', loadAnalyticsPro);
      refreshBtn.setAttribute('data-ap-listener', 'true');
    }

    // AI Refresh button
    const aiRefreshBtn = document.getElementById('apRefreshAI');
    if (aiRefreshBtn && !aiRefreshBtn.hasAttribute('data-ap-listener')) {
      aiRefreshBtn.addEventListener('click', loadAIRecommendations);
      aiRefreshBtn.setAttribute('data-ap-listener', 'true');
    }

    // Filter dropdowns - map element IDs to localStorage keys
    const filterStorageKeys = {
      'apStatusPeriod': 'ap_status_period',
      'apTypePeriod': 'ap_type_period',
      'apVolumePeriod': 'ap_volume_period',
      'apTeamPeriod': 'ap_team_period',
      'apTeamFilter': 'ap_team_filter',
      'apDurationPeriod': 'ap_duration_period'
    };
    
    Object.keys(filterStorageKeys).forEach(id => {
      const el = document.getElementById(id);
      if (el && !el.hasAttribute('data-ap-listener')) {
        el.addEventListener('change', function() {
          // Save to localStorage
          localStorage.setItem(filterStorageKeys[id], this.value);
          loadAnalyticsPro();
        });
        el.setAttribute('data-ap-listener', 'true');
      }
    });
  }

  // Status Duration Chart (Bar)
  function renderDurationChart(data) {
    const ctx = document.getElementById('apDurationChart')?.getContext('2d');
    if (!ctx) return;

    const labels = [];
    const avgDays = [];
    const minDays = [];
    const maxDays = [];

    (data || []).forEach(item => {
      labels.push(item.status || 'Unknown');
      avgDays.push(Number(item.avg_days_in_status || 0));
      minDays.push(Number(item.min_days_in_status || 0));
      maxDays.push(Number(item.max_days_in_status || 0));
    });

    if (labels.length === 0) return;

    apCharts['apDurationChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Average Days',
            data: avgDays,
            backgroundColor: colors.primary,
            borderRadius: 6,
            borderSkipped: false
          },
          {
            label: 'Min Days',
            data: minDays,
            backgroundColor: colors.success,
            borderRadius: 6,
            borderSkipped: false
          },
          {
            label: 'Max Days',
            data: maxDays,
            backgroundColor: colors.danger,
            borderRadius: 6,
            borderSkipped: false
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 16,
              usePointStyle: true,
              font: { size: 11, family: "'Poppins', sans-serif" }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          }
        }
      }
    });
  }

  // Lifecycle Distribution Chart (Bar)
  function renderLifecycleChart(data) {
    const ctx = document.getElementById('apLifecycleChart')?.getContext('2d');
    if (!ctx) return;

    if (!data || !data.avg_total_days) {
      // Show empty state
      apCharts['apLifecycleChart'] = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: ['No Data'],
          datasets: [{
            label: 'Days',
            data: [0],
            backgroundColor: colors.slate,
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          },
          scales: {
            y: { beginAtZero: true }
          }
        }
      });
      return;
    }

    apCharts['apLifecycleChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['Fastest', 'Average', 'Slowest'],
        datasets: [{
          label: 'Days',
          data: [
            Number(data.min_total_days || 0),
            Number(data.avg_total_days || 0),
            Number(data.max_total_days || 0)
          ],
          backgroundColor: [colors.success, colors.primary, colors.danger],
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(context) {
                return context.parsed.y + ' days';
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 10, family: "'Poppins', sans-serif" } }
          }
        }
      }
    });
  }

  // Initialize immediately since this script is lazy-loaded after DOMContentLoaded
  function initOnLoad() {
    initializeEventListeners();
    
    // Load billing tier and apply visibility restrictions
    loadBillingTierAndApplyVisibility();
    
    // Check if Analytics/Insights tab is already active
    const activeTab = document.querySelector('.main-tab.active');
    if (activeTab && (activeTab.getAttribute('data-tab') === 'analytics' || activeTab.getAttribute('data-tab') === 'insights')) {
      setTimeout(loadAnalyticsPro, 100);
    }
  }
  
  // Run immediately if DOM is ready, otherwise wait for DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOnLoad);
  } else {
    // DOM already loaded (script was lazy-loaded)
    initOnLoad();
  }

  // Use event delegation for the team filter dropdown as a fallback
  // This ensures it works even if the regular listener wasn't attached
  document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'apTeamFilter') {
      localStorage.setItem('ap_team_filter', e.target.value);
      loadAnalyticsPro();
    }
  });

})();
