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
  // Last successful recommendations payload + UI filter state. Kept so
  // lifecycle actions and filter chips can re-render without refetching.
  let aiRecsData = null;
  let aiRecsFilter = 'active';

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
      'apDurationPeriod': localStorage.getItem('ap_duration_period') || 'active',
      'apCreatorPeriod': localStorage.getItem('ap_creator_period') || 'all'
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

  // ---- Global Insights filters (af_*) -------------------------------------
  // Persisted in localStorage; applied server-side to the historical
  // analytics queries in api/get-analytics.php. Operational metrics
  // (caseFlow, attentionQueue, KPI cards) intentionally keep their
  // current-state definitions and ignore the reporting period.
  const AF_STORAGE_KEY = 'ap_global_filters';
  const AF_DEFAULTS = { period: '90d', from: '', to: '', case_type: '', status: '', assignee: '', carrier: '' };
  let afFilters = Object.assign({}, AF_DEFAULTS);
  let afRequestSeq = 0;
  let afFacetsLoaded = false;

  function loadAfFilters() {
    try {
      const saved = JSON.parse(localStorage.getItem(AF_STORAGE_KEY) || '{}');
      afFilters = Object.assign({}, AF_DEFAULTS, saved);
    } catch (e) { afFilters = Object.assign({}, AF_DEFAULTS); }
    // Sanitize period value.
    if (!['30d', '90d', '12m', 'all', 'custom'].includes(afFilters.period)) {
      afFilters.period = '90d';
    }
  }

  function saveAfFilters() {
    try { localStorage.setItem(AF_STORAGE_KEY, JSON.stringify(afFilters)); } catch (e) {}
  }

  function buildAfQuery() {
    const p = new URLSearchParams();
    if (afFilters.period) p.set('af_period', afFilters.period);
    if (afFilters.period === 'custom') {
      if (/^\d{4}-\d{2}-\d{2}$/.test(afFilters.from)) p.set('af_from', afFilters.from);
      if (/^\d{4}-\d{2}-\d{2}$/.test(afFilters.to)) p.set('af_to', afFilters.to);
    }
    if (afFilters.case_type) p.set('af_case_type', afFilters.case_type);
    if (afFilters.status) p.set('af_status', afFilters.status);
    if (afFilters.assignee) p.set('af_assignee', afFilters.assignee);
    if (afFilters.carrier) p.set('af_carrier', afFilters.carrier);
    const s = p.toString();
    return s ? '&' + s : '';
  }

  function afActiveCount() {
    // Count non-default dimension filters (period is always "set" but is the
    // baseline context, not counted as an extra filter).
    return ['case_type', 'status', 'assignee', 'carrier'].filter(function(k) { return afFilters[k] !== ''; }).length;
  }

  function syncAfToolbar() {
    const set = function(id, val) { const el = document.getElementById(id); if (el) el.value = val; };
    set('afPeriod', afFilters.period);
    set('afCaseType', afFilters.case_type);
    set('afStatus', afFilters.status);
    set('afAssignee', afFilters.assignee);
    set('afCarrier', afFilters.carrier);
    const from = document.getElementById('afFrom');
    const to = document.getElementById('afTo');
    if (from) from.value = afFilters.from;
    if (to) to.value = afFilters.to;
    const dates = document.getElementById('afCustomDates');
    if (dates) dates.hidden = afFilters.period !== 'custom';
    const badge = document.getElementById('afFilterBadge');
    const n = afActiveCount();
    if (badge) {
      badge.hidden = n === 0;
      badge.textContent = n > 0 ? '(' + n + ')' : '';
    }
    renderAfChips();
  }

  function renderAfChips() {
    const wrap = document.getElementById('afFilterChips');
    if (!wrap) return;
    const defs = [
      ['case_type', 'afCaseType', 'insights.filters.case_type'],
      ['status', 'afStatus', 'insights.filters.status'],
      ['assignee', 'afAssignee', 'insights.filters.team_member'],
      ['carrier', 'afCarrier', 'insights.filters.laboratory']
    ];
    const chips = [];
    defs.forEach(function(d) {
      const key = d[0], selId = d[1], labelKey = d[2];
      if (!afFilters[key]) return;
      const sel = document.getElementById(selId);
      const opt = sel ? sel.options[sel.selectedIndex] : null;
      const label = opt ? opt.textContent : afFilters[key];
      chips.push('<button type="button" class="ap-filter-chip" data-af-chip="' + key + '">' +
        escapeHtml(t(labelKey)) + ': ' + escapeHtml(label) +
        ' <span aria-hidden="true">×</span></button>');
    });
    // Custom range chip when period=custom.
    if (afFilters.period === 'custom' && (afFilters.from || afFilters.to)) {
      chips.push('<button type="button" class="ap-filter-chip" data-af-chip="__period">' +
        escapeHtml(t('insights.filters.custom_range')) + ': ' + escapeHtml(afFilters.from || '…') + ' – ' + escapeHtml(afFilters.to || '…') +
        ' <span aria-hidden="true">×</span></button>');
    }
    wrap.innerHTML = chips.join('');
    wrap.hidden = chips.length === 0;
    wrap.querySelectorAll('.ap-filter-chip').forEach(function(btn) {
      btn.addEventListener('click', function() {
        const key = btn.dataset.afChip;
        if (key === '__period') { afFilters.period = '90d'; afFilters.from = ''; afFilters.to = ''; }
        else { afFilters[key] = ''; }
        saveAfFilters(); syncAfToolbar(); loadAnalyticsPro();
      });
    });
  }

  // Populate dimension dropdowns from facets returned by get-analytics.php.
  function populateAfFacets(facets) {
    if (!facets || afFacetsLoaded) return;
    afFacetsLoaded = true;
    const fill = function(id, values, keepValue) {
      const sel = document.getElementById(id);
      if (!sel) return;
      const current = keepValue !== undefined ? keepValue : sel.value;
      values.forEach(function(v) {
        if (![].some.call(sel.options, function(o) { return o.value === v; })) {
          const opt = document.createElement('option');
          opt.value = v; opt.textContent = v;
          sel.appendChild(opt);
        }
      });
      sel.value = current;
    };
    fill('afCaseType', facets.case_types || [], afFilters.case_type);
    fill('afAssignee', facets.assignees || [], afFilters.assignee);
    fill('afCarrier', facets.carriers || [], afFilters.carrier);
    // Workflow statuses: groups + each configured stage.
    const statusSel = document.getElementById('afStatus');
    if (statusSel && Array.isArray(window.workflowStageOrder)) {
      window.workflowStageOrder.forEach(function(st) {
        const label = (typeof getStageLabel === 'function' && getStageLabel(st)) || st;
        if (![].some.call(statusSel.options, function(o) { return o.value === st; })) {
          const opt = document.createElement('option');
          opt.value = st; opt.textContent = label;
          statusSel.appendChild(opt);
        }
      });
      statusSel.value = afFilters.status;
    }
  }

  function initAfToolbar() {
    loadAfFilters();
    syncAfToolbar();

    const period = document.getElementById('afPeriod');
    if (period) period.addEventListener('change', function() {
      afFilters.period = period.value;
      saveAfFilters(); syncAfToolbar(); loadAnalyticsPro();
    });
    ['afFrom', 'afTo'].forEach(function(id) {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', function() {
        afFilters[id === 'afFrom' ? 'from' : 'to'] = el.value;
        saveAfFilters(); renderAfChips(); loadAnalyticsPro();
      });
    });
    [['afCaseType', 'case_type'], ['afStatus', 'status'], ['afAssignee', 'assignee'], ['afCarrier', 'carrier']].forEach(function(pair) {
      const el = document.getElementById(pair[0]);
      if (el) el.addEventListener('change', function() {
        afFilters[pair[1]] = el.value;
        saveAfFilters(); syncAfToolbar(); loadAnalyticsPro();
      });
    });
    const toggle = document.getElementById('afFiltersToggle');
    const panel = document.getElementById('afFiltersPanel');
    if (toggle && panel) {
      toggle.addEventListener('click', function() {
        const open = panel.hidden;
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }
    const reset = document.getElementById('afResetFilters');
    if (reset) reset.addEventListener('click', function() {
      afFilters = Object.assign({}, AF_DEFAULTS);
      saveAfFilters(); syncAfToolbar(); loadAnalyticsPro();
    });
  }

  // ---- Practice Insights view tabs (Overview / Operations / Trends) ------
  // Panels are pure show/hide over the single shared analytics payload; the
  // selected view persists via ?ap_view= (shareable/back-forward) with a
  // localStorage fallback. Charts in a newly revealed panel are resized
  // explicitly because Chart.js cannot measure hidden containers.
  const AP_VIEW_KEY = 'ap_active_view';
  const AP_VIEWS = ['overview', 'operations', 'trends'];

  function getApViewFromUrl() {
    try {
      const v = new URLSearchParams(window.location.search).get('ap_view');
      if (AP_VIEWS.includes(v)) return v;
    } catch (e) {}
    return null;
  }

  function activateApView(view, pushHistory) {
    if (!AP_VIEWS.includes(view)) view = 'overview';
    document.querySelectorAll('#apViewTabs .ap-view-tab').forEach(function(tab) {
      const on = tab.dataset.apView === view;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    AP_VIEWS.forEach(function(v) {
      const panel = document.getElementById('apView' + v.charAt(0).toUpperCase() + v.slice(1));
      if (panel) panel.hidden = v !== view;
    });
    try { localStorage.setItem(AP_VIEW_KEY, view); } catch (e) {}
    if (pushHistory && window.history && window.history.pushState) {
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('ap_view', view);
        window.history.pushState(null, '', url.toString());
      } catch (e) {}
    }
    // Let the panel become visible, then re-measure charts inside it.
    setTimeout(function() {
      if (typeof resizeCharts === 'function') resizeCharts();
      document.dispatchEvent(new CustomEvent('insightsVisible'));
    }, 60);
  }

  function initApViewTabs() {
    const tabs = document.querySelectorAll('#apViewTabs .ap-view-tab');
    if (!tabs.length) return;
    let initial = getApViewFromUrl();
    if (!initial) {
      try { initial = localStorage.getItem(AP_VIEW_KEY); } catch (e) {}
    }
    activateApView(AP_VIEWS.includes(initial) ? initial : 'overview', false);

    tabs.forEach(function(tab, idx) {
      tab.addEventListener('click', function() {
        activateApView(tab.dataset.apView, true);
      });
      tab.addEventListener('keydown', function(e) {
        let next = -1;
        if (e.key === 'ArrowRight') next = (idx + 1) % tabs.length;
        else if (e.key === 'ArrowLeft') next = (idx - 1 + tabs.length) % tabs.length;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = tabs.length - 1;
        if (next >= 0) {
          e.preventDefault();
          tabs[next].focus();
          activateApView(tabs[next].dataset.apView, true);
        }
      });
    });

    window.addEventListener('popstate', function() {
      const v = getApViewFromUrl();
      if (v) activateApView(v, false);
    });
  }

  function loadAnalyticsPro() {
    // Plan entitlement is emitted by main.php; anything other than a strict
    // true (including an evaluation failure) must not fetch protected data.
    if (window.userHasControlAccess !== true) {
      setAnalyticsLoading(false);
      return;
    }

    setAnalyticsLoading(true);
    setAnalyticsError('');

    const teamPeriod = document.getElementById('apTeamPeriod')?.value || '12';
    const teamFilter = document.getElementById('apTeamFilter')?.value || 'both';
    const volumePeriod = document.getElementById('apVolumePeriod')?.value || '12';
    const statusPeriod = document.getElementById('apStatusPeriod')?.value || 'active';
    const typePeriod = document.getElementById('apTypePeriod')?.value || 'active';
    const durationPeriod = document.getElementById('apDurationPeriod')?.value || 'active';
    const creatorPeriod = document.getElementById('apCreatorPeriod')?.value || 'all';

    const apiUrl = `api/get-analytics.php?team_period=${teamPeriod}&team_filter=${teamFilter}&volume_period=${volumePeriod}&status_period=${statusPeriod}&type_period=${typePeriod}&duration_period=${durationPeriod}&creator_period=${creatorPeriod}${buildAfQuery()}`;
    const reqSeq = ++afRequestSeq;

    // A pending flag set by activateInsightsSubview() marks this request as a
    // screen visit; it is cleared only when the screen renders successfully.
    const headers = {};
    if (window.__insightsVisitPending && window.__insightsVisitPending.practice) {
      headers['X-Insights-Visit'] = '1';
    }

    fetch(apiUrl, {
      credentials: 'same-origin',
      headers: headers
    })
      .then(response => {
        return response.json().then(data => {
          if (!response.ok) {
            var err = new Error('HTTP ' + response.status);
            err.serverMessage = data && data.message;
            err.errorCode = data && data.error_code;
            throw err;
          }
          return data;
        });
      })
      .then(data => {
        // Drop stale responses: a newer filter selection superseded this one.
        if (reqSeq !== afRequestSeq) return;
        setAnalyticsLoading(false);
        if (data && data.success) {
          if (window.__insightsVisitPending) {
            window.__insightsVisitPending.practice = false;
          }
          const payload = data.data || {};
          populateAfFacets((payload.charts || {}).facets);
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
        if (reqSeq !== afRequestSeq) return;
        setAnalyticsLoading(false);
        // A mid-session entitlement loss surfaces the server's message
        // (e.g. upgrade_required) rather than a generic failure.
        var catchMsg = (error && error.errorCode === 'upgrade_required' && error.serverMessage)
          ? error.serverMessage
          : (t('insights.error.analytics_data') || 'Unable to load analytics data. Please try again.');
        setAnalyticsError(catchMsg);
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

    // Operational KPI summary + Case Health (replaces the old metric cards
    // and Case Flow Status grid - same underlying metrics).
    renderKpiSummary(metrics, insights.caseFlow || {});
    bindKpiNavigation();
    renderAttentionQueue(insights.attentionQueue || {});

    // Update trends insights. growthRate is null when no prior-year
    // baseline exists - render N/A, never a misleading 0%.
    const trends = insights.trends || {};
    updateElement('apPeakMonth', trends.peakMonth ? I18n.formatMonth(trends.peakMonth, 'long') : '-');
    updateElement('apGrowthRate', (trends.growthRate === null || trends.growthRate === undefined)
      ? t('common.not_applicable')
      : trends.growthRate + '%');
    updateElement('apNextPeak', trends.nextPeak ? I18n.formatMonth(trends.nextPeak, 'long') : '-');

    // Update lifecycle metrics. Null averages mean no qualifying completed
    // cases - show N/A with an explanatory tooltip, not "0 days".
    const lifecycle = charts.lifecycle || {};
    const lifecycleSample = lifecycle.delivered_cases || 0;
    const hasLifecycle = lifecycleSample > 0 && lifecycle.avg_total_days !== null && lifecycle.avg_total_days !== undefined;
    [['apAvgLifecycle', lifecycle.avg_total_days],
     ['apFastestCase', lifecycle.min_total_days],
     ['apSlowestCase', lifecycle.max_total_days]].forEach(function(pair) {
      var el = document.getElementById(pair[0]);
      if (!el) return;
      if (hasLifecycle) {
        el.textContent = formatDurationDays(pair[1]);
        el.title = I18n.pluralize(lifecycleSample, 'insights.metrics.completed_cases_base');
      } else {
        el.textContent = t('common.not_applicable');
        el.title = t('insights.metrics.no_completed_cases');
      }
    });

    // Destroy existing charts before creating new ones
    destroyAllCharts();

    // Render charts
    renderStatusChart(charts.statusDistribution || []);
    renderTypeChart(charts.caseTypeBreakdown || []);
    renderVolumeChart(charts.monthlyVolume || []);
    apLastTeamData = charts.teamPerformance || [];
    if (apTeamView === 'activity') {
      renderTeamChart(apLastTeamData);
    }
    renderWorkloadTable(charts.workload || []);
    renderLabWorkload(charts.labWorkload || []);
    renderBottlenecks(charts.bottlenecks || []);
    renderBacklogChart(charts.backlog || {});
    renderThroughputSummary(charts.throughput || {});
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

  /* =========================================================
     Attention Required queue
     Items arrive pre-classified from api/get-analytics.php via the
     shared api/case-attention.php rules (same thresholds and highlight
     flags as the Case Health categories). Each item appears once;
     `reasons` drives chip filtering, `tier` preserves server priority.
     ========================================================= */
  var attentionState = { items: [], counts: {}, filter: 'all', sort: 'priority' };
  var attentionBound = false;
  var ATTENTION_PREVIEW_COUNT = 5;

  function attentionChipCountKey(filter) {
    return { all: 'all', overdue: 'overdue', appt_risk: 'appt_risk', due_soon: 'due_soon', awaiting_ack: 'awaiting_ack' }[filter] || 'all';
  }

  function attentionFilterMatch(item, filter) {
    if (filter === 'all') return true;
    return (item.reasons || []).indexOf(filter) !== -1;
  }

  function attentionAssignedDisplay(value) {
    if (typeof window.caseFilterSort !== 'undefined' && window.caseFilterSort && typeof window.caseFilterSort.assignedDisplay === 'function') {
      return window.caseFilterSort.assignedDisplay(value);
    }
    return Array.isArray(value) ? value.join(', ') : String(value || '');
  }

  function attentionStatusLabel(status) {
    if (typeof window.getStageLabel === 'function') return window.getStageLabel(status);
    return status || '';
  }

  function attentionCaseTypeLabel(caseType) {
    if (caseType && typeof window.getCaseTypeDisplayLabel === 'function') {
      return window.getCaseTypeDisplayLabel(caseType);
    }
    return caseType || '';
  }

  /**
   * Build ordered reason entries for an item. The primary reason's
   * category is shown first; additional qualifying reasons follow as
   * secondary context. Returns [{cat, text}].
   */
  function attentionReasonEntries(item) {
    var entries = [];
    var has = function (cat) { return (item.reasons || []).indexOf(cat) !== -1; };
    var daysUntilDue = item.daysUntilDue;
    var daysUntilAppt = item.daysUntilAppt;

    if (has('late') && daysUntilDue !== null && daysUntilDue !== undefined) {
      var overdueDays = Math.max(0, -daysUntilDue);
      entries.push({ cat: 'late', text: I18n.pluralize(overdueDays, 'insights.attention.reason_late') });
    }
    if (has('appt_risk') && daysUntilAppt !== null && daysUntilAppt !== undefined) {
      var apptText;
      if (daysUntilAppt < 0) {
        apptText = I18n.pluralize(-daysUntilAppt, 'insights.attention.reason_appt_missed');
      } else if (daysUntilAppt === 0) {
        apptText = t('insights.attention.reason_appt_today');
      } else {
        apptText = I18n.pluralize(daysUntilAppt, 'insights.attention.reason_appt_in');
      }
      entries.push({ cat: 'appt_risk', text: apptText });
    }
    if (has('due_soon') && daysUntilDue !== null && daysUntilDue !== undefined) {
      var dueText = daysUntilDue === 0
        ? t('insights.attention.reason_due_today')
        : I18n.pluralize(daysUntilDue, 'insights.attention.reason_due_in');
      entries.push({ cat: 'due_soon', text: dueText });
    }
    if (has('awaiting_ack')) {
      entries.push({ cat: 'awaiting_ack', text: t('insights.attention.reason_awaiting_ack') });
    }

    // Primary reason first. 'late_appt' keeps late then appt order,
    // which is already the push order above.
    var primaryCat = {
      late_appt: 'late', appt_risk: 'appt_risk', late: 'late',
      due_soon: 'due_soon', awaiting_ack: 'awaiting_ack'
    }[item.primaryReason];
    if (primaryCat) {
      entries.sort(function (a, b) {
        if (a.cat === primaryCat) return -1;
        if (b.cat === primaryCat) return 1;
        return 0;
      });
    }
    return entries;
  }

  function attentionSortValue(item, sort) {
    switch (sort) {
      case 'due_date':
        return item.daysUntilDue === null || item.daysUntilDue === undefined ? Infinity : item.daysUntilDue;
      case 'days_overdue': {
        var overdue = item.daysUntilDue === null || item.daysUntilDue === undefined ? -Infinity : Math.max(0, -item.daysUntilDue);
        return -overdue; // most overdue first
      }
      case 'status':
        return attentionStatusLabel(item.status).toLowerCase();
      case 'assigned': {
        var who = attentionAssignedDisplay(item.assignedTo).toLowerCase();
        return who || '\uffff'; // unassigned sorts last
      }
      default:
        return item.tier || 99;
    }
  }

  function attentionSortedItems() {
    var items = attentionState.items.filter(function (item) {
      return attentionFilterMatch(item, attentionState.filter);
    });
    if (attentionState.sort === 'priority') {
      return items; // server order is already priority-sorted
    }
    return items.slice().sort(function (a, b) {
      var av = attentionSortValue(a, attentionState.sort);
      var bv = attentionSortValue(b, attentionState.sort);
      if (av === bv) return (a.tier || 99) - (b.tier || 99);
      return av < bv ? -1 : 1;
    });
  }

  function attentionOpenCase(item) {
    if (typeof window.openCaseById === 'function') {
      window.openCaseById(item.id);
    }
  }

  function renderAttentionRows() {
    var body = document.getElementById('apAttentionBody');
    var wrap = document.getElementById('apAttentionTableWrap');
    var empty = document.getElementById('apAttentionEmpty');
    var footer = document.getElementById('apAttentionFooter');
    var showing = document.getElementById('apAttentionShowing');
    var viewAll = document.getElementById('apAttentionViewAll');
    if (!body) return;

    var sorted = attentionSortedItems();
    var preview = sorted.slice(0, ATTENTION_PREVIEW_COUNT);
    var totalForFilter = attentionState.counts[attentionChipCountKey(attentionState.filter)] || 0;

    if (preview.length === 0) {
      body.innerHTML = '';
      if (wrap) wrap.hidden = true;
      if (empty) {
        empty.hidden = false;
        var emptyTitle = empty.querySelector('.ap-attention-empty-title');
        var emptySub = empty.querySelector('.ap-attention-empty-sub');
        var hasAny = (attentionState.counts.all || 0) > 0;
        if (emptyTitle) emptyTitle.textContent = t(hasAny ? 'insights.attention.empty_filter_title' : 'insights.attention.empty_title');
        if (emptySub) emptySub.textContent = t(hasAny ? 'insights.attention.empty_filter_subtitle' : 'insights.attention.empty_subtitle');
      }
      if (footer) footer.hidden = true;
      return;
    }

    if (wrap) wrap.hidden = false;
    if (empty) empty.hidden = true;

    body.innerHTML = preview.map(function (item) {
      var name = ((item.patientFirstName || '') + ' ' + (item.patientLastName || '')).trim();
      var typeLabel = attentionCaseTypeLabel(item.caseType);
      var assigned = attentionAssignedDisplay(item.assignedTo);
      var reasons = attentionReasonEntries(item);
      var primary = reasons[0];
      var secondary = reasons.slice(1);
      var shortId = String(item.id || '').slice(0, 8);
      var dateLabelDue = t('insights.attention.date_due');
      var dateLabelAppt = t('insights.attention.date_appt');

      var reasonHtml = primary
        ? '<span class="ap-attn-reason ap-attn-reason--' + escapeHtml(primary.cat) + '">' + escapeHtml(primary.text) + '</span>' +
          secondary.map(function (r) {
            return '<span class="ap-attn-reason-secondary">' + escapeHtml(r.text) + '</span>';
          }).join('')
        : '<span class="ap-attn-reason-secondary">' + escapeHtml(t('insights.attention.reason_generic')) + '</span>';

      var dateHtml = '';
      if (item.dueDate) {
        dateHtml += '<span class="ap-attn-date-line"><span class="ap-attn-date-label">' + escapeHtml(dateLabelDue) + '</span> ' + escapeHtml(I18n.formatDate(item.dueDate)) + '</span>';
      }
      if (item.patientAppointmentDate) {
        dateHtml += '<span class="ap-attn-date-line"><span class="ap-attn-date-label">' + escapeHtml(dateLabelAppt) + '</span> ' + escapeHtml(I18n.formatDate(item.patientAppointmentDate)) + '</span>';
      }
      if (!dateHtml) dateHtml = '<span class="ap-attn-muted">\u2014</span>';

      return '<tr class="ap-attn-row" data-attn-case="' + escapeHtml(item.id) + '">' +
        '<td class="ap-attn-cell-case" data-label="' + escapeHtml(t('insights.attention.col_case')) + '">' +
          '<button type="button" class="ap-attn-case-link" data-attn-open="' + escapeHtml(item.id) + '" title="' + escapeHtml(String(item.id)) + '">#' + escapeHtml(shortId) + '</button>' +
        '</td>' +
        '<td class="ap-attn-cell-patient" data-label="' + escapeHtml(t('insights.attention.col_patient')) + '">' +
          '<span class="ap-attn-patient">' + escapeHtml(name || '\u2014') + '</span>' +
          (typeLabel ? '<span class="ap-attn-case-type">' + escapeHtml(typeLabel) + '</span>' : '') +
        '</td>' +
        '<td class="ap-attn-cell-risk" data-label="' + escapeHtml(t('insights.attention.col_risk')) + '">' + reasonHtml + '</td>' +
        '<td class="ap-attn-cell-status" data-label="' + escapeHtml(t('insights.attention.col_status')) + '">' + escapeHtml(attentionStatusLabel(item.status)) + '</td>' +
        '<td class="ap-attn-cell-assigned" data-label="' + escapeHtml(t('insights.attention.col_assigned')) + '">' +
          '<span class="' + (assigned ? 'ap-attn-assigned' : 'ap-attn-muted') + '">' + escapeHtml(assigned || t('insights.attention.unassigned')) + '</span>' +
        '</td>' +
        '<td class="ap-attn-cell-date" data-label="' + escapeHtml(t('insights.attention.col_date')) + '">' + dateHtml + '</td>' +
        '<td class="ap-attn-cell-action">' +
          '<button type="button" class="ap-btn ap-btn-secondary ap-attn-view" data-attn-open="' + escapeHtml(item.id) + '">' + escapeHtml(t('insights.attention.view_case')) + '</button>' +
        '</td>' +
      '</tr>';
    }).join('');

    if (footer) {
      footer.hidden = false;
      if (showing) {
        showing.textContent = t('insights.attention.showing', { shown: preview.length, total: totalForFilter });
      }
      if (viewAll) {
        viewAll.hidden = !(attentionState.counts.all > ATTENTION_PREVIEW_COUNT);
      }
    }
  }

  function renderAttentionQueue(queue) {
    attentionState.items = Array.isArray(queue.items) ? queue.items : [];
    attentionState.counts = queue.counts || {};
    attentionState.counts.all = queue.total !== undefined ? queue.total : attentionState.items.length;

    // Chip counts reflect the full qualifying set from the server.
    var map = { all: 'apAttnCountAll', overdue: 'apAttnCountOverdue', appt_risk: 'apAttnCountApptRisk', due_soon: 'apAttnCountDueSoon', awaiting_ack: 'apAttnCountAck' };
    Object.keys(map).forEach(function (filter) {
      var el = document.getElementById(map[filter]);
      if (el) el.textContent = attentionState.counts[attentionChipCountKey(filter)] || 0;
    });

    renderAttentionRows();
    bindAttentionQueue();
  }

  function bindAttentionQueue() {
    if (attentionBound) return;
    attentionBound = true;

    var filtersEl = document.getElementById('apAttentionFilters');
    if (filtersEl) {
      filtersEl.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-attention-filter]');
        if (!chip) return;
        attentionState.filter = chip.getAttribute('data-attention-filter') || 'all';
        filtersEl.querySelectorAll('.ap-attention-chip').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
        renderAttentionRows();
      });
    }

    var sortEl = document.getElementById('apAttentionSort');
    if (sortEl) {
      sortEl.addEventListener('change', function () {
        attentionState.sort = sortEl.value || 'priority';
        renderAttentionRows();
      });
    }

    var body = document.getElementById('apAttentionBody');
    if (body) {
      body.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-attn-open]');
        if (!opener) return;
        var id = opener.getAttribute('data-attn-open');
        var item = attentionState.items.find(function (i) { return String(i.id) === String(id); });
        if (item) attentionOpenCase(item);
      });
    }

    var viewAll = document.getElementById('apAttentionViewAll');
    if (viewAll) {
      viewAll.addEventListener('click', function () {
        // Navigate to the board filter matching the active chip so the
        // "View All" destination reconciles with the filtered preview.
        var targetByFilter = {
          all: 'attention', overdue: 'late', appt_risk: 'appt_risk',
          due_soon: 'due_soon', awaiting_ack: 'awaiting_ack'
        };
        navigateToFilteredCases(targetByFilter[attentionState.filter] || 'attention');
      });
    }
  }

  // Helper to update element text
  function updateElement(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  /**
   * Render the Operational KPI summary and Case Health stacked bar.
   * All values come from the existing get-analytics payload - the same
   * mutually-exclusive caseFlow classification the Kanban board uses.
   */
  function renderKpiSummary(metrics, caseFlow) {
    var totalActive = metrics.totalActiveCases || 0;
    var late = caseFlow.late || 0;
    var apptRisk = caseFlow.appointmentRisk || 0;
    var onTrack = caseFlow.onTrack || 0;
    var dueSoon = caseFlow.dueSoon || 0;

    updateElement('apKpiActiveValue', totalActive);
    updateElement('apKpiOverdueValue', late);
    updateElement('apKpiApptRiskValue', apptRisk);
    updateElement('apKpiDeliveredValue', metrics.deliveredThisMonth || 0);

    // Overdue share of active workload - never divide by zero; show an
    // em dash when there is no active workload to contextualize.
    var overduePct = totalActive > 0 ? Math.round((late / totalActive) * 1000) / 10 : null;
    var overduePctText = overduePct === null ? '' : String(overduePct);
    var overdueCard = document.getElementById('apKpiOverdue');
    var overdueSub = document.getElementById('apKpiOverdueSub');
    if (overdueCard) {
      overdueCard.classList.toggle('ap-kpi-card--alert-active', late > 0);
    }
    if (overdueSub) {
      overdueSub.textContent = overduePct === null
        ? t('insights.kpi.overdue_subtitle')
        : t('insights.kpi.overdue_pct_of_active', { pct: overduePctText });
    }

    // Case Health stacked bar - widths are percentages of ACTIVE cases.
    var flowTotal = onTrack + dueSoon + apptRisk + late;
    var healthCard = document.getElementById('apHealthCard');
    if (healthCard) {
      healthCard.classList.toggle('ap-health-card--empty', flowTotal === 0);
    }
    var pct = function (n) { return flowTotal > 0 ? (n / flowTotal) * 100 : 0; };
    var segs = {
      apHealthOnTrack: pct(onTrack),
      apHealthDueSoon: pct(dueSoon),
      apHealthApptRisk: pct(apptRisk),
      apHealthLate: pct(late)
    };
    Object.keys(segs).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.style.width = segs[id] + '%';
    });
    updateElement('apHealthOnTrackCount', onTrack);
    updateElement('apHealthDueSoonCount', dueSoon);
    updateElement('apHealthApptRiskCount', apptRisk);
    updateElement('apHealthLateCount', late);

    var healthPct = document.getElementById('apHealthOverduePct');
    if (healthPct) {
      healthPct.textContent = overduePct === null
        ? ''
        : t('insights.kpi.overdue_pct_of_active', { pct: overduePctText });
    }
  }

  var kpiNavBound = false;

  /**
   * Navigate from a KPI card / Case Health segment to the Cases tab with
   * the matching board filter applied. Reuses the existing checkbox
   * filters (late_only / appt_risk_only / delivered_this_month) so the
   * result is identical to using the filter bar manually.
   */
  function navigateToFilteredCases(target) {
    var casesTab = document.querySelector('.main-tab[data-tab="cases"]');
    if (casesTab) casesTab.click();

    var setCheck = function (id, checked) {
      var el = document.getElementById(id);
      if (el) el.checked = checked;
      return el || null;
    };
    // Start from a clean risk-filter slate, then apply the requested one.
    ['filterLateCases', 'filterDueSoon', 'filterApptRisk', 'filterAtRisk',
     'filterAwaitingAck', 'filterNeedsInfo', 'filterDeliveredMonth',
     'filterAttentionOnly'].forEach(function (id) {
      setCheck(id, false);
    });

    var setSelectFilter = function (id, value, optionLabel) {
      var el = document.getElementById(id);
      if (!el) return null;
      if (![].some.call(el.options, function (o) { return o.value === value; })) {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = optionLabel || value;
        el.appendChild(opt);
      }
      el.value = value;
      return el;
    };

    var targetEl = null;
    if (target === 'late') targetEl = setCheck('filterLateCases', true);
    else if (target === 'appt_risk') targetEl = setCheck('filterApptRisk', true);
    else if (target === 'delivered_month') targetEl = setCheck('filterDeliveredMonth', true);
    else if (target === 'attention') targetEl = setCheck('filterAttentionOnly', true);
    else if (target === 'due_soon') targetEl = setCheck('filterDueSoon', true);
    else if (target === 'awaiting_ack') targetEl = setCheck('filterAwaitingAck', true);
    else if (target === 'unassigned') {
      // Select-based target: set the assignee filter to the synthetic
      // __unassigned value (handled server-side in list-cases.php).
      targetEl = setSelectFilter('filterAssignedTo', '__unassigned', t('insights.recs.unassigned_option'));
    } else if (target === 'unclassified') {
      targetEl = setSelectFilter('filterCaseType', '__unclassified', t('insights.recs.unclassified_option'));
    }
    // 'cases' (Active Cases) intentionally leaves every filter cleared.

    // Dispatch change on the TARGET element only: its own listeners apply
    // the filters, and case-filter-sort's delegated listener persists the
    // full snapshot (including the cleared boxes). Dispatching on any
    // other risk checkbox would fire clearDeliveredMonthForRiskFilters
    // and undo a delivered_month/attention_only selection. For 'cases'
    // any already-cleared risk checkbox is a safe change source.
    var changeSource = targetEl || document.getElementById('filterLateCases');
    if (changeSource) {
      changeSource.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (typeof window.applyFilters === 'function') {
      window.applyFilters();
    }
  }

  function bindKpiNavigation() {
    if (kpiNavBound) return;
    kpiNavBound = true;

    var targetFor = function (el) {
      return el && el.dataset ? (el.dataset.kpiTarget || el.dataset.healthTarget || '') : '';
    };
    var activate = function (el) {
      var target = targetFor(el);
      if (target) navigateToFilteredCases(target);
    };

    document.querySelectorAll('#apKpiGrid [data-kpi-target]').forEach(function (el) {
      el.addEventListener('click', function () { activate(el); });
    });
    document.querySelectorAll('#apHealthBar [data-health-target], .ap-health-item--link[data-health-target]').forEach(function (el) {
      el.addEventListener('click', function () { activate(el); });
      // Native <button> segments already activate on Enter/Space - only
      // the span-based legend items need a manual key handler.
      if (el.tagName !== 'BUTTON') {
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(el); }
        });
      }
    });
  }

  // Status Distribution Chart (horizontal bars in workflow stage order).
  // One bar per status, count at bar end, click navigates to the board
  // filtered to that status via the hidden filterStatus select.
  function renderStatusChart(data) {
    const canvas = document.getElementById('apStatusChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    const statusData = {};
    (data || []).forEach(item => {
      const status = item.status || 'Unknown';
      statusData[status] = (statusData[status] || 0) + Number(item.count || 0);
    });

    // Order by the practice's configured workflow sequence; unknown statuses
    // (e.g. 'Unknown') sort last in their original order.
    const stageOrder = Array.isArray(window.workflowStageOrder) ? window.workflowStageOrder : [];
    const orderIndex = {};
    stageOrder.forEach(function(s, i) { orderIndex[s] = i; });
    const statuses = Object.keys(statusData).sort(function(a, b) {
      const ia = orderIndex.hasOwnProperty(a) ? orderIndex[a] : 9999;
      const ib = orderIndex.hasOwnProperty(b) ? orderIndex[b] : 9999;
      return ia - ib;
    });

    const labels = statuses.map(function(status) {
      return (typeof getStageLabel === 'function') ? (getStageLabel(status) || status) : status;
    });
    const values = statuses.map(function(s) { return statusData[s]; });

    if (statuses.length === 0) {
      showChartEmptyMessage(canvas, t('insights.charts.no_data'));
      setChartAriaLabel(canvas, t('insights.charts.status_distribution'), [t('insights.charts.no_data')], [0]);
      return;
    }
    hideChartEmptyMessage(canvas);
    sizeHorizontalBarChart(canvas.closest('.ap-chart-container'), statuses.length);

    apCharts['apStatusChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors.primary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: Object.assign(horizontalBarOptions(), {
        onClick: function(evt, elements) {
          if (!elements || !elements.length) return;
          const idx = elements[0].index;
          if (statuses[idx]) navigateToFilteredStatus(statuses[idx]);
        },
        onHover: function(evt, elements) {
          const target = evt.native && evt.native.target;
          if (target) target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
        }
      }),
      plugins: [apBarEndValuePlugin]
    });
    setChartAriaLabel(canvas, t('insights.charts.status_distribution'), labels, values, function(v) { return t('insights.charts.cases_count', { count: v }); });
  }

  // Navigate to the Case Board filtered to a specific workflow status.
  // Uses the hidden #filterStatus select (persisted like the other filters).
  function navigateToFilteredStatus(status) {
    var casesTab = document.querySelector('.main-tab[data-tab="cases"]');
    if (casesTab) casesTab.click();
    var sel = document.getElementById('filterStatus');
    if (!sel) return;
    var label = (typeof getStageLabel === 'function') ? (getStageLabel(status) || status) : status;
    if (![].some.call(sel.options, function(o) { return o.value === status; })) {
      var opt = document.createElement('option');
      opt.value = status;
      opt.textContent = label;
      sel.appendChild(opt);
    }
    sel.value = status;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  // Case Type Chart (horizontal bar — top 7 + Other, expandable, click nav)
  const AP_TYPE_PREVIEW = 7;
  let apTypeChartExpanded = false;

  function renderTypeChart(data) {
    const canvas = document.getElementById('apTypeChart');
    if (!canvas) return;
    const container = canvas.closest('.ap-chart-container');
    const scrollWrap = canvas.closest('.ap-chart-scroll');
    const emptyEl = document.getElementById('apTypeChartEmpty');

    const typeData = {};
    (data || []).forEach(item => {
      const type = item.case_type || 'Unspecified';
      typeData[type] = (typeData[type] || 0) + Number(item.count || 0);
    });

    const entries = sortBreakdownEntries(typeData);
    const totalTypes = entries.length;

    // Collapsed view: top N types + an "Other" rollup. Expanded: every type.
    let viewEntries = entries;
    let hasOther = false;
    if (!apTypeChartExpanded && totalTypes > AP_TYPE_PREVIEW) {
      hasOther = true;
      const top = entries.slice(0, AP_TYPE_PREVIEW);
      const restCount = entries.slice(AP_TYPE_PREVIEW).reduce(function(s, e) { return s + e[1]; }, 0);
      viewEntries = top.concat([[t('insights.charts.other_category'), restCount]]);
    }

    const labels = viewEntries.map(function(e) { return e[0]; });
    const values = viewEntries.map(function(e) { return e[1]; });
    // Navigation target per visible row: raw case_type value, 'Other' and
    // 'Unspecified' map to the synthetic __unclassified board filter.
    const navValues = viewEntries.map(function(e, i) {
      if (hasOther && i === viewEntries.length - 1) return null;
      return e[0] === 'Unspecified' ? '__unclassified' : e[0];
    });

    if (labels.length === 0) {
      setChartEmpty(canvas, emptyEl, true);
      if (container) container.style.height = 'auto';
      hideChartEmptyMessage(canvas);
      var tgl0 = ensureChartToggle(canvas, 'type', '');
      if (tgl0) tgl0.style.display = 'none';
      setChartAriaLabel(canvas, t('insights.charts.case_type_breakdown'), [t('insights.charts.no_data')], [0]);
      return;
    }
    setChartEmpty(canvas, emptyEl, false);
    hideChartEmptyMessage(canvas);
    if (scrollWrap) scrollWrap.style.maxHeight = 'none';
    sizeHorizontalBarChart(container, labels.length);

    // Show-all / show-less control when there are more types than the preview.
    var toggleBtn = ensureChartToggle(canvas, 'type', '');
    if (toggleBtn) {
      if (totalTypes > AP_TYPE_PREVIEW) {
        toggleBtn.style.display = '';
        toggleBtn.textContent = apTypeChartExpanded
          ? t('insights.charts.show_less_types')
          : I18n.pluralize(totalTypes, 'insights.charts.show_all_types');
        toggleBtn.onclick = function() {
          apTypeChartExpanded = !apTypeChartExpanded;
          renderTypeChart(typeDataToArray(typeData));
        };
      } else {
        toggleBtn.style.display = 'none';
        toggleBtn.onclick = null;
      }
    }

    const ctx = canvas.getContext('2d');
    apCharts['apTypeChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors.primary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: Object.assign(horizontalBarOptions(), apChartClickOptions(function(idx) {
        const v = navValues[idx];
        return v ? function() { navigateToFilteredCasesParam('case_type', v); } : null;
      })),
      plugins: [apBarEndValuePlugin]
    });
    setChartAriaLabel(canvas, t('insights.charts.case_type_breakdown'), labels, values, function(v) { return I18n.pluralize(v, 'insights.creators.case'); });
  }

  function typeDataToArray(typeData) {
    return Object.keys(typeData).map(function(k) { return { case_type: k, count: typeData[k] }; });
  }

  // Navigate to the Case Board with an arbitrary filter param value by
  // driving the matching hidden/visible select or checkbox where one exists.
  // Currently supports case_type (select#filterCaseType) and assigned_to.
  function navigateToFilteredCasesParam(param, value) {
    var casesTab = document.querySelector('.main-tab[data-tab="cases"]');
    if (casesTab) casesTab.click();
    var map = {
      case_type: { id: 'filterCaseType' },
      assigned_to: { id: 'filterAssignedTo' },
      carrier: { id: 'filterCarrier' }
    };
    var cfg = map[param];
    if (!cfg) return;
    var sel = document.getElementById(cfg.id);
    if (!sel) return;
    var label = value;
    if (value === '__unclassified') label = t('insights.recs.unclassified_option');
    if (value === '__unassigned') label = t('insights.recs.unassigned_option');
    if (![].some.call(sel.options, function(o) { return o.value === value; })) {
      var opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      sel.appendChild(opt);
    }
    sel.value = value;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
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

    if (labels.length === 0) {
      showChartEmptyMessage(ctx.canvas, t('insights.charts.no_data'));
      setChartAriaLabel(ctx.canvas, t('insights.charts.monthly_case_volume'), [t('insights.charts.no_data')], [0]);
      return;
    }
    hideChartEmptyMessage(ctx.canvas);

    // Subtle backlog context: net cases created minus delivered (terminal)
    // across the visible period. Only shown when both series have activity
    // so the note never implies a trend from missing data.
    var netCreated = created.reduce(function(s, v) { return s + v; }, 0);
    var netDelivered = delivered.reduce(function(s, v) { return s + v; }, 0);
    var volumeNoteEl = document.getElementById('apVolumeNote');
    if (!volumeNoteEl) {
      volumeNoteEl = document.createElement('p');
      volumeNoteEl.className = 'ap-chart-note';
      volumeNoteEl.id = 'apVolumeNote';
      ctx.canvas.parentElement.appendChild(volumeNoteEl);
    }
    if (netCreated > 0 && netDelivered > 0) {
      var net = netCreated - netDelivered;
      volumeNoteEl.textContent = net === 0
        ? t('insights.charts.volume_net_balanced')
        : (net > 0
          ? I18n.pluralize(net, 'insights.charts.volume_net_growth')
          : I18n.pluralize(Math.abs(net), 'insights.charts.volume_net_decline'));
      volumeNoteEl.style.display = '';
    } else {
      volumeNoteEl.style.display = 'none';
    }

    apCharts['apVolumeChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: t('insights.charts.dataset_created'),
            data: created,
            borderColor: colors.primary,
            backgroundColor: 'rgba(30, 64, 175, 0.08)',
            fill: false,
            tension: 0.3,
            pointRadius: 3,
            pointHoverRadius: 5,
            borderWidth: 2
          },
          {
            label: t('insights.charts.dataset_delivered'),
            data: delivered,
            borderColor: colors.success,
            backgroundColor: 'rgba(16, 185, 129, 0.08)',
            fill: false,
            tension: 0.3,
            pointRadius: 3,
            pointHoverRadius: 5,
            borderWidth: 2
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
    setChartAriaLabel(ctx.canvas, t('insights.charts.monthly_case_volume'), labels, created, function(v) { return t('insights.charts.created_count', { count: v }); });
  }

  // Team Performance Chart (Horizontal Bar)
  // Shows display names when the assignee resolves to a practice user
  // (assignee_name from the API), falling back to the raw assigned_to value.
  // Top 8 by workload with an expandable remainder; click navigates to the
  // board filtered to that assignee.
  const AP_TEAM_PREVIEW = 8;
  let apTeamChartExpanded = false;

  function renderTeamChart(data) {
    const canvas = document.getElementById('apTeamChart');
    if (!canvas) return;
    const container = canvas.closest('.ap-chart-container');

    const teamData = {};
    const teamNav = {};
    (data || []).forEach(item => {
      const assignee = item.assignee || 'Unassigned';
      const name = (item.assignee_name || '').trim() || assignee;
      teamData[name] = (teamData[name] || 0) + Number(item.cases_count || 0);
      // Prefer the email for filtering when the display name differs.
      teamNav[name] = (name !== assignee && assignee !== 'Unassigned') ? assignee : (assignee === 'Unassigned' ? '__unassigned' : assignee);
    });

    const entries = sortBreakdownEntries(teamData);
    const totalMembers = entries.length;
    const viewEntries = (!apTeamChartExpanded && totalMembers > AP_TEAM_PREVIEW)
      ? entries.slice(0, AP_TEAM_PREVIEW)
      : entries;

    const labels = viewEntries.map(function(e) { return e[0]; });
    const values = viewEntries.map(function(e) { return e[1]; });
    const navValues = viewEntries.map(function(e) { return teamNav[e[0]] || null; });

    if (labels.length === 0) {
      showChartEmptyMessage(canvas, t('insights.charts.no_data'));
      setChartAriaLabel(canvas, t('insights.charts.team_workload'), [t('insights.charts.no_data')], [0]);
      return;
    }
    hideChartEmptyMessage(canvas);
    sizeHorizontalBarChart(container, labels.length);

    var toggleBtn = ensureChartToggle(canvas, 'team', '');
    if (toggleBtn) {
      if (totalMembers > AP_TEAM_PREVIEW) {
        toggleBtn.style.display = '';
        toggleBtn.textContent = apTeamChartExpanded
          ? t('insights.charts.show_less_team')
          : I18n.pluralize(totalMembers, 'insights.charts.show_all_team');
        toggleBtn.onclick = function() {
          apTeamChartExpanded = !apTeamChartExpanded;
          renderTeamChart(data);
        };
      } else {
        toggleBtn.style.display = 'none';
        toggleBtn.onclick = null;
      }
    }

    apCharts['apTeamChart'] = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: t('insights.charts.dataset_cases'),
          data: values,
          backgroundColor: colors.primary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: Object.assign({
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { right: 40 } },
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { precision: 0, font: { size: 11, family: "'Poppins', sans-serif" } }
          },
          y: {
            grid: { display: false },
            ticks: {
              autoSkip: false,
              font: { size: 11, family: "'Poppins', sans-serif" },
              callback: function(value) {
                const label = this.getLabelForValue(value);
                const width = this.chart ? this.chart.width : window.innerWidth;
                const max = width < 480 ? 16 : 30;
                return label.length > max ? label.slice(0, max - 1) + '…' : label;
              }
            }
          }
        }
      }, apChartClickOptions(function(idx) {
        const v = navValues[idx];
        return v ? function() { navigateToFilteredCasesParam('assigned_to', v); } : null;
      })),
      plugins: [apBarEndValuePlugin]
    });
    setChartAriaLabel(canvas, t('insights.charts.team_workload'), labels, values, function(v) { return I18n.pluralize(v, 'insights.creators.case'); });
  }

  // ---- Workload & Throughput (Operations tab) ----
  let apLastTeamData = [];
  let apWorkloadRows = [];
  let apWorkloadSort = { key: 'active', dir: -1 };
  let apTeamView = 'workload'; // 'workload' table | 'activity' chart

  function renderThroughputSummary(tp) {
    tp = tp || {};
    const set = function(id, val) {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    const num = function(v) { return (v === null || v === undefined) ? t('common.not_applicable') : String(v); };
    set('apTsCreated', num(tp.created));
    set('apTsDelivered', num(tp.delivered));
    if (tp.net === null || tp.net === undefined) {
      set('apTsNet', t('common.not_applicable'));
    } else {
      set('apTsNet', (tp.net > 0 ? '+' : '') + tp.net);
    }
    set('apTsAvgDelivery', (tp.avgDeliveryDays === null || tp.avgDeliveryDays === undefined)
      ? t('common.not_applicable')
      : formatDurationDays(tp.avgDeliveryDays));
    const wrap = document.getElementById('apThroughputSummary');
    if (wrap) wrap.style.display = (tp.created === null && tp.delivered === null) ? 'none' : '';
  }

  function workloadMemberLabel(row) {
    if (row.unassigned) return t('insights.recs.unassigned_option');
    return row.name || row.assignee || t('insights.recs.unassigned_option');
  }

  function renderWorkloadTable(rows) {
    apWorkloadRows = rows || [];
    const tbody = document.getElementById('apWorkloadBody');
    const empty = document.getElementById('apWorkloadEmpty');
    const table = document.getElementById('apWorkloadTable');
    if (!tbody) return;
    if (apWorkloadRows.length === 0) {
      tbody.innerHTML = '';
      if (table) table.style.display = 'none';
      if (empty) empty.hidden = false;
      return;
    }
    if (table) table.style.display = '';
    if (empty) empty.hidden = true;
    drawWorkloadRows();
  }

  function drawWorkloadRows() {
    const tbody = document.getElementById('apWorkloadBody');
    if (!tbody) return;
    const sorted = apWorkloadRows.slice().sort(function(a, b) {
      const k = apWorkloadSort.key, d = apWorkloadSort.dir;
      const av = a[k], bv = b[k];
      if (av === null || av === undefined) return 1;
      if (bv === null || bv === undefined) return -1;
      if (typeof av === 'string') return d * av.localeCompare(bv);
      return d * (av - bv);
    });
    tbody.innerHTML = sorted.map(function(r) {
      const nav = r.unassigned ? '__unassigned' : r.assignee;
      const name = escapeHtml(workloadMemberLabel(r));
      const age = (r.avgStageAgeDays === null || r.avgStageAgeDays === undefined)
        ? t('common.not_applicable') : formatDurationDays(r.avgStageAgeDays);
      const sharePct = (r.share !== null && r.share !== undefined) ? Math.round(r.share * 100) : null;
      return '<tr>' +
        '<td><button type="button" class="ap-wl-link" data-nav="' + escapeHtml(nav || '') + '">' + name + '</button></td>' +
        '<td class="ap-num">' + r.active + '</td>' +
        '<td class="ap-num' + (r.overdue > 0 ? ' ap-wl-risk' : '') + '">' + r.overdue + '</td>' +
        '<td class="ap-num' + (r.apptRisk > 0 ? ' ap-wl-warn' : '') + '">' + r.apptRisk + '</td>' +
        '<td class="ap-num">' + escapeHtml(age) + '</td>' +
        '<td class="ap-num">' + (sharePct === null ? '—' :
          '<span class="ap-share"><i style="width:' + sharePct + '%"></i></span>' + sharePct + '%') + '</td>' +
      '</tr>';
    }).join('');
    tbody.querySelectorAll('.ap-wl-link').forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (btn.dataset.nav) navigateToFilteredCasesParam('assigned_to', btn.dataset.nav);
      });
    });
    document.querySelectorAll('#apWorkloadTable .ap-th-sort').forEach(function(th) {
      th.classList.toggle('sorted', th.dataset.sort === apWorkloadSort.key);
      th.classList.toggle('sorted-desc', th.dataset.sort === apWorkloadSort.key && apWorkloadSort.dir === -1);
    });
  }

  function renderLabWorkload(rows) {
    const section = document.getElementById('labWorkloadSection');
    const tbody = document.getElementById('apLabWorkloadBody');
    if (!section || !tbody) return;
    rows = rows || [];
    if (rows.length === 0) { section.hidden = true; return; }
    section.hidden = false;
    tbody.innerHTML = rows.map(function(r) {
      return '<tr>' +
        '<td><button type="button" class="ap-wl-link" data-lab="' + escapeHtml(r.lab) + '">' + escapeHtml(r.lab) + '</button></td>' +
        '<td class="ap-num">' + r.active + '</td>' +
        '<td class="ap-num' + (r.overdue > 0 ? ' ap-wl-risk' : '') + '">' + r.overdue + '</td>' +
        '<td class="ap-num' + (r.apptRisk > 0 ? ' ap-wl-warn' : '') + '">' + r.apptRisk + '</td>' +
      '</tr>';
    }).join('');
    tbody.querySelectorAll('.ap-wl-link').forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (btn.dataset.lab) navigateToFilteredCasesParam('carrier', btn.dataset.lab);
      });
    });
  }

  function renderBottlenecks(rows) {
    const wrap = document.getElementById('apBottleneckList');
    if (!wrap) return;
    rows = rows || [];
    if (rows.length === 0) { wrap.hidden = true; wrap.innerHTML = ''; return; }
    wrap.hidden = false;
    const label = function(st) { return (typeof getStageLabel === 'function' && getStageLabel(st)) || st; };
    wrap.innerHTML = '<p class="ap-bottlenecks-title">' + escapeHtml(t('insights.throughput.bottlenecks_title')) + '</p>' +
      '<ul class="ap-bottlenecks-list">' + rows.map(function(r) {
        const age = (r.avgStageAgeDays === null || r.avgStageAgeDays === undefined)
          ? '' : ' · ' + escapeHtml(t('insights.throughput.bottleneck_age', { days: formatDurationDays(r.avgStageAgeDays) }));
        return '<li><button type="button" class="ap-wl-link" data-status="' + escapeHtml(r.status) + '">' +
          escapeHtml(I18n.pluralize(r.active, 'insights.throughput.bottleneck_cases')) +
          ' ' + escapeHtml(t('insights.throughput.bottleneck_in')) + ' ' + escapeHtml(label(r.status)) + '</button>' + age + '</li>';
      }).join('') + '</ul>';
    wrap.querySelectorAll('.ap-wl-link').forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (btn.dataset.status) navigateToFilteredStatus(btn.dataset.status);
      });
    });
  }

  function renderBacklogChart(data) {
    const canvas = document.getElementById('apBacklogChart');
    if (!canvas) return;
    const labels = (data && data.labels) || [];
    const values = (data && data.values) || [];
    if (labels.length === 0) {
      showChartEmptyMessage(canvas, t('insights.charts.no_data'));
      setChartAriaLabel(canvas, t('insights.charts.backlog_trend'), [t('insights.charts.no_data')], [0]);
      return;
    }
    hideChartEmptyMessage(canvas);
    const ctx = canvas.getContext('2d');
    apCharts['apBacklogChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: t('insights.charts.dataset_backlog'),
          data: values,
          borderColor: colors.primary,
          backgroundColor: 'transparent',
          borderWidth: 2,
          pointRadius: 3,
          pointBackgroundColor: colors.primary,
          tension: 0.25,
          fill: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(c) { return t('insights.charts.backlog_count', { count: c.parsed.y }); }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11, family: "'Poppins', sans-serif" } } },
          y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { precision: 0, font: { size: 11, family: "'Poppins', sans-serif" } } }
        }
      }
    });
    setChartAriaLabel(canvas, t('insights.charts.backlog_trend'), labels, values, function(v) { return t('insights.charts.backlog_count', { count: v }); });
  }

  function setTeamView(view) {
    apTeamView = view === 'activity' ? 'activity' : 'workload';
    const wlBtn = document.getElementById('apTeamViewWorkload');
    const acBtn = document.getElementById('apTeamViewActivity');
    const tableWrap = document.getElementById('apWorkloadTableWrap');
    const chartWrap = document.getElementById('apTeamChartWrap');
    const actControls = document.getElementById('apTeamActivityControls');
    if (wlBtn) { wlBtn.classList.toggle('active', apTeamView === 'workload'); wlBtn.setAttribute('aria-pressed', apTeamView === 'workload' ? 'true' : 'false'); }
    if (acBtn) { acBtn.classList.toggle('active', apTeamView === 'activity'); acBtn.setAttribute('aria-pressed', apTeamView === 'activity' ? 'true' : 'false'); }
    if (tableWrap) tableWrap.hidden = apTeamView !== 'workload';
    if (chartWrap) chartWrap.hidden = apTeamView !== 'activity';
    if (actControls) actControls.style.display = apTeamView === 'activity' ? '' : 'none';
    if (apTeamView === 'activity') {
      // Lazily render the creation-activity chart the first time it is
      // requested; it lives in a hidden container until then.
      renderTeamChart(apLastTeamData);
      setTimeout(function() { if (typeof resizeCharts === 'function') resizeCharts(); }, 60);
    }
  }

  function initWorkloadUI() {
    const wlBtn = document.getElementById('apTeamViewWorkload');
    const acBtn = document.getElementById('apTeamViewActivity');
    if (wlBtn) wlBtn.addEventListener('click', function() { setTeamView('workload'); });
    if (acBtn) acBtn.addEventListener('click', function() { setTeamView('activity'); });
    document.querySelectorAll('#apWorkloadTable .ap-th-sort').forEach(function(th) {
      th.addEventListener('click', function() {
        const k = th.dataset.sort;
        if (!k) return;
        if (apWorkloadSort.key === k) apWorkloadSort.dir *= -1;
        else apWorkloadSort = { key: k, dir: k === 'name' ? 1 : -1 };
        drawWorkloadRows();
      });
    });
    setTeamView('workload');
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
        // API returns month as a 1-12 number; format it in the active locale
        const monthLabel = I18n.formatMonth(item.month, 'short');
        if (!labels.includes(monthLabel)) {
          labels.push(monthLabel);
          currentYearData.push(item.currentYear || 0);
          lastYearData.push(item.lastYear || 0);
        }
      }
    });

    if (labels.length === 0) {
      showChartEmptyMessage(ctx.canvas, t('insights.charts.no_data'));
      setChartAriaLabel(ctx.canvas, t('insights.charts.yoy_trends'), [t('insights.charts.no_data')], [0]);
      return;
    }
    hideChartEmptyMessage(ctx.canvas);

    // Honest comparison handling: only draw the prior-year line when it has
    // at least one nonzero month; otherwise show a single-year trend with an
    // explanatory note instead of an empty comparison line.
    var hasPriorData = lastYearData.some(function(v) { return Number(v) > 0; });
    var hasCurrentData = currentYearData.some(function(v) { return Number(v) > 0; });
    var noteEl = document.getElementById('apTrendsNote');
    if (!noteEl) {
      noteEl = document.createElement('p');
      noteEl.className = 'ap-chart-note';
      noteEl.id = 'apTrendsNote';
      ctx.canvas.parentElement.appendChild(noteEl);
    }
    var datasets = [{
      label: currentYear.toString(),
      data: currentYearData,
      borderColor: colors.primary,
      backgroundColor: 'rgba(30, 64, 175, 0.06)',
      fill: false,
      tension: 0.3,
      pointRadius: 3,
      pointHoverRadius: 5,
      borderWidth: 2
    }];
    if (hasPriorData) {
      datasets.push({
        label: lastYear.toString(),
        data: lastYearData,
        borderColor: colors.slate,
        backgroundColor: 'rgba(100, 116, 139, 0.04)',
        fill: false,
        tension: 0.3,
        pointRadius: 3,
        pointHoverRadius: 5,
        borderWidth: 2,
        borderDash: [5, 5]
      });
      noteEl.style.display = 'none';
    } else {
      noteEl.textContent = hasCurrentData
        ? t('insights.charts.yoy_insufficient', { year: lastYear })
        : t('insights.charts.no_data');
      noteEl.style.display = '';
    }
    // Compact height: the card no longer needs a tall canvas for 12 points.
    var trendsContainer = ctx.canvas.closest('.ap-chart-container');
    if (trendsContainer) trendsContainer.style.height = '190px';

    apCharts['apTrendsChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: datasets
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
    setChartAriaLabel(ctx.canvas, t('insights.charts.yoy_trends'), labels, currentYearData, function(v) { return t('insights.charts.this_year_count', { count: v }); });
  }

  // Cases Created by User (horizontal bar — top 8 + expandable remainder,
  // count at bar end). Counts cases by their creator (created_by_user_id);
  // creators whose accounts were removed fall back to "Unknown".
  const AP_CREATOR_PREVIEW = 8;
  let apCreatorChartExpanded = false;

  function renderCreatorBreakdown(creatorBreakdown) {
    const canvas = document.getElementById('apCreatorChart');
    if (!canvas) return;
    const container = canvas.closest('.ap-chart-container');
    const scrollWrap = canvas.closest('.ap-chart-scroll');
    const emptyEl = document.getElementById('apCreatorBreakdownEmpty');

    const counts = {};
    (creatorBreakdown || []).forEach(function(item) {
      const name = (item.creator || '').trim() || t('insights.creators.unknown');
      counts[name] = (counts[name] || 0) + parseInt(item.cases_count || 0, 10);
    });

    const entries = sortBreakdownEntries(counts);
    const totalCreators = entries.length;
    const viewEntries = (!apCreatorChartExpanded && totalCreators > AP_CREATOR_PREVIEW)
      ? entries.slice(0, AP_CREATOR_PREVIEW)
      : entries;
    const labels = viewEntries.map(function(e) { return e[0]; });
    const values = viewEntries.map(function(e) { return e[1]; });

    if (labels.length === 0) {
      setChartEmpty(canvas, emptyEl, true);
      if (container) container.style.height = 'auto';
      hideChartEmptyMessage(canvas);
      var cgl = ensureChartToggle(canvas, 'creator', '');
      if (cgl) cgl.style.display = 'none';
      setChartAriaLabel(canvas, t('insights.charts.cases_by_creator'), [t('insights.creators.empty')], [0]);
      return;
    }
    setChartEmpty(canvas, emptyEl, false);
    hideChartEmptyMessage(canvas);
    if (scrollWrap) scrollWrap.style.maxHeight = 'none';
    sizeHorizontalBarChart(container, labels.length);

    var cToggle = ensureChartToggle(canvas, 'creator', '');
    if (cToggle) {
      if (totalCreators > AP_CREATOR_PREVIEW) {
        cToggle.style.display = '';
        cToggle.textContent = apCreatorChartExpanded
          ? t('insights.charts.show_less_creators')
          : I18n.pluralize(totalCreators, 'insights.charts.show_all_creators');
        cToggle.onclick = function() {
          apCreatorChartExpanded = !apCreatorChartExpanded;
          renderCreatorBreakdown(creatorBreakdown);
        };
      } else {
        cToggle.style.display = 'none';
        cToggle.onclick = null;
      }
    }

    const ctx = canvas.getContext('2d');
    apCharts['apCreatorChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors.primary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: horizontalBarOptions(),
      plugins: [apBarEndValuePlugin]
    });
    setChartAriaLabel(canvas, t('insights.charts.cases_by_creator'), labels, values, function(v) { return I18n.pluralize(v, 'insights.creators.case'); });
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
    // Smart Recommendations is Control-gated server-side; never fetch without
    // a confirmed entitlement.
    if (window.userHasControlAccess !== true) return;

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
    container.innerHTML = '<li class="ap-rec ap-rec-empty"><span class="ap-rec-text">' + escapeHtml(t('insights.ai.generating')) + '</span></li>';

    // Manual Refresh forces a new generation (?refresh=1); the automatic
    // load hits the server-side cache and only calls the AI when the
    // underlying analytics signature changed or the generation is stale.
    var url = 'api/ai-recommendations.php' + (isManual ? '?refresh=1' : '');
    fetch(url, { credentials: 'same-origin' })
      .then(response => response.json())
      .then(data => {
        // Always clear the loading indicator before rendering any result
        clearAIRecommendationsLoadingState(container);

        if (data.error) {
          // Failed — leave aiRecommendationsLoaded as-is so manual Refresh still works.
          // If we already have a payload, keep showing it (possibly stale)
          // instead of wiping working content for a transient failure.
          if (aiRecsData) {
            renderAIRecommendationsMeta(data.warning || data.error);
          } else {
            showAIError(container, data.error);
          }
          return;
        }

        if (data.success) {
          aiRecsData = data;
          renderAIRecommendations();
          aiRecommendationsLoaded = true;
        } else {
          showAIError(container, t('insights.ai.none_available'));
        }
      })
      .catch(error => {
        // Network/parse failure; keep any existing payload visible
        if (!aiRecsData) {
          clearAIRecommendationsLoadingState(container);
          showAIError(container, t('insights.ai.load_error'));
        }
      })
      .finally(() => {
        aiRecommendationsLoading = false;
        if (aiRefreshBtn) aiRefreshBtn.disabled = false;
      });
  }

  // Format a duration in days: sub-day values render as hours so a same-day
  // completion never displays as a misleading "0 days"; longer values keep
  // one decimal place.
  function formatDurationDays(days) {
    var d = Number(days);
    if (!isFinite(d) || d < 0) return t('common.not_applicable');
    if (d > 0 && d < 1) {
      var hours = Math.max(1, Math.round(d * 24));
      return I18n.pluralize(hours, 'insights.metrics.hours');
    }
    return I18n.pluralize(Math.round(d * 10) / 10, 'insights.metrics.days');
  }

  // ---- Recommendation lifecycle -------------------------------------------------

  function setRecommendationState(recKey, state, snapshot) {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return fetch('api/ai-recommendations.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfMeta ? csrfMeta.getAttribute('content') : ''
      },
      body: JSON.stringify({ action: 'set_state', rec_key: recKey, state: state, snapshot: snapshot || null })
    }).then(function (r) { return r.json(); });
  }

  // ---- Card rendering ------------------------------------------------------------

  const AP_RECS_PREVIEW = 3;
  const AP_PRIORITY_TO_SEVERITY = { high: 'attention', medium: 'watch', low: 'info' };
  const AP_STATE_ORDER = { new: 0, reviewed: 1, dismissed: 2, resolved: 3 };

  // Maps AI action_target values onto the shared board-navigation targets.
  const AP_ACTION_NAV = {
    overdue: 'late',
    appt_risk: 'appt_risk',
    due_soon: 'due_soon',
    unassigned: 'unassigned',
    unclassified: 'unclassified',
    lab_ack: 'awaiting_ack',
    attention: 'attention'
  };

  // Deterministic observed-fact renderers. fact_keys in a recommendation are
  // validated server-side; unknown keys are ignored here as well.
  const AP_FACT_DEFS = {
    overdue_share: { label: 'insights.recs.fact_overdue_share' },
    unassigned_cases: { label: 'insights.recs.fact_unassigned' },
    unclassified_cases: { label: 'insights.recs.fact_unclassified' },
    due_this_week: { label: 'insights.recs.fact_due_week' },
    awaiting_ack: { label: 'insights.recs.fact_awaiting_ack' },
    workload_imbalance: { label: 'insights.recs.fact_workload' },
    throughput_30d: { label: 'insights.recs.fact_throughput' }
  };

  function formatObservedFact(key, facts) {
    const def = AP_FACT_DEFS[key];
    const f = facts && facts[key];
    if (!def || !f) return '';
    if (key === 'overdue_share') {
      return t(def.label, { count: f.count, total: f.total });
    }
    if (key === 'workload_imbalance') {
      return t(def.label, { avg: (Math.round((f.avg || 0) * 10) / 10), assignees: f.assignees });
    }
    if (key === 'throughput_30d') {
      return t(def.label, { created: f.created, completed: f.completed });
    }
    return t(def.label, { count: f.count });
  }

  function recMatchesFilter(state) {
    if (aiRecsFilter === 'all') return true;
    if (aiRecsFilter === 'active') return state === 'new' || state === 'reviewed';
    return state === aiRecsFilter;
  }

  function renderAIRecommendationsMeta(extraWarning) {
    const meta = document.getElementById('apRecsMeta');
    if (!meta || !aiRecsData) return;
    const parts = [t('insights.recs.ai_generated_note')];
    if (aiRecsData.generated_at) {
      const d = new Date(String(aiRecsData.generated_at).replace(' ', 'T'));
      if (!isNaN(d)) parts.push(t('insights.recs.last_updated', { time: d.toLocaleString() }));
    }
    if (aiRecsData.stale) parts.push(t('insights.recs.stale_note'));
    if (extraWarning) parts.push(extraWarning);
    else if (aiRecsData.warning) parts.push(aiRecsData.warning);
    meta.textContent = parts.join(' · ');
    meta.classList.toggle('is-stale', !!aiRecsData.stale);
  }

  function renderAIRecommendations() {
    const container = document.getElementById('apRecommendations');
    const summaryEl = document.getElementById('apRecsSummary');
    const filtersEl = document.getElementById('apRecsFilters');
    if (!container || !aiRecsData) return;

    const recs = Array.isArray(aiRecsData.recommendations) ? aiRecsData.recommendations : [];
    const facts = aiRecsData.facts || {};

    // Summary counts across all lifecycle states.
    const counts = { new: 0, reviewed: 0, dismissed: 0, resolved: 0 };
    recs.forEach(function (r) {
      const s = AP_STATE_ORDER.hasOwnProperty(r.state) ? r.state : 'new';
      counts[s] = (counts[s] || 0) + 1;
    });
    const activeCount = counts.new + counts.reviewed;
    if (summaryEl) {
      summaryEl.textContent = I18n.pluralize(activeCount, 'insights.recs.summary_active');
    }
    if (filtersEl) {
      filtersEl.querySelectorAll('.ap-recs-chip').forEach(function (chip) {
        chip.classList.toggle('is-active', chip.dataset.recFilter === aiRecsFilter);
      });
    }
    renderAIRecommendationsMeta();

    const visible = recs
      .filter(function (r) { return recMatchesFilter(r.state || 'new'); })
      .sort(function (a, b) {
        const pa = { high: 0, medium: 1, low: 2 }[a.priority] ?? 3;
        const pb = { high: 0, medium: 1, low: 2 }[b.priority] ?? 3;
        if (pa !== pb) return pa - pb;
        return (AP_STATE_ORDER[a.state] ?? 0) - (AP_STATE_ORDER[b.state] ?? 0);
      });

    container.innerHTML = '';
    const moreBtn = document.getElementById('apRecsMore');

    if (visible.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'ap-rec ap-rec-empty';
      empty.innerHTML = '<span class="ap-rec-text">' + escapeHtml(t('insights.recs.empty_filtered')) + '</span>';
      container.appendChild(empty);
      if (moreBtn) moreBtn.style.display = 'none';
      return;
    }

    visible.forEach(function (rec, idx) {
      container.appendChild(buildRecCard(rec, facts, idx));
    });

    const extra = visible.length - AP_RECS_PREVIEW;
    if (moreBtn && extra > 0) {
      moreBtn.style.display = '';
      moreBtn.textContent = I18n.pluralize(extra, 'insights.recs.more');
      moreBtn.onclick = function () {
        const collapsed = container.querySelectorAll('.ap-rec-collapsed');
        const showing = collapsed.length === 0;
        if (showing) {
          for (let i = AP_RECS_PREVIEW; i < container.children.length; i++) {
            container.children[i].classList.add('ap-rec-collapsed');
          }
        } else {
          collapsed.forEach(function (el) { el.classList.remove('ap-rec-collapsed'); });
        }
        moreBtn.textContent = showing
          ? I18n.pluralize(extra, 'insights.recs.more')
          : t('insights.recs.less');
      };
    } else if (moreBtn) {
      moreBtn.style.display = 'none';
      moreBtn.onclick = null;
    }
  }

  function buildRecCard(rec, facts, idx) {
    const severity = AP_PRIORITY_TO_SEVERITY[rec.priority] || 'info';
    const state = AP_STATE_ORDER.hasOwnProperty(rec.state) ? rec.state : 'new';
    const factKeys = Array.isArray(rec.fact_keys) ? rec.fact_keys : [];
    const factLines = factKeys.map(function (k) { return formatObservedFact(k, facts); })
      .filter(function (s) { return s !== ''; });
    const navTarget = AP_ACTION_NAV[rec.action_target] || null;

    const li = document.createElement('li');
    li.className = 'ap-rec-card ap-sev-' + severity + ' ap-state-' + state +
      (idx >= AP_RECS_PREVIEW ? ' ap-rec-collapsed' : '');
    li.dataset.recKey = rec.rec_key || '';

    var html = '<div class="ap-rec-card-head">' +
      '<span class="ap-rec-severity ap-sev-' + severity + '">' + escapeHtml(t('insights.recs.severity.' + severity)) + '</span>' +
      '<span class="ap-rec-state st-' + state + '">' + escapeHtml(t('insights.recs.state.' + state)) + '</span>' +
      '</div>' +
      '<div class="ap-rec-title">' + escapeHtml(rec.title) + '</div>' +
      '<p class="ap-rec-desc">' + escapeHtml(rec.description) + '</p>';

    if (factLines.length) {
      html += '<div class="ap-rec-metric">' + escapeHtml(factLines.join(' · ')) + '</div>';
    }

    html += '<div class="ap-rec-card-actions">';
    if (navTarget && navTarget !== 'none') {
      html += '<button type="button" class="ap-rec-btn ap-rec-primary" data-rec-nav="' + escapeHtml(navTarget) + '">' +
        escapeHtml(t('insights.recs.action.' + rec.action_target)) + '</button>';
    }
    html += '<details class="ap-rec-details"><summary>' + escapeHtml(t('insights.recs.view_details')) + '</summary>' +
      '<div class="ap-rec-details-body">' +
      '<p>' + escapeHtml(rec.description) + '</p>';
    if (factLines.length) {
      html += '<ul class="ap-rec-fact-list">' + factLines.map(function (s) {
        return '<li>' + escapeHtml(s) + '</li>';
      }).join('') + '</ul>';
    }
    if (aiRecsData && aiRecsData.generated_at) {
      var d = new Date(String(aiRecsData.generated_at).replace(' ', 'T'));
      if (!isNaN(d)) {
        html += '<p class="ap-rec-generated">' + escapeHtml(t('insights.recs.generated_at', { time: d.toLocaleString() })) + '</p>';
      }
    }
    html += '</div></details>';

    // Lifecycle actions: resolved is terminal/automatic; dismissed can be restored.
    if (state === 'new' || state === 'reviewed') {
      html += '<button type="button" class="ap-rec-btn ap-rec-lifecycle" data-rec-state="reviewed">' +
        escapeHtml(t('insights.recs.mark_reviewed')) + '</button>' +
        '<button type="button" class="ap-rec-btn ap-rec-lifecycle" data-rec-state="dismissed">' +
        escapeHtml(t('insights.recs.dismiss')) + '</button>';
    } else if (state === 'dismissed') {
      html += '<button type="button" class="ap-rec-btn ap-rec-lifecycle" data-rec-state="new">' +
        escapeHtml(t('insights.recs.restore')) + '</button>';
    }
    html += '</div>';

    li.innerHTML = html;

    // Delegated-free per-card wiring (cards are rebuilt on each render anyway).
    li.querySelectorAll('[data-rec-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        navigateToFilteredCases(btn.dataset.recNav);
      });
    });
    li.querySelectorAll('[data-rec-state]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var newState = btn.dataset.recState;
        var key = li.dataset.recKey;
        if (!key) return;
        btn.disabled = true;
        setRecommendationState(key, newState, {
          rec_key: rec.rec_key, title: rec.title, description: rec.description,
          priority: rec.priority, category: rec.category,
          fact_keys: rec.fact_keys || [], action_target: rec.action_target || 'none'
        }).then(function (res) {
          if (res && res.success && aiRecsData) {
            aiRecsData.recommendations.forEach(function (r) {
              if (r.rec_key === key) r.state = newState;
            });
            renderAIRecommendations();
          } else {
            btn.disabled = false;
          }
        }).catch(function () { btn.disabled = false; });
      });
    });

    return li;
  }

  // Show AI error
  function showAIError(container, message) {
    const item = document.createElement('li');
    item.className = 'ap-rec ap-rec-empty';
    item.innerHTML = '<span class="ap-rec-text">' + escapeHtml(message) + '</span>';
    container.appendChild(item);
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

  // Shared plugin for horizontal breakdown bars: draws each bar's integer
  // value just past the end of the bar (inside the layout right-padding the
  // chart reserves, so labels never clip).
  const apBarEndValuePlugin = {
    id: 'apBarEndValue',
    afterDatasetsDraw(chart) {
      const meta = chart.getDatasetMeta(0);
      const values = (chart.data.datasets[0] && chart.data.datasets[0].data) || [];
      const ctx = chart.ctx;
      ctx.save();
      ctx.font = "11px 'Poppins', sans-serif";
      ctx.fillStyle = (typeof dtChartColors === 'function') ? dtChartColors().text
        : (document.documentElement.getAttribute('data-theme') === 'dark' ? '#94a3b8' : '#64748b');
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      meta.data.forEach(function(bar, i) {
        const v = values[i];
        if (v === null || v === undefined || !bar) return;
        ctx.fillText(String(v), bar.x + 6, bar.y);
      });
      ctx.restore();
    }
  };

  // Sort breakdown entries by count desc, then name asc (stable, case-insensitive).
  function sortBreakdownEntries(counts) {
    return Object.keys(counts)
      .map(function(name) { return [name, counts[name]]; })
      .sort(function(a, b) {
        if (b[1] !== a[1]) return b[1] - a[1];
        return a[0].localeCompare(b[0], undefined, { sensitivity: 'base' });
      });
  }

  // Size a horizontal breakdown chart's inner container to its row count; the
  // surrounding .ap-chart-scroll wrapper caps the visible height and scrolls
  // when there are many categories, so labels are never hidden or squeezed.
  function sizeHorizontalBarChart(container, rowCount) {
    var height = Math.max(180, rowCount * 36 + 60);
    if (container) container.style.height = height + 'px';
  }

  // Toggle between the chart canvas and its empty-state message.
  function setChartEmpty(canvas, emptyEl, isEmpty) {
    if (canvas) canvas.style.display = isEmpty ? 'none' : '';
    if (emptyEl) emptyEl.style.display = isEmpty ? '' : 'none';
  }

  // Show a lightweight text empty state inside a chart container instead of
  // rendering an empty axis area. Reuses a single sibling div per canvas.
  function showChartEmptyMessage(canvas, message) {
    if (!canvas || !canvas.parentElement) return;
    var el = canvas.parentElement.querySelector('.ap-chart-empty-msg');
    if (!el) {
      el = document.createElement('p');
      el.className = 'ap-chart-empty-msg insights-empty-state';
      canvas.parentElement.appendChild(el);
    }
    el.textContent = message;
    el.style.display = '';
    canvas.style.display = 'none';
    var container = canvas.closest('.ap-chart-container');
    if (container) { container.style.minHeight = '0'; container.style.height = 'auto'; }
  }

  function hideChartEmptyMessage(canvas) {
    if (!canvas || !canvas.parentElement) return;
    var el = canvas.parentElement.querySelector('.ap-chart-empty-msg');
    if (el) el.style.display = 'none';
    canvas.style.display = '';
    var container = canvas.closest('.ap-chart-container');
    if (container && !container.dataset.apFixedHeight) {
      container.style.minHeight = '';
      container.style.height = '';
    }
  }

  // Bind a Chart.js click handler that maps the clicked bar index to a
  // callback. Used for board navigation from breakdown charts.
  function apChartClickOptions(getIndex) {
    return {
      onClick: function(evt, elements) {
        if (!elements || !elements.length) return;
        var idx = elements[0].index;
        var target = getIndex(idx);
        if (target) target();
      },
      onHover: function(evt, elements) {
        var target = evt.native && evt.native.target;
        if (target) target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
      }
    };
  }

  // Render a "Show all / Show less" toggle button after a chart container
  // and wire it to re-render via the provided callback.
  function ensureChartToggle(anchorCanvas, storageKey, label) {
    var container = anchorCanvas && anchorCanvas.closest('.ap-chart-card');
    if (!container) return null;
    var btn = container.querySelector('.ap-chart-toggle[data-toggle-for="' + anchorCanvas.id + '"]');
    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-chart-toggle';
      btn.dataset.toggleFor = anchorCanvas.id;
      btn.dataset.expanded = '0';
      var body = container.querySelector('.ap-chart-scroll, .ap-chart-container');
      if (body && body.nextSibling) container.insertBefore(btn, body.nextSibling);
      else container.appendChild(btn);
    }
    btn.textContent = label;
    return btn;
  }

  // Shared options for horizontal breakdown bar charts (type, creator).
  function horizontalBarOptions() {
    return {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { right: 40 } },
      plugins: {
        legend: { display: false }
      },
      scales: {
        x: {
          beginAtZero: true,
          grid: { color: 'rgba(0,0,0,0.05)' },
          ticks: { precision: 0, font: { size: 11, family: "'Poppins', sans-serif" } }
        },
        y: {
          grid: { display: false },
          ticks: {
            autoSkip: false,
            font: { size: 11, family: "'Poppins', sans-serif" },
            // Truncation adapts to the chart's current width so labels stay
            // within the canvas on resize (e.g. desktop -> mobile viewport).
            callback: function(value) {
              const label = this.getLabelForValue(value);
              const width = this.chart ? this.chart.width : window.innerWidth;
              const max = width < 480 ? 16 : 30;
              return label.length > max ? label.slice(0, max - 1) + '…' : label;
            }
          }
        }
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

    // Recommendation lifecycle filter chips — re-render from the cached
    // payload; no additional server request.
    const recsFilters = document.getElementById('apRecsFilters');
    if (recsFilters && !recsFilters.hasAttribute('data-ap-listener')) {
      recsFilters.addEventListener('click', function (e) {
        const chip = e.target.closest('.ap-recs-chip');
        if (!chip || !aiRecsData) return;
        aiRecsFilter = chip.dataset.recFilter || 'active';
        renderAIRecommendations();
      });
      recsFilters.setAttribute('data-ap-listener', 'true');
    }

    // Filter dropdowns - map element IDs to localStorage keys
    const filterStorageKeys = {
      'apStatusPeriod': 'ap_status_period',
      'apTypePeriod': 'ap_type_period',
      'apVolumePeriod': 'ap_volume_period',
      'apTeamPeriod': 'ap_team_period',
      'apTeamFilter': 'ap_team_filter',
      'apDurationPeriod': 'ap_duration_period',
      'apCreatorPeriod': 'ap_creator_period'
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
  // Status Duration Analytics — one horizontal bar per workflow stage showing
  // the AVERAGE days currently spent in that status (time-in-status, not
  // completed intervals). Min/max/sample size live in the tooltip. Ordered by
  // the practice's workflow sequence.
  function renderDurationChart(data) {
    const canvas = document.getElementById('apDurationChart');
    if (!canvas) return;
    const container = canvas.closest('.ap-chart-container');

    const rows = (data || []).map(function(item) {
      var rawStatus = item.status || 'Unknown';
      return {
        status: rawStatus,
        label: (typeof getStageLabel === 'function') ? (getStageLabel(rawStatus) || rawStatus) : rawStatus,
        avg: Number(item.avg_days_in_status || 0),
        min: Number(item.min_days_in_status || 0),
        max: Number(item.max_days_in_status || 0),
        count: Number(item.case_count || 0)
      };
    });

    const stageOrder = Array.isArray(window.workflowStageOrder) ? window.workflowStageOrder : [];
    const orderIndex = {};
    stageOrder.forEach(function(s, i) { orderIndex[s] = i; });
    rows.sort(function(a, b) {
      const ia = orderIndex.hasOwnProperty(a.status) ? orderIndex[a.status] : 9999;
      const ib = orderIndex.hasOwnProperty(b.status) ? orderIndex[b.status] : 9999;
      return ia - ib;
    });

    if (rows.length === 0) {
      showChartEmptyMessage(canvas, t('insights.charts.no_data'));
      setChartAriaLabel(canvas, t('insights.charts.average_time_by_status'), [t('insights.charts.no_data')], [0]);
      return;
    }
    hideChartEmptyMessage(canvas);
    sizeHorizontalBarChart(container, rows.length);

    apCharts['apDurationChart'] = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: rows.map(function(r) { return r.label; }),
        datasets: [{
          data: rows.map(function(r) { return r.avg; }),
          backgroundColor: colors.primary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: Object.assign(horizontalBarOptions(), {
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(context) {
                var r = rows[context.dataIndex];
                return [
                  t('insights.charts.duration_avg_tooltip', { days: r.avg }),
                  t('insights.charts.duration_range_tooltip', { min: r.min, max: r.max }),
                  I18n.pluralize(r.count, 'insights.creators.case')
                ];
              }
            }
          }
        }
      }),
      plugins: [apBarEndValuePlugin]
    });
    setChartAriaLabel(canvas, t('insights.charts.average_time_by_status'),
      rows.map(function(r) { return r.label; }),
      rows.map(function(r) { return r.avg; }),
      function(v) { return t('insights.charts.days_count', { count: v }); });
  }

  // Lifecycle Distribution Chart (Bar)
  function renderLifecycleChart(data) {
    const ctx = document.getElementById('apLifecycleChart')?.getContext('2d');
    if (!ctx) return;

    if (!data || !data.delivered_cases) {
      showChartEmptyMessage(ctx.canvas, t('insights.charts.lifecycle_empty'));
      setChartAriaLabel(ctx.canvas, t('insights.charts.lifecycle_distribution'), [t('insights.charts.lifecycle_empty')], [0]);
      return;
    }
    hideChartEmptyMessage(ctx.canvas);

    // Sample-size context: lifecycle stats only cover qualifying completed
    // cases - surface the count so users can judge the dataset.
    var lcNoteEl = document.getElementById('apLifecycleNote');
    if (!lcNoteEl) {
      lcNoteEl = document.createElement('p');
      lcNoteEl.className = 'ap-chart-note';
      lcNoteEl.id = 'apLifecycleNote';
      ctx.canvas.parentElement.appendChild(lcNoteEl);
    }
    lcNoteEl.textContent = I18n.pluralize(data.delivered_cases, 'insights.metrics.completed_cases_base');
    lcNoteEl.style.display = '';

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
    setChartAriaLabel(ctx.canvas, t('insights.charts.lifecycle_distribution'), lifecycleLabels, lifecycleValues, function(v) { return t('insights.charts.days_count', { count: v }); });
  }

  // Initialize immediately since this script is lazy-loaded after DOMContentLoaded
  function initOnLoad() {
    initializeEventListeners();
    initAfToolbar();
    initApViewTabs();
    initWorkloadUI();

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
