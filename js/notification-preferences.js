(function() {
  'use strict';

  var modal = null;
  var masterInput = null;
  var eventInputs = null;
  var closeBtn = null;
  var saveBtn = null;
  var cancelBtn = null;
  var preferencesList = null;
  var controls = null;
  var loading = null;
  var errorPanel = null;
  var retryBtn = null;
  var errorCloseBtn = null;

  var cached = null;
  var cacheKey = 'notification-prefs-cache';
  var CACHE_TTL_MS = 60 * 1000;
  var baseline = null;

  function init() {
    modal = document.getElementById('notificationPreferencesModal');
    if (!modal) return;

    masterInput = document.getElementById('prefMaster');
    preferencesList = document.getElementById('notificationPreferencesList');
    closeBtn = document.getElementById('notificationPreferencesClose');
    saveBtn = document.getElementById('notificationPreferencesSave');
    cancelBtn = document.getElementById('notificationPreferencesCancel');
    controls = document.getElementById('notificationPreferencesControls');
    loading = document.getElementById('notificationPreferencesLoading');
    errorPanel = document.getElementById('notificationPreferencesError');
    retryBtn = document.getElementById('notificationPreferencesRetry');
    errorCloseBtn = document.getElementById('notificationPreferencesErrorClose');
    eventInputs = Array.prototype.slice.call(document.querySelectorAll('.event-preference'));

    // Try to restore a safe in-memory cache from sessionStorage so reopened modals
    // do not flash off-states, but we always refresh it in the background.
    try {
      var stored = window.sessionStorage ? window.sessionStorage.getItem(cacheKey) : null;
      if (stored) {
        var parsed = JSON.parse(stored);
        if (parsed && parsed.data && (Date.now() - parsed.time) < CACHE_TTL_MS) {
          cached = parsed.data;
        }
      }
    } catch (e) {
      // Ignore storage parse errors
    }

    var menuItem = document.getElementById('notificationPreferencesMenuItem');
    if (menuItem) {
      menuItem.addEventListener('click', function(e) {
        e.preventDefault();
        openPreferences();
      });
    }

    var panelLink = document.getElementById('notificationPanelPreferencesLink');
    if (panelLink) {
      panelLink.addEventListener('click', function(e) {
        e.preventDefault();
        openPreferences();
      });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (saveBtn) saveBtn.addEventListener('click', savePreferences);
    if (retryBtn) retryBtn.addEventListener('click', function() { loadPreferences(true); });
    if (errorCloseBtn) errorCloseBtn.addEventListener('click', closeModal);

    if (masterInput) {
      masterInput.addEventListener('change', onMasterChange);
    }

    eventInputs.forEach(function(input) {
      input.addEventListener('change', updateSaveButton);
    });

    modal.addEventListener('click', function(e) {
      if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && modal.style.display !== 'none') {
        closeModal();
      }
    });
  }

  function openPreferences() {
    if (!modal) return;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    showLoading();
    loadPreferences(false);
  }

  function closeModal() {
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  function showLoading() {
    if (loading) loading.style.display = 'block';
    if (errorPanel) errorPanel.style.display = 'none';
    if (controls) controls.style.display = 'none';
    if (saveBtn) saveBtn.disabled = true;
    setInputsEnabled(false);
  }

  function showError() {
    if (loading) loading.style.display = 'none';
    if (errorPanel) errorPanel.style.display = 'block';
    if (controls) controls.style.display = 'none';
    if (saveBtn) saveBtn.disabled = true;
    setInputsEnabled(false);
  }

  function showControls() {
    if (loading) loading.style.display = 'none';
    if (errorPanel) errorPanel.style.display = 'none';
    if (controls) controls.style.display = 'block';
    setInputsEnabled(true);
    updateSaveButton();
  }

  function setInputsEnabled(enabled) {
    if (masterInput) masterInput.disabled = !enabled;
    eventInputs.forEach(function(input) {
      input.disabled = !enabled;
    });
  }

  function onMasterChange() {
    var masterOn = masterInput && masterInput.checked;
    eventInputs.forEach(function(input) {
      input.disabled = masterInput && !masterOn;
    });
    if (preferencesList) {
      preferencesList.classList.toggle('disabled', masterInput ? !masterOn : false);
    }
    updateSaveButton();
  }

  function loadPreferences(forceRefresh) {
    if (!forceRefresh && cached) {
      applyData(cached);
      showControls();
      // Still refresh in the background if cache is older than 5s so stale
      // values do not persist across a long session.
      return;
    }

    fetch('api/get-notification-preferences.php', {
      method: 'GET',
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (!data.success) {
        showError();
        if (typeof showToast === 'function') showToast(data.message || t('preferences.load_error'), 'error');
        return;
      }

      cached = data;
      try {
        if (window.sessionStorage) {
          window.sessionStorage.setItem(cacheKey, JSON.stringify({ time: Date.now(), data: data }));
        }
      } catch (e) {
        // Ignore storage write errors
      }

      applyData(data);
      showControls();
    })
    .catch(function(error) {
      console.error('Error loading preferences:', error);
      showError();
      if (typeof showToast === 'function') showToast(t('preferences.load_error'), 'error');
    });
  }

  function buildBaseline(data) {
    var events = {};
    if (data.preferences) {
      data.preferences.forEach(function(pref) {
        events[pref.event_type] = !!pref.enabled;
      });
    }
    return {
      all: !!(data.all && data.all.enabled),
      events: events
    };
  }

  function hasChanges() {
    if (!baseline) return false;
    if (masterInput && masterInput.checked !== baseline.all) return true;
    for (var i = 0; i < eventInputs.length; i++) {
      var input = eventInputs[i];
      var ev = input.getAttribute('data-event');
      if (input.checked !== !!baseline.events[ev]) return true;
    }
    return false;
  }

  function updateSaveButton() {
    if (saveBtn) {
      var changed = hasChanges();
      saveBtn.disabled = !changed;
      saveBtn.setAttribute('aria-disabled', String(!changed));
    }
  }

  function applyData(data) {
    if (!data) return;

    baseline = buildBaseline(data);

    if (masterInput) {
      masterInput.checked = !!(data.all && data.all.enabled);
    }

    if (data.preferences) {
      data.preferences.forEach(function(pref) {
        var input = document.querySelector('.event-preference[data-event="' + (pref.event_type || '') + '"]');
        if (input) {
          input.checked = !!pref.enabled;
        }
      });
    }

    onMasterChange();
  }

  function savePreferences() {
    var preferences = [];
    var masterEnabled = masterInput ? masterInput.checked : true;
    preferences.push({ event_type: 'all', enabled: masterEnabled });

    eventInputs.forEach(function(input) {
      preferences.push({
        event_type: input.getAttribute('data-event'),
        enabled: input.checked
      });
    });

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    saveBtn.disabled = true;

    fetch('api/save-notification-preferences.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({ preferences: preferences })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        cached = { all: data.all, preferences: data.preferences };
        try {
          if (window.sessionStorage) {
            window.sessionStorage.setItem(cacheKey, JSON.stringify({ time: Date.now(), data: cached }));
          }
        } catch (e) {
          // Ignore storage write errors
        }
        applyData(cached);
        if (typeof showToast === 'function') showToast(t('preferences.save_success'), 'success');
        closeModal();
      } else {
        updateSaveButton();
        if (typeof showToast === 'function') showToast(data.message || t('preferences.save_error'), 'error');
      }
    })
    .catch(function(error) {
      updateSaveButton();
      console.error('Error saving preferences:', error);
      if (typeof showToast === 'function') showToast(t('preferences.save_error'), 'error');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
