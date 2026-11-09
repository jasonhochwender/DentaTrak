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

    const apiUrl = `api/get-analytics.php?team_period=${teamPeriod}&team_filter=${teamFilter}&volume_period=${volumePeriod}&status_period=${statusPeriod}&type_period=${typePeriod}&duration_period=${durationPeriod}&creator_period=${creatorPeriod}`;

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
        setAnalyticsLoading(false);
        if (data && data.success) {
          if (window.__insightsVisitPending) {
            window.__insightsVisitPending.practice = false;
          }
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

    // Update trends insights
    const trends = insights.trends || {};
    updateElement('apPeakMonth', trends.peakMonth ? I18n.formatMonth(trends.peakMonth, 'long') : '-');
    updateElement('apGrowthRate', (trends.growthRate || 0) + '%');
    updateElement('apNextPeak', trends.nextPeak ? I18n.formatMonth(trends.nextPeak, 'long') : '-');

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

    var targetEl = null;
    if (target === 'late') targetEl = setCheck('filterLateCases', true);
    else if (target === 'appt_risk') targetEl = setCheck('filterApptRisk', true);
    else if (target === 'delivered_month') targetEl = setCheck('filterDeliveredMonth', true);
    else if (target === 'attention') targetEl = setCheck('filterAttentionOnly', true);
    else if (target === 'due_soon') targetEl = setCheck('filterDueSoon', true);
    else if (target === 'awaiting_ack') targetEl = setCheck('filterAwaitingAck', true);
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, t('insights.charts.status_distribution'), [t('insights.charts.no_data')], [0]); return; }

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
    setChartAriaLabel(ctx.canvas, t('insights.charts.status_distribution'), labels, values, function(v) { return t('insights.charts.cases_count', { count: v }); });
  }

  // Case Type Chart (horizontal bar — every type labeled, count at bar end)
  function renderTypeChart(data) {
    const canvas = document.getElementById('apTypeChart');
    if (!canvas) return;
    const container = canvas.closest('.ap-chart-container');
    const emptyEl = document.getElementById('apTypeChartEmpty');

    const typeData = {};
    (data || []).forEach(item => {
      const type = item.case_type || 'Unspecified';
      typeData[type] = (typeData[type] || 0) + Number(item.count || 0);
    });

    const entries = sortBreakdownEntries(typeData);
    const labels = entries.map(function(e) { return e[0]; });
    const values = entries.map(function(e) { return e[1]; });

    if (labels.length === 0) {
      setChartEmpty(canvas, emptyEl, true);
      if (container) container.style.height = 'auto';
      setChartAriaLabel(canvas, t('insights.charts.case_type_breakdown'), [t('insights.charts.no_data')], [0]);
      return;
    }
    setChartEmpty(canvas, emptyEl, false);
    sizeHorizontalBarChart(container, labels.length);

    const ctx = canvas.getContext('2d');
    apCharts['apTypeChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors.secondary,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: horizontalBarOptions(),
      plugins: [apBarEndValuePlugin]
    });
    setChartAriaLabel(canvas, t('insights.charts.case_type_breakdown'), labels, values, function(v) { return I18n.pluralize(v, 'insights.creators.case'); });
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, t('insights.charts.monthly_case_volume'), [t('insights.charts.no_data')], [0]); return; }

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
    setChartAriaLabel(ctx.canvas, t('insights.charts.monthly_case_volume'), labels, created, function(v) { return t('insights.charts.created_count', { count: v }); });
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, t('insights.charts.team_workload'), [t('insights.charts.no_data')], [0]); return; }

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
    setChartAriaLabel(ctx.canvas, t('insights.charts.team_workload'), labels, values, function(v) { return I18n.pluralize(v, 'insights.creators.case'); });
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, t('insights.charts.yoy_trends'), [t('insights.charts.no_data')], [0]); return; }

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
    setChartAriaLabel(ctx.canvas, t('insights.charts.yoy_trends'), labels, currentYearData, function(v) { return t('insights.charts.this_year_count', { count: v }); });
  }

  // Cases Created by User (horizontal bar — every creator labeled, count at
  // bar end). Counts cases by their creator (created_by_user_id); creators
  // whose accounts were removed fall back to "Unknown".
  function renderCreatorBreakdown(creatorBreakdown) {
    const canvas = document.getElementById('apCreatorChart');
    if (!canvas) return;
    const container = canvas.closest('.ap-chart-container');
    const emptyEl = document.getElementById('apCreatorBreakdownEmpty');

    const counts = {};
    (creatorBreakdown || []).forEach(function(item) {
      const name = (item.creator || '').trim() || t('insights.creators.unknown');
      counts[name] = (counts[name] || 0) + parseInt(item.cases_count || 0, 10);
    });

    const entries = sortBreakdownEntries(counts);
    const labels = entries.map(function(e) { return e[0]; });
    const values = entries.map(function(e) { return e[1]; });

    if (labels.length === 0) {
      setChartEmpty(canvas, emptyEl, true);
      if (container) container.style.height = 'auto';
      setChartAriaLabel(canvas, t('insights.charts.cases_by_creator'), [t('insights.creators.empty')], [0]);
      return;
    }
    setChartEmpty(canvas, emptyEl, false);
    sizeHorizontalBarChart(container, labels.length);

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

  // Display AI Recommendations as shared compact recommendation rows —
  // the same visual treatment as Lab Insights Smart Recommendations.
  // API priority maps onto the shared severity scale.
  const AP_RECS_PREVIEW = 3;
  const AP_PRIORITY_TO_SEVERITY = { high: 'attention', medium: 'watch', low: 'info' };

  function displayRecommendations(container, recommendations) {
    const moreBtn = document.getElementById('apRecsMore');
    recommendations.forEach((rec, idx) => {
      const severity = AP_PRIORITY_TO_SEVERITY[rec.priority] || 'info';
      const item = document.createElement('li');
      item.className = 'ap-rec ap-rec-' + severity + (idx >= AP_RECS_PREVIEW ? ' ap-rec-collapsed' : '');

      item.innerHTML =
        '<span class="ap-rec-severity">' + escapeHtml(t('insights.recs.severity.' + severity)) + '</span>' +
        '<span class="ap-rec-text">' +
        '<strong class="ap-rec-title">' + escapeHtml(rec.title) + '</strong> ' +
        '<span class="ap-rec-msg">' + escapeHtml(rec.description) + '</span>' +
        '</span>';

      container.appendChild(item);
    });

    const extra = recommendations.length - AP_RECS_PREVIEW;
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
          collapsed.forEach(el => el.classList.remove('ap-rec-collapsed'));
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

    if (labels.length === 0) { setChartAriaLabel(ctx.canvas, t('insights.charts.average_time_by_status'), [t('insights.charts.no_data')], [0]); return; }

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
    setChartAriaLabel(ctx.canvas, t('insights.charts.average_time_by_status'), labels, avgDays, function(v) { return t('insights.charts.days_count', { count: v }); });
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
      setChartAriaLabel(ctx.canvas, t('insights.charts.lifecycle_distribution'), [t('insights.charts.no_data')], [0]);
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
    setChartAriaLabel(ctx.canvas, t('insights.charts.lifecycle_distribution'), lifecycleLabels, lifecycleValues, function(v) { return t('insights.charts.days_count', { count: v }); });
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
