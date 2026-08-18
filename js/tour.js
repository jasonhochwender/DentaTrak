/**
 * Shepherd.js Tour Implementation
 * Concise product tour for new DentaTrak users
 */

(function() {
  'use strict';

  // Desktop threshold below which the tour is not appropriate.
  var DESKTOP_MIN_WIDTH = 1024;

  // Check if Shepherd is available
  if (typeof Shepherd === 'undefined') {
    return;
  }

  // Initialize tour only after DOM is ready and settings are loaded
  document.addEventListener('DOMContentLoaded', function() {
    // Wait for settings to be loaded (app.js sets window.tourCompleted)
    var checkCount = 0;
    var maxChecks = 20; // 20 * 250ms = 5 seconds max wait

    var checkInterval = setInterval(function() {
      checkCount++;

      if (typeof window.tourCompleted !== 'undefined') {
        clearInterval(checkInterval);
        // Additional delay to ensure UI is fully rendered
        setTimeout(maybeAutoStartTour, 500);
      } else if (checkCount >= maxChecks) {
        clearInterval(checkInterval);
        window.tourCompleted = false;
        setTimeout(maybeAutoStartTour, 500);
      }
    }, 250);
  });

  /**
   * Auto-start the tour only on desktop for users who have not completed it.
   */
  function maybeAutoStartTour() {
    if (window.tourCompleted === true) {
      return;
    }
    if (window.innerWidth < DESKTOP_MIN_WIDTH) {
      return;
    }
    initTour();
  }

  /**
   * Return a simple offset modifier for Shepherd poppers.
   */
  function offsetModifier(x, y) {
    return {
      modifiers: [{
        name: 'offset',
        options: { offset: [x || 0, y || 16] }
      }]
    };
  }

  /**
   * Build the ordered list of eligible tour steps.
   * Any step whose target does not exist in the DOM is skipped up front.
   */
  function buildTourSteps() {
    var steps = [];

    // Step 1: Welcome
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
          action: function() {
            tour.next();
          }
        }
      ]
    });

    // Step 2: Your Cases Board
    if (document.querySelector('.kanban-board')) {
      steps.push({
        id: 'cases-board',
        title: 'Your Cases Board',
        text: 'Cases are organized into workflow stages. Move a case from one column to another as work progresses.',
        attachTo: {
          element: '.kanban-board',
          on: 'top-start'
        },
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: tour.back, secondary: true },
          { text: 'Next', action: tour.next }
        ]
      });
    }

    // Step 3: Create a Case
    if (document.querySelector('.create-case-button')) {
      steps.push({
        id: 'create-case',
        title: 'Create a Case',
        text: 'Add a case here, including patient and dentist information, case details, due dates, assignments, and files.',
        attachTo: {
          element: '.create-case-button',
          on: 'bottom-start'
        },
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: tour.back, secondary: true },
          { text: 'Next', action: tour.next }
        ]
      });
    }

    // Step 4: Keep Track of Every Case
    // Target the first Kanban column so the tour works even with zero cases.
    if (document.querySelector('.kanban-column')) {
      steps.push({
        id: 'keep-track',
        title: 'Keep Track of Every Case',
        text: 'Case cards give you the important details at a glance. Due Soon and Late indicators help you see what needs attention.',
        attachTo: {
          element: '.kanban-column',
          on: 'right'
        },
        popperOptions: offsetModifier(16, 0),
        buttons: [
          { text: 'Back', action: tour.back, secondary: true },
          { text: 'Next', action: tour.next }
        ]
      });
    }

    // Step 5: Search & Filter
    if (document.querySelector('#kanbanFilterToggle')) {
      steps.push({
        id: 'search-filter',
        title: 'Search & Filter',
        text: 'Quickly find cases or narrow the board by patient, dentist, case type, assignment, carrier, due status, and other criteria.',
        attachTo: {
          element: '#kanbanFilterToggle',
          on: 'bottom'
        },
        popperOptions: offsetModifier(0, 16),
        buttons: [
          { text: 'Back', action: tour.back, secondary: true },
          { text: 'Next', action: tour.next }
        ]
      });
    }

    // Step 6: Assign Work
    // The board is the only persistent target for assignment when no cards exist.
    if (document.querySelector('.kanban-board')) {
      steps.push({
        id: 'assign-work',
        title: 'Assign Work',
        text: 'Assign cases to team members, labs, or assignment labels so everyone can see who is responsible for the next step.',
        attachTo: {
          element: '.kanban-board',
          on: 'top-start'
        },
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: tour.back, secondary: true },
          { text: 'Next', action: tour.next }
        ]
      });
    }

    // Step 7: Insights (only if the Insights tab is actually rendered)
    if (document.querySelector('.main-tab[data-tab="insights"]')) {
      steps.push({
        id: 'insights',
        title: 'Insights',
        text: 'Use Insights to understand workload, turnaround times, case activity, labs, and other trends across your practice.',
        attachTo: {
          element: '.main-tab[data-tab="insights"]',
          on: 'bottom-start'
        },
        popperOptions: offsetModifier(20, 16),
        buttons: [
          { text: 'Back', action: tour.back, secondary: true },
          { text: 'Next', action: tour.next }
        ]
      });
    }

    // Step 8: You're Ready
    steps.push({
      id: 'finish',
      title: "You're Ready",
      text: 'You can take this tour again anytime from your user menu. The DentaTrak User Guide is also available whenever you need more detail.',
      buttons: [
        { text: 'Back', action: tour.back, secondary: true },
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

  var tour;

  /**
   * Create and start the tour from the eligible steps.
   */
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

    // Temporarily hide each step while Shepherd settles its position, then fade in.
    tour.on('show', function() {
      var currentStep = tour.getCurrentStep();
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

    // Mark as completed on finish or early dismiss.
    tour.on('complete', completeTour);
    tour.on('cancel', completeTour);

    tour.start();
  }

  // Track if we've already saved to prevent duplicate calls
  var tourSaveInProgress = false;

  /**
   * Persist tour completion for the current user.
   */
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

  /**
   * Manual replay entry point. Used by the user menu "Take a Tour" link.
   * Does not permanently reset server-side completion; only the current page load.
   */
  window.startAppTour = function() {
    if (window.innerWidth < DESKTOP_MIN_WIDTH) {
      if (typeof showToast === 'function') {
        showToast('The guided tour is available on a larger screen.', 'info');
      }
      return;
    }

    window.tourCompleted = false;
    initTour();
  };
})();
