/**
 * Workflow Draft Manager
 *
 * Pure client-side operations for the Settings > Display & Behavior
 * workflow column editor. All mutations are staged in a draft until
 * the user clicks Save Settings.
 *
 * The draft holds a deep copy of the persisted snapshot plus the
 * applied user changes. It is the single source of truth for the
 * rendered list while Settings is open.
 */
(function (global) {
  'use strict';

  var t = window.t || function (k, p) { return k; };

  // Six canonical DentaTrak internal statuses in order.
  var DEFAULT_COLUMN_ORDER = [
    'Originated',
    'Sent To External Lab',
    'Designed',
    'Manufactured',
    'Received From External Lab',
    'Delivered'
  ];

  // Lightweight deep clone for arrays of simple column objects.
  function cloneColumn(column) {
    return {
      id: column.id,
      label: String(column.label || ''),
      display: String(column.display || column.label || ''),
      position: typeof column.position === 'number' ? column.position : 0,
      colorKey: typeof column.colorKey === 'number' ? column.colorKey : -1,
      archived: !!column.archived,
      isFirst: !!column.isFirst,
      isLast: !!column.isLast,
      isNew: !!column.isNew,
      tempId: column.tempId || null
    };
  }

  function cloneColumns(columns) {
    return (columns || []).map(cloneColumn);
  }

  function buildSnapshot(snapshot) {
    return {
      fingerprint: (snapshot && snapshot.fingerprint) || '',
      active: cloneColumns(snapshot && snapshot.active),
      archived: cloneColumns(snapshot && snapshot.archived)
    };
  }

  // Sort a loaded snapshot by its persisted position values before normalizing.
  function sortByPersistedPosition(columns) {
    columns.sort(function (a, b) {
      var ap = typeof a.position === 'number' ? a.position : 0;
      var bp = typeof b.position === 'number' ? b.position : 0;
      return ap - bp;
    });
  }

  // Reassign positions based on the current array order. Does NOT sort.
  function reindexPositions(columns) {
    columns.forEach(function (c, i) {
      c.position = i;
      c.isFirst = (i === 0);
      c.isLast = (i === columns.length - 1);
    });
  }

  function isCanonical(id) {
    return DEFAULT_COLUMN_ORDER.indexOf(id) !== -1;
  }

  function isDefaultWorkflow(draft) {
    if (!draft || !draft.active) return false;
    if (draft.active.length !== DEFAULT_COLUMN_ORDER.length) return false;
    for (var i = 0; i < DEFAULT_COLUMN_ORDER.length; i++) {
      var col = draft.active[i];
      var expectedId = DEFAULT_COLUMN_ORDER[i];
      if (!col || col.id !== expectedId) return false;
      if ((col.label || '') !== expectedId || (col.display || col.label || '') !== expectedId) return false;
    }
    // Any active custom column makes it non-default.
    for (var j = 0; j < draft.active.length; j++) {
      if (!isCanonical(draft.active[j].id)) return false;
    }
    return true;
  }

  // Create a new draft from a persisted snapshot.
  function createDraft(snapshot) {
    var draft = {
      original: buildSnapshot(snapshot),
      active: cloneColumns(snapshot && snapshot.active),
      archived: cloneColumns(snapshot && snapshot.archived),
      labelOverrides: {},
      archiveDestinations: {},
      resetDestination: null,
      resetPending: false,
      newCounter: 1
    };
    sortByPersistedPosition(draft.original.active);
    sortByPersistedPosition(draft.original.archived);
    sortByPersistedPosition(draft.active);
    sortByPersistedPosition(draft.archived);
    reindexPositions(draft.original.active);
    reindexPositions(draft.original.archived);
    reindexPositions(draft.active);
    reindexPositions(draft.archived);
    return draft;
  }

  function isCustomId(id) {
    return typeof id === 'string' && id.indexOf('Custom-') === 0;
  }

  function isTempId(id) {
    return typeof id === 'string' && id.indexOf('Temp-') === 0;
  }

  function makeTempId(draft) {
    return 'Temp-' + (draft.newCounter++);
  }

  function findLowestAvailableColorKey(columns) {
    var used = [];
    for (var i = 0; i < columns.length; i++) {
      if (typeof columns[i].colorKey === 'number' && columns[i].colorKey >= 0) {
        used.push(columns[i].colorKey);
      }
    }
    for (var k = 0; k < 10; k++) {
      if (used.indexOf(k) === -1) return k;
    }
    return 0;
  }

  function findById(columns, id) {
    for (var i = 0; i < columns.length; i++) {
      if (columns[i].id === id) return { index: i, column: columns[i] };
    }
    return null;
  }

  function findByIdInDraft(draft, id) {
    var found = findById(draft.active, id);
    if (found) return { array: 'active', index: found.index, column: found.column };
    found = findById(draft.archived, id);
    if (found) return { array: 'archived', index: found.index, column: found.column };
    return null;
  }

  // ----- Public operations -----

  function addColumn(draft, label) {
    if (!label || !label.trim()) {
      return { success: false, message: 'Column name is required.' };
    }
    if (label.length > 40) {
      return { success: false, message: 'Column name cannot exceed 40 characters.' };
    }
    if (draft.active.length >= 10) {
      return { success: false, message: 'A workflow may have at most 10 columns.' };
    }
    var normalized = label.trim().toLowerCase();
    for (var i = 0; i < draft.active.length; i++) {
      if ((draft.active[i].label || '').trim().toLowerCase() === normalized) {
        return { success: false, message: 'An active column with that name already exists.' };
      }
    }

    var tempId = makeTempId(draft);
    var newColumn = {
      id: tempId,
      label: label.trim(),
      display: label.trim(),
      position: 0,
      colorKey: findLowestAvailableColorKey(draft.active),
      archived: false,
      isFirst: false,
      isLast: false,
      isNew: true,
      tempId: tempId
    };
    // Insert before the required terminal column so the final column stays last.
    var insertAt = Math.max(0, draft.active.length - 1);
    draft.active.splice(insertAt, 0, newColumn);
    reindexPositions(draft.active);
    return { success: true, draft: draft };
  }

  function renameColumn(draft, id, label) {
    if (!label || !label.trim()) {
      return { success: false, message: 'Column name is required.' };
    }
    if (label.length > 40) {
      return { success: false, message: 'Column name cannot exceed 40 characters.' };
    }
    var found = findByIdInDraft(draft, id);
    if (!found) {
      return { success: false, message: 'Column not found.' };
    }
    var normalized = label.trim().toLowerCase();
    for (var i = 0; i < draft.active.length; i++) {
      if (draft.active[i].id !== id && (draft.active[i].label || '').trim().toLowerCase() === normalized) {
        return { success: false, message: 'An active column with that name already exists.' };
      }
    }
    found.column.label = label.trim();
    found.column.display = label.trim();
    if (found.array === 'active') {
      draft.labelOverrides[id] = label.trim();
    }
    return { success: true, draft: draft };
  }

  function moveColumn(draft, id, direction) {
    var active = draft.active;
    var idx = active.findIndex(function (c) { return c.id === id; });
    if (idx === -1) {
      return { success: false, message: 'Column not found.' };
    }
    if (idx === 0 || idx === active.length - 1) {
      return { success: false, message: 'The first and last columns cannot be moved.' };
    }
    var newIdx;
    if (direction === 'up') {
      if (idx <= 1) {
        return { success: false, message: 'Cannot move past the first column.' };
      }
      newIdx = idx - 1;
    } else if (direction === 'down') {
      if (idx >= active.length - 2) {
        return { success: false, message: 'Cannot move past the last column.' };
      }
      newIdx = idx + 1;
    } else {
      return { success: false, message: 'Invalid direction.' };
    }
    var col = active.splice(idx, 1)[0];
    active.splice(newIdx, 0, col);
    reindexPositions(active);
    return { success: true, draft: draft };
  }

  function archiveColumn(draft, id, destinationId) {
    var found = findById(draft.active, id);
    if (!found) {
      return { success: false, message: t('settings.workflow_columns.not_found') };
    }
    var col = found.column;
    if (isTempId(id)) {
      return { success: false, message: t('settings.workflow_columns.archive_not_temporary') };
    }
    if (found.index === 0 || found.index === draft.active.length - 1) {
      return { success: false, message: t('settings.workflow_columns.first_last_protected') };
    }
    if (draft.active.length <= 3) {
      return { success: false, message: t('settings.workflow_columns.min_active_required', { min: 3 }) };
    }
    if (destinationId) {
      var dest = draft.active.find(function (c) { return c.id === destinationId; });
      if (!dest) {
        return { success: false, message: t('settings.workflow_columns.invalid_destination') };
      }
      if (dest.id === id) {
        return { success: false, message: t('settings.workflow_columns.destination_same') };
      }
    }

    // Mark archived and move to archived list.
    var active = draft.active;
    var archived = active.splice(found.index, 1)[0];
    archived.archived = true;
    archived.isFirst = false;
    archived.isLast = false;
    archived.position = 0;
    draft.archived.push(archived);
    reindexPositions(active);
    reindexPositions(draft.archived);
    if (destinationId) {
      draft.archiveDestinations[id] = destinationId;
    } else {
      delete draft.archiveDestinations[id];
    }
    return { success: true, draft: draft };
  }

  function removeColumn(draft, id) {
    var found = findById(draft.active, id);
    if (!found) {
      return { success: false, message: t('settings.workflow_columns.not_found') };
    }
    if (!isTempId(id)) {
      return { success: false, message: t('settings.workflow_columns.remove_not_temporary') };
    }
    if (found.index === 0 || found.index === draft.active.length - 1) {
      return { success: false, message: t('settings.workflow_columns.remove_protected') };
    }
    draft.active.splice(found.index, 1);
    delete draft.archiveDestinations[id];
    reindexPositions(draft.active);
    return { success: true, draft: draft };
  }

  function restoreColumn(draft, id) {
    var reason = '';
    var columnInfo = null;
    if (!id) {
      reason = 'Column ID is missing.';
    } else {
      var found = findById(draft.archived, id);
      if (!found) {
        reason = t('settings.workflow_columns.not_found');
      } else if (draft.active.length >= 10) {
        reason = t('settings.workflow_columns.restore_max_active', { max: 10 });
      } else {
        columnInfo = found.column;
        if (!columnInfo.id) {
          reason = 'Archived column is missing an ID.';
        } else if (!columnInfo.label) {
          reason = 'Archived column is missing a name.';
        }
      }
    }

    if (reason) {
      return { success: false, message: reason };
    }

    var col = columnInfo;
    // Ensure restored name doesn't conflict with an active column.
    var normalized = (col.label || '').trim().toLowerCase();
    for (var i = 0; i < draft.active.length; i++) {
      if ((draft.active[i].label || '').trim().toLowerCase() === normalized) {
        reason = t('settings.workflow_columns.restore_name_conflict', { name: col.label || col.id });
        return { success: false, message: reason };
      }
    }

    // Reassign colorKey if it is already used by an active column.
    var usedKeys = draft.active.map(function (c) { return c.colorKey; });
    if (usedKeys.indexOf(col.colorKey) !== -1 || typeof col.colorKey !== 'number' || col.colorKey < 0) {
      col.colorKey = findLowestAvailableColorKey(draft.active);
    }

    // Insert immediately before the terminal (Delivered) column, or append if no terminal is found.
    var active = draft.active;
    var insertIndex = active.length;
    for (var j = 0; j < active.length; j++) {
      if (active[j].id === 'Delivered') {
        insertIndex = j;
        break;
      }
    }
    col.archived = false;
    active.splice(insertIndex, 0, col);
    draft.archived.splice(found.index, 1);
    delete draft.archiveDestinations[id];
    reindexPositions(active);
    reindexPositions(draft.archived);
    return { success: true, draft: draft };
  }

  function undoArchive(draft, id) {
    return restoreColumn(draft, id);
  }

  function resetToDefaults(draft, affectedCases, resetDestination) {
    if ((affectedCases || 0) > 0) {
      if (!resetDestination) {
        return { success: false, message: t('settings.workflow_columns.destination_required') };
      }
      if (DEFAULT_COLUMN_ORDER.indexOf(resetDestination) === -1) {
        return { success: false, message: 'Select a default destination for cases in custom columns.' };
      }
    }

    // Build the canonical six active columns, reusing the existing record wherever it exists.
    var newActive = DEFAULT_COLUMN_ORDER.map(function (id, i) {
      var found = findByIdInDraft(draft, id);
      var col = found ? cloneColumn(found.column) : {
        id: id,
        label: id,
        display: id,
        position: i,
        colorKey: i,
        archived: false,
        isFirst: false,
        isLast: false,
        isNew: false,
        tempId: null
      };
      col.id = id;
      col.label = id;
      col.display = id;
      col.position = i;
      col.colorKey = i;
      col.archived = false;
      col.isFirst = i === 0;
      col.isLast = i === DEFAULT_COLUMN_ORDER.length - 1;
      col.isNew = false;
      col.tempId = null;
      return col;
    });

    // Custom columns (non-canonical) that are currently active become archived.
    // Previously archived non-canonical columns remain archived. No ID appears twice.
    var toArchive = (draft.active || []).filter(function (c) { return !isCanonical(c.id); });
    var archived = (draft.archived || []).filter(function (c) { return !isCanonical(c.id); });

    // Deduplicate by ID across the two archived collections.
    var seen = {};
    draft.active = newActive;
    draft.archived = archived.concat(toArchive).filter(function (c) {
      if (seen[c.id]) return false;
      seen[c.id] = true;
      return true;
    }).map(function (c) {
      var copy = cloneColumn(c);
      copy.archived = true;
      copy.isFirst = false;
      copy.isLast = false;
      return copy;
    });
    reindexPositions(draft.active);
    reindexPositions(draft.archived);

    // Remove stale destination mappings for canonical columns that are now active again.
    draft.archiveDestinations = {};
    draft.labelOverrides = {};
    draft.resetPending = true;
    draft.resetDestination = resetDestination || null;

    return { success: true, draft: draft, affectedCount: affectedCases || 0 };
  }

  function restoreSnapshot(draft) {
    draft.active = cloneColumns(draft.original.active);
    draft.archived = cloneColumns(draft.original.archived);
    draft.labelOverrides = {};
    draft.archiveDestinations = {};
    draft.resetDestination = null;
    draft.resetPending = false;
    draft.newCounter = 1;
    reindexPositions(draft.active);
    reindexPositions(draft.archived);
    return draft;
  }

  function isDirty(draft) {
    if (draft.resetPending) return true;
    if (Object.keys(draft.archiveDestinations).length > 0) return true;
    if (draft.resetDestination) return true;
    if (Object.keys(draft.labelOverrides).length > 0) return true;
    if (draft.active.length !== draft.original.active.length) return true;
    if (draft.archived.length !== draft.original.archived.length) return true;
    for (var i = 0; i < draft.active.length; i++) {
      var cur = draft.active[i];
      var orig = draft.original.active[i];
      if (!orig || cur.id !== orig.id || cur.label !== orig.label || cur.position !== orig.position) {
        return true;
      }
    }
    for (var j = 0; j < draft.archived.length; j++) {
      var cur2 = draft.archived[j];
      var orig2 = draft.original.archived[j];
      if (!orig2 || cur2.id !== orig2.id || cur2.label !== orig2.label || cur2.position !== orig2.position) {
        return true;
      }
    }
    return false;
  }

  function serializeForSave(draft) {
    return {
      active: draft.active.map(function (c) {
        return { id: c.id, label: c.label || c.display || '', display: c.display || c.label || '', position: c.position, colorKey: c.colorKey, archived: false };
      }),
      archived: draft.archived.map(function (c) {
        return { id: c.id, label: c.label || c.display || '', display: c.display || c.label || '', position: c.position, colorKey: c.colorKey, archived: true };
      }),
      labelOverrides: draft.labelOverrides,
      archiveDestinations: draft.archiveDestinations,
      resetPending: draft.resetPending,
      resetDestination: draft.resetDestination,
      baseVersion: draft.original.fingerprint
    };
  }

  // Expose a minimal API.
  global.WorkflowDraft = {
    DEFAULT_COLUMN_ORDER: DEFAULT_COLUMN_ORDER,
    createDraft: createDraft,
    addColumn: addColumn,
    renameColumn: renameColumn,
    moveColumn: moveColumn,
    archiveColumn: archiveColumn,
    removeColumn: removeColumn,
    restoreColumn: restoreColumn,
    undoArchive: undoArchive,
    resetToDefaults: resetToDefaults,
    restoreSnapshot: restoreSnapshot,
    isDirty: isDirty,
    serializeForSave: serializeForSave,
    isTempId: isTempId,
    makeTempId: makeTempId,
    findByIdInDraft: findByIdInDraft,
    isCanonical: isCanonical,
    isDefaultWorkflow: isDefaultWorkflow,
    sortByPersistedPosition: sortByPersistedPosition,
    reindexPositions: reindexPositions
  };
})(window);
