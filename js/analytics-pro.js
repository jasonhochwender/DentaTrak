/**
 * Analytics Pro - Premium Analytics Dashboard
 * Provides enhanced analytics with refined UI and same data as regular analytics
 */

(function() {
  'use strict';

  // Chart instances for cleanup
  const apCharts = {};
  let apDataLoaded = false;
  let aiRecommendationsLoading = false; // Guard against duplicate AI recommendation loads
  let aiRecommendationsLoaded = false;  // Prevents automatic repeat calls after first success

  /**
   * Apply Control-entitlement-based visibility to Insights sections.
   *
   * hasControlAccess must be the authoritative practice-level value from
   * api/billing.php's has_control_access field (backed by
   * hasControlAccess()/getPracticeSubscriptionAccess() in
   * api/subscription-access.php) -- NOT derived from the legacy, per-user
   * users.billing_tier value, which can be stale/manually set and does not
   * reflect the active practice's real subscription plan.
   */
  function applyTierBasedVisibility(hasControlAccess) {
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
        // has_control_access is the authoritative, practice-level entitlement
        // (see api/subscription-access.php: hasControlAccess()). Fall back to
        // full access only when the field is entirely absent (older cached
        // response shape), matching the previous fail-open behavior.
        const hasControlAccess = data && Object.prototype.hasOwnProperty.call(data, 'has_control_access')
          ? !!data.has_control_access
          : true;
        applyTierBasedVisibility(hasControlAccess);
      })
      .catch(function() {
        // Default to full access on error, matching previous fail-open behavior.
        applyTierBasedVisibility(true);
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
  function setAnalyticsLoading(isLoading) {
    var el = document.getElementById('apLoading');
    if (el) { el.style.display = isLoading ? 'flex' : 'none'; }
  }

  function setAnalyticsError(message) {
    var el = document.getElementById('apError');
    var text = document.getElementById('apErrorText');
    if (el) { el.style.display = message ? 'flex' : 'none'; }
    if (text && message) { text.textContent = message; }
  }

  function loadAnalyticsPro() {
    setAnalyticsLoading(true);
    setAnalyticsError('');

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
        setAnalyticsLoading(false);
        if (data && data.success) {
          const payload = data.data || {};
          renderAnalyticsPro(payload);
          apDataLoaded = true;
        } else {
          const msg = (data && data.message) ? data.message : (t('insights.error.analytics_data') || 'Unable to load analytics data.');
          setAnalyticsError(msg);
          if (typeof console !== 'undefined' && console.error) {
            console.error('[Practice Insights] API returned an error:', data);
          }
        }
      })
      .catch(error => {
        setAnalyticsLoading(false);
        setAnalyticsError(t('insights.error.analytics_data') || 'Unable to load analytics data. Please try again.');
        if (typeof console !== 'undefined' && console.error) {
          console.error('[Practice Insights] Failed to load analytics data:', error);
        }
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
    updateElement('apDelivered', metrics.deliveredThisMonth || 0);
    updateElement('apPastDue', metrics.casesPastDue || 0);
    updateElement('apArchived', metrics.totalArchivedCases || 0);

    // Update Case Flow Status (On Track, Due Soon, Appointment Risk, Late)
    const caseFlow = insights.caseFlow || {};
    updateElement('apOnTrack', caseFlow.onTrack || 0);
    updateElement('apDueSoon', caseFlow.dueSoon || 0);
    updateElement('apAppointmentRisk', caseFlow.appointmentRisk || 0);
    updateElement('apLate', caseFlow.late || 0);

    // Update trends insights
    const trends = insights.trends || {};
    updateElement('apPeakMonth', trends.peakMonth || '-');
    updateElement('apGrowthRate', (trends.growthRate || 0) + '%');
    updateElement('apNextPeak', trends.nextPeak || '-');

    // Update lifecycle metrics
    const lifecycle = charts.lifecycle || {};
    updateElement('apAvgLifecycle', I18n.pluralize(lifecycle.avg_total_days || 0, 'insights.metrics.days'));
    updateElement('apFastestCase', I18n.pluralize(lifecycle.min_total_days || 0, 'insights.metrics.days'));
    updateElement('apSlowestCase', I18n.pluralize(lifecycle.max_total_days || 0, 'insights.metrics.days'));

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
    renderCreatorBreakdown(charts.creatorBreakdown || []);

    // Show AI recommendations section if there are cases, then load recommendations
    const totalCases = (metrics.totalActiveCases || 0) + (metrics.totalDeliveredCases || 0) + (metrics.totalArchivedCases || 0);
    const aiSection = document.getElementById('aiRecommendationsSection');

    if (totalCases > 0) {
      if (aiSection) aiSection.style.display = 'block';
      // Only auto-load on the first successful analytics render.
      // Filter changes and tab re-opens do not trigger a new Gemini call.
      if (!aiRecommendationsLoaded) {
        loadAIRecommendations(false);
      }
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

    const labels = Object.keys(statusData).map(function(status) {
      return (typeof getStageLabel === 'function') ? getStageLabel(status) : status;
    });
    const values = Object.values(statusData);

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, 'Status distribution', [t('insights.charts.no_data')], [0]); return; }

    apCharts['apStatusChart'] = new Chart(ctx, {
      type: 'doughnut',
      data: {
        // statusData is keyed by the fixed internal status; only the
        // chart's visible labels are resolved to the practice-specific
        // display label, Object.values(statusData) stays aligned.
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors.chartColors.slice(0, labels.length),
          borderWidth: 0,
          hoverOffset: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: getMobileLegendOptions()
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Status distribution', labels, values, function(v) { return v + ' cases'; });
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

    const labels = Object.keys(typeData);
    const values = Object.values(typeData);

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, 'Case type breakdown', [t('insights.charts.no_data')], [0]); return; }

    apCharts['apTypeChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: t('insights.charts.dataset_cases'),
          data: values,
          backgroundColor: colors.secondary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: getMobileLegendOptions()
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          }
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Case type breakdown', labels, values, function(v) { return v + ' cases'; });
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, 'Monthly case volume', [t('insights.charts.no_data')], [0]); return; }

    apCharts['apVolumeChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: t('insights.charts.dataset_created'),
            data: created,
            borderColor: colors.primary,
            backgroundColor: 'rgba(30, 64, 175, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6
          },
          {
            label: t('insights.charts.dataset_delivered'),
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
          legend: getMobileLegendOptions()
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          }
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Monthly case volume', labels, created, function(v) { return v + ' created'; });
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

    const labels = Object.keys(teamData);
    const values = Object.values(teamData);

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, 'Cases by assignee', [t('insights.charts.no_data')], [0]); return; }

    apCharts['apTeamChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: t('insights.charts.dataset_cases'),
          data: values,
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
          legend: getMobileLegendOptions()
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          },
          y: {
            grid: { display: false },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          }
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Cases by assignee', labels, values, function(v) { return v + ' cases'; });
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, 'Year-over-year trends', [t('insights.charts.no_data')], [0]); return; }

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
          legend: getMobileLegendOptions()
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          }
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Year-over-year trends', labels, currentYearData, function(v) { return v + ' this year'; });
  }

  // Cases Created by User breakdown
  function renderCreatorBreakdown(creatorBreakdown) {
    const container = document.getElementById('apCreatorBreakdown');
    if (!container) return;

    if (!creatorBreakdown || !Array.isArray(creatorBreakdown) || creatorBreakdown.length === 0) {
      container.innerHTML = '<p class="insights-empty-state" id="apCreatorBreakdownEmpty" style="width: 100%;">' + t('insights.creators.empty') + '</p>';
      return;
    }

    let html = '';
    creatorBreakdown.forEach(function(item) {
      const name = escapeHtml(item.creator || 'Unknown');
      const count = parseInt(item.cases_count || 0, 10);
      html += '<div class="ap-insight-card">' +
        '<div class="ap-insight-value">' + count + '</div>' +
        '<div class="ap-insight-label">' + name + '</div>' +
      '</div>';
    });
    container.innerHTML = html;
  }

  // Clears the recommendations container — removes the loading indicator, stale errors,
  // and any previous recommendations before rendering new content.
  function clearAIRecommendationsLoadingState(container) {
    container.innerHTML = '';
  }

  // Load AI Recommendations.
  // isManual=true: user-initiated Refresh, bypasses aiRecommendationsLoaded guard.
  // isManual=false (default): auto-load, skipped if already loaded successfully.
  function loadAIRecommendations(isManual) {
    const container = document.getElementById('apRecommendations');
    const aiRefreshBtn = document.getElementById('apRefreshAI');

    if (!container) return;

    // Prevent overlapping requests regardless of how this was called
    if (aiRecommendationsLoading) return;
    aiRecommendationsLoading = true;

    // Disable Refresh button for the duration of the request
    if (aiRefreshBtn) aiRefreshBtn.disabled = true;

    // Clear any previous state and show loading indicator inside the container.
    // The static #apAILoading child is intentionally replaced here — it is inside
    // #apRecommendations and would be wiped by innerHTML anyway.
    container.innerHTML = '<div class="ap-loading"><div class="ap-loading-spinner"></div><p class="ap-loading-text">' + t('insights.ai.generating') + '</p></div>';

    fetch('api/ai-recommendations.php', { credentials: 'same-origin' })
      .then(response => response.json())
      .then(data => {
        // Always clear the loading indicator before rendering any result
        clearAIRecommendationsLoadingState(container);

        if (data.error) {
          // Failed — leave aiRecommendationsLoaded as-is so manual Refresh still works
          showAIError(container, data.error);
          return;
        }

        if (data.success && data.recommendations && data.recommendations.length > 0) {
          displayRecommendations(container, data.recommendations);
          aiRecommendationsLoaded = true;
        } else {
          showAIError(container, t('insights.ai.none_available'));
        }
      })
      .catch(error => {
        // Network/parse failure; UI toast handles display
        clearAIRecommendationsLoadingState(container);
        showAIError(container, t('insights.ai.load_error'));
      })
      .finally(() => {
        aiRecommendationsLoading = false;
        if (aiRefreshBtn) aiRefreshBtn.disabled = false;
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
            <span class="ap-recommendation-priority ${rec.priority || 'medium'}">${t('insights.priority.' + (rec.priority || 'medium'))}</span>
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

  // Shared legend options: bottom, compact on narrow screens, point style.
  function getMobileLegendOptions() {
    const isNarrow = window.innerWidth < 480;
    return {
      display: true,
      position: 'bottom',
      labels: {
        padding: isNarrow ? 8 : 12,
        boxWidth: isNarrow ? 10 : 12,
        usePointStyle: true,
        pointStyle: 'circle',
        font: { size: isNarrow ? 11 : 11, family: "'Poppins', sans-serif" }
      }
    };
  }

  // Accessible text summary for chart canvases.
  function setChartAriaLabel(canvas, title, labels, values, valueFormatter) {
    if (!canvas) return;
    const fmt = valueFormatter || function(v) { return String(v); };
    const summary = (labels || []).map(function(label, i) {
      const val = (values && typeof values[i] !== 'undefined') ? fmt(values[i]) : '';
      return label + ': ' + val;
    }).join('; ');
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', title + '. ' + summary);
  }

  // Expose load function globally
  window.loadAnalyticsProData = function() {
    initializeEventListeners();
    loadAnalyticsPro();
  };

  // Setup event listeners for dropdowns
  var eventListenersInitialized = false;
  function initializeEventListeners() {
    if (eventListenersInitialized) {
      // Restore filters without re-attaching listeners on every tab visit.
      restoreSavedFilters();
      return;
    }
    eventListenersInitialized = true;

    // Restore saved filter values from localStorage (always do this)
    restoreSavedFilters();

    // Refresh button
    const refreshBtn = document.getElementById('apRefreshData');
    if (refreshBtn && !refreshBtn.hasAttribute('data-ap-listener')) {
      refreshBtn.addEventListener('click', loadAnalyticsPro);
      refreshBtn.setAttribute('data-ap-listener', 'true');
    }

    // AI Refresh button — manual clicks bypass the aiRecommendationsLoaded guard
    const aiRefreshBtn = document.getElementById('apRefreshAI');
    if (aiRefreshBtn && !aiRefreshBtn.hasAttribute('data-ap-listener')) {
      aiRefreshBtn.addEventListener('click', function() { loadAIRecommendations(true); });
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
      var rawStatus = item.status || 'Unknown';
      labels.push((typeof getStageLabel === 'function') ? getStageLabel(rawStatus) : rawStatus);
      avgDays.push(Number(item.avg_days_in_status || 0));
      minDays.push(Number(item.min_days_in_status || 0));
      maxDays.push(Number(item.max_days_in_status || 0));
    });

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, 'Status duration', [t('insights.charts.no_data')], [0]); return; }

    apCharts['apDurationChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: t('insights.charts.dataset_average_days'),
            data: avgDays,
            backgroundColor: colors.primary,
            borderRadius: 6,
            maxBarThickness: (window.innerWidth < 480 ? 24 : 48),
            borderSkipped: false
          },
          {
            label: t('insights.charts.dataset_min_days'),
            data: minDays,
            backgroundColor: colors.success,
            borderRadius: 6,
            maxBarThickness: (window.innerWidth < 480 ? 24 : 48),
            borderSkipped: false
          },
          {
            label: t('insights.charts.dataset_max_days'),
            data: maxDays,
            backgroundColor: colors.danger,
            borderRadius: 6,
            maxBarThickness: (window.innerWidth < 480 ? 24 : 48),
            borderSkipped: false
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: getMobileLegendOptions()
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          }
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Status duration', labels, avgDays, function(v) { return v + ' days'; });
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
          labels: [t('insights.charts.no_data')],
          datasets: [{
            label: t('insights.charts.dataset_days'),
            data: [0],
            backgroundColor: colors.slate,
            borderRadius: 6,
            maxBarThickness: (window.innerWidth < 480 ? 24 : 48)
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: getMobileLegendOptions()
          },
          scales: {
            y: { beginAtZero: true }
          }
        }
      });
      setChartAriaLabel(ctx.canvas, 'Lifecycle distribution', [t('insights.charts.no_data')], [0]);
      return;
    }

    const lifecycleLabels = [t('insights.charts.lifecycle_fastest'), t('insights.charts.lifecycle_average'), t('insights.charts.lifecycle_slowest')];
    const lifecycleValues = [
      Number(data.min_total_days || 0),
      Number(data.avg_total_days || 0),
      Number(data.max_total_days || 0)
    ];

    apCharts['apLifecycleChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: lifecycleLabels,
        datasets: [{
          label: t('insights.charts.dataset_days'),
          data: lifecycleValues,
          backgroundColor: [colors.success, colors.primary, colors.danger],
          borderRadius: 6,
          maxBarThickness: (window.innerWidth < 480 ? 24 : 48),
          borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: getMobileLegendOptions(),
          tooltip: {
            callbacks: {
              label: function(context) {
                return I18n.pluralize(context.parsed.y, 'insights.metrics.days');
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: (window.innerWidth < 480 ? 13 : 10), family: "'Poppins', sans-serif" }, autoSkip: true, maxRotation: (window.innerWidth < 480 ? 45 : 0), minRotation: 0 }
          }
        }
      }
    });
    setChartAriaLabel(ctx.canvas, 'Lifecycle distribution', lifecycleLabels, lifecycleValues, function(v) { return v + ' days'; });
  }

  // Initialize immediately since this script is lazy-loaded after DOMContentLoaded
  function initOnLoad() {
    initializeEventListeners();

    // Load billing tier and apply visibility restrictions
    loadBillingTierAndApplyVisibility();

    // NOTE: Do NOT call loadAnalyticsPro() here - app.js will call window.loadAnalyticsProData()
    // after this script loads. Calling it here would cause duplicate API calls.
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

  // Resize and orientation-change handler: Chart.js responsive does not always
  // notice when the Insights tab becomes visible or the viewport changes on a
  // phone. Call resize() explicitly so charts fit the new container size.
  var resizeTimeout;
  function resizeCharts() {
    Object.keys(apCharts).forEach(function(key) {
      var chart = apCharts[key];
      if (chart && typeof chart.resize === 'function') {
        chart.resize();
      }
    });
  }
  window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(resizeCharts, 150);
  });
  window.addEventListener('orientationchange', function() {
    setTimeout(resizeCharts, 300);
  });
  document.addEventListener('insightsVisible', resizeCharts);

})();
