/**
 * Workflow Columns Settings UI
 *
 * Drives the draft-based workflow editor in Settings > Display & Behavior.
 * All user actions modify the client-side draft (WorkflowDraft). The draft
 * is only persisted when the user clicks Save Settings.
 */
(function () {
  'use strict';

  var M = window.WorkflowDraft;
  var t = window.t || function (k, p) { return '[draft-' + k + ']'; };
  var workflowColumnsDraft = null;
  var managerInitialized = false;
  var archivedExpanded = false;

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function getEndpoint() {
    return (typeof window.workflowColumnsEndpoint === 'string' && window.workflowColumnsEndpoint)
      ? window.workflowColumnsEndpoint
      : 'api/workflow-columns.php';
  }

  function getEl(id) { return document.getElementById(id); }

  function showError(message) {
    clearSuccess();
    var el = getEl('workflowColumnsError');
    if (el) {
      el.textContent = message || '';
      el.style.display = 'block';
      el.hidden = false;
      if (el.scrollIntoView) {
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }
  }

  function clearError() {
    var el = getEl('workflowColumnsError');
    if (el) {
      el.style.display = 'none';
      el.hidden = true;
    }
  }

  function showSuccess(message) {
    clearError();
    var el = getEl('workflowColumnsSuccess');
    if (el) {
      el.textContent = message || '';
      el.style.display = 'block';
      el.hidden = false;
      if (el.scrollIntoView) {
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }
  }

  function clearSuccess() {
    var el = getEl('workflowColumnsSuccess');
    if (el) {
      el.style.display = 'none';
      el.hidden = true;
    }
  }

  function markWorkflowDirty() {
    // Dirty state is integrated with the centralized Settings unsaved-changes modal.
  }

  function updateResetButtonVisibility() {
    var resetBtn = getEl('resetWorkflowColumnsBtn');
    if (!resetBtn || !workflowColumnsDraft) return;
    var isDefault = M.isDefaultWorkflow(workflowColumnsDraft);
    resetBtn.style.display = isDefault ? 'none' : '';
    resetBtn.hidden = isDefault;
  }

  function buildActiveRow(column, index, count) {
    var isFirst = index === 0;
    var isLast = index === count - 1;
    var isTemp = M.isTempId(column.id);
    var isCustom = isTemp || (typeof column.id === 'string' && column.id.indexOf('Custom-') === 0);
    var rowClass = 'workflow-column-row' + (isCustom ? ' workflow-column-row-custom' : '');
    var html = '<div class="' + rowClass + '" role="listitem" data-column-id="' + escapeHtml(column.id) + '" data-is-first="' + (isFirst ? '1' : '0') + '" data-is-last="' + (isLast ? '1' : '0') + '">';
    html += '<span class="workflow-column-position">' + (index + 1) + '</span>';
    html += '<input type="text" class="workflow-column-label-input" value="' + escapeHtml(column.display || column.label || '') + '" maxlength="40" data-internal-id="' + escapeHtml(column.id) + '" aria-label="' + t('settings.workflow_columns.rename') + '">';

    if (isFirst || isLast) {
      html += '<span class="workflow-column-protected-note" title="' + t('settings.workflow_columns.cannot_archive_protected') + '">' + t('settings.workflow_columns.required') + '</span>';
    } else if (window.isPracticeAdmin) {
      html += '<div class="workflow-column-controls" data-column-id="' + escapeHtml(column.id) + '">';
      if (index > 1) {
        html += '<button type="button" class="workflow-column-move-up" data-action="move-up" aria-label="' + t('settings.workflow_columns.aria_reorder_up') + '">&#8593; ' + t('settings.workflow_columns.reorder_up') + '</button>';
      }
      if (index < count - 2) {
        html += '<button type="button" class="workflow-column-move-down" data-action="move-down" aria-label="' + t('settings.workflow_columns.aria_reorder_down') + '">&#8595; ' + t('settings.workflow_columns.reorder_down') + '</button>';
      }
      if (isTemp) {
        html += '<button type="button" class="workflow-column-remove btn-outline-danger" data-action="remove" aria-label="' + t('settings.workflow_columns.aria_remove') + '">' + t('settings.workflow_columns.remove') + '</button>';
      } else {
        html += '<button type="button" class="workflow-column-archive btn-outline-danger" data-action="archive" aria-label="' + t('settings.workflow_columns.archive') + '">' + t('settings.workflow_columns.archive') + '</button>';
      }
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  function buildArchivedRow(column) {
    return '<div class="workflow-column-row workflow-column-row-archived" role="listitem" data-column-id="' + escapeHtml(column.id) + '">' +
      '<span class="workflow-column-name">' + escapeHtml(column.display || column.label || column.id) + '</span>' +
      '<button type="button" class="workflow-column-restore btn-secondary" data-action="restore" aria-label="' + t('settings.workflow_columns.aria_restore') + '">' + t('settings.workflow_columns.restore') + '</button>' +
      '</div>';
  }

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderWorkflowList() {
    clearError();
    var list = getEl('workflowColumnsList');
    var archivedList = getEl('workflowArchivedList');
    var countEl = getEl('workflowColumnsCount');
    if (!list || !workflowColumnsDraft) return;

    list.innerHTML = '';
    workflowColumnsDraft.active.forEach(function (column, index) {
      var row = buildActiveRow(column, index, workflowColumnsDraft.active.length);
      list.insertAdjacentHTML('beforeend', row);
    });

    list.querySelectorAll('.workflow-column-label-input').forEach(function (input) {
      input.addEventListener('input', function () {
        input.classList.remove('workflow-column-name-invalid');
        clearError();
      });
      input.addEventListener('change', function () {
        var id = input.dataset.internalId;
        var result = M.renameColumn(workflowColumnsDraft, id, input.value);
        if (!result.success) {
          input.value = (M.findByIdInDraft(workflowColumnsDraft, id) || { column: {} }).column.display || '';
          input.classList.add('workflow-column-name-invalid');
          input.focus();
        } else {
          workflowColumnsDraft = result.draft;
          input.classList.remove('workflow-column-name-invalid');
          renderWorkflowList();
          markWorkflowDirty();
          clearError();
        }
      });
    });

    if (countEl) {
      countEl.textContent = t('settings.workflow_columns.count', { count: workflowColumnsDraft.active.length, max: 10 });
      countEl.dataset.count = String(workflowColumnsDraft.active.length);
    }

    renderArchivedList();
    markWorkflowDirty();
    bindWorkflowColumnActions();
    updateResetButtonVisibility();
  }

  function renderArchivedList() {
    var archivedList = getEl('workflowArchivedList');
    var archivedCount = getEl('workflowArchivedCount');
    var archivedEmpty = getEl('workflowArchivedEmpty');
    var archivedToggle = getEl('workflowArchivedListToggle');
    if (!archivedList || !workflowColumnsDraft) return;

    archivedList.innerHTML = '';
    var count = workflowColumnsDraft.archived.length;
    if (archivedCount) {
      archivedCount.textContent = t('settings.workflow_columns.archived_heading', { count: count });
    }

    if (count > 0) {
      workflowColumnsDraft.archived.forEach(function (column) {
        archivedList.insertAdjacentHTML('beforeend', buildArchivedRow(column));
      });
      if (archivedEmpty) archivedEmpty.hidden = true;
    } else if (archivedEmpty) {
      archivedList.appendChild(archivedEmpty);
      archivedEmpty.hidden = false;
    }

    archivedList.hidden = !archivedExpanded;
    if (archivedToggle) {
      archivedToggle.setAttribute('aria-expanded', archivedExpanded ? 'true' : 'false');
      archivedToggle.disabled = (count === 0);
    }
    bindWorkflowColumnActions();
  }

  function bindWorkflowColumnActions() {
    var list = getEl('workflowColumnsList');
    var archivedList = getEl('workflowArchivedList');

    function getColumnIdFromButton(btn) {
      var row = btn && btn.closest ? btn.closest('.workflow-column-row') : null;
      return row ? row.dataset.columnId : null;
    }

    if (list) {
      list.querySelectorAll('button[data-action="move-up"]').forEach(function (btn) {
        btn.onclick = function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = getColumnIdFromButton(btn);
          if (!id || !workflowColumnsDraft) return;
          var result = M.moveColumn(workflowColumnsDraft, id, 'up');
          if (result.success) {
            workflowColumnsDraft = result.draft;
            renderWorkflowList();
            markWorkflowDirty();
          } else {
            showError(result.message);
          }
        };
      });

      list.querySelectorAll('button[data-action="move-down"]').forEach(function (btn) {
        btn.onclick = function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = getColumnIdFromButton(btn);
          if (!id || !workflowColumnsDraft) return;
          var result = M.moveColumn(workflowColumnsDraft, id, 'down');
          if (result.success) {
            workflowColumnsDraft = result.draft;
            renderWorkflowList();
            markWorkflowDirty();
          } else {
            showError(result.message);
          }
        };
      });

      list.querySelectorAll('button[data-action="archive"]').forEach(function (btn) {
        btn.onclick = function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = getColumnIdFromButton(btn);
          if (!id || !workflowColumnsDraft) return;
          onArchiveClick(id, btn);
        };
      });

      list.querySelectorAll('button[data-action="remove"]').forEach(function (btn) {
        btn.onclick = function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = getColumnIdFromButton(btn);
          if (!id || !workflowColumnsDraft) return;
          var result = M.removeColumn(workflowColumnsDraft, id);
          if (result.success) {
            workflowColumnsDraft = result.draft;
            clearConfirmation();
            clearSuccess();
            renderWorkflowList();
            markWorkflowDirty();
          } else {
            showError(result.message);
          }
        };
      });
    }

    var archivedToggle = getEl('workflowArchivedListToggle');
    if (archivedToggle) {
      archivedToggle.onclick = function () {
        archivedExpanded = !archivedExpanded;
        renderArchivedList();
      };
    }

    if (archivedList) {
      archivedList.querySelectorAll('button[data-action="restore"]').forEach(function (btn) {
        btn.onclick = function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = getColumnIdFromButton(btn);
          if (!id || !workflowColumnsDraft) return;
          var result = M.restoreColumn(workflowColumnsDraft, id);
          if (result.success) {
            workflowColumnsDraft = result.draft;
            clearConfirmation();
            clearSuccess();
            renderWorkflowList();
            markWorkflowDirty();
          } else {
            showError(result.message);
          }
        };
      });
    }
  }

  function getColumnDisplayName(id) {
    if (!workflowColumnsDraft) return id || '';
    var found = M.findByIdInDraft(workflowColumnsDraft, id);
    if (found && found.column) {
      return found.column.display || found.column.label || id;
    }
    return id || '';
  }

  function getConfirmationPanel() {
    var panel = getEl('workflowColumnsConfirmation');
    return panel;
  }

  function clearConfirmation() {
    var panel = getEl('workflowColumnsConfirmation');
    if (panel) {
      panel.innerHTML = '';
      panel.hidden = true;
    }
  }

  function renderConfirmation(content) {
    var panel = getConfirmationPanel();
    if (!panel) return;
    panel.innerHTML = content;
    panel.hidden = false;
    if (panel.scrollIntoView) {
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  function makeWorkflowError(r, text, parsed) {
    var message = 'HTTP ' + r.status;
    var diagnosticCode = 'SERVER_ERROR';
    if (parsed && typeof parsed === 'object') {
      if (parsed.message) message = parsed.message;
      if (parsed.diagnosticCode) diagnosticCode = parsed.diagnosticCode;
    } else if (text) {
      message = 'HTTP ' + r.status + ': ' + text;
    }
    var err = new Error(message);
    err.diagnosticCode = diagnosticCode;
    err.status = r.status;
    return err;
  }

  function fetchArchivePreview(id) {
    var expected = (window.workflowColumnsSnapshot && window.workflowColumnsSnapshot.practiceId)
      ? '&expectedPracticeId=' + encodeURIComponent(window.workflowColumnsSnapshot.practiceId)
      : '';
    return fetch(getEndpoint() + '?action=archivePreview&id=' + encodeURIComponent(id) + expected, {
      headers: { 'X-CSRF-Token': getCsrfToken() },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (text) {
        var parsed = null;
        try {
          parsed = text ? JSON.parse(text) : null;
        } catch (e) {
          throw makeWorkflowError(r, text, null);
        }
        if (!r.ok) {
          throw makeWorkflowError(r, text, parsed);
        }
        return parsed;
      });
    });
  }

  function fetchResetPreview() {
    var expected = (window.workflowColumnsSnapshot && window.workflowColumnsSnapshot.practiceId)
      ? '&expectedPracticeId=' + encodeURIComponent(window.workflowColumnsSnapshot.practiceId)
      : '';
    return fetch(getEndpoint() + '?action=resetPreview' + expected, {
      headers: { 'X-CSRF-Token': getCsrfToken() },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (text) {
        var parsed = null;
        try {
          parsed = text ? JSON.parse(text) : null;
        } catch (e) {
          throw makeWorkflowError(r, text, null);
        }
        if (!r.ok) {
          throw makeWorkflowError(r, text, parsed);
        }
        return parsed;
      });
    });
  }

  function resolveErrorMessage(error) {
    var fallback = t('settings.workflow_columns.archive_count_failed');
    var message = (error && error.message) ? error.message : fallback;
    if (error && error.diagnosticCode === 'WORKFLOW_COUNT_QUERY_FAILED') {
      message = t('settings.workflow_columns.archive_count_failed');
    }
    if (error && error.diagnosticCode === 'PRACTICE_CONTEXT_CHANGED') {
      message = t('settings.workflow_columns.practice_context_changed') || message;
    }
    return message;
  }

  function getValidArchiveDestinations(sourceId) {
    if (!workflowColumnsDraft) return [];
    var result = [];
    workflowColumnsDraft.active.forEach(function (col) {
      if (col.id === sourceId) return;
      if (M.isTempId(col.id)) return;
      result.push({ id: col.id, label: col.display || col.label || col.id });
    });
    return result;
  }

  function onArchiveClick(id, opener) {
    clearError();
    clearConfirmation();
    clearSuccess();
    renderConfirmation('<p>' + escapeHtml(t('settings.workflow_columns.archive_loading')) + '</p>');

    fetchArchivePreview(id).then(function (preview) {
      if (!preview || !preview.success) {
        clearConfirmation();
        showError((preview && preview.message) || t('settings.workflow_columns.archive_count_failed'));
        return;
      }
      var affected = (typeof preview.affectedCount === 'number') ? preview.affectedCount : 0;
      if (affected === 0) {
        var result = M.archiveColumn(workflowColumnsDraft, id);
        if (result.success) {
          workflowColumnsDraft = result.draft;
          clearConfirmation();
          clearSuccess();
          renderWorkflowList();
          markWorkflowDirty();
          if (opener && opener.focus) {
            try { opener.focus(); } catch (e) { /* ignore */ }
          }
        } else {
          showError(result.message);
        }
        return;
      }

      var name = getColumnDisplayName(id);
      var html = '<p>' + escapeHtml(t('settings.workflow_columns.archive_affected_in_column', { count: affected, columnName: name })) + '</p>' +
        '<label for="workflowArchiveDestination">' + escapeHtml(t('settings.workflow_columns.move_cases_prompt', { count: affected })) + '</label>' +
        '<select id="workflowArchiveDestination" class="form-select">' +
        '<option value="" disabled selected>' + escapeHtml(t('settings.workflow_columns.destination_select')) + '</option>';
      var destinations = preview.destinations || getValidArchiveDestinations(id);
      if (destinations.length === 0) {
        html += '<option value="" disabled>' + escapeHtml(t('settings.workflow_columns.no_valid_destinations')) + '</option>';
      } else {
        destinations.forEach(function (dest) {
          html += '<option value="' + escapeHtml(dest.id) + '">' + escapeHtml(dest.label) + '</option>';
        });
      }
      html += '</select>' +
        '<p class="workflow-confirmation-actions"><button type="button" id="workflowArchiveCancel" class="workflow-confirmation-cancel">' + escapeHtml(t('common.cancel')) + '</button></p>';
      renderConfirmation(html);

      var destination = getEl('workflowArchiveDestination');
      if (destination) {
        destination.addEventListener('change', function () {
          if (destination.value) {
            var res = M.archiveColumn(workflowColumnsDraft, id, destination.value);
            if (res.success) {
              workflowColumnsDraft = res.draft;
              clearConfirmation();
              clearSuccess();
              renderWorkflowList();
              markWorkflowDirty();
              if (opener && opener.focus) {
                try { opener.focus(); } catch (e) { /* ignore */ }
              }
            } else {
              showError(res.message);
              destination.value = '';
            }
          }
        });
      }

      var cancel = getEl('workflowArchiveCancel');
      if (cancel) {
        cancel.addEventListener('click', function () {
          clearConfirmation();
          if (opener && opener.focus) {
            try { opener.focus(); } catch (e) { /* ignore */ }
          }
        });
      }
    }).catch(function (error) {
      clearConfirmation();
      showError(resolveErrorMessage(error));
    });
  }

  function applyReset(opener, affected, destinationId) {
    var result = M.resetToDefaults(workflowColumnsDraft, affected, destinationId);
    if (result.success) {
      workflowColumnsDraft = result.draft;
      clearConfirmation();
      clearSuccess();
      renderWorkflowList();
      markWorkflowDirty();
      if (opener && opener.focus) {
        try { opener.focus(); } catch (e) { /* ignore */ }
      }
    } else {
      showError(result.message);
    }
  }

  function onResetClick(opener) {
    clearError();
    clearConfirmation();
    clearSuccess();
    renderConfirmation('<p>' + escapeHtml(t('settings.workflow_columns.reset_loading')) + '</p>');

    fetchResetPreview().then(function (preview) {
      if (!preview || !preview.success) {
        clearConfirmation();
        showError((preview && preview.message) || t('settings.workflow_columns.archive_count_failed'));
        return;
      }
      var affected = (typeof preview.affectedCount === 'number') ? preview.affectedCount : 0;

      if (affected === 0) {
        applyReset(opener, 0, null);
        return;
      }

      var html = '<p>' + escapeHtml(t('settings.workflow_columns.reset_affected_cases', { count: affected })) + '</p>' +
        '<label for="workflowResetDestination">' + escapeHtml(t('settings.workflow_columns.move_cases_prompt', { count: affected })) + '</label>' +
        '<select id="workflowResetDestination" class="form-select">' +
        '<option value="" disabled selected>' + escapeHtml(t('settings.workflow_columns.destination_select')) + '</option>';
      var destinations = M.DEFAULT_COLUMN_ORDER.map(function (id) {
        return { id: id, label: id };
      });
      destinations.forEach(function (dest) {
        html += '<option value="' + escapeHtml(dest.id) + '">' + escapeHtml(dest.label) + '</option>';
      });
      html += '</select>' +
        '<p class="workflow-confirmation-actions"><button type="button" id="workflowResetCancel" class="workflow-confirmation-cancel">' + escapeHtml(t('common.cancel')) + '</button></p>';
      renderConfirmation(html);

      var destination = getEl('workflowResetDestination');
      if (destination) {
        destination.addEventListener('change', function () {
          if (destination.value) {
            applyReset(opener, affected, destination.value);
          }
        });
      }

      var cancel = getEl('workflowResetCancel');
      if (cancel) {
        cancel.addEventListener('click', function () {
          clearConfirmation();
        });
      }
    }).catch(function (error) {
      clearConfirmation();
      showError(resolveErrorMessage(error));
    });
  }

  function getWorkflowSnapshotForDraft() {
    var snapshot = (typeof window.workflowColumnsSnapshot === 'object' && window.workflowColumnsSnapshot)
      ? window.workflowColumnsSnapshot
      : { fingerprint: '', active: [], archived: [] };
    if (snapshot && snapshot.practiceId && window.currentPracticeId &&
        parseInt(snapshot.practiceId, 10) !== parseInt(window.currentPracticeId, 10)) {
      return { fingerprint: '', active: [], archived: [] };
    }
    return snapshot;
  }

  window.initWorkflowColumnsManager = function () {
    archivedExpanded = false;
    if (managerInitialized) {
      workflowColumnsDraft = M.createDraft(getWorkflowSnapshotForDraft());
      renderWorkflowList();
      return;
    }
    if (typeof window.isPracticeAdmin !== 'undefined' && !window.isPracticeAdmin) {
      return;
    }
    workflowColumnsDraft = M.createDraft(getWorkflowSnapshotForDraft());

    renderWorkflowList();

    var addBtn = getEl('addWorkflowColumnBtn');
    var addForm = getEl('addWorkflowColumnForm');
    var newNameInput = getEl('newWorkflowColumnName');
    var saveNewBtn = getEl('saveNewWorkflowColumnBtn');
    var cancelNewBtn = getEl('cancelNewWorkflowColumnBtn');
    var resetBtn = getEl('resetWorkflowColumnsBtn');

    if (addBtn && addForm) {
      addBtn.addEventListener('click', function () {
        if (addBtn.disabled) return;
        addForm.style.display = 'block';
        addBtn.style.display = 'none';
        clearError();
        clearConfirmation();
        if (newNameInput) {
          newNameInput.value = '';
          newNameInput.focus();
        }
      });
    }

    if (cancelNewBtn) {
      cancelNewBtn.addEventListener('click', function () {
        addForm.style.display = 'none';
        if (addBtn) addBtn.style.display = 'inline-flex';
        if (newNameInput) newNameInput.value = '';
        clearError();
        clearConfirmation();
      });
    }

    if (newNameInput) {
      newNameInput.addEventListener('input', function () {
        clearError();
        newNameInput.classList.remove('workflow-column-name-invalid');
      });
      newNameInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (saveNewBtn) saveNewBtn.click();
        }
      });
    }

    if (saveNewBtn) {
      saveNewBtn.addEventListener('click', function () {
        var name = newNameInput ? newNameInput.value.trim() : '';
        var result = M.addColumn(workflowColumnsDraft, name);
        if (result.success) {
          workflowColumnsDraft = result.draft;
          renderWorkflowList();
          addForm.style.display = 'none';
          if (addBtn) addBtn.style.display = 'inline-flex';
          if (newNameInput) newNameInput.value = '';
          clearError();
          clearConfirmation();
        } else {
          showError(result.message);
        }
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (resetBtn.disabled) return;
        onResetClick(resetBtn);
      });
    }

    managerInitialized = true;
  };

  // Expose the current draft for Save Settings and cancel/close handling.
  window.getWorkflowColumnsDraft = function () { return workflowColumnsDraft; };
  window.setWorkflowColumnsDraft = function (draft) { workflowColumnsDraft = draft; };
  window.discardWorkflowColumnsDraft = function () {
    if (workflowColumnsDraft) {
      workflowColumnsDraft = M.restoreSnapshot(workflowColumnsDraft);
      renderWorkflowList();
      markWorkflowDirty();
      clearConfirmation();
    }
  };
  window.workflowColumnsHasUnsavedChanges = function () {
    return workflowColumnsDraft ? M.isDirty(workflowColumnsDraft) : false;
  };
  window.getWorkflowColumnsPayload = function () {
    return (workflowColumnsDraft && M.isDirty(workflowColumnsDraft)) ? M.serializeForSave(workflowColumnsDraft) : null;
  };
})();
