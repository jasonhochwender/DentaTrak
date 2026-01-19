/**
 * Real-time Updates Module
 * 
 * Polls the server for case updates and refreshes the board automatically.
 * This allows users to see changes made by other users without refreshing.
 */

(function() {
  'use strict';
  
  // Configuration
  var POLL_INTERVAL = 5000; // Poll every 5 seconds
  var MAX_POLL_INTERVAL = 30000; // Max interval when idle (30 seconds)
  var IDLE_THRESHOLD = 60000; // Consider idle after 1 minute of no activity
  
  // State
  var lastCheckTime = Math.floor(Date.now() / 1000);
  var pollTimer = null;
  var isPolling = false;
  var lastActivityTime = Date.now();
  var currentPollInterval = POLL_INTERVAL;
  var currentUserEmail = '';
  
  // Get current user email from page
  function getCurrentUserEmail() {
    var userEmailEl = document.getElementById('userEmailData');
    if (userEmailEl) {
      return userEmailEl.getAttribute('data-email') || '';
    }
    return '';
  }
  
  /**
   * Start polling for updates
   */
  function startPolling() {
    if (pollTimer) {
      return; // Already polling
    }
    
    currentUserEmail = getCurrentUserEmail();
    lastCheckTime = Math.floor(Date.now() / 1000);
    
    // Start the polling loop
    schedulePoll();
    
    // Track user activity to adjust polling frequency
    document.addEventListener('mousemove', trackActivity);
    document.addEventListener('keydown', trackActivity);
    document.addEventListener('click', trackActivity);
    
    console.log('[RealTimeUpdates] Started polling for updates');
  }
  
  /**
   * Stop polling for updates
   */
  function stopPolling() {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    
    document.removeEventListener('mousemove', trackActivity);
    document.removeEventListener('keydown', trackActivity);
    document.removeEventListener('click', trackActivity);
    
    console.log('[RealTimeUpdates] Stopped polling');
  }
  
  /**
   * Track user activity to adjust polling frequency
   */
  function trackActivity() {
    lastActivityTime = Date.now();
    
    // If we were in slow polling mode, speed up
    if (currentPollInterval > POLL_INTERVAL) {
      currentPollInterval = POLL_INTERVAL;
    }
  }
  
  /**
   * Schedule the next poll
   */
  function schedulePoll() {
    // Adjust interval based on activity
    var timeSinceActivity = Date.now() - lastActivityTime;
    if (timeSinceActivity > IDLE_THRESHOLD) {
      // User is idle, slow down polling
      currentPollInterval = Math.min(currentPollInterval * 1.5, MAX_POLL_INTERVAL);
    } else {
      currentPollInterval = POLL_INTERVAL;
    }
    
    pollTimer = setTimeout(checkForUpdates, currentPollInterval);
  }
  
  /**
   * Check for updates from the server
   */
  function checkForUpdates() {
    if (isPolling) {
      schedulePoll();
      return;
    }
    
    isPolling = true;
    
    fetch('api/check-updates.php?since=' + lastCheckTime, {
      method: 'GET',
      credentials: 'same-origin'
    })
    .then(function(response) {
      return response.json();
    })
    .then(function(data) {
      isPolling = false;
      
      if (data.success && data.updates && data.updates.length > 0) {
        processUpdates(data.updates);
      }
      
      // Update the last check time
      if (data.serverTime) {
        lastCheckTime = data.serverTime;
      }
      
      // Schedule next poll
      schedulePoll();
    })
    .catch(function(error) {
      isPolling = false;
      console.error('[RealTimeUpdates] Error checking for updates:', error);
      
      // Schedule next poll even on error
      schedulePoll();
    });
  }
  
  /**
   * Process updates received from the server
   */
  function processUpdates(updates) {
    updates.forEach(function(update) {
      // Skip updates made by the current user
      if (update.updatedBy === currentUserEmail) {
        return;
      }
      
      console.log('[RealTimeUpdates] Processing update:', update.type, update.caseId);
      
      switch (update.type) {
        case 'create':
          handleCaseCreated(update);
          break;
        case 'update':
        case 'status':
          handleCaseUpdated(update);
          break;
        case 'assignment':
          handleAssignmentChanged(update);
          break;
        case 'delete':
          handleCaseDeleted(update);
          break;
      }
    });
  }
  
  /**
   * Handle a new case being created
   */
  function handleCaseCreated(update) {
    var caseData = update.caseData;
    if (!caseData || !caseData.id) return;
    
    // Check if card already exists
    var existingCard = findCardByCaseId(caseData.id);
    if (existingCard) {
      return; // Already on the board
    }
    
    // Add the new case to the board
    if (typeof addCaseToKanban === 'function') {
      addCaseToKanban(caseData);
      updateColumnCounts();
      showUpdateToast('New case added: ' + getPatientName(caseData));
    }
  }
  
  /**
   * Handle a case being updated
   */
  function handleCaseUpdated(update) {
    var caseData = update.caseData;
    if (!caseData || !caseData.id) return;
    
    var existingCard = findCardByCaseId(caseData.id);
    
    if (existingCard) {
      // Get old status from the card
      var oldCardData = getCardData(existingCard);
      var oldStatus = oldCardData ? oldCardData.status : null;
      var newStatus = caseData.status;
      
      // If status changed, move the card to the new column
      if (oldStatus && newStatus && oldStatus !== newStatus) {
        moveCardToColumn(existingCard, caseData, newStatus);
        showUpdateToast(getPatientName(caseData) + ' moved to ' + newStatus);
      } else {
        // Just update the card content
        updateCardContent(existingCard, caseData);
      }
    } else {
      // Card doesn't exist, add it (might be newly visible due to assignment)
      if (typeof addCaseToKanban === 'function') {
        addCaseToKanban(caseData);
        updateColumnCounts();
      }
    }
  }
  
  /**
   * Handle assignment change
   */
  function handleAssignmentChanged(update) {
    var caseData = update.caseData;
    if (!caseData || !caseData.id) return;
    
    var existingCard = findCardByCaseId(caseData.id);
    
    // Check if current user has limited visibility
    var hasLimitedVisibility = document.body.classList.contains('limited-visibility');
    
    if (hasLimitedVisibility) {
      // For limited visibility users
      if (caseData.assignedTo === currentUserEmail) {
        // Case was assigned to current user
        if (!existingCard) {
          // Add the card to the board
          if (typeof addCaseToKanban === 'function') {
            addCaseToKanban(caseData);
            updateColumnCounts();
            showUpdateToast('Case assigned to you: ' + getPatientName(caseData), 'info');
          }
        } else {
          updateCardContent(existingCard, caseData);
        }
      } else {
        // Case was unassigned from current user or assigned to someone else
        if (existingCard) {
          removeCard(existingCard);
          updateColumnCounts();
          showUpdateToast('Case unassigned: ' + getPatientName(caseData), 'info');
        }
      }
    } else {
      // For users with full visibility, just update the card
      if (existingCard) {
        updateCardContent(existingCard, caseData);
      }
    }
  }
  
  /**
   * Handle case deletion
   */
  function handleCaseDeleted(update) {
    var caseId = update.caseId;
    var existingCard = findCardByCaseId(caseId);
    
    if (existingCard) {
      removeCard(existingCard);
      updateColumnCounts();
      showUpdateToast('Case removed', 'info');
    }
  }
  
  /**
   * Find a card by case ID
   */
  function findCardByCaseId(caseId) {
    var cards = document.querySelectorAll('.kanban-card');
    for (var i = 0; i < cards.length; i++) {
      var cardData = getCardData(cards[i]);
      if (cardData && cardData.id === caseId) {
        return cards[i];
      }
    }
    return null;
  }
  
  /**
   * Get card data from a card element
   */
  function getCardData(card) {
    try {
      var jsonStr = card.dataset.caseJson;
      if (jsonStr) {
        return JSON.parse(jsonStr);
      }
    } catch (e) {
      // Ignore parse errors
    }
    return null;
  }
  
  /**
   * Get patient name from case data
   */
  function getPatientName(caseData) {
    var firstName = caseData.patientFirstName || '';
    var lastName = caseData.patientLastName || '';
    return (firstName + ' ' + lastName).trim() || 'Unknown Patient';
  }
  
  /**
   * Update card content with new data
   */
  function updateCardContent(card, caseData) {
    // Update the stored JSON data
    card.dataset.caseJson = JSON.stringify(caseData);
    
    // Update patient name
    var nameEl = card.querySelector('.kanban-card-title');
    if (nameEl) {
      nameEl.textContent = getPatientName(caseData);
    }
    
    // Update case type
    var typeEl = card.querySelector('.kanban-card-type');
    if (typeEl) {
      typeEl.textContent = caseData.caseType || '';
    }
    
    // Update due date
    var dateEl = card.querySelector('.kanban-card-date-value');
    if (dateEl && caseData.dueDate) {
      dateEl.textContent = formatDate(caseData.dueDate);
    }
    
    // Update assigned to badge
    var assignedBadge = card.querySelector('.kanban-card-assigned');
    if (caseData.assignedTo) {
      if (!assignedBadge) {
        // Create badge if it doesn't exist
        var footer = card.querySelector('.kanban-card-footer');
        if (footer) {
          assignedBadge = document.createElement('span');
          assignedBadge.className = 'kanban-card-assigned';
          footer.appendChild(assignedBadge);
        }
      }
      if (assignedBadge) {
        assignedBadge.textContent = caseData.assignedTo.split('@')[0];
      }
    } else if (assignedBadge) {
      assignedBadge.remove();
    }
    
    // Add visual feedback
    card.classList.add('card-updated');
    setTimeout(function() {
      card.classList.remove('card-updated');
    }, 2000);
  }
  
  /**
   * Move a card to a different column
   */
  function moveCardToColumn(card, caseData, newStatus) {
    // Find the target column
    var columns = document.querySelectorAll('.kanban-column');
    var targetColumn = null;
    
    columns.forEach(function(column) {
      var titleEl = column.querySelector('.kanban-column-title');
      if (titleEl && titleEl.textContent.trim() === newStatus) {
        targetColumn = column;
      }
    });
    
    if (!targetColumn) {
      return;
    }
    
    // Get the cards container in the target column
    var cardsContainer = targetColumn.querySelector('.kanban-cards');
    if (!cardsContainer) {
      return;
    }
    
    // Update card data and classes
    card.dataset.caseJson = JSON.stringify(caseData);
    
    // Update status class
    var statusClasses = ['kanban-card-originated', 'kanban-card-sent-to-external-lab', 
                         'kanban-card-designed', 'kanban-card-manufactured', 
                         'kanban-card-received-from-external-lab', 'kanban-card-delivered'];
    statusClasses.forEach(function(cls) {
      card.classList.remove(cls);
    });
    var newStatusClass = 'kanban-card-' + newStatus.toLowerCase().replace(/\s+/g, '-');
    card.classList.add(newStatusClass);
    
    // Move the card
    cardsContainer.insertBefore(card, cardsContainer.firstChild);
    
    // Update column counts
    updateColumnCounts();
    
    // Add visual feedback
    card.classList.add('card-moved');
    setTimeout(function() {
      card.classList.remove('card-moved');
    }, 2000);
  }
  
  /**
   * Remove a card from the board
   */
  function removeCard(card) {
    card.classList.add('card-removing');
    setTimeout(function() {
      card.remove();
    }, 300);
  }
  
  /**
   * Update column counts
   */
  function updateColumnCounts() {
    if (typeof window.updateColumnCounts === 'function') {
      window.updateColumnCounts();
    } else {
      // Fallback: manually update counts
      var columns = document.querySelectorAll('.kanban-column');
      columns.forEach(function(column) {
        var cards = column.querySelectorAll('.kanban-card');
        var countBadge = column.querySelector('.kanban-column-count');
        if (countBadge) {
          countBadge.textContent = cards.length;
        }
      });
    }
  }
  
  /**
   * Format a date string
   */
  function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
      var date = new Date(dateStr);
      return date.toLocaleDateString();
    } catch (e) {
      return dateStr;
    }
  }
  
  /**
   * Show a toast notification for updates
   */
  function showUpdateToast(message, type) {
    type = type || 'info';
    
    if (typeof showToast === 'function') {
      showToast(message, type);
    } else {
      console.log('[RealTimeUpdates] ' + message);
    }
  }
  
  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      // Delay start to let the main app initialize first
      setTimeout(startPolling, 2000);
    });
  } else {
    // DOM already loaded
    setTimeout(startPolling, 2000);
  }
  
  // Stop polling when page is hidden, resume when visible
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      stopPolling();
    } else {
      startPolling();
    }
  });
  
  // Expose functions globally for debugging
  window.RealTimeUpdates = {
    start: startPolling,
    stop: stopPolling,
    checkNow: checkForUpdates
  };
  
})();
