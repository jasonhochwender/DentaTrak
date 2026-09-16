/**
 * Shared "Case actions" menu for kanban board cards and list-view rows.
 *
 * A single fixed-position menu element appended to <body> serves every
 * .case-actions-toggle button, so menus are never clipped by scrollable
 * board/list containers and only one menu can be open at a time.
 *
 * Actions reuse the existing implementations:
 *   Edit    -> window.editCaseHandler / window.openCaseById (archived cases)
 *   Print   -> window.printCase
 *   Archive -> window.showDeleteConfirmation + window.deleteCase
 *   Move to -> window.updateCardStatus (phone-width board cards only,
 *              where drag-and-drop is unavailable)
 *
 * Archive visibility follows the existing "Allow archiving of individual
 * cases" preference via the .allow-card-delete container class; the server
 * still enforces it in api/delete-case.php.
 */
(function () {
  'use strict';

  var t = window.t || function (k) { return k; };

  var menu = null;
  var currentTrigger = null;
  var currentCtx = null; // { kind: 'card'|'row', caseId: string, card: Element|null, row: Element|null }

  function isPhone() {
    return window.matchMedia('(max-width: 480px)').matches;
  }

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
  }

  /**
   * The "Allow archiving of individual cases" preference is applied as an
   * .allow-card-delete class on .main-container / .kanban-board / .dashboard.
   */
  function isArchiveAllowed() {
    return !!document.querySelector(
      '.main-container.allow-card-delete, .kanban-board.allow-card-delete, .dashboard.allow-card-delete'
    );
  }

  function findCard(caseId) {
    return document.querySelector('.kanban-card[data-case-id="' + caseId + '"]');
  }

  function getCardData(card) {
    var raw = card ? card.dataset.caseJson : '';
    try {
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      return {};
    }
  }

  function ensureMenu() {
    if (menu && menu.parentNode) return;
    menu = document.createElement('div');
    menu.id = 'caseActionsMenu';
    menu.className = 'case-actions-menu';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-hidden', 'true');
    document.body.appendChild(menu);

    menu.addEventListener('click', function (e) {
      var item = e.target.closest('[data-action]');
      if (!item || item.disabled) return;
      runAction(item.dataset.action);
    });

    menu.addEventListener('change', function (e) {
      var select = e.target.closest('.case-actions-move-select');
      if (!select || !currentCtx) return;
      var newStatus = select.value;
      select.value = '';
      if (!newStatus) return;
      var ctx = currentCtx;
      close(false);
      moveCase(ctx, newStatus);
    });

    menu.addEventListener('keydown', onMenuKeydown);
  }

  function focusableItems() {
    return menu ? Array.from(menu.querySelectorAll('.case-actions-menu-item, .case-actions-move-select')) : [];
  }

  function onMenuKeydown(e) {
    var items = focusableItems();
    if (!items.length) return;
    var idx = items.indexOf(document.activeElement);

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      // Let selects keep their native arrow behavior.
      if (document.activeElement && document.activeElement.tagName === 'SELECT') return;
      e.preventDefault();
      var next = e.key === 'ArrowDown'
        ? (idx < 0 ? 0 : (idx + 1) % items.length)
        : (idx <= 0 ? items.length - 1 : idx - 1);
      items[next].focus();
    } else if (e.key === 'Home') {
      e.preventDefault();
      items[0].focus();
    } else if (e.key === 'End') {
      e.preventDefault();
      items[items.length - 1].focus();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      close(true);
    } else if (e.key === 'Tab') {
      close(false);
    }
  }

  function buildMenuItems(ctx) {
    var cardData = ctx.card ? getCardData(ctx.card) : {};
    var parts = [];

    // Phone-width board cards cannot be dragged, so they keep the Move to
    // control that the retired mobile card menu provided.
    if (ctx.kind === 'card' && isPhone()) {
      parts.push(
        '<div class="case-actions-menu-item case-actions-move">' +
          '<label class="case-actions-move-label">' + esc(t('cases.move_to')) + '</label>' +
          '<select class="case-actions-move-select" aria-label="' + esc(t('cases.move_to')) + '">' +
            '<option value="" selected>' + esc(t('select.select_status')) + '</option>' +
          '</select>' +
        '</div>'
      );
    }

    parts.push('<button type="button" class="case-actions-menu-item" data-action="edit" role="menuitem">' + esc(t('common.edit')) + '</button>');
    parts.push('<button type="button" class="case-actions-menu-item" data-action="print" role="menuitem">' + esc(t('common.print')) + '</button>');

    var showArchive = isArchiveAllowed() && !cardData.archived;
    if (showArchive) {
      parts.push('<div class="case-actions-separator" role="separator"></div>');
      parts.push('<button type="button" class="case-actions-menu-item case-actions-archive danger" data-action="archive" role="menuitem">' + esc(t('common.archive')) + '</button>');
    }

    menu.innerHTML = parts.join('');

    if (ctx.kind === 'card' && isPhone()) {
      populateMoveSelect(cardData.status || '');
    }
  }

  function populateMoveSelect(currentStatus) {
    var select = menu.querySelector('.case-actions-move-select');
    if (!select) return;
    var opts = '<option value="" selected>' + esc(t('select.select_status')) + '</option>';
    document.querySelectorAll('#kanbanBoard .kanban-column').forEach(function (col) {
      if (col.dataset.status === currentStatus) return;
      var title = col.querySelector('.kanban-column-title');
      var label = title ? title.textContent : col.dataset.status;
      opts += '<option value="' + esc(col.dataset.status) + '">' + esc(label) + '</option>';
    });
    select.innerHTML = opts;
  }

  function positionMenu(anchor) {
    var rect = anchor.getBoundingClientRect();
    menu.style.visibility = 'hidden';
    menu.classList.add('open');
    var menuRect = menu.getBoundingClientRect();
    var viewportWidth = window.innerWidth;
    var viewportHeight = window.innerHeight;

    var top = rect.bottom + 4;
    var left = rect.right - menuRect.width; // right-align to the toggle

    if (left < 8) left = 8;
    if (left + menuRect.width > viewportWidth - 8) {
      left = viewportWidth - menuRect.width - 8;
    }
    if (top + menuRect.height > viewportHeight - 8) {
      top = rect.top - menuRect.height - 4; // flip above when no room below
    }
    if (top < 8) top = 8;

    menu.style.top = top + 'px';
    menu.style.left = left + 'px';
    menu.style.visibility = '';
  }

  function open(trigger) {
    var caseId = trigger.getAttribute('data-case-id');
    if (!caseId) return;

    ensureMenu();
    close(false);

    var card = findCard(caseId);
    var row = trigger.closest('.case-list-row');
    currentCtx = {
      kind: trigger.closest('.kanban-card') ? 'card' : 'row',
      caseId: caseId,
      card: card,
      row: row
    };
    currentTrigger = trigger;

    buildMenuItems(currentCtx);

    trigger.setAttribute('aria-expanded', 'true');
    trigger.setAttribute('aria-controls', 'caseActionsMenu');
    menu.setAttribute('aria-hidden', 'false');

    positionMenu(trigger);

    var first = focusableItems().filter(function (el) { return el.tagName !== 'SELECT'; })[0] || focusableItems()[0];
    if (first) first.focus();
  }

  function close(restoreFocus) {
    if (!menu) return;
    var trigger = currentTrigger;
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      trigger.removeAttribute('aria-controls');
    }
    currentTrigger = null;
    currentCtx = null;
    if (restoreFocus && trigger && trigger.isConnected) {
      trigger.focus();
    }
  }

  function toggleMenu(trigger) {
    if (currentTrigger === trigger && menu && menu.classList.contains('open')) {
      close(true);
    } else {
      open(trigger);
    }
  }

  function runAction(action) {
    var ctx = currentCtx;
    if (!ctx) return;
    var cardData = ctx.card ? getCardData(ctx.card) : {};
    var patientName = ((cardData.patientFirstName || '') + ' ' + (cardData.patientLastName || '')).trim();

    if (action === 'edit') {
      close(false);
      if (window.isPrintingCase) return;
      // Same routing as case-list openRowCase(): the card payload goes
      // straight to editCaseHandler; archived cases need openCaseById's
      // read-only/archived handling.
      if (cardData && cardData.id && !cardData.archived && typeof window.editCaseHandler === 'function') {
        window.editCaseHandler(cardData);
      } else if (typeof window.openCaseById === 'function') {
        window.openCaseById(ctx.caseId, { tab: 'details' });
      }
    } else if (action === 'print') {
      close(true);
      if (typeof window.printCase === 'function') window.printCase(cardData);
    } else if (action === 'archive') {
      close(false);
      if (window.isPrintingCase) return;
      var caseId = (cardData && cardData.id) || ctx.caseId;
      if (!caseId) return;
      if (!patientName && ctx.row) {
        var nameEl = ctx.row.querySelector('.case-list-open');
        if (nameEl) patientName = nameEl.textContent.trim();
      }
      // Prefer the card element for removal: it is the source of truth that
      // collectCases() reads, and its removal triggers the cardsUpdated
      // refresh that removes the list row too.
      var el = ctx.card || ctx.row;
      if (typeof window.showDeleteConfirmation === 'function') {
        window.showDeleteConfirmation(el, patientName, function () {
          if (typeof window.deleteCase === 'function') window.deleteCase(caseId, el);
        });
      } else if (typeof window.deleteCase === 'function') {
        window.deleteCase(caseId, el);
      }
    }
  }

  function moveCase(ctx, newStatus) {
    if (!ctx || !ctx.card) return;
    var target = null;
    document.querySelectorAll('#kanbanBoard .kanban-column').forEach(function (col) {
      if (col.dataset.status === newStatus) target = col;
    });
    if (!target || typeof window.updateCardStatus !== 'function') return;
    window.updateCardStatus(ctx.card, getCardData(ctx.card), newStatus, target.querySelector('.kanban-column-body'));
  }

  function init() {
    // Open/close via the trigger button (delegated so re-rendered cards and
    // rows never need rebinding).
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest ? e.target.closest('.case-actions-toggle') : null;
      if (trigger) {
        e.preventDefault();
        e.stopPropagation();
        toggleMenu(trigger);
        return;
      }
      if (menu && menu.classList.contains('open') && !e.target.closest('.case-actions-menu')) {
        close(false);
      }
    });

    // The toggle lives inside a draggable card: swallow drag initiation and
    // the card's mousedown bookkeeping in the capture phase so clicking the
    // button can never start a board drag.
    document.addEventListener('dragstart', function (e) {
      if (e.target.closest && e.target.closest('.case-actions-toggle')) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);

    document.addEventListener('mousedown', function (e) {
      if (e.target.closest && e.target.closest('.case-actions-toggle')) {
        e.stopPropagation();
      }
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu && menu.classList.contains('open')) {
        close(true);
      }
    });

    // A fixed-position menu detaches from its anchor when anything scrolls
    // or the viewport resizes - keep it glued to the trigger instead of
    // closing (a smooth scroll can otherwise kill a menu that was just
    // opened by the click that triggered the scroll).
    function repositionOrClose() {
      if (!menu || !menu.classList.contains('open')) return;
      if (currentTrigger && currentTrigger.isConnected) {
        positionMenu(currentTrigger);
      } else {
        close(false);
      }
    }
    window.addEventListener('scroll', repositionOrClose, true);
    window.addEventListener('resize', repositionOrClose);

    // Re-rendering cards/rows orphans the anchor - close the open menu.
    window.addEventListener('cardsLoaded', function () { close(false); });
    window.addEventListener('cardsUpdated', function () { close(false); });
  }

  window.caseActionsMenu = {
    open: open,
    close: close,
    isOpen: function () { return !!(menu && menu.classList.contains('open')); }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
