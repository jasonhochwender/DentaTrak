/**
 * Mobile Kanban board navigation.
 *
 * On phone-width viewports this module:
 * - Shows one workflow column at a time with prev/next/selector controls.
 * - Keeps the selector and scroll position in sync.
 * - Disables drag-and-drop on phones; status changes and card actions live
 *   in the shared Case actions menu (js/case-actions-menu.js), which also
 *   provides the Move to control on phones.
 *
 * On desktop it is a no-op.
 */
(function () {
  'use strict';

  var board;
  var nav;
  var prevBtn;
  var nextBtn;
  var columnSelect;
  var announcer;

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

    if (scrollTimeout) clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(function () {
      var index = getActiveIndex();
      if (index >= 0) updateNav(index);
    }, 100);
  }

  function onResize() {
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

    // Let the card's own buttons (actions toggle, review badge) and selects
    // handle themselves.
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

    board.addEventListener('click', function (e) {
      if (!isPhone()) return;
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
    isPhone: isPhone
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
