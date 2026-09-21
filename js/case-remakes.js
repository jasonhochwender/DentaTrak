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

  function renderHistory(remakes) {
    var list = el('remakeHistoryList');
    if (!list) return;

    if (!remakes || remakes.length === 0) {
      list.innerHTML = '<p class="remake-empty">' + esc(t('remakes.empty')) + '</p>';
      return;
    }

    var html = '';
    remakes.forEach(function (r) {
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

      html += '<div class="remake-item" data-remake-id="' + esc(r.id) + '">' +
        '<div class="remake-item-header">' +
          '<span class="remake-item-title">' + esc(t('remakes.number_label', { number: r.remake_number })) + '</span>' +
          '<span class="remake-status ' + (open ? 'remake-status-open' : 'remake-status-completed') + '">' +
            esc(open ? t('remakes.open') : t('remakes.completed')) +
          '</span>' +
        '</div>' +
        '<p class="remake-item-meta">' + meta.join(' &middot; ') + '</p>' +
        (r.notes ? '<p class="remake-item-notes">' + esc(r.notes) + '</p>' : '') +
        (open ? '<button type="button" class="remake-complete-btn" data-remake-id="' + esc(r.id) + '">' +
          esc(t('remakes.mark_complete')) + '</button>' : '') +
        '</div>';
    });

    list.innerHTML = html;
  }

  function loadRemakes() {
    if (!currentCaseId) return;
    fetch('api/case-remakes.php?caseId=' + encodeURIComponent(currentCaseId))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        renderHistory(data && data.remakes ? data.remakes : []);
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
  function refreshActivityTimeline() {
    var form = document.getElementById('createCaseForm');
    if (typeof window.loadActivityTimeline === 'function'
        && form && form.dataset && form.dataset.caseId === currentCaseId) {
      window.loadActivityTimeline(currentCaseId);
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
          el('remakeReason').value = '';
          el('remakeAttribution').value = '';
          el('remakeNotes').value = '';
          el('remakeOtherHint').hidden = true;
          showError('');
          loadRemakes();
          refreshActivityTimeline();
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

  function completeRemake(remakeId, btn) {
    if (!currentCaseId || !remakeId) return;
    if (btn) btn.disabled = true;

    secureFetch('api/case-remakes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'complete',
        caseId: currentCaseId,
        remakeId: remakeId
      })
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.success) {
          loadRemakes();
          refreshActivityTimeline();
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

  document.addEventListener('DOMContentLoaded', function () {
    var close = el('remakeModalClose');
    var cancel = el('remakeCancel');
    var submit = el('remakeSubmit');
    var reason = el('remakeReason');
    var modal = el('remakeModal');

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
})();
