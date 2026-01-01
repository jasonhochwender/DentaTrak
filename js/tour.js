/**
 * Shepherd.js Tour Implementation
 * First-time user walkthrough for the application
 */

(function() {
  'use strict';

  // Check if Shepherd is available
  if (typeof Shepherd === 'undefined') {
    return;
  }

  // Initialize tour only after DOM is ready and settings are loaded
  document.addEventListener('DOMContentLoaded', function() {
    // Wait for settings to be loaded (app.js sets window.tourCompleted)
    // Check periodically until tourCompleted is defined or timeout
    var checkCount = 0;
    var maxChecks = 20; // 20 * 250ms = 5 seconds max wait
    
    var checkInterval = setInterval(function() {
      checkCount++;
      
      // If tourCompleted is explicitly set (true or false), we can proceed
      if (typeof window.tourCompleted !== 'undefined') {
        clearInterval(checkInterval);
        // Additional delay to ensure UI is fully rendered
        setTimeout(initTour, 500);
      } else if (checkCount >= maxChecks) {
        // Timeout - assume tour not completed (new user)
        clearInterval(checkInterval);
        window.tourCompleted = false;
        setTimeout(initTour, 500);
      }
    }, 250);
  });

  function initTour() {
    // Check if user has already completed the tour
    if (window.tourCompleted === true) {
      return;
    }

    // Create the tour instance
    const tour = new Shepherd.Tour({
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
    
    // Add event listener to handle positioning jump
    tour.on('show', function() {
      const currentStep = tour.getCurrentStep();
      if (currentStep && currentStep.el) {
        // Hide immediately, then show after positioning settles
        currentStep.el.style.opacity = '0';
        setTimeout(function() {
          if (currentStep.el) {
            currentStep.el.style.opacity = '1';
          }
        }, 150);
      }
    });

    // Step 1: Welcome
    tour.addStep({
      id: 'welcome',
      title: 'Welcome to DentaTrak',
      text: 'Let\'s take a quick tour of the main features to help you get started.',
      buttons: [
        {
          text: 'Skip Tour',
          action: function() {
            completeTour();
            tour.complete();
          },
          secondary: true
        },
        {
          text: 'Start Tour',
          action: tour.next
        }
      ]
    });

    // Step 2: Cases Tab
    tour.addStep({
      id: 'cases-tab',
      title: 'Cases Board',
      text: 'This is your main workspace. Cases are organized by status in columns. Drag and drop cases between columns to update their status.',
      attachTo: {
        element: '.main-tab[data-tab="cases"]',
        on: 'bottom-start'
      },
      popperOptions: {
        modifiers: [{ name: 'offset', options: { offset: [20, 16] } }]
      },
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Next',
          action: tour.next
        }
      ]
    });

    // Step 3: Create Case Button
    tour.addStep({
      id: 'create-case',
      title: 'Create New Cases',
      text: 'Click here to create a new dental case. You can add patient details, case type, due dates, and attach files.',
      attachTo: {
        element: '.create-case-button',
        on: 'bottom-start'
      },
      popperOptions: {
        modifiers: [{ name: 'offset', options: { offset: [20, 16] } }]
      },
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Next',
          action: tour.next
        }
      ]
    });

    // Step 4: Filters (now includes search)
    tour.addStep({
      id: 'filters',
      title: 'Search & Filter Cases',
      text: 'Click Filters to search by patient or dentist name, filter by case type, assignee, and more.',
      attachTo: {
        element: '#kanbanFilterToggle',
        on: 'bottom'
      },
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Next',
          action: tour.next
        }
      ]
    });

    // Step 6: Insights Tab
    tour.addStep({
      id: 'insights-tab',
      title: 'Insights Dashboard',
      text: 'View detailed analytics about your cases, team performance, and trends over time. You can also ask questions about your practice data.',
      attachTo: {
        element: '.main-tab[data-tab="insights"]',
        on: 'bottom-start'
      },
      popperOptions: {
        modifiers: [{ name: 'offset', options: { offset: [20, 16] } }]
      },
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Next',
          action: tour.next
        }
      ]
    });

    // Step 7: User Menu
    tour.addStep({
      id: 'user-menu',
      title: 'Menu',
      text: 'Click your profile picture to access the main menu with Settings, Billing, Support, and more.',
      attachTo: {
        element: '#userMenuToggle',
        on: 'bottom-end'
      },
      popperOptions: {
        modifiers: [{ name: 'offset', options: { offset: [-20, 16] } }]
      },
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Next',
          action: tour.next
        }
      ]
    });

    // Step 8: Ask DentaTrak
    tour.addStep({
      id: 'ask-dentatrak',
      title: 'Ask DentaTrak AI',
      text: 'Your AI-powered practice assistant! Ask questions about your cases, get insights about workload, find overdue cases, understand what puts cases at risk, and more. It\'s like having a smart assistant who knows your practice data.',
      attachTo: {
        element: '#askDentatrakFab',
        on: 'top-end'
      },
      popperOptions: {
        modifiers: [{ name: 'offset', options: { offset: [-20, 16] } }]
      },
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Next',
          action: tour.next
        }
      ]
    });

    // Step 9: Finish
    tour.addStep({
      id: 'finish',
      title: 'You\'re All Set!',
      text: 'That covers the essentials. Remember, you can always access Support from the menu if you need help, or use the AI Chat for quick answers. Happy tracking!',
      buttons: [
        {
          text: 'Back',
          action: tour.back,
          secondary: true
        },
        {
          text: 'Get Started',
          action: function() {
            completeTour();
            tour.complete();
          }
        }
      ]
    });

    // Handle tour cancellation (X button or clicking outside)
    tour.on('cancel', function() {
      completeTour();
    });

    // Start the tour
    tour.start();
  }

  // Track if we've already saved to prevent duplicate calls
  var tourSaveInProgress = false;

  // Function to mark tour as completed
  function completeTour() {
    // Prevent duplicate saves
    if (window.tourCompleted === true || tourSaveInProgress) {
      return;
    }
    
    tourSaveInProgress = true;
    window.tourCompleted = true;
    
    // Save to server
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch('api/save-tour-completed.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({ tourCompleted: true })
    })
    .then(response => response.json())
    .then(data => {
      tourSaveInProgress = false;
    })
    .catch(error => {
      tourSaveInProgress = false;
    });
  }

  // Expose function to manually start tour (for testing or help menu)
  window.startAppTour = function() {
    window.tourCompleted = false;
    initTour();
  };

})();
