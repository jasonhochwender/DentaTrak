/**
 * Billing Portal UI
 *
 * Loads practice subscription state from api/billing-portal.php and renders
 * the Billing modal. Connects to create-checkout-session.php and
 * create-portal-session.php for Stripe redirects.
 *
 * Public surface:
 *   window.openBillingPortal()   — called by the "Billing" menu item
 *   window.closeBillingPortal()  — called by the close / X buttons
 */

(function () {
  'use strict';

  var modal     = null;
  var bodyEl    = null;
  var isLoading = false;

  // Selected billing interval for the plan-selection cards ('year' | 'month').
  // Reset to 'year' every time the modal is opened so Annual is always the default.
  var selectedInterval = 'year';
  // Cache of the last API response so toggling the interval can re-render
  // without an extra network request.
  var lastRenderData = null;

  // CSRF token — set by main.php in a <meta> tag
  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // ── Open ────────────────────────────────────────────────────────────────────
  window.openBillingPortal = function () {
    // SECURITY: Billing is an admin-only surface. This guard covers every
    // call site (menu click, or any future direct call) so non-admins can
    // never open it client-side. window.isPracticeAdmin is set from the
    // server's isPracticeAdmin() check - api/billing-portal.php and every
    // billing mutation endpoint independently re-verify this too.
    if (!window.isPracticeAdmin) {
      if (typeof window.showToast === 'function') {
        window.showToast('Billing is only available to practice administrators.', 'error');
      }
      return;
    }

    // Product decision: Billing is desktop/tablet-only on phones. Central
    // guard here covers every call site (menu click, any future direct
    // call) so phone users never land inside the admin/Stripe UI.
    if (window.matchMedia('(max-width: 720px)').matches) {
      if (typeof window.showMobileRestrictedModal === 'function') {
        window.showMobileRestrictedModal('billing');
      }
      return;
    }

    modal  = document.getElementById('billingPortalModal');
    bodyEl = document.getElementById('billingPortalBody');
    if (!modal || !bodyEl) return;
    selectedInterval = 'year';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    loadBillingPortal();
  };

  // ── Close ───────────────────────────────────────────────────────────────────
  window.closeBillingPortal = function () {
    if (!modal) modal = document.getElementById('billingPortalModal');
    if (modal) {
      modal.style.display = 'none';
      document.body.style.overflow = '';
    }
  };

  // ── Load from API ────────────────────────────────────────────────────────────
  function loadBillingPortal() {
    if (isLoading) return;
    isLoading = true;
    renderLoading();

    fetch('api/billing-portal.php', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        isLoading = false;
        if (data.error) { renderError(data.error); return; }
        if (data.hide_billing_ui) { renderBypassed(); return; }
        render(data);
      })
      .catch(function (err) {
        isLoading = false;

        renderError('Unable to load billing information. Please try again.');
      });
  }

  // ── Render: loading ──────────────────────────────────────────────────────────
  function renderLoading() {
    bodyEl.innerHTML =
      '<div class="bp-loading">' +
        '<div class="bp-loading-spinner"></div>' +
        '<span>Loading billing information\u2026</span>' +
      '</div>';
  }

  // ── Render: error ────────────────────────────────────────────────────────────
  function renderError(message) {
    bodyEl.innerHTML =
      '<div class="bp-error">' +
        '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
          '<circle cx="12" cy="12" r="10"/>' +
          '<line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' +
        '</svg>' +
        '<p>' + escHtml(message) + '</p>' +
        '<button class="bp-close-btn" onclick="window.closeBillingPortal()">Close</button>' +
      '</div>';
  }

  // ── Render: bypass user (no billing UI needed) ───────────────────────────────
  function renderBypassed() {
    bodyEl.innerHTML =
      '<div class="bp-empty-state">' +
        '<p style="color:#6b7280;font-size:0.95rem;">Billing is managed separately for this account.</p>' +
        '<div class="bp-actions"><button class="bp-close-btn" onclick="window.closeBillingPortal()">Close</button></div>' +
      '</div>';
  }

  // ── Render: main ─────────────────────────────────────────────────────────────
  function render(data) {
    lastRenderData = data;
    var canManage = !!data.can_manage_billing;
    var hasSub    = !!data.has_subscription;
    var access    = data.access || {};
    var sub       = data.subscription || null;
    var prices    = data.display_prices || {};

    var html = '';

    // Non-admin notice
    if (!canManage) {
      html +=
        '<div class="bp-readonly-notice">' +
          lockIcon() +
          'You can view billing information. Only practice administrators can manage the subscription.' +
        '</div>';
    }

    // Past-due warning banner
    if (access.show_billing_warning) {
      html +=
        '<div class="bp-cancel-notice" style="background:#fef2f2;border-color:#fecaca;">' +
          warnIcon() +
          '<strong>Payment past due.</strong> Please update your payment method to avoid service interruption.' +
        '</div>';
    }

    var status = access.status || 'none';

    if (status === 'trialing' && !hasSub) {
      html += renderTrialState(access, prices, canManage);
    } else if (status === 'trial_expired' && !hasSub) {
      html += renderExpiredState(access, prices, canManage);
    } else if (status === 'incomplete' || status === 'incomplete_expired') {
      html += renderIncompleteState(access, canManage);
    } else if (hasSub && sub) {
      html += renderSubscriptionCard(sub, access, prices, canManage);
    } else {
      html += renderTrialState(access, prices, canManage);
    }

    html += '<div class="bp-actions"><button class="bp-close-btn" onclick="window.closeBillingPortal()">Close</button></div>';
    bodyEl.innerHTML = html;

    // Wire billing-interval toggle (Annual / Monthly) after inserting HTML
    var intervalBtns = bodyEl.querySelectorAll('.bp-interval-btn');
    intervalBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var interval = btn.getAttribute('data-interval');
        if (interval === selectedInterval) return;
        selectedInterval = interval;
        if (lastRenderData) render(lastRenderData);
      });
    });

    // Wire plan selector buttons after inserting HTML
    // Interval comes from the module-level selectedInterval (set by the toggle above),
    // not from the button itself, so the existing checkout call always uses the
    // currently-selected billing interval.
    var planBtns = bodyEl.querySelectorAll('[data-plan]');
    planBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        handleChoosePlan(btn.getAttribute('data-plan'), selectedInterval, btn);
      });
    });

    // Wire Manage Billing button
    var manageBtns = bodyEl.querySelectorAll('[data-action="manage-billing"]');
    manageBtns.forEach(function (btn) {
      btn.addEventListener('click', function () { handleManageBilling(btn); });
    });
  }

  // ── Render: trialing (no paid subscription yet) ───────────────────────────────
  function renderTrialState(access, prices, canManage) {
    var daysLeft  = access.trial_days_remaining;
    var daysLabel = daysLeft !== null
      ? (daysLeft === 0 ? 'Trial ends today' : daysLeft + ' day' + (daysLeft === 1 ? '' : 's') + ' left in your free trial')
      : '90-day free trial';

    var html =
      '<div class="bp-plan-header">' +
        '<h3 class="bp-plan-header-title">Choose your DentaTrak plan</h3>' +
        '<p class="bp-plan-header-sub">' + escHtml(daysLabel) + ' \u2022 No credit card required</p>' +
      '</div>';

    if (canManage) {
      html += renderPlanGrid(prices, access.trial_ends_at);
    } else {
      html +=
        '<p style="text-align:center;color:#6b7280;font-size:0.9rem;">' +
          'Contact your practice administrator to choose a plan.' +
        '</p>';
    }
    return html;
  }

  // ── Render: trial expired, no subscription ────────────────────────────────────
  function renderExpiredState(access, prices, canManage) {
    var html =
      '<div class="bp-empty-state" style="padding-top:20px;">' +
        '<div class="bp-empty-icon" style="background:linear-gradient(135deg,#fee2e2,#fecaca);">' +
          '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2">' +
            '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' +
          '</svg>' +
        '</div>' +
        '<h3>Trial Ended</h3>' +
        '<p>Your 90-day trial has ended. Your cases and data are safe. Choose a plan to restore full access immediately.</p>' +
      '</div>';

    if (canManage) {
      html += renderPlanGrid(prices, null);
    } else {
      html +=
        '<p style="text-align:center;color:#6b7280;font-size:0.9rem;">' +
          'Contact your practice administrator to resubscribe.' +
        '</p>';
    }
    return html;
  }

  // ── Render: incomplete checkout ───────────────────────────────────────────────
  function renderIncompleteState(access, canManage) {
    return (
      '<div class="bp-empty-state">' +
        '<h3>Subscription Setup Incomplete</h3>' +
        '<p>' + escHtml(access.access_message) + '</p>' +
        (canManage
          ? '<button class="bp-manage-btn" data-action="manage-billing" style="opacity:1;cursor:pointer;margin-top:8px;">Complete Setup</button>'
          : '') +
      '</div>'
    );
  }

  // ── Render: active/paid subscription card ─────────────────────────────────────
  function renderSubscriptionCard(sub, access, prices, canManage) {
    var planSlug   = sub.plan          || 'unknown';
    var planLabel  = formatPlan(planSlug);
    var statusKey  = sub.status        || access.status || 'unknown';
    var statusLabel = formatStatus(statusKey);
    var interval   = sub.billing_interval ? capitalize(sub.billing_interval) + 'ly' : '\u2014';
    var periodEnd  = access.current_period_ends_at ? formatDate(access.current_period_ends_at) : '\u2014';
    var trialEnd   = access.trial_ends_at ? formatDate(access.trial_ends_at) : null;
    var cancelAtEnd = !!sub.cancel_at_period_end;
    // Special-case: a trial that is scheduled to cancel at trial end — the
    // customer will never be charged, so trial/first-charge messaging is
    // replaced with cancellation messaging sourced from the same trial_ends_at.
    var trialingCancel = statusKey === 'trialing' && cancelAtEnd;

    // Display price from config
    var priceAmt = '\u2014';
    if (prices[planSlug] && sub.billing_interval && prices[planSlug][sub.billing_interval]) {
      priceAmt = formatCents(prices[planSlug][sub.billing_interval]) +
                 (sub.billing_interval === 'month' ? '/mo' : '/yr');
    }

    var html =
      '<div class="bp-card">' +
        '<div class="bp-card-header">' +
          '<span class="bp-plan-name-group">' +
            '<span class="bp-plan-name">' + escHtml(planLabel) + '</span>' +
            (trialingCancel && trialEnd
              ? '<span class="bp-cancels-badge">Cancels ' + escHtml(formatShortDate(access.trial_ends_at)) + '</span>'
              : '') +
          '</span>' +
          '<span class="bp-status-badge bp-status-' + escHtml(statusKey) + '">' +
            statusDot(statusKey) + escHtml(statusLabel) +
          '</span>' +
        '</div>';

    if (trialingCancel && trialEnd) {
      html +=
        '<div class="bp-trial-banner">' +
          infoIcon() +
          'Your subscription is scheduled to end on ' + escHtml(trialEnd) + '. You will not be charged.' +
        '</div>';
    } else if (trialEnd && statusKey === 'trialing') {
      // Stripe trial (subscribed mid-trial), not scheduled to cancel
      html +=
        '<div class="bp-trial-banner">' +
          infoIcon() +
          'First charge on ' + escHtml(trialEnd) + '. No charge until your trial ends.' +
        '</div>';
    }

    if (cancelAtEnd && !trialingCancel) {
      html +=
        '<div class="bp-cancel-notice">' +
          warnIcon() +
          'Cancellation scheduled. Access continues until ' + escHtml(periodEnd) + '.' +
        '</div>';
    }

    html +=
        '<div class="bp-card-body">' +
          field('Plan',        planLabel) +
          field('Status',      statusLabel) +
          field('Billing',     interval) +
          field('Amount',      priceAmt,  priceAmt === '\u2014') +
          // "Next Billing" implies an upcoming charge, which would contradict the
          // "You will not be charged" message above — omit it for this state and
          // show "Access Until" instead, sourced from the same trial_ends_at.
          (trialingCancel && trialEnd
            ? field('Access Until', trialEnd)
            : field('Next Billing', periodEnd, periodEnd === '\u2014') +
              (trialEnd && statusKey === 'trialing' ? field('First Charge', trialEnd) : '')) +
        '</div>';

    if (canManage) {
      html +=
        '<div style="padding:0 20px 20px;text-align:right;">' +
          '<button class="bp-manage-btn" data-action="manage-billing" style="opacity:1;cursor:pointer;">' +
            cardIcon() + 'Manage Billing' +
          '</button>' +
        '</div>';
    }

    html += '</div>';
    return html;
  }

  // Static benefit copy per plan — display only, no pricing/billing logic here.
  var PLAN_INFO = {
    operate: {
      label: 'Operate',
      benefits: [
        'Unlimited case tracking',
        'Up to 5 users',
        'Analytics and insights',
        'Case assignments and workflow tracking',
        'Practice management tools',
      ],
    },
    control: {
      label: 'Control',
      benefits: [
        'Everything in Operate',
        'Unlimited users',
        'Built for larger practices and teams',
        'Expanded operational visibility',
        'Advanced practice oversight',
      ],
    },
  };

  // ── Render: single plan card (Operate or Control) ────────────────────────────
  function renderPlanCard(planKey, prices, interval) {
    var info      = PLAN_INFO[planKey];
    var isFeatured = planKey === 'control';
    var cents     = (prices[planKey] && prices[planKey][interval]) ? prices[planKey][interval] : null;
    var priceStr  = cents ? formatCents(cents) : '';
    var perStr    = interval === 'month' ? '/month' : '/year';

    var html = '<div class="bp-plan-card' + (isFeatured ? ' bp-plan-card--featured' : '') + '">';
    if (isFeatured) {
      html += '<div class="bp-plan-badge">Most Popular</div>';
    }
    html +=
        '<div class="bp-plan-title">' + escHtml(info.label) + '</div>' +
        '<div class="bp-plan-price">' + (priceStr ? escHtml(priceStr) : '') +
          '<span class="bp-plan-per">' + escHtml(perStr) + '</span>' +
        '</div>' +
        (interval === 'year' ? '<div class="bp-plan-savings">Save 2 months</div>' : '') +
        '<ul class="bp-plan-benefits">';
    info.benefits.forEach(function (b) {
      html += '<li>' + checkIcon() + '<span>' + escHtml(b) + '</span></li>';
    });
    html +=
        '</ul>' +
        '<button class="bp-plan-select-btn" data-plan="' + escHtml(planKey) + '">' +
          'Choose ' + escHtml(info.label) +
        '</button>' +
      '</div>';

    return html;
  }

  // ── Render: plan selection grid (Annual/Monthly toggle + Operate/Control cards) ─
  function renderPlanGrid(prices, trialEndsAt) {
    var footerNote = trialEndsAt
      ? 'Your first charge occurs when your trial ends on ' + formatDate(trialEndsAt) + '.'
      : 'Charge begins immediately.';

    var toggleHtml =
      '<div class="bp-interval-toggle" role="tablist">' +
        '<button type="button" class="bp-interval-btn' + (selectedInterval === 'year' ? ' active' : '') + '" data-interval="year">Annual</button>' +
        '<button type="button" class="bp-interval-btn' + (selectedInterval === 'month' ? ' active' : '') + '" data-interval="month">Monthly</button>' +
      '</div>';

    var cardsHtml =
      '<div class="bp-plan-grid">' +
        renderPlanCard('operate', prices, selectedInterval) +
        renderPlanCard('control', prices, selectedInterval) +
      '</div>';

    var footerHtml =
      '<p class="bp-plan-note">' +
        infoIcon() +
        escHtml(footerNote) +
      '</p>';

    return toggleHtml + cardsHtml + footerHtml;
  }

  // ── Handle plan selection → Checkout ─────────────────────────────────────────
  function handleChoosePlan(plan, interval, btn) {
    if (!plan || !interval) return;
    var originalText = btn.textContent;
    btn.disabled    = true;
    btn.textContent = 'Redirecting\u2026';

    fetch('api/create-checkout-session.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type':  'application/json',
        'X-CSRF-Token':  getCsrfToken(),
      },
      body: JSON.stringify({ plan: plan, interval: interval }),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.checkout_url) {
          window.location.href = data.checkout_url;
        } else if (data.portal_url) {
          window.location.href = data.portal_url;
        } else {
          showBpToast(data.error || 'Unable to start checkout. Please try again.');
          btn.disabled    = false;
          btn.textContent = originalText;
        }
      })
      .catch(function () {
        showBpToast('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = originalText;
      });
  }

  // ── Handle Manage Billing → Customer Portal ───────────────────────────────────
  function handleManageBilling(btn) {
    var originalText = btn.textContent;
    btn.disabled    = true;
    btn.textContent = 'Opening\u2026';

    fetch('api/create-portal-session.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      body: JSON.stringify({}),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.portal_url) {
          window.location.href = data.portal_url;
        } else if (data.error_code === 'no_customer') {
          showBpToast('No billing account found. Please choose a plan first.');
          btn.disabled    = false;
          btn.textContent = originalText;
        } else {
          showBpToast(data.error || 'Unable to open billing portal. Please try again.');
          btn.disabled    = false;
          btn.textContent = originalText;
        }
      })
      .catch(function () {
        showBpToast('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = originalText;
      });
  }

  // ── Formatting helpers ────────────────────────────────────────────────────────
  function formatCents(cents) {
    if (!cents) return '';
    return '$' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function formatPlan(slug) {
    var map = { evaluate: 'Evaluate (Trial)', operate: 'Operate', control: 'Control', unknown: 'Unknown' };
    return map[slug] || capitalize(slug || 'Unknown');
  }

  function formatStatus(s) {
    var map = {
      active: 'Active', trialing: 'Trialing', past_due: 'Past Due',
      canceled: 'Canceled', unpaid: 'Unpaid', incomplete: 'Incomplete',
      incomplete_expired: 'Expired', trial_expired: 'Trial Ended', none: 'No Subscription', unknown: 'Unknown',
    };
    return map[s] || capitalize(s || 'Unknown');
  }

  function formatDate(iso) {
    if (!iso) return '\u2014';
    try {
      var d = new Date(iso);
      return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) { return iso; }
  }

  // Short form for the "Cancels {date}" badge, e.g. "Nov 6" — sourced from the
  // same trial_ends_at value as formatDate(), just formatted more compactly.
  function formatShortDate(iso) {
    if (!iso) return '\u2014';
    try {
      var d = new Date(iso);
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    } catch (e) { return iso; }
  }

  function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  function escHtml(str) {
    var d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
  }

  function field(label, value, muted) {
    return (
      '<div class="bp-field">' +
        '<span class="bp-field-label">' + escHtml(label) + '</span>' +
        '<span class="bp-field-value' + (muted ? ' muted' : '') + '">' + escHtml(String(value)) + '</span>' +
      '</div>'
    );
  }

  function statusDot(k) {
    var c = { active: '#10b981', trialing: '#f59e0b', past_due: '#ef4444', canceled: '#9ca3af',
               unpaid: '#ef4444', incomplete: '#f59e0b', incomplete_expired: '#6b7280',
               trial_expired: '#6b7280', none: '#6b7280' };
    var fill = c[k] || '#9ca3af';
    return '<svg width="8" height="8" viewBox="0 0 8 8" style="flex-shrink:0"><circle cx="4" cy="4" r="4" fill="' + fill + '"/></svg>';
  }

  function lockIcon() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0">' +
      '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
  }

  function warnIcon() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0">' +
      '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>' +
      '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  }

  function infoIcon() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0">' +
      '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
  }

  function checkIcon() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0">' +
      '<polyline points="20 6 9 17 4 12"/></svg>';
  }

  function cardIcon() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px">' +
      '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>';
  }

  function showBpToast(msg) {
    if (typeof window.showToast === 'function') {
      window.showToast(msg, 'error');
    } else {
      alert(msg);
    }
  }

  // ── Backdrop click + Escape ───────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('billingPortalModal');
    if (!m) return;
    m.addEventListener('click', function (e) {
      if (e.target === m) window.closeBillingPortal();
    });
  });

  document.addEventListener('keydown', function (e) {
    var m = document.getElementById('billingPortalModal');
    if (e.key === 'Escape' && m && m.style.display === 'block') {
      window.closeBillingPortal();
    }
  });

})();
