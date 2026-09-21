/**
 * Case Remakes modal controller.
 *
 * Opened from the shared case-actions menu ("Record Remake"). Records a
 * structured remake event (reason + independent attribution + optional
 * notes) and lists the case's remake history with a "Mark complete"
 * action for open remakes. Refreshes in place via fetch - no reload.
 *
 * A remake is an explicit user record only; nothing here is inferred
 * from workflow status moves.
 */
(function () {
  'use strict';

  var currentCaseId = null;
  var submitting = false;

  function el(id) {
    return document.getElementById(id);
  }

  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function showError(message) {
    var box = el('remakeModalError');
    if (!box) return;
    box.textContent = message;
    box.hidden = !message;
  }

  function formatDateTime(value) {
    if (!value) return '';
    var d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function remakeLabel(prefix, code) {
    var label = t(prefix + '.' + code);
    // Missing translations fall back to the raw key - show the code
    // itself instead so the UI never renders "remakes.reasons.x".
    return label && label.indexOf(prefix) !== 0 ? label : code;
  }

  /* One remake entry, shared by the Record Remake modal list and the
     Remake History strip in the case modal. showComplete controls whether
     an open remake offers "Mark complete" (hidden for archived cases -
     the server rejects state changes on them). openLabel lets the case
     modal say "In progress" while the modal keeps its "Open" chip. */
  function remakeItemHtml(r, showComplete, openLabel) {
    var open = !r.completed_at;
    var meta = [];

    meta.push(esc(remakeLabel('remakes.reasons', r.reason_code)));
    meta.push(esc(remakeLabel('remakes.attribution', r.attribution)));
    if (r.lab_name) {
      meta.push(esc(t('remakes.lab_label')) + ': ' + esc(r.lab_name));
    }
    meta.push(esc(t('remakes.initiated_label')) + ' ' + esc(formatDateTime(r.initiated_at)));
    if (r.completed_at) {
      meta.push(esc(t('remakes.completed_label')) + ' ' + esc(formatDateTime(r.completed_at)));
    }
    var creator = r.created_by_first_name
      ? (r.created_by_first_name + ' ' + (r.created_by_last_name || '')).trim()
      : (r.created_by_email || '');
    if (creator) {
      meta.push(esc(t('remakes.recorded_by', { name: creator })));
    }

    return '<div class="remake-item" data-remake-id="' + esc(r.id) + '">' +
      '<div class="remake-item-header">' +
        '<span class="remake-item-title">' + esc(t('remakes.number_label', { number: r.remake_number })) + '</span>' +
        '<span class="remake-status ' + (open ? 'remake-status-open' : 'remake-status-completed') + '">' +
          esc(open ? (openLabel || t('remakes.open')) : t('remakes.completed')) +
        '</span>' +
      '</div>' +
      '<p class="remake-item-meta">' + meta.join(' &middot; ') + '</p>' +
      (r.notes ? '<p class="remake-item-notes">' + esc(r.notes) + '</p>' : '') +
      (open && showComplete ? '<button type="button" class="remake-complete-btn" data-remake-id="' + esc(r.id) + '">' +
        esc(t('remakes.mark_complete')) + '</button>' : '') +
      '</div>';
  }

  function renderHistory(remakes) {
    var list = el('remakeHistoryList');
    if (!list) return;

    if (!remakes || remakes.length === 0) {
      list.innerHTML = '<p class="remake-empty">' + esc(t('remakes.empty')) + '</p>';
      return;
    }

    list.innerHTML = remakes.map(function (r) { return remakeItemHtml(r, true); }).join('');
  }

  /* Remake records are cached per case for the page session so the case
     modal's Remake History strip can reuse data the modal already fetched
     (and vice versa). Entries are invalidated whenever this module
     records or completes a remake. */
  var caseRemakesCache = {};

  function loadRemakes() {
    if (!currentCaseId) return;
    fetch('api/case-remakes.php?caseId=' + encodeURIComponent(currentCaseId))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        var remakes = data && data.remakes ? data.remakes : [];
        caseRemakesCache[currentCaseId] = remakes;
        renderHistory(remakes);
      })
      .catch(function () {
        renderHistory([]);
      });
  }

  function closeModal() {
    var modal = el('remakeModal');
    if (modal) modal.style.display = 'none';
    currentCaseId = null;
    submitting = false;
  }

  // Keep the open case modal's activity timeline in sync when the remake
  // was recorded for the case currently being viewed/edited.
  function refreshActivityTimeline(caseId) {
    var form = document.getElementById('createCaseForm');
    if (typeof window.loadActivityTimeline === 'function'
        && form && form.dataset && form.dataset.caseId === caseId) {
      window.loadActivityTimeline(caseId);
    }
  }

  function openModal(caseId) {
    if (!caseId) return;
    var modal = el('remakeModal');
    if (!modal) return;

    currentCaseId = caseId;
    showError('');
    el('remakeReason').value = '';
    el('remakeAttribution').value = '';
    el('remakeNotes').value = '';
    el('remakeOtherHint').hidden = true;
    renderHistory([]);
    loadRemakes();
    modal.style.display = 'block';
  }

  function submitRemake() {
    if (submitting || !currentCaseId) return;

    var reason = el('remakeReason').value;
    var attribution = el('remakeAttribution').value;
    var notes = el('remakeNotes').value;

    if (!reason || !attribution) {
      showError(!reason ? t('api.remakes.invalid_reason') : t('api.remakes.invalid_attribution'));
      return;
    }

    submitting = true;
    var btn = el('remakeSubmit');
    if (btn) { btn.disabled = true; btn.textContent = t('remakes.recording'); }

    secureFetch('api/case-remakes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'record',
        caseId: currentCaseId,
        reasonCode: reason,
        attribution: attribution,
        notes: notes
      })
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.success) {
          // Remake is saved - close the form (openModal resets it for the
          // next use) and refresh surrounding context. The modal stays
          // open on validation/API/network errors so nothing looks saved
          // when it wasn't.
          var recordedCaseId = currentCaseId;
          delete caseRemakesCache[recordedCaseId];
          closeModal();
          refreshActivityTimeline(recordedCaseId);
          refreshCaseRemakeHistory(recordedCaseId, true);
          if (typeof window.showToast === 'function') {
            window.showToast(t('api.remakes.recorded'), 'success');
          }
        } else {
          showError((data && data.message) || t('remakes.record_error'));
        }
      })
      .catch(function () {
        showError(t('remakes.record_error'));
      })
      .then(function () {
        submitting = false;
        if (btn) { btn.disabled = false; btn.textContent = t('remakes.submit'); }
      });
  }

  /* Shared completion request - the idempotent 'complete' action is used
     by both the remake modal's history list and the case modal's Remake
     History strip, so the POST lives in one place. */
  function requestCompleteRemake(remakeId, caseId) {
    return secureFetch('api/case-remakes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'complete',
        caseId: caseId,
        remakeId: remakeId
      })
    }).then(function (res) { return res.json(); });
  }

  function completeRemake(remakeId, btn) {
    if (!currentCaseId || !remakeId) return;
    if (btn) btn.disabled = true;

    requestCompleteRemake(remakeId, currentCaseId)
      .then(function (data) {
        if (data && data.success) {
          delete caseRemakesCache[currentCaseId];
          loadRemakes();
          refreshActivityTimeline(currentCaseId);
          refreshCaseRemakeHistory(currentCaseId, true);
        } else {
          showError((data && data.message) || t('remakes.complete_error'));
          if (btn) btn.disabled = false;
        }
      })
      .catch(function () {
        showError(t('remakes.complete_error'));
        if (btn) btn.disabled = false;
      });
  }

  /* ---- Remake History strip in the case modal (Details tab) ----

     Compact, collapsible strip rendered only when the saved case actually
     has structured remake records (case_remake_events via
     api/case-remakes.php - workflow regressions never appear here).
     Fetching never blocks the rest of the modal: a failure only marks the
     strip area, and cached results are reused when available. */
  function hideCaseRemakeHistory() {
    var strip = document.getElementById('caseRemakeHistory');
    if (strip) strip.style.display = 'none';
  }

  /* Collapsed-state summary: "Latest: Fit issue · Lab-related · In
     progress" so the strip stays one line until expanded. */
  function remakeSummaryText(remakes) {
    if (!remakes || remakes.length === 0) return '';
    var latest = remakes.reduce(function (a, b) {
      return ((b.remake_number || 0) > (a.remake_number || 0)) ? b : a;
    });
    var parts = [
      remakeLabel('remakes.reasons', latest.reason_code),
      remakeLabel('remakes.attribution', latest.attribution),
      latest.completed_at ? t('remakes.completed') : t('remakes.in_progress')
    ];
    return t('remakes.latest_label') + ': ' + parts.join(' · ');
  }

  function renderCaseRemakeHistory(remakes, archived) {
    var strip = document.getElementById('caseRemakeHistory');
    var list = document.getElementById('caseRemakeHistoryList');
    var count = document.getElementById('caseRemakeHistoryCount');
    var summary = document.getElementById('caseRemakeHistorySummary');
    if (!strip || !list) return;

    if (!remakes || remakes.length === 0) {
      strip.style.display = 'none';
      return;
    }

    list.innerHTML = remakes.map(function (r) {
      return remakeItemHtml(r, !archived, t('remakes.in_progress'));
    }).join('');
    if (count) count.textContent = '(' + remakes.length + ')';
    if (summary) summary.textContent = remakeSummaryText(remakes);
    strip.style.display = '';
  }

  function renderCaseRemakeHistoryError() {
    var strip = document.getElementById('caseRemakeHistory');
    var list = document.getElementById('caseRemakeHistoryList');
    var count = document.getElementById('caseRemakeHistoryCount');
    var summary = document.getElementById('caseRemakeHistorySummary');
    if (!strip || !list) return;
    list.innerHTML = '<p class="remake-empty">' + esc(t('remakes.history_load_error')) + '</p>';
    if (count) count.textContent = '';
    if (summary) summary.textContent = '';
    // Expand so the error is visible even though the strip defaults to
    // collapsed.
    list.hidden = false;
    var toggle = document.getElementById('caseRemakeHistoryToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    strip.style.display = '';
  }

  // Refresh the strip after a record/complete, but only when the case
  // modal is open for that same case. form.dataset.caseArchived (set by
  // app.js alongside the modal's other case state) keeps Mark complete
  // suppressed on archived views.
  function refreshCaseRemakeHistory(caseId, force) {
    var form = document.getElementById('createCaseForm');
    if (form && form.dataset && form.dataset.caseId === caseId) {
      window.loadCaseRemakeHistory(caseId, {
        force: !!force,
        archived: form.dataset.caseArchived === '1'
      });
    }
  }

  /* Called by app.js whenever the case modal shows a case (edit, view, or
     archived view). caseId falsy hides the strip (new-case state).
     opts.archived suppresses Mark complete on historical entries. */
  window.loadCaseRemakeHistory = function (caseId, opts) {
    opts = opts || {};
    var strip = document.getElementById('caseRemakeHistory');
    if (!strip) return;
    if (!caseId) {
      hideCaseRemakeHistory();
      return;
    }
    strip.dataset.caseId = caseId;
    if (opts.archived !== undefined) {
      strip.dataset.archived = opts.archived ? '1' : '0';
    }
    var archived = strip.dataset.archived === '1';

    var cached = caseRemakesCache[caseId];
    if (cached && !opts.force) {
      renderCaseRemakeHistory(cached, archived);
      return;
    }

    if (typeof secureFetch !== 'function') {
      renderCaseRemakeHistory([], archived);
      return;
    }
    secureFetch('api/case-remakes.php?caseId=' + encodeURIComponent(caseId))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (strip.dataset.caseId !== caseId) return; // modal switched cases
        if (!data || !data.success || !data.remakes) {
          renderCaseRemakeHistoryError();
          return;
        }
        caseRemakesCache[caseId] = data.remakes;
        renderCaseRemakeHistory(data.remakes, archived);
      })
      .catch(function () {
        if (strip.dataset.caseId !== caseId) return;
        renderCaseRemakeHistoryError();
      });
  };

  document.addEventListener('DOMContentLoaded', function () {
    var close = el('remakeModalClose');
    var cancel = el('remakeCancel');
    var submit = el('remakeSubmit');
    var reason = el('remakeReason');
    var modal = el('remakeModal');
    var stripToggle = document.getElementById('caseRemakeHistoryToggle');
    var stripList = document.getElementById('caseRemakeHistoryList');

    if (stripToggle && stripList) {
      stripToggle.addEventListener('click', function () {
        var expanded = stripToggle.getAttribute('aria-expanded') === 'true';
        stripToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        stripList.hidden = expanded;
      });
    }

    // "Mark complete" inside the case-modal strip reuses the shared
    // idempotent completion request - no duplicated completion logic.
    if (stripList) {
      stripList.addEventListener('click', function (e) {
        var completeBtn = e.target.closest ? e.target.closest('.remake-complete-btn') : null;
        if (!completeBtn) return;
        var strip = document.getElementById('caseRemakeHistory');
        var caseId = strip && strip.dataset ? strip.dataset.caseId : null;
        if (!caseId) return;
        completeBtn.disabled = true;
        requestCompleteRemake(completeBtn.getAttribute('data-remake-id'), caseId)
          .then(function (data) {
            if (data && data.success) {
              delete caseRemakesCache[caseId];
              refreshCaseRemakeHistory(caseId, true);
              refreshActivityTimeline(caseId);
            } else {
              completeBtn.disabled = false;
            }
          })
          .catch(function () {
            completeBtn.disabled = false;
          });
      });
    }

    if (close) close.addEventListener('click', closeModal);
    if (cancel) cancel.addEventListener('click', closeModal);
    if (submit) submit.addEventListener('click', submitRemake);
    if (reason) {
      reason.addEventListener('change', function () {
        el('remakeOtherHint').hidden = reason.value !== 'other';
      });
    }

    // Delegated "Mark complete" clicks + backdrop click to close,
    // matching the delete-confirm modal's conventions.
    document.addEventListener('click', function (e) {
      var completeBtn = e.target.closest ? e.target.closest('.remake-complete-btn') : null;
      if (completeBtn && modal && modal.contains(completeBtn)) {
        completeRemake(completeBtn.getAttribute('data-remake-id'), completeBtn);
        return;
      }
      if (e.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && modal.style.display === 'block') {
        closeModal();
      }
    });
  });

  window.openCaseRemakesModal = openModal;

  /* Explicit Record Remake button in the case modal's meta row (active
     cases only - app.js shows/hides it from populateCreateCaseForm and
     editCaseHandler). Reuses the same modal and endpoint as the
     actions-menu item. */
  var modalRemakeBtn = document.getElementById('recordRemakeBtn');
  if (modalRemakeBtn) {
    modalRemakeBtn.addEventListener('click', function () {
      var form = document.getElementById('createCaseForm');
      var caseId = form && form.dataset ? form.dataset.caseId : null;
      if (caseId) {
        openModal(caseId);
      }
    });
  }
})();

