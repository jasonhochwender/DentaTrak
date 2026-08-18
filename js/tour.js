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

  document.addEventListener('DOMContentLoaded', function() {
    var checkCount = 0;
    var maxChecks = 20;
    var checkInterval = setInterval(function() {
      checkCount++;
      if (typeof window.tourCompleted !== 'undefined') {
        clearInterval(checkInterval);
        setTimeout(maybeAutoStartTour, 500);
      } else if (checkCount >= maxChecks) {
        clearInterval(checkInterval);
        window.tourCompleted = false;
        setTimeout(maybeAutoStartTour, 500);
      }
    }, 250);
  });

  function isDesktop() {
    return window.innerWidth >= DESKTOP_MIN_WIDTH;
  }

  function maybeAutoStartTour() {
    if (window.tourCompleted === true) {
      return;
    }
    if (!isDesktop()) {
      return;
    }
    initTour();
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

  function waitForElement(selector, timeout) {
    return new Promise(function(resolve) {
      var el = document.querySelector(selector);
      if (el && isVisible(el)) {
        resolve(el);
        return;
      }

      var elapsed = 0;
      var interval = setInterval(function() {
        elapsed += 50;
        el = document.querySelector(selector);
        if (el && isVisible(el)) {
          clearInterval(interval);
          resolve(el);
          return;
        }
        if (elapsed >= timeout) {
          clearInterval(interval);
          resolve(null);
        }
      }, 50);
    });
  }

  function waitForHidden(selector, timeout) {
    return new Promise(function(resolve) {
      var el = document.querySelector(selector);
      if (!el || !isVisible(el)) {
        resolve();
        return;
      }

      var elapsed = 0;
      var interval = setInterval(function() {
        elapsed += 50;
        el = document.querySelector(selector);
        if (!el || !isVisible(el)) {
          clearInterval(interval);
          resolve();
          return;
        }
        if (elapsed >= timeout) {
          clearInterval(interval);
          resolve();
        }
      }, 50);
    });
  }

  function waitForActiveTab(tabName, timeout) {
    return waitForElement('.main-tab[data-tab="' + tabName + '"].active', timeout);
  }

  function openUserMenu() {
    return new Promise(function(resolve) {
      var menu = document.getElementById('userMenu');
      var toggle = document.getElementById('userMenuToggle');
      if (!menu || !toggle) {
        resolve(null);
        return;
      }
      if (menu.classList.contains('open')) {
        waitForElement('#userMenu.open', 300).then(resolve);
        return;
      }
      if (typeof window.openUserMenu === 'function') {
        window.openUserMenu();
      } else {
        toggle.click();
      }
      waitForElement('#userMenu.open', 1000).then(resolve);
    });
  }

  function closeUserMenu() {
    return new Promise(function(resolve) {
      var menu = document.getElementById('userMenu');
      var toggle = document.getElementById('userMenuToggle');
      if (!menu || !menu.classList.contains('open')) {
        resolve();
        return;
      }
      if (typeof window.closeUserMenu === 'function') {
        window.closeUserMenu();
      } else if (toggle) {
        toggle.click();
      }
      waitForHidden('#userMenu', 1000).then(resolve);
    });
  }

  function goToCasesTab() {
    return new Promise(function(resolve) {
      var tab = document.querySelector('.main-tab[data-tab="cases"]');
      if (tab && !tab.classList.contains('active')) {
        tab.click();
      }
      openedInsightsTab = null;
      waitForActiveTab('cases', 1000).then(resolve);
    });
  }

  function goToInsightsTab() {
    return new Promise(function(resolve) {
      var tab = document.querySelector('.main-tab[data-tab="insights"]');
      if (tab) {
        tab.click();
      }
      openedInsightsTab = 'insights';
      waitForActiveTab('insights', 1000).then(resolve);
    });
  }

  function goToLabInsightsTab() {
    return new Promise(function(resolve) {
      var tab = document.querySelector('.main-tab[data-tab="lab-insights"]');
      if (tab) {
        tab.click();
      }
      openedInsightsTab = 'lab-insights';
      waitForActiveTab('lab-insights', 1000).then(resolve);
    });
  }

  function openSettingsModal() {
    return new Promise(function(resolve) {
      if (!window.isPracticeAdmin) {
        resolve(false);
        return;
      }
      if (openedSettings) {
        resolve(true);
        return;
      }
      var menuItem = document.getElementById('settingsMenuItem');
      if (!menuItem) {
        resolve(false);
        return;
      }
      menuItem.click();
      openedSettings = true;
      resolve(true);
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
      } else {
        var modal = document.getElementById('settingsBillingModal');
        if (modal) {
          modal.style.display = 'none';
        }
        document.body.style.overflow = '';
      }
      openedSettings = false;

      var elapsed = 0;
      var interval = setInterval(function() {
        elapsed += 50;
        var modal = document.getElementById('settingsBillingModal');
        if (!modal || modal.style.display === 'none' || window.getComputedStyle(modal).display === 'none') {
          clearInterval(interval);
          resolve();
          return;
        }
        if (elapsed >= 1000) {
          clearInterval(interval);
          resolve();
        }
      }, 50);
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

  function prepareForStep(stepId) {
    if (stepId !== 'practice-settings' && stepId !== 'share-feedback') {
      window.tourKeepsUserMenuOpen = false;
    }
    switch (stepId) {
      case 'welcome':
        return closeSettingsModal().then(closeUserMenu).then(goToCasesTab).then(function() { return true; });
      case 'cases-board':
      case 'create-case':
      case 'search-filter':
        return closeSettingsModal().then(closeUserMenu).then(goToCasesTab).then(function() { return true; });
      case 'practice-insights':
        return closeSettingsModal().then(closeUserMenu).then(goToInsightsTab).then(function() { return true; });
      case 'lab-insights':
        return closeSettingsModal().then(closeUserMenu).then(goToLabInsightsTab).then(function() { return true; });
      case 'practice-settings':
        window.tourKeepsUserMenuOpen = true;
        return closeSettingsModal().then(goToCasesTab).then(openUserMenu).then(function(menu) {
          if (!menu) { window.tourKeepsUserMenuOpen = false; return false; }
          return waitForElement('#settingsMenuItem', 1000).then(function(el) {
            if (!el) { window.tourKeepsUserMenuOpen = false; return false; }
            return true;
          });
        });
      case 'practice-users':
        return closeUserMenu().then(openSettingsModal).then(function(ok) {
          if (!ok) { return false; }
          navigateSettingsToAuthorized();
          return waitForElement('.settings-twisty[data-twisty-id="authorized"] .settings-twisty-header', 1000).then(function(el) { return !!el; });
        });
      case 'add-users':
        return openSettingsModal().then(function(ok) {
          if (!ok) { return false; }
          navigateSettingsToAuthorized();
          return waitForElement('#addGmailUser', 1000).then(function(el) { return !!el; });
        });
      case 'lab-user':
        return openSettingsModal().then(function(ok) {
          if (!ok) { return false; }
          navigateSettingsToAuthorized();
          return waitForElement('.practice-user-lab-header', 1000).then(function(el) { return !!el; });
        });
      case 'assignment-labels':
        return openSettingsModal().then(function(ok) {
          if (!ok) { return false; }
          navigateSettingsToAuthorized();
          return waitForElement('#newAssignmentLabel', 1000).then(function(el) { return !!el; });
        });
      case 'share-feedback':
        window.tourKeepsUserMenuOpen = true;
        return closeSettingsModal().then(goToCasesTab).then(openUserMenu).then(function(menu) {
          if (!menu) { window.tourKeepsUserMenuOpen = false; return false; }
          return waitForElement('#contactUsLink', 1000).then(function(el) {
            if (!el) { window.tourKeepsUserMenuOpen = false; return false; }
            return true;
          });
        });
      case 'finish':
        return closeSettingsModal().then(closeUserMenu).then(goToCasesTab).then(function() { return true; });
      default:
        return Promise.resolve(true);
    }
  }

  function showStepById(stepId) {
    if (!tour) {
      return Promise.resolve(false);
    }
    return prepareForStep(stepId).then(function(ready) {
      if (ready) {
        tour.show(stepId);
        return true;
      }
      return false;
    });
  }

  function tryShowForward(startIdx) {
    if (!tour) {
      return;
    }
    if (startIdx >= tour.steps.length) {
      tour.complete();
      return;
    }
    var id = tour.steps[startIdx].id;
    showStepById(id).then(function(shown) {
      if (!shown) {
        tryShowForward(startIdx + 1);
      }
    });
  }

  function tryShowBackward(startIdx) {
    if (!tour) {
      return;
    }
    if (startIdx < 0) {
      return;
    }
    var id = tour.steps[startIdx].id;
    showStepById(id).then(function(shown) {
      if (!shown) {
        tryShowBackward(startIdx - 1);
      }
    });
  }

  function currentStepIndex() {
    if (!tour || !tour.getCurrentStep()) {
      return -1;
    }
    var currentId = tour.getCurrentStep().id;
    for (var i = 0; i < tour.steps.length; i++) {
      if (tour.steps[i].id === currentId) {
        return i;
      }
    }
    return -1;
  }

  function goToNext() {
    var idx = currentStepIndex();
    if (idx === -1) {
      tryShowForward(0);
      return;
    }
    tryShowForward(idx + 1);
  }

  function goToPrev() {
    var idx = currentStepIndex();
    if (idx === -1) {
      return;
    }
    tryShowBackward(idx - 1);
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
          action: function() {
            tour.complete();
          },
          secondary: true
        },
        {
          text: 'Start Tour',
          action: goToNext
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
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
        ]
      });
    }

    if (document.querySelector('#kanbanFilterToggle')) {
      steps.push({
        id: 'search-filter',
        title: 'Search & Filter',
        text: 'Quickly find cases or narrow the board by patient, dentist, case type, assignment, carrier, due status, and other criteria.',
        attachTo: {
          element: '#kanbanFilterToggle',
          on: 'bottom'
        },
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(-16, 0),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(16, 0),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
        ]
      });
    }

    if (document.querySelector('.practice-user-lab-header')) {
      steps.push({
        id: 'lab-user',
        title: 'Lab User',
        text: 'Mark a user as a Lab when they represent an internal or external laboratory. Lab users are used to power Lab Insights reporting, showing where cases are assigned and how work is moving through your labs.',
        attachTo: {
          element: '.practice-user-lab-header',
          on: 'bottom'
        },
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
        ]
      });
    }

    if (document.getElementById('newAssignmentLabel')) {
      steps.push({
        id: 'assignment-labels',
        title: 'Assignment Labels',
        text: 'Create assignment labels for work that is not tied to a specific person, such as an outside lab, department, or workflow responsibility. Labels can also be designated as Labs for Lab Insights reporting when that feature is enabled.',
        attachTo: {
          element: '#newAssignmentLabel',
          on: 'bottom'
        },
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
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
        scrollTo: false,
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: goToPrev, secondary: true },
          { text: 'Next', action: goToNext }
        ]
      });
    }

    steps.push({
      id: 'finish',
      title: "You're Ready",
      text: 'You can take this tour again anytime from your user menu. The DentaTrak User Guide is also available whenever you need more detail.',
      buttons: [
        { text: 'Back', action: goToPrev, secondary: true },
        {
          text: 'Get Started',
          action: function() {
            tour.complete();
          }
        }
      ]
    });

    return steps;
  }

  function cleanupTour() {
    window.tourKeepsUserMenuOpen = false;
    closeSettingsModal().then(closeUserMenu).then(goToCasesTab);
  }

  function initTour() {
    if (window.tourCompleted === true) {
      return;
    }

    tour = new Shepherd.Tour({
      useModalOverlay: true,
      defaultStepOptions: {
        classes: 'shepherd-theme-custom',
        scrollTo: { behavior: 'smooth', block: 'center' },
        cancelIcon: {
          enabled: true
        },
        modalOverlayOpeningPadding: 8,
        modalOverlayOpeningRadius: 8
      }
    });

    tour.on('show', function() {
      var currentStep = tour.getCurrentStep();

      if (currentStep && currentStep.id !== 'share-feedback' && currentStep.id !== 'practice-settings') {
        if (typeof window.closeUserMenu === 'function') {
          window.closeUserMenu();
        } else {
          var userMenu = document.getElementById('userMenu');
          var userMenuToggle = document.getElementById('userMenuToggle');
          if (userMenu && userMenu.classList.contains('open')) {
            userMenu.classList.remove('open');
            if (userMenuToggle) {
              userMenuToggle.setAttribute('aria-expanded', 'false');
            }
          }
        }
      }

      if (currentStep && currentStep.el) {
        currentStep.el.style.opacity = '0';
        setTimeout(function() {
          if (currentStep.el) {
            currentStep.el.style.opacity = '1';
          }
        }, 150);
      }
    });

    var steps = buildTourSteps();
    steps.forEach(function(step) {
      tour.addStep(step);
    });

    tour.on('complete', function() {
      completeTour();
      cleanupTour();
    });

    tour.on('cancel', function() {
      completeTour();
      cleanupTour();
    });

    tour.start();
  }

  function completeTour() {
    if (window.tourCompleted === true || tourSaveInProgress) {
      return;
    }
    tourSaveInProgress = true;
    window.tourCompleted = true;

    var csrfToken = '';
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) {
      csrfToken = meta.content;
    }

    fetch('api/save-tour-completed.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({ tourCompleted: true })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      tourSaveInProgress = false;
    })
    .catch(function() {
      tourSaveInProgress = false;
    });
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
    window.tourCompleted = false;
    initTour();
  };
})();
