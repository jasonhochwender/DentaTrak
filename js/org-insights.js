/* Organization Insights - consolidated "All practices" reporting for Scale
   organizations. Renders inside #insights-tab only while the organization
   scope is active; api/get-org-insights.php re-derives authorization on
   every request. */
(function () {
  'use strict';

  var state = {
    preset: '90d',
    customStart: '',
    customEnd: '',
    loading: false,
    loaded: false,
    chart: null,
    data: null
  };

  function el(id) { return document.getElementById(id); }

  function readBootstrap() {
    var node = document.getElementById('caseViewBootstrap');
    if (!node) return null;
    try {
      var data = JSON.parse(node.textContent || '{}');
      return data || null;
    } catch (e) {
      return null;
    }
  }

  // Expose eligibility immediately (deferred scripts run before
  // DOMContentLoaded, so app.js click handlers can test it early).
  var earlyBoot = readBootstrap();
  window.__orgInsightsEligible = !!(earlyBoot && earlyBoot.orgCaseView && earlyBoot.orgCaseView.active
      && earlyBoot.orgInsights && earlyBoot.orgInsights.eligible);

  function escapeHtml(v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function fmtInt(v) {
    return (v === null || v === undefined) ? t('org_insights.not_enough_data') : String(v);
  }

  function fmtPct(v) {
    return (v === null || v === undefined) ? t('org_insights.not_enough_data') : v + '%';
  }

  function fmtRate(pct, n, d) {
    if (pct === null || pct === undefined) {
      return '<span class="oi-insufficient">' + escapeHtml(t('org_insights.not_enough_data')) + '</span>';
    }
    return escapeHtml(pct + '%') + ' <span class="oi-denom">(' + n + '/' + d + ')</span>';
  }

  function fmtDays(v, n) {
    if (v === null || v === undefined) {
      return '<span class="oi-insufficient">' + escapeHtml(t('org_insights.not_enough_data')) + '</span>';
    }
    return escapeHtml(String(v)) + ' <span class="oi-denom">(' + n + ' ' + escapeHtml(t('org_insights.cases_unit')) + ')</span>';
  }

  function rangeLabel(data) {
    var r = data.range || {};
    if (r.unbounded) return t('org_insights.range.all_time');
    if (!r.start || !r.end) return '';
    var s = new Date(r.start + 'T00:00:00').toLocaleDateString();
    var e = new Date(r.end + 'T00:00:00').toLocaleDateString();
    return s + ' \u2013 ' + e;
  }

  // ---------------------------------------------------------------
  // Fetch
  // ---------------------------------------------------------------
  function queryString() {
    var p = new URLSearchParams();
    if (state.preset === 'custom') {
      p.set('start', state.customStart);
      p.set('end', state.customEnd);
    } else {
      p.set('preset', state.preset);
    }
    return p.toString();
  }

  function setLoading(on) {
    var n = el('oiLoading');
    if (n) n.style.display = on ? 'flex' : 'none';
    var btn = el('orgInsightsRefresh');
    if (btn) btn.classList.toggle('loading', on);
  }

  function setError(msg) {
    var wrap = el('oiError'), txt = el('oiErrorText');
    if (wrap) wrap.style.display = msg ? 'flex' : 'none';
    if (txt) txt.textContent = msg || '';
  }

  function load() {
    if (state.loading) return;
    state.loading = true;
    setLoading(true);
    setError('');

    fetch('api/get-org-insights.php?' + queryString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        state.loading = false;
        setLoading(false);
        var data = res.data || {};
        if (!res.ok || !data.success) {
          if (data && data.error_code === 'ORG_VIEW_UNAVAILABLE') {
            window.location.reload();
            return;
          }
          setError(data.message || t('insights.error.analytics'));
          return;
        }
        state.data = data;
        state.loaded = true;
        render(data);
      })
      .catch(function () {
        state.loading = false;
        setLoading(false);
        setError(t('insights.error.analytics'));
      });
  }
  window.loadOrgInsights = load;

  // ---------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------
  function render(data) {
    renderRange(data);
    renderCards(data);
    renderPracticeTable(data);
    renderLabTable(data);
    renderTrend(data);
    renderFootnotes(data);
  }

  function renderRange(data) {
    var note = el('oiRangeNote');
    if (note) {
      note.textContent = t('org_insights.range.note', { range: rangeLabel(data), tz: (data.meta && data.meta.timezone) || '' });
    }
    var excl = el('oiExcludedNote');
    var list = data.excludedPractices || [];
    if (excl) {
      if (list.length) {
        excl.hidden = false;
        excl.textContent = t('org_insights.excluded_note', {
          count: list.length,
          names: list.map(function (p) { return p.name; }).join(', ')
        });
      } else {
        excl.hidden = true;
      }
    }
    var sub = el('oiHistoryColGroup');
    if (sub) sub.textContent = t('org_insights.table.period_group', { range: rangeLabel(data) });
  }

  function renderCards(data) {
    var w = (data.totals && data.totals.workload) || {};
    var h = (data.totals && data.totals.history) || {};
    var range = rangeLabel(data);

    var set = function (id, v) { var n = el(id); if (n) n.textContent = v; };
    set('oiCardActive', fmtInt(w.active));
    set('oiCardOverdue', fmtInt(w.overdue));
    set('oiCardOverdueSub', t('org_insights.cards.overdue_sub', {
      rate: w.overdueRatePct === null || w.overdueRatePct === undefined ? '0' : w.overdueRatePct,
      active: w.active || 0
    }));
    set('oiCardCreated', fmtInt(h.created));
    set('oiCardCreatedSub', t('org_insights.cards.period_sub', { range: range }));
    set('oiCardCompleted', fmtInt(h.completed));
    set('oiCardCompletedSub', t('org_insights.cards.period_sub', { range: range }));
    set('oiCardOnTime', h.onTimePct === null || h.onTimePct === undefined ? t('org_insights.not_enough_data') : h.onTimePct + '%');
    set('oiCardOnTimeSub', t('org_insights.cards.on_time_sub', { n: h.onTime || 0, d: h.onTimeEligible || 0 }));
    var turn = h.labTurnaroundAvgDays;
    set('oiCardTurnaround', turn === null || turn === undefined ? t('org_insights.not_enough_data') : turn + ' ' + t('org_insights.days_unit'));
    set('oiCardTurnaroundSub', t('org_insights.cards.turnaround_sub', { n: h.labTurnN || 0 }));
  }

  function drillLink(label, opts, enabled) {
    if (!enabled) return escapeHtml(String(label));
    return '<button type="button" class="oi-drill" data-drill=\'' + JSON.stringify(opts) + '\'>' + escapeHtml(String(label)) + '</button>';
  }

  function renderPracticeTable(data) {
    var tbody = el('oiPracticeBody');
    var tfoot = el('oiPracticeFoot');
    var subtitle = el('oiPracticeSubtitle');
    if (subtitle) {
      subtitle.textContent = t('org_insights.table.practices_sub', { range: rangeLabel(data) });
    }
    if (!tbody) return;
    var rows = (data.practices || []).map(function (p) {
      var w = p.workload || {};
      var h = p.history || {};
      var ot = h.onTime || {};
      return '<tr>' +
        '<th scope="row" class="oi-name">' + escapeHtml(p.practiceName) + '</th>' +
        '<td>' + drillLink(w.active, { practiceId: p.practiceId, activeOnly: true }, true) + '</td>' +
        '<td>' + drillLink(w.overdue, { practiceId: p.practiceId, lateOnly: true }, true) + '</td>' +
        '<td>' + fmtRate(w.overdueRatePct, w.overdue, w.active) + '</td>' +
        '<td>' + escapeHtml(String(w.activeNoDueDate || 0)) + '</td>' +
        '<td>' + escapeHtml(String(h.created || 0)) + '</td>' +
        '<td>' + escapeHtml(String(h.completed || 0)) + '</td>' +
        '<td>' + fmtRate(ot.pct, ot.onTime, ot.eligible) + '</td>' +
        '</tr>';
    }).join('');
    tbody.innerHTML = rows;

    if (tfoot) {
      var tw = (data.totals && data.totals.workload) || {};
      var th = (data.totals && data.totals.history) || {};
      tfoot.innerHTML = '<tr class="oi-total-row">' +
        '<th scope="row">' + escapeHtml(t('org_insights.table.total')) + '</th>' +
        '<td>' + drillLink(tw.active, { activeOnly: true }, true) + '</td>' +
        '<td>' + drillLink(tw.overdue, { lateOnly: true }, true) + '</td>' +
        '<td>' + fmtRate(tw.overdueRatePct, tw.overdue, tw.active) + '</td>' +
        '<td>' + escapeHtml(String(tw.activeNoDueDate || 0)) + '</td>' +
        '<td>' + escapeHtml(String(th.created || 0)) + '</td>' +
        '<td>' + escapeHtml(String(th.completed || 0)) + '</td>' +
        '<td>' + fmtRate(th.onTimePct, th.onTime, th.onTimeEligible) + '</td>' +
        '</tr>';
    }
  }

  function renderLabTable(data) {
    var section = el('oiLabSection');
    if (!section) return;
    var labs = data.labs || {};
    if (!labs.available) {
      section.hidden = true;
      return;
    }
    section.hidden = false;
    var tbody = el('oiLabBody');
    var note = el('oiLabNote');
    var subtitle = el('oiLabSubtitle');
    if (subtitle) {
      subtitle.textContent = t('org_insights.table.labs_sub', { range: rangeLabel(data) });
    }
    var rows = labs.rows || [];
    if (!rows.length) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="oi-empty">' + escapeHtml(t('org_insights.labs_empty')) + '</td></tr>';
      if (note) note.textContent = '';
      return;
    }
    if (tbody) {
      tbody.innerHTML = rows.map(function (l) {
        var practices = (l.practices || []).join(', ');
        return '<tr>' +
          '<th scope="row" class="oi-name">' + escapeHtml(l.name || t('org_insights.unnamed_lab')) +
            (l.type === 'label' ? ' <span class="oi-tag">' + escapeHtml(t('org_insights.label_lab')) + '</span>' : '') +
            (l.isLive === false ? '' : '') + '</th>' +
          '<td class="oi-practices">' + escapeHtml(practices) + '</td>' +
          '<td>' + escapeHtml(String(l.volume || 0)) + '</td>' +
          '<td>' + escapeHtml(String(l.completed || 0)) + '</td>' +
          '<td>' + fmtDays(l.avgTurnaroundDays, l.turnN) + '</td>' +
          '<td>' + fmtRate(l.onTimePct, l.onTime, l.onTimeN) + '</td>' +
          '</tr>';
      }).join('');
    }
    if (note) {
      note.textContent = t('org_insights.labs_note', { min: labs.minSample || 5 });
    }
  }

  function renderTrend(data) {
    var empty = el('oiTrendEmpty');
    var subtitle = el('oiTrendSubtitle');
    var trend = data.trend || {};
    var months = trend.months || [];
    if (subtitle) {
      subtitle.textContent = trend.months && trend.months.length
        ? t('org_insights.trend.subtitle', { range: rangeLabel(data) })
        : t('org_insights.trend.subtitle_empty');
    }
    if (state.chart) { state.chart.destroy(); state.chart = null; }
    if (!months.length) {
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    if (typeof window.ensureChartJsLoaded !== 'function') {
      if (empty) { empty.hidden = false; empty.textContent = t('insights.error.chart_js_failed'); }
      return;
    }
    window.ensureChartJsLoaded(function (err) {
      if (err || typeof Chart === 'undefined') {
        if (empty) { empty.hidden = false; empty.textContent = t('insights.error.chart_js_failed'); }
        return;
      }
      var canvas = el('oiTrendChart');
      if (!canvas) return;
      state.chart = new Chart(canvas.getContext('2d'), {
        data: {
          labels: months.map(function (m) {
            var d = new Date(m + '-01T00:00:00');
            return d.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
          }),
          datasets: [
            {
              type: 'bar',
              label: t('org_insights.trend.created'),
              data: trend.created || [],
              backgroundColor: 'rgba(59, 130, 246, 0.55)',
              borderColor: 'rgba(59, 130, 246, 1)',
              borderWidth: 1,
              yAxisID: 'y'
            },
            {
              type: 'bar',
              label: t('org_insights.trend.completed'),
              data: trend.completed || [],
              backgroundColor: 'rgba(16, 185, 129, 0.55)',
              borderColor: 'rgba(16, 185, 129, 1)',
              borderWidth: 1,
              yAxisID: 'y'
            },
            {
              type: 'line',
              label: t('org_insights.trend.on_time'),
              data: trend.onTimePct || [],
              borderColor: 'rgba(139, 92, 246, 1)',
              backgroundColor: 'rgba(139, 92, 246, 0.12)',
              tension: 0.3,
              spanGaps: true,
              yAxisID: 'y1'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: {
            y: { beginAtZero: true, title: { display: true, text: t('org_insights.cases_unit') }, ticks: { precision: 0 } },
            y1: {
              beginAtZero: true, max: 100, position: 'right',
              title: { display: true, text: t('org_insights.trend.on_time') },
              ticks: { callback: function (v) { return v + '%'; } },
              grid: { drawOnChartArea: false }
            }
          }
        }
      });
    });
  }

  function renderFootnotes(data) {
    var box = el('oiFootnotes');
    if (!box) return;
    var defs = (data.meta && data.meta.definitions) || {};
    var items = [
      ['active', defs.active],
      ['overdue', defs.overdue],
      ['created', defs.created],
      ['completed', defs.completed],
      ['onTime', defs.onTime],
      ['labTurnaround', defs.labTurnaround],
      ['labOnTime', defs.labOnTime]
    ];
    var html = '<h3 class="oi-footnotes-title">' + escapeHtml(t('org_insights.definitions_title')) + '</h3><ul>';
    items.forEach(function (it) {
      if (!it[1]) return;
      html += '<li><strong>' + escapeHtml(t('org_insights.def.' + it[0])) + ':</strong> ' + escapeHtml(it[1]) + '</li>';
    });
    html += '<li><strong>' + escapeHtml(t('org_insights.def.timezone')) + ':</strong> ' +
      escapeHtml(t('org_insights.tz_note', { tz: (data.meta && data.meta.timezone) || '' })) + '</li>';
    var h = (data.totals && data.totals.history) || {};
    if ((h.excludedNoDueDate || 0) + (h.excludedDueEdited || 0) > 0) {
      html += '<li><strong>' + escapeHtml(t('org_insights.def.exclusions')) + ':</strong> ' +
        escapeHtml(t('org_insights.exclusions_note', { nodue: h.excludedNoDueDate || 0, edited: h.excludedDueEdited || 0 })) + '</li>';
    }
    html += '</ul>';
    box.innerHTML = html;
  }

  // ---------------------------------------------------------------
  // Drill-down into the consolidated case list (current-state metrics
  // only - historical metrics cannot be reproduced by a current list).
  // ---------------------------------------------------------------
  function wireDrilldowns() {
    var root = el('orgInsightsRoot');
    if (!root) return;
    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest('button.oi-drill');
      if (!btn) return;
      var opts = {};
      try { opts = JSON.parse(btn.getAttribute('data-drill') || '{}'); } catch (e) { return; }
      var casesTab = document.querySelector('.main-tab[data-tab="cases"]');
      if (casesTab) casesTab.click();
      if (typeof window.orgCaseViewDrillDown === 'function') {
        window.orgCaseViewDrillDown(opts);
      }
    });
  }

  // ---------------------------------------------------------------
  // Range controls
  // ---------------------------------------------------------------
  function wireRangeControls() {
    var bar = el('orgInsightsRoot');
    if (!bar) return;
    Array.prototype.forEach.call(bar.querySelectorAll('.oi-preset'), function (btn) {
      btn.addEventListener('click', function () {
        Array.prototype.forEach.call(bar.querySelectorAll('.oi-preset'), function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        state.preset = btn.getAttribute('data-preset');
        load();
      });
    });
    var apply = el('oiRangeApply');
    if (apply) {
      apply.addEventListener('click', function () {
        var s = el('oiRangeStart'), e = el('oiRangeEnd');
        if (!s || !e || !s.value || !e.value || s.value > e.value) {
          setError(t('org_insights.range.invalid'));
          return;
        }
        state.customStart = s.value;
        state.customEnd = e.value;
        state.preset = 'custom';
        Array.prototype.forEach.call(bar.querySelectorAll('.oi-preset'), function (b) { b.classList.remove('active'); });
        load();
      });
    }
    var refresh = el('orgInsightsRefresh');
    if (refresh) refresh.addEventListener('click', function () { load(); });
  }

  function init() {
    var boot = readBootstrap();
    window.__orgInsightsEligible = !!(boot && boot.orgCaseView && boot.orgCaseView.active
        && boot.orgInsights && boot.orgInsights.eligible);
    if (!window.__orgInsightsEligible) return;
    wireDrilldowns();
    wireRangeControls();
    // First load happens when the Insights tab is activated (app.js calls
    // window.loadOrgInsights); also support deep-linking straight to it.
    if ((window.location.hash || '').indexOf('#insights') === 0) {
      load();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
