/**
 * Mobile Kanban board navigation and card actions.
 *
 * On phone-width viewports this module:
 * - Shows one workflow column at a time with prev/next/selector controls.
 * - Keeps the selector and scroll position in sync.
 * - Adds a fixed-position action menu to each kanban card.
 * - Disables drag-and-drop on phones; status changes use the existing
 *   API-driven update path.
 *
 * On desktop it is a no-op.
 */
(function () {
  'use strict';

  var t = window.t || function (k) { return k; };

  var board;
  var nav;
  var prevBtn;
  var nextBtn;
  var columnSelect;
  var announcer;
  var menu;

  var currentCard = null;
  var currentAnchor = null;
  var resizeTimeout = null;
  var scrollTimeout = null;
  var programmaticScroll = false;

  var initialized = false;

  function isPhone() {
    return window.matchMedia('(max-width: 480px)').matches;
  }

  function getColumns() {
    return Array.from(board ? board.querySelectorAll('.kanban-column') : []);
  }

  function getColumnByStatus(status) {
    return getColumns().find(function (col) { return col.dataset.status === status; }) || null;
  }

  function getActiveIndex() {
    var columns = getColumns();
    if (!columns.length || !board) return -1;
    var boardRect = board.getBoundingClientRect();
    var boardCenter = boardRect.left + boardRect.width / 2;
    var closest = -1;
    var minDist = Infinity;
    columns.forEach(function (col, i) {
      var r = col.getBoundingClientRect();
      var colCenter = r.left + r.width / 2;
      var dist = Math.abs(colCenter - boardCenter);
      if (dist < minDist) {
        minDist = dist;
        closest = i;
      }
    });
    return closest;
  }

  function updateNav(index) {
    var columns = getColumns();
    if (!columns.length) return;
    if (index < 0) index = 0;
    if (index >= columns.length) index = columns.length - 1;

    if (prevBtn) prevBtn.disabled = index === 0;
    if (nextBtn) nextBtn.disabled = index === columns.length - 1;

    if (columnSelect) {
      var status = columns[index].dataset.status;
      if (columnSelect.value !== status) {
        columnSelect.value = status;
      }
    }

    var active = columns[index];
    var title = active.querySelector('.kanban-column-title');
    var label = (title ? title.textContent : active.dataset.status) || '';
    if (announcer) announcer.textContent = label;
  }

  function scrollToColumn(col, animate) {
    if (!board || !col) return;
    var left = col.offsetLeft;
    programmaticScroll = true;
    var duration = (animate === false) ? 50 : 350;
    if (animate === false) {
      board.scrollLeft = left;
    } else {
      board.style.scrollBehavior = 'smooth';
      board.scrollLeft = left;
      setTimeout(function () {
        board.style.scrollBehavior = '';
      }, 300);
    }
    setTimeout(function () {
      programmaticScroll = false;
    }, duration);
  }

  function goToColumn(index, animate) {
    var columns = getColumns();
    if (index < 0 || index >= columns.length) return;
    scrollToColumn(columns[index], animate);
    updateNav(index);
  }

  function saveActiveColumn(status) {
    if (!isPhone() || !columnSelect) return;
    if (status && getColumnByStatus(status)) {
      columnSelect.value = status;
    } else {
      var index = getActiveIndex();
      var columns = getColumns();
      if (index >= 0 && columns[index]) {
        columnSelect.value = columns[index].dataset.status;
      }
    }
  }

  function restoreActiveColumn(animate) {
    if (!isPhone() || !columnSelect) return;
    var saved = columnSelect.value;
    var target = saved ? getColumnByStatus(saved) : null;
    if (!target) {
      var columns = getColumns();
      target = columns[0];
    }
    if (target) {
      scrollToColumn(target, animate === false ? false : true);
      updateNav(getColumns().indexOf(target));
    }
  }

  function onBoardScroll() {
    if (programmaticScroll) return;
    if (currentCard) hideMenu();

    if (scrollTimeout) clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(function () {
      var index = getActiveIndex();
      if (index >= 0) updateNav(index);
    }, 100);
  }

  function onResize() {
    if (currentCard) hideMenu();
    if (!isPhone()) return;
    if (resizeTimeout) clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function () {
      restoreActiveColumn(false);
    }, 150);
  }

  function getCardData(card) {
    var raw = card ? card.dataset.caseJson : '';
    try {
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      return {};
    }
  }

  function onCardClick(e) {
    if (!isPhone()) return;
    var card = e.target.closest('.kanban-card');
    if (!card) return;

    // Let the card's own edit/archive buttons and the menu toggle handle themselves.
    if (e.target.closest('button') || e.target.closest('select')) return;

    e.preventDefault();
    e.stopPropagation();

    var cardData = getCardData(card);
    var column = card ? card.closest('.kanban-column') : null;
    saveActiveColumn(column ? column.dataset.status : null);
    if (window.editCaseHandler) {
      window.editCaseHandler(cardData);
    }
  }

  function ensureMenu() {
    if (menu && menu.parentNode) return;
    menu = document.createElement('div');
    menu.id = 'kanbanCardMobileMenu';
    menu.className = 'kanban-card-mobile-menu';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-hidden', 'true');
    menu.innerHTML =
      '<button type="button" class="mobile-card-menu-item" data-action="edit" role="menuitem">' +
        t('common.view') + ' / ' + t('common.edit') +
      '</button>' +
      '<div class="mobile-card-menu-item" data-action="move" role="menuitem">' +
        '<label class="mobile-card-move-label">' + t('cases.move_to') + '</label>' +
        '<select class="mobile-card-move-select" aria-label="' + t('cases.move_to') + '">' +
          '<option value="" selected>Select status...</option>' +
        '</select>' +
      '</div>' +
      '<button type="button" class="mobile-card-menu-item" data-action="print" role="menuitem">' + t('common.print') + '</button>' +
      '<button type="button" class="mobile-card-menu-item mobile-card-menu-archive danger" data-action="archive" role="menuitem">' + t('common.archive') + '</button>';

    document.body.appendChild(menu);

    menu.addEventListener('click', function (e) {
      var item = e.target.closest('[data-action]');
      if (!item) return;

      var action = item.dataset.action;
      var card = currentCard;
      if (!card) return;

      if (action === 'edit') {
        hideMenu();
        var editColumn = card ? card.closest('.kanban-column') : null;
        saveActiveColumn(editColumn ? editColumn.dataset.status : null);
        if (window.editCaseHandler) window.editCaseHandler(getCardData(card));
      } else if (action === 'print') {
        hideMenu();
        if (window.printCase) window.printCase(getCardData(card));
      } else if (action === 'archive') {
        hideMenu();
        var archiveCardData = getCardData(card);
        var patientName = (archiveCardData.patientFirstName || '') + ' ' + (archiveCardData.patientLastName || '');
        if (window.showDeleteConfirmation) {
          window.showDeleteConfirmation(card, patientName.trim(), function () {
            if (window.deleteCase) window.deleteCase(archiveCardData.id, card);
          });
        } else if (window.deleteCase) {
          window.deleteCase(archiveCardData.id, card);
        }
      }
    });

    menu.addEventListener('change', function (e) {
      var select = e.target.closest('.mobile-card-move-select');
      if (!select || !currentCard) return;

      var newStatus = select.value;
      if (!newStatus) return;

      var target = getColumnByStatus(newStatus);
      if (!target) {
        select.value = '';
        return;
      }

      var cardToMove = currentCard;
      hideMenu();

      if (window.updateCardStatus && cardToMove) {
        window.updateCardStatus(cardToMove, getCardData(cardToMove), newStatus, target.querySelector('.kanban-column-body'));
      }
    });
  }

  function populateMoveSelect(currentStatus) {
    if (!menu) return;
    var select = menu.querySelector('.mobile-card-move-select');
    if (!select) return;

    var columns = getColumns();
    var opts = '<option value="" selected>Select status...</option>';
    columns.forEach(function (col) {
      if (col.dataset.status === currentStatus) return;
      var title = col.querySelector('.kanban-column-title');
      var label = title ? title.textContent : col.dataset.status;
      opts += '<option value="' + escapeHtml(col.dataset.status) + '">' + escapeHtml(label) + '</option>';
    });
    select.innerHTML = opts;
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }

  function positionMenu(anchor) {
    if (!menu || !anchor) return;
    var rect = anchor.getBoundingClientRect();
    var menuRect = menu.getBoundingClientRect();
    var viewportWidth = window.innerWidth;
    var viewportHeight = window.innerHeight;

    var top = rect.bottom + 4;
    var left = rect.left + rect.width / 2 - menuRect.width / 2;

    if (left < 8) left = 8;
    if (left + menuRect.width > viewportWidth - 8) {
      left = viewportWidth - menuRect.width - 8;
    }

    if (top + menuRect.height > viewportHeight - 8) {
      top = rect.top - menuRect.height - 4;
    }
    if (top < 8) top = 8;

    menu.style.top = top + 'px';
    menu.style.left = left + 'px';
  }

  function showMenu(card, anchor) {
    if (!card || !anchor) return;
    ensureMenu();
    hideMenu();

    currentCard = card;
    currentAnchor = anchor;
    populateMoveSelect(getCardData(card).status || '');

    anchor.setAttribute('aria-expanded', 'true');
    menu.setAttribute('aria-hidden', 'false');
    menu.classList.add('open');

    // Show archive only when the board allows it (same allow-card-delete flag
    // that controls the desktop archive button).
    var archiveItem = menu.querySelector('.mobile-card-menu-archive');
    if (archiveItem) {
      archiveItem.style.display = board && board.classList.contains('allow-card-delete') ? 'flex' : 'none';
    }

    positionMenu(anchor);
  }

  function hideMenu() {
    if (!menu) return;
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    if (currentAnchor) currentAnchor.setAttribute('aria-expanded', 'false');
    currentCard = null;
    currentAnchor = null;
  }

  function init() {
    if (initialized) return;
    initialized = true;

    board = document.getElementById('kanbanBoard');
    nav = document.getElementById('mobileKanbanNav');
    prevBtn = document.getElementById('mobileKanbanPrev');
    nextBtn = document.getElementById('mobileKanbanNext');
    columnSelect = document.getElementById('mobileKanbanSelect');
    announcer = document.getElementById('kanbanNavAnnouncer');

    if (!board) return;

    board.addEventListener('scroll', onBoardScroll, { passive: true });

    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        if (!isPhone()) return;
        var idx = getActiveIndex();
        if (idx > 0) goToColumn(idx - 1, true);
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (!isPhone()) return;
        var idx = getActiveIndex();
        var columns = getColumns();
        if (idx < columns.length - 1) goToColumn(idx + 1, true);
      });
    }

    if (columnSelect) {
      columnSelect.addEventListener('change', function () {
        if (!isPhone()) return;
        var col = getColumnByStatus(this.value);
        if (col) scrollToColumn(col, true);
      });
    }

    document.addEventListener('click', function (e) {
      if (currentCard && !e.target.closest('.kanban-card-mobile-menu') && !e.target.closest('.kanban-card-mobile-menu-toggle')) {
        hideMenu();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && currentCard) {
        hideMenu();
      }
    });

    board.addEventListener('click', function (e) {
      if (!isPhone()) return;
      var toggle = e.target.closest('.kanban-card-mobile-menu-toggle');
      if (toggle) {
        e.stopPropagation();
        var card = toggle.closest('.kanban-card');
        if (currentCard === card) {
          hideMenu();
        } else {
          showMenu(card, toggle);
        }
        return;
      }
      onCardClick(e);
    });

    window.addEventListener('cardsLoaded', function () {
      if (!isPhone()) return;
      requestAnimationFrame(function () {
        restoreActiveColumn(false);
      });
    });

    window.addEventListener('resize', onResize);

    if (isPhone()) {
      requestAnimationFrame(function () {
        restoreActiveColumn(false);
      });
    }
  }

  // Public API for tests and diagnostics.
  window.MobileKanban = {
    init: init,
    goToColumn: goToColumn,
    getActiveIndex: getActiveIndex,
    saveActiveColumn: saveActiveColumn,
    restoreActiveColumn: restoreActiveColumn,
    showMenu: showMenu,
    hideMenu: hideMenu,
    isPhone: isPhone
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
