/**
 * Patient Search Functionality
 * Fast, client-side filtering of patient cards
 */

document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('patientSearch');
  if (!searchInput) return;
  
  // Track cards for fast searching without DOM queries
  const patientCardIndex = [];
  
  // Filter controls for Kanban board
  const filterToggle = document.getElementById('kanbanFilterToggle');
  const filterBar = document.getElementById('kanbanFiltersBar');
  const filterCaseType = document.getElementById('filterCaseType');
  const filterAssignedTo = document.getElementById('filterAssignedTo');
  const filterDueFromInput = document.getElementById('filterDueFrom');
  const filterDueToInput = document.getElementById('filterDueTo');
  const filterActiveDot = document.getElementById('kanbanFilterActiveDot');
  const clearFiltersBtn = document.getElementById('clearFiltersBtn');

  const FILTER_STORAGE_KEY = 'kanban_filters_v1';

  function loadSavedFilters() {
    if (!window.localStorage) return {};
    try {
      const raw = localStorage.getItem(FILTER_STORAGE_KEY);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function getCurrentFilters() {
    return {
      caseType: filterCaseType ? filterCaseType.value : '',
      assignedTo: filterAssignedTo ? filterAssignedTo.value : '',
      dueFrom: filterDueFromInput ? filterDueFromInput.value : '',
      dueTo: filterDueToInput ? filterDueToInput.value : '',
      barOpen: filterBar ? filterBar.classList.contains('filters-open') : false
    };
  }

  function saveFilters() {
    if (!window.localStorage) return;
    try {
      localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(getCurrentFilters()));
    } catch (e) {
      // Ignore storage errors
    }
  }

  function updateFilterActiveIndicatorWithValues(caseTypeVal, assignedVal) {
    const anyFilterActive = !!(caseTypeVal || assignedVal);
    if (filterActiveDot) {
      filterActiveDot.classList.toggle('active', anyFilterActive);
    }

    if (filterToggle) {
      const barIsOpen = filterBar && filterBar.classList.contains('filters-open');
      filterToggle.classList.toggle('active', anyFilterActive || barIsOpen);
    }
  }

  const savedFilters = loadSavedFilters();

  // Apply saved basic filter values (except Assigned To, which depends on async options)
  if (filterCaseType && savedFilters.caseType) {
    filterCaseType.value = savedFilters.caseType;
  }
  if (filterDueFromInput && savedFilters.dueFrom) {
    filterDueFromInput.value = savedFilters.dueFrom;
  }
  if (filterDueToInput && savedFilters.dueTo) {
    filterDueToInput.value = savedFilters.dueTo;
  }
  if (filterBar && typeof savedFilters.barOpen === 'boolean') {
    filterBar.classList.toggle('filters-open', savedFilters.barOpen);
  }

  // Initial active-indicator state
  updateFilterActiveIndicatorWithValues(
    filterCaseType ? filterCaseType.value : '',
    savedFilters.assignedTo || ''
  );

  function addAssignedValuesFromCardsToFilter() {
    if (!filterAssignedTo) return;

    const existing = {};
    for (let i = 0; i < filterAssignedTo.options.length; i++) {
      const val = filterAssignedTo.options[i].value || '';
      existing[val.toLowerCase()] = true;
    }

    function ensureOption(value) {
      const val = (value || '').toString().trim();
      if (!val) return;
      const key = val.toLowerCase();
      if (existing[key]) return;
      existing[key] = true;
      const opt = document.createElement('option');
      opt.value = val;
      opt.textContent = val;
      filterAssignedTo.appendChild(opt);
    }

    // From window.caseAssignments cache
    if (typeof window.caseAssignments === 'object' && window.caseAssignments) {
      Object.keys(window.caseAssignments).forEach(function(caseId) {
        ensureOption(window.caseAssignments[caseId]);
      });
    }

    // From current cards' caseJson
    const cards = document.querySelectorAll('.kanban-card');
    cards.forEach(function(card) {
      try {
        const data = JSON.parse(card.dataset.caseJson || '{}');
        if (data.assignedTo) {
          ensureOption(data.assignedTo);
        }
      } catch (e) {
        // Ignore parse errors
      }
    });
  }

  // Populate Assigned To filter options from available users/labels
  if (filterAssignedTo && typeof loadAvailableUsers === 'function') {
    loadAvailableUsers()
      .then(function(users) {
        if (!Array.isArray(users)) return;
        const seen = {};
        users.forEach(function(value) {
          const val = (value || '').toString().trim();
          if (!val) return;
          const key = val.toLowerCase();
          if (seen[key]) return;
          seen[key] = true;
          const option = document.createElement('option');
          option.value = val;
          option.textContent = val;
          filterAssignedTo.appendChild(option);
        });

        // Ensure we also include any assigned values present on cards / cached assignments
        addAssignedValuesFromCardsToFilter();

        if (savedFilters.assignedTo) {
          const desired = savedFilters.assignedTo;
          let hasOption = false;
          for (let i = 0; i < filterAssignedTo.options.length; i++) {
            if (filterAssignedTo.options[i].value === desired) {
              hasOption = true;
              break;
            }
          }
          if (!hasOption) {
            const opt = document.createElement('option');
            opt.value = desired;
            opt.textContent = desired;
            filterAssignedTo.appendChild(opt);
          }
          filterAssignedTo.value = desired;
        }
      })
      .catch(function() {
        // Ignore errors loading users for filters
      });
  }

  // Toggle filter bar visibility
  if (filterToggle && filterBar) {
    filterToggle.addEventListener('click', function() {
      const isOpen = filterBar.classList.contains('filters-open');
      filterBar.classList.toggle('filters-open', !isOpen);
      saveFilters();
      updateFilterActiveIndicatorWithValues(
        filterCaseType ? filterCaseType.value : '',
        filterAssignedTo ? filterAssignedTo.value : ''
      );
    });
  }

  // Wire up filter change handlers
  if (filterCaseType) {
    filterCaseType.addEventListener('change', function() {
      saveFilters();
      applySearchAndFilters();
    });
  }
  if (filterAssignedTo) {
    filterAssignedTo.addEventListener('change', function() {
      saveFilters();
      applySearchAndFilters();
    });
  }
  if (clearFiltersBtn) {
    clearFiltersBtn.addEventListener('click', function() {
      if (filterCaseType) filterCaseType.value = '';
      if (filterAssignedTo) filterAssignedTo.value = '';
      if (searchInput) searchInput.value = '';
      saveFilters();
      applySearchAndFilters();
    });
  }
  
  /**
   * Build the patient index for fast searching
   */
  function buildPatientIndex() {
    // Clear the existing index
    patientCardIndex.length = 0;
    
    // Get all kanban cards
    const cards = document.querySelectorAll('.kanban-card');
    
    cards.forEach(card => {
      try {
        // Extract case data from card
        const cardData = JSON.parse(card.dataset.caseJson || '{}');
        
        // Add to index with normalized search text (patient name + dentist name)
        const patientName = (cardData.patientFirstName + ' ' + cardData.patientLastName).toLowerCase();
        const dentistName = (cardData.dentistName || '').toLowerCase();
        // Also create normalized dentist name without "dr" prefix for flexible matching
        const dentistNameNormalized = dentistName.replace(/^dr\.?\s*/i, '').trim();
        
        patientCardIndex.push({
          card: card,
          searchText: patientName,
          dentistName: dentistName,
          dentistNameNormalized: dentistNameNormalized,
          id: cardData.id
        });
      } catch (e) {
        // Handle parse error
      }
    });
    
    // Patient search index built
  }
  
  /**
   * Apply patient-name search and all active Kanban filters
   * to determine which cards are visible.
   */
  function applySearchAndFilters() {
    const searchText = searchInput.value.toLowerCase().trim();

    const selectedCaseType = filterCaseType ? filterCaseType.value : '';
    const selectedAssigned = filterAssignedTo ? filterAssignedTo.value : '';

    // Update active-filter indicator
    updateFilterActiveIndicatorWithValues(selectedCaseType, selectedAssigned);

    let matchCount = 0;

    patientCardIndex.forEach(indexItem => {
      const card = indexItem.card;

      // Parse latest case data from the card
      let cardData = {};
      try {
        cardData = JSON.parse(card.dataset.caseJson || '{}');
      } catch (e) {
        // Ignore parse errors; cardData stays empty
      }

      // Patient-name and dentist-name search: require at least 2 characters to filter
      let matchesSearch = true;
      if (searchText.length >= 2) {
        const matchesPatient = indexItem.searchText.includes(searchText);
        
        // Check dentist name - direct match
        let matchesDentist = indexItem.dentistName && indexItem.dentistName.includes(searchText);
        
        // Also check normalized dentist name (without "dr" prefix)
        if (!matchesDentist && indexItem.dentistNameNormalized) {
          const normalizedSearch = searchText.replace(/^dr\.?\s*/i, '').trim();
          if (normalizedSearch) {
            matchesDentist = indexItem.dentistNameNormalized.includes(normalizedSearch);
          } else {
            // User typed just "dr" or "dr." - match any card with a dentist
            matchesDentist = !!indexItem.dentistName;
          }
        }
        
        matchesSearch = matchesPatient || matchesDentist;
      }

      // Case Type filter
      let matchesCaseType = true;
      if (selectedCaseType) {
        matchesCaseType = cardData.caseType === selectedCaseType;
      }

      // Assigned To filter: prefer cardData.assignedTo, fall back to window.caseAssignments
      let assignedValue = '';
      if (cardData.assignedTo) {
        assignedValue = cardData.assignedTo;
      } else if (window.caseAssignments && cardData.id && window.caseAssignments[cardData.id]) {
        assignedValue = window.caseAssignments[cardData.id];
      }
      let matchesAssigned = true;
      if (selectedAssigned) {
        matchesAssigned = assignedValue === selectedAssigned;
      }

      const visible = matchesSearch && matchesCaseType && matchesAssigned;
      card.style.display = visible ? '' : 'none';
      if (visible) matchCount++;
    });

    // Update empty message visibility in each column
    updateEmptyMessages();

    return matchCount;
  }

  /**
   * Filter cards based on search text (wrapper for compatibility).
   */
  function filterPatientCards() {
    return applySearchAndFilters();
  }
  
  /**
   * Update empty messages in columns based on visible cards
   */
  function updateEmptyMessages() {
    const columns = document.querySelectorAll('.kanban-column');
    
    columns.forEach(column => {
      const columnBody = column.querySelector('.kanban-column-body');
      let visibleCards = 0;
      let emptyMessage = columnBody.querySelector('.kanban-empty');
      
      // Count visible cards
      const cards = columnBody.querySelectorAll('.kanban-card');
      cards.forEach(card => {
        if (card.style.display !== 'none') {
          visibleCards++;
        }
      });
      
      // Create or remove empty message
      if (visibleCards === 0) {
        // Show empty message if no visible cards
        if (!emptyMessage) {
          emptyMessage = document.createElement('p');
          emptyMessage.className = 'kanban-empty';
          emptyMessage.textContent = 'No cases in this stage.';
          columnBody.appendChild(emptyMessage);
        }
      } else if (emptyMessage) {
        // Remove empty message if there are visible cards
        emptyMessage.remove();
      }
    });
  }
  
  /**
   * Reset the search to show all cards
   */
  function resetSearch() {
    searchInput.value = '';
    applySearchAndFilters();
  }
  
  // Event handler for input changes (debounced for performance)
  let searchTimer = null;
  searchInput.addEventListener('input', function(e) {
    // Clear previous timer to prevent multiple executions
    if (searchTimer) {
      clearTimeout(searchTimer);
    }
    
    // Execute immediately for fast feedback
    filterPatientCards(this.value);
  });
  
  // Handle Escape key to clear search
  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      resetSearch();
    }
  });
  
  // Clear button functionality
  const searchContainer = searchInput.parentElement;
  if (searchContainer) {
    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'search-clear-btn';
    clearButton.innerHTML = '&times;';
    clearButton.title = 'Clear search';
    clearButton.setAttribute('aria-label', 'Clear search');
    
    clearButton.addEventListener('click', function() {
      resetSearch();
      searchInput.focus();
    });
    
    searchContainer.appendChild(clearButton);
    
    // Toggle clear button visibility
    function toggleClearButton() {
      clearButton.style.display = searchInput.value.length > 0 ? 'block' : 'none';
    }
    
    searchInput.addEventListener('input', toggleClearButton);
    toggleClearButton(); // Initial state
  }
  
  // Re-index cards when new ones are added or updated
  window.addEventListener('cardsUpdated', function() {
    buildPatientIndex();
    applySearchAndFilters();
    addAssignedValuesFromCardsToFilter();
  });
  
  // Build the initial index when cards are loaded
  window.addEventListener('DOMContentLoaded', function() {
    // Wait for cards to be loaded
    setTimeout(function() {
      buildPatientIndex();
      applySearchAndFilters();
      addAssignedValuesFromCardsToFilter();
    }, 1500);
  });
  
  // Also rebuild index when all cards are loaded
  if (window.addCardLoadedCallback) {
    window.addCardLoadedCallback(function() {
      buildPatientIndex();
      applySearchAndFilters();
      addAssignedValuesFromCardsToFilter();
    });
  }
});