/* Regression -> remake prompt.

   After a successful backward workflow move, the app calls
   window.promptRemakeForRegression(caseId). The move itself - and its
   case_regression activity + revision increment - is already saved before
   this prompt opens, so it is a classification aid only, never a gate.
   "Yes" opens the structured remake form above (which shows existing
   remake history); "No"/dismiss simply closes. A remake is created only
   through the explicit form submit - nothing is recorded here. */
(function () {
  var prompt = document.getElementById('regressionRemakePrompt');
  var openNote = document.getElementById('regressionRemakePromptOpen');
  var yesBtn = document.getElementById('regressionRemakeYes');
  var noBtn = document.getElementById('regressionRemakeNo');
  var closeBtn = document.getElementById('regressionRemakePromptClose');
  if (!prompt || !yesBtn || !noBtn || !closeBtn) return;

  var promptCaseId = null;
  var previousFocus = null;

  function closePrompt() {
    prompt.style.display = 'none';
    promptCaseId = null;
    if (previousFocus && document.contains(previousFocus)) {
      try { previousFocus.focus(); } catch (e) {}
    }
    previousFocus = null;
  }

  yesBtn.addEventListener('click', function () {
    var id = promptCaseId;
    closePrompt();
    if (id && typeof window.openCaseRemakesModal === 'function') {
      window.openCaseRemakesModal(id);
    }
  });
  noBtn.addEventListener('click', closePrompt);
  closeBtn.addEventListener('click', closePrompt);
  prompt.addEventListener('mousedown', function (e) {
    if (e.target === prompt) closePrompt();
  });

  // Escape takes priority over other modals' handlers while this prompt is
  // on top (capture phase, same convention as the confirm modal in app.js).
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && prompt.style.display === 'block') {
      e.preventDefault();
      e.stopPropagation();
      closePrompt();
    }
  }, true);

  window.promptRemakeForRegression = function (caseId) {
    if (!caseId || prompt.style.display === 'block') return;
    promptCaseId = caseId;
    previousFocus = document.activeElement;
    if (openNote) openNote.hidden = true;
    prompt.style.display = 'block';
    try { yesBtn.focus(); } catch (e) {}

    // Surface an existing open remake without blocking the prompt. On any
    // failure the prompt still works with the default copy.
    if (typeof secureFetch === 'function' && openNote) {
      secureFetch('api/case-remakes.php?caseId=' + encodeURIComponent(caseId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (promptCaseId !== caseId || !data || !data.success) return;
          var hasOpen = (data.remakes || []).some(function (r) { return !r.completed_at; });
          if (hasOpen) openNote.hidden = false;
        })
        .catch(function () {});
    }
  };
})();
