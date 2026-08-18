(function() {
  'use strict';

  var DESKTOP_MIN_WIDTH = 1024;

  if (typeof Shepherd === 'undefined') {
    return;
  }

  var tour;
  var tourSaveInProgress = false;
  var openedSettings = false;
  var openedInsightsTab = null;

  function attemptAutoStartTour() {
    if (window.tourCompleted !== false || !isDesktop()) {
      return;
    }
    initTour();
  }

  window.addEventListener('toursettingsloaded', attemptAutoStartTour);

  if (window.tourSettingsLoaded) {
    attemptAutoStartTour();
  }

  function isDesktop() {
    return window.innerWidth >= DESKTOP_MIN_WIDTH;
  }

  function isVisible(el) {
    if (!el) {
      return false;
    }
    if (el.offsetParent === null) {
      return false;
    }
    var rects = el.getClientRects();
    if (!rects || rects.length === 0) {
      return false;
    }
    var rect = el.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) {
      return false;
    }
    var style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity) === 0) {
      return false;
    }
    return true;
  }

  function raf(n) {
    return new Promise(function(resolve) {
      var count = 0;
      function step() {
        count++;
        if (count >= n) {
          resolve();
        } else {
          requestAnimationFrame(step);
        }
      }
      requestAnimationFrame(step);
    });
  }

  function closeSettingsModal() {
    return new Promise(function(resolve) {
      if (!openedSettings) {
        resolve();
        return;
      }
      var closeBtn = document.getElementById('settingsBillingClose');
      if (closeBtn) {
        closeBtn.click();
      }
      openedSettings = false;
      raf(1).then(resolve);
    });
  }

  function openSettingsModal() {
    return new Promise(function(resolve) {
      var settingsMenuItem = document.getElementById('settingsMenuItem');
      if (settingsMenuItem) {
        settingsMenuItem.click();
        openedSettings = true;
      }
      raf(1).then(resolve);
    });
  }

  function navigateSettingsToAuthorized() {
    var nav = document.querySelector('.settings-nav-item[data-nav-target="authorized"]');
    if (nav) {
      nav.click();
    }
    var twisty = document.querySelector('.settings-twisty[data-twisty-id="authorized"]');
    if (twisty && !twisty.classList.contains('open')) {
      var header = twisty.querySelector('.settings-twisty-header');
      if (header) {
        header.click();
      }
    }
  }

  function scrollSettingsTo(selector) {
    return new Promise(function(resolve) {
      var scrollContainer = document.querySelector('#settingsBillingModal .tab-content-scroll');
      var el = document.querySelector(selector);
      if (!scrollContainer || !el) {
        resolve();
        return;
      }
      var containerRect = scrollContainer.getBoundingClientRect();
      var elRect = el.getBoundingClientRect();
      var target = scrollContainer.scrollTop + (elRect.top - containerRect.top) - 16;
      var maxScroll = scrollContainer.scrollHeight - scrollContainer.clientHeight;
      if (target < 0) {
        target = 0;
      }
      if (maxScroll > 0 && target > maxScroll) {
        target = maxScroll;
      }
      scrollContainer.scrollTop = target;
      raf(1).then(resolve);
    });
  }

  function openUserMenu() {
    if (typeof window.openUserMenu === 'function') {
      window.openUserMenu();
    } else {
      var toggle = document.getElementById('userMenuToggle');
      if (toggle) {
        toggle.click();
      }
    }
  }

  function closeUserMenu() {
    return new Promise(function(resolve) {
      if (typeof window.closeUserMenu === 'function') {
        window.closeUserMenu();
      } else {
        var menu = document.getElementById('userMenu');
        var toggle = document.getElementById('userMenuToggle');
        if (menu && menu.classList.contains('open')) {
          menu.classList.remove('open');
          if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
          }
        }
      }
      raf(1).then(resolve);
    });
  }

  function goToCasesTab() {
    var tab = document.querySelector('.main-tab[data-tab="cases"]');
    if (tab && !tab.classList.contains('active')) {
      tab.click();
    }
    openedInsightsTab = null;
    return raf(1);
  }

  function goToInsightsTab() {
    var tab = document.querySelector('.main-tab[data-tab="insights"]');
    if (tab) {
      tab.click();
    }
    openedInsightsTab = 'insights';
    return raf(1);
  }

  function goToLabInsightsTab() {
    var tab = document.querySelector('.main-tab[data-tab="lab-insights"]');
    if (tab) {
      tab.click();
    }
    openedInsightsTab = 'lab-insights';
    return raf(1);
  }

  function mainViewBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeSettingsModal)
      .then(closeUserMenu)
      .then(goToCasesTab);
  }

  function insightsBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeSettingsModal)
      .then(closeUserMenu)
      .then(goToInsightsTab);
  }

  function labInsightsBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeSettingsModal)
      .then(closeUserMenu)
      .then(goToLabInsightsTab);
  }

  function practiceSettingsBefore() {
    window.tourKeepsUserMenuOpen = true;
    return Promise.resolve()
      .then(closeSettingsModal)
      .then(goToCasesTab)
      .then(function() {
        openUserMenu();
        return raf(1);
      });
  }

  function settingsBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeUserMenu)
      .then(openSettingsModal)
      .then(function() {
        navigateSettingsToAuthorized();
        return raf(1);
      });
  }

  function labUserBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeUserMenu)
      .then(openSettingsModal)
      .then(function() {
        navigateSettingsToAuthorized();
        return scrollSettingsTo('.practice-user-lab-header');
      });
  }

  function assignmentLabelsBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeUserMenu)
      .then(openSettingsModal)
      .then(function() {
        navigateSettingsToAuthorized();
        return scrollSettingsTo('.assignment-labels-section');
      });
  }

  function shareFeedbackBefore() {
    window.tourKeepsUserMenuOpen = true;
    return Promise.resolve()
      .then(closeSettingsModal)
      .then(goToCasesTab)
      .then(function() {
        openUserMenu();
        return raf(1);
      });
  }

  function finishBefore() {
    window.tourKeepsUserMenuOpen = false;
    return Promise.resolve()
      .then(closeSettingsModal)
      .then(closeUserMenu)
      .then(goToCasesTab);
  }

  function offsetModifier(x, y) {
    return {
      modifiers: [{
        name: 'offset',
        options: { offset: [x || 0, y || 16] }
      }]
    };
  }

  function buildTourSteps() {
    var steps = [];

    steps.push({
      id: 'welcome',
      title: 'Welcome to DentaTrak',
      text: "Here's a quick overview of the tools you'll use to manage cases and keep work moving.",
      buttons: [
        {
          text: 'Skip Tour',
          action: function() { tour.complete(); },
          secondary: true
        },
        {
          text: 'Start Tour',
          action: function() { tour.next(); }
        }
      ]
    });

    if (document.querySelector('.kanban-board')) {
      steps.push({
        id: 'cases-board',
        title: 'Your Cases Board',
        text: 'Cases are organized into workflow stages. Move a case from one column to another as work progresses.',
        attachTo: {
          element: '.kanban-board',
          on: 'top-start'
        },
        beforeShowPromise: mainViewBefore,
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.querySelector('.create-case-button')) {
      steps.push({
        id: 'create-case',
        title: 'Create a Case',
        text: 'Add a case here, including patient and dentist information, case details, due dates, assignments, and files.',
        attachTo: {
          element: '.create-case-button',
          on: 'bottom-start'
        },
        beforeShowPromise: mainViewBefore,
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.getElementById('kanbanFilterToggle')) {
      steps.push({
        id: 'search-filter',
        title: 'Search & Filter',
        text: 'Quickly find cases or narrow the board by patient, dentist, case type, assignment, carrier, due status, and other criteria.',
        attachTo: {
          element: '#kanbanFilterToggle',
          on: 'bottom'
        },
        beforeShowPromise: mainViewBefore,
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.querySelector('.main-tab[data-tab="insights"]')) {
      steps.push({
        id: 'practice-insights',
        title: 'Practice Insights',
        text: 'See workload, turnaround times, case activity, user activity, and other trends across your practice.',
        attachTo: {
          element: '.main-tab[data-tab="insights"]',
          on: 'bottom-start'
        },
        beforeShowPromise: insightsBefore,
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.querySelector('.main-tab[data-tab="lab-insights"]')) {
      steps.push({
        id: 'lab-insights',
        title: 'Lab Insights',
        text: 'Review lab workload and performance to understand where cases are currently assigned and how work is moving through your labs.',
        attachTo: {
          element: '.main-tab[data-tab="lab-insights"]',
          on: 'bottom-start'
        },
        beforeShowPromise: labInsightsBefore,
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.getElementById('settingsMenuItem')) {
      steps.push({
        id: 'practice-settings',
        title: 'Practice Settings',
        text: 'Manage your practice, users, permissions, assignment labels, security, and other DentaTrak preferences from Settings.',
        attachTo: {
          element: '#settingsMenuItem',
          on: 'left-start'
        },
        beforeShowPromise: practiceSettingsBefore,
        scrollTo: false,
        popperOptions: offsetModifier(-16, 0),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.getElementById('settingsMenuItem')) {
      steps.push({
        id: 'practice-users',
        title: 'Practice Users & Roles',
        text: 'Add people to your practice and control what they can access and do in DentaTrak.',
        attachTo: {
          element: '.settings-twisty[data-twisty-id="authorized"] .settings-twisty-header',
          on: 'right-start'
        },
        beforeShowPromise: settingsBefore,
        scrollTo: false,
        popperOptions: offsetModifier(16, 0),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.getElementById('settingsMenuItem')) {
      steps.push({
        id: 'add-users',
        title: 'Add Users',
        text: 'Invite team members and choose the access they need. Permissions let you control administrative access, Insights access, case editing, assignment visibility, and other capabilities.',
        attachTo: {
          element: '#addGmailUser',
          on: 'bottom'
        },
        beforeShowPromise: settingsBefore,
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.querySelector('.practice-user-lab-header')) {
      steps.push({
        id: 'lab-user',
        title: 'Lab User',
        text: 'Mark a user as Lab when they represent an internal or external laboratory. Cases assigned to Lab users are included in Lab Insights so you can track lab workload and performance.',
        attachTo: {
          element: '.practice-user-lab-header',
          on: 'left-start'
        },
        beforeShowPromise: labUserBefore,
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.getElementById('newAssignmentLabel')) {
      steps.push({
        id: 'assignment-labels',
        title: 'Assignment Labels',
        text: 'Create reusable assignment labels for work that is not tied to a specific person, such as an outside lab, department, or workflow responsibility. Labels can also represent labs for Lab Insights.',
        attachTo: {
          element: '.assignment-labels-section',
          on: 'top'
        },
        beforeShowPromise: assignmentLabelsBefore,
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    if (document.getElementById('contactUsLink')) {
      steps.push({
        id: 'share-feedback',
        title: 'Share Feedback',
        text: 'Have an idea, found an issue, or need help? Send feedback directly from DentaTrak.',
        attachTo: {
          element: '#contactUsLink',
          on: 'bottom-start'
        },
        beforeShowPromise: shareFeedbackBefore,
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: function() { tour.back(); }, secondary: true },
          { text: 'Next', action: function() { tour.next(); } }
        ]
      });
    }

    steps.push({
      id: 'finish',
      title: "You're Ready",
      text: 'You can restart the tour any time from your profile menu. Enjoy using DentaTrak!',
      beforeShowPromise: finishBefore,
      buttons: [
        { text: 'Finish', action: function() { tour.complete(); } }
      ]
    });

    return steps;
  }

  function cleanupTour() {
    window.tourKeepsUserMenuOpen = false;
    closeSettingsModal();
    closeUserMenu();
    var casesTab = document.querySelector('.main-tab[data-tab="cases"]');
    if (casesTab && !casesTab.classList.contains('active')) {
      casesTab.click();
    }
    openedInsightsTab = null;
  }

  function completeTour() {
    if (window.tourCompleted === true || tourSaveInProgress) {
      return;
    }
    tourSaveInProgress = true;

    var csrfToken = '';
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) {
      csrfToken = meta.content;
    }

    fetch('api/save-tour-completed.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tourCompleted: true, csrf_token: csrfToken })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      tourSaveInProgress = false;
      if (data && data.success) {
        window.tourCompleted = true;
      }
    })
    .catch(function() {
      tourSaveInProgress = false;
    });
  }

  function initTour(manual) {
    if (!manual && window.tourCompleted !== false) {
      return;
    }

    tour = new Shepherd.Tour({
      useModalOverlay: true,
      defaultStepOptions: {
        classes: 'shepherd-theme-custom',
        scrollTo: false,
        cancelIcon: {
          enabled: true
        },
        modalOverlayOpeningPadding: 8,
        modalOverlayOpeningRadius: 8
      }
    });

    tour.on('show', function() {
      var currentStep = tour.getCurrentStep();
      var el = currentStep && currentStep.el;
      if (el) {
        el.style.visibility = 'hidden';
        el.style.opacity = '0';
        raf(2).then(function() {
          if (el.parentNode) {
            el.style.visibility = 'visible';
            el.style.opacity = '1';
          }
        });
      }
    });

    tour.on('complete', function() {
      completeTour();
      cleanupTour();
    });

    tour.on('cancel', function() {
      completeTour();
      cleanupTour();
    });

    var steps = buildTourSteps();
    steps.forEach(function(step) {
      tour.addStep(step);
    });

    tour.start();
  }

  window.startAppTour = function() {
    if (!isDesktop()) {
      if (typeof showToast === 'function') {
        showToast('The guided tour is available on a larger screen.', 'info');
      }
      return;
    }

    openedSettings = false;
    openedInsightsTab = null;
    initTour(true);
  };
})();
