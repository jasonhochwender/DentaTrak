/**
 * Case Labels JavaScript
 * Handles inline label creation, typeahead selection, and label management
 */

(function() {
  'use strict';

  // Store practice labels
  window.practiceLabels = [];
  
  // Currently selected labels for the case being edited
  window.selectedCaseLabels = [];

  /**
   * Check if the current user can create new labels
   * Returns true if user has permission, false otherwise
   */
  function canCurrentUserAddLabels() {
    // Get current user email from the hidden data element
    var userEmailEl = document.getElementById('userEmailData');
    if (!userEmailEl) return true; // Default to allowing if we can't determine
    
    var currentEmail = (userEmailEl.dataset.email || '').toLowerCase();
    if (!currentEmail) return true; // Default to allowing if no email
    
    // Check the canAddLabelsUsers map
    if (window.canAddLabelsUsers && window.canAddLabelsUsers.hasOwnProperty(currentEmail)) {
      return !!window.canAddLabelsUsers[currentEmail];
    }
    
    // Default to true if not explicitly set (backwards compatibility)
    return true;
  }
  
  // Expose for external use
  window.canCurrentUserAddLabels = canCurrentUserAddLabels;

  // Initialize labels functionality
  document.addEventListener('DOMContentLoaded', function() {
    initLabelsTypeahead();
    loadPracticeLabels();
    
    // Also initialize filter labels after a short delay as backup
    setTimeout(function() {
      if (typeof window.initLabelFilters === 'function') {
        window.initLabelFilters();
      }
    }, 500);
  });

  /**
   * Load all labels for the current practice
   */
  function loadPracticeLabels() {
    fetch('api/case-labels.php', {
      credentials: 'same-origin'
    })
    .then(function(response) { 
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json(); 
    })
    .then(function(data) {
      if (data.success) {
        window.practiceLabels = data.labels || [];
        // Populate the label filter dropdown if it exists
        if (typeof window.populateLabelFilterSelect === 'function') {
          window.populateLabelFilterSelect();
        }
        // Initialize filter labels after practice labels are loaded
        if (typeof window.initLabelFilters === 'function') {
          window.initLabelFilters();
        }
      }
    })
    .catch(function(error) {
      console.error('Error loading labels:', error);
      // Still try to initialize filters even if labels failed to load
      if (typeof window.initLabelFilters === 'function') {
        window.initLabelFilters();
      }
    });
  }

  /**
   * Initialize the labels typeahead input
   */
  function initLabelsTypeahead() {
    var labelInput = document.getElementById('labelInput');
    var labelsDropdown = document.getElementById('labelsDropdown');
    var selectedLabelsContainer = document.getElementById('selectedLabels');
    var labelsInputContainer = document.getElementById('labelsInputContainer');

    if (!labelInput || !labelsDropdown || !selectedLabelsContainer) {
      return;
    }

    var highlightedIndex = -1;

    // Focus input when clicking container
    if (labelsInputContainer) {
      labelsInputContainer.addEventListener('click', function(e) {
        if (e.target === labelsInputContainer || e.target === selectedLabelsContainer) {
          labelInput.focus();
        }
      });
    }

    // Handle input changes
    labelInput.addEventListener('input', function() {
      var query = this.value.trim().toLowerCase();
      showLabelsDropdown(query);
      highlightedIndex = -1;
    });

    // Handle focus
    labelInput.addEventListener('focus', function() {
      var query = this.value.trim().toLowerCase();
      showLabelsDropdown(query);
    });

    // Handle blur (with delay to allow click on dropdown)
    labelInput.addEventListener('blur', function() {
      setTimeout(function() {
        hideLabelsDropdown();
      }, 200);
    });

    // Handle keyboard navigation
    labelInput.addEventListener('keydown', function(e) {
      var items = labelsDropdown.querySelectorAll('.labels-dropdown-item, .labels-dropdown-create');
      
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
        updateHighlight(items, highlightedIndex);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightedIndex = Math.max(highlightedIndex - 1, 0);
        updateHighlight(items, highlightedIndex);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        if (highlightedIndex >= 0 && items[highlightedIndex]) {
          items[highlightedIndex].click();
        } else if (this.value.trim()) {
          // Create new label if nothing highlighted
          createAndSelectLabel(this.value.trim());
        }
        return false;
      } else if (e.key === 'Escape') {
        hideLabelsDropdown();
        this.blur();
      } else if (e.key === 'Backspace' && !this.value) {
        // Remove last selected label
        var lastChip = selectedLabelsContainer.querySelector('.label-chip:last-child');
        if (lastChip) {
          var labelId = lastChip.dataset.labelId;
          removeSelectedLabel(labelId);
        }
      }
    });

    // Make functions available globally
    window.showLabelsDropdown = showLabelsDropdown;
    window.hideLabelsDropdown = hideLabelsDropdown;
    window.selectLabel = selectLabel;
    window.removeSelectedLabel = removeSelectedLabel;
    window.createAndSelectLabel = createAndSelectLabel;
    window.renderSelectedLabels = renderSelectedLabels;
    window.clearSelectedLabels = clearSelectedLabels;
    window.setSelectedLabels = setSelectedLabels;
    window.getSelectedLabelIds = getSelectedLabelIds;
  }

  /**
   * Show the labels dropdown with filtered options
   */
  function showLabelsDropdown(query) {
    var labelsDropdown = document.getElementById('labelsDropdown');
    if (!labelsDropdown) return;

    query = (query || '').toLowerCase();
    
    // Filter labels that match query and aren't already selected
    var selectedIds = window.selectedCaseLabels.map(function(l) { return l.id; });
    var filteredLabels = window.practiceLabels.filter(function(label) {
      var matchesQuery = !query || label.name.toLowerCase().includes(query);
      var notSelected = selectedIds.indexOf(label.id) === -1;
      return matchesQuery && notSelected;
    });

    var html = '';

    if (filteredLabels.length > 0) {
      filteredLabels.forEach(function(label) {
        html += '<div class="labels-dropdown-item" data-label-id="' + label.id + '" data-label-name="' + escapeHtml(label.name) + '">';
        html += '<span class="labels-dropdown-item-name">' + escapeHtml(label.name) + '</span>';
        html += '</div>';
      });
    }

    // Show "Create new label" option if query doesn't exactly match existing AND user has permission
    if (query && !window.practiceLabels.some(function(l) { return l.name.toLowerCase() === query; }) && canCurrentUserAddLabels()) {
      html += '<div class="labels-dropdown-create" data-create-name="' + escapeHtml(query) + '">';
      html += '<span class="labels-dropdown-create-icon">+</span>';
      html += '<span class="labels-dropdown-create-text">Create "' + escapeHtml(query) + '"</span>';
      html += '</div>';
    }

    if (!html) {
      html = '<div class="labels-dropdown-empty">No labels found</div>';
    }

    labelsDropdown.innerHTML = html;
    labelsDropdown.classList.add('open');

    // Add click handlers
    labelsDropdown.querySelectorAll('.labels-dropdown-item').forEach(function(item) {
      item.addEventListener('click', function() {
        var labelId = parseInt(this.dataset.labelId, 10);
        var labelName = this.dataset.labelName;
        selectLabel({ id: labelId, name: labelName });
      });
    });

    var createBtn = labelsDropdown.querySelector('.labels-dropdown-create');
    if (createBtn) {
      createBtn.addEventListener('click', function() {
        createAndSelectLabel(this.dataset.createName);
      });
    }
  }

  /**
   * Hide the labels dropdown
   */
  function hideLabelsDropdown() {
    var labelsDropdown = document.getElementById('labelsDropdown');
    if (labelsDropdown) {
      labelsDropdown.classList.remove('open');
    }
  }

  /**
   * Update highlight in dropdown
   */
  function updateHighlight(items, index) {
    items.forEach(function(item, i) {
      item.classList.toggle('highlighted', i === index);
    });
  }

  /**
   * Select a label and add it to the selected list
   */
  function selectLabel(label) {
    if (!label || !label.id) return;

    // Check if already selected
    if (window.selectedCaseLabels.some(function(l) { return l.id === label.id; })) {
      return;
    }

    window.selectedCaseLabels.push(label);
    renderSelectedLabels();
    
    // Clear input and hide dropdown, then refocus
    var labelInput = document.getElementById('labelInput');
    if (labelInput) {
      labelInput.value = '';
      // Refocus input for continuous label entry
      setTimeout(function() {
        labelInput.focus();
      }, 10);
    }
    hideLabelsDropdown();
  }

  /**
   * Create a new label and select it
   */
  function createAndSelectLabel(name) {
    if (!name || !name.trim()) return;

    name = name.trim();

    // Check if label already exists
    var existing = window.practiceLabels.find(function(l) {
      return l.name.toLowerCase() === name.toLowerCase();
    });

    if (existing) {
      selectLabel(existing);
      return;
    }

    // Check if user has permission to create new labels
    if (!canCurrentUserAddLabels()) {
      console.warn('User does not have permission to create new labels');
      return;
    }

    // Create new label via API
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/case-labels.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        action: 'create',
        name: name
      }),
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success && data.label) {
        // Add to practice labels cache
        if (!data.existed) {
          window.practiceLabels.push(data.label);
        }
        // Select the label
        selectLabel(data.label);
      } else {
        console.error('Error creating label:', data.error);
      }
    })
    .catch(function(error) {
      console.error('Error creating label:', error);
    });
  }

  /**
   * Remove a label from the selected list
   */
  function removeSelectedLabel(labelId) {
    labelId = parseInt(labelId, 10);
    window.selectedCaseLabels = window.selectedCaseLabels.filter(function(l) {
      return l.id !== labelId;
    });
    renderSelectedLabels();
  }

  /**
   * Render the selected labels as chips
   */
  function renderSelectedLabels() {
    var container = document.getElementById('selectedLabels');
    var hiddenInput = document.getElementById('caseLabelsData');
    
    if (!container) return;

    var html = '';
    window.selectedCaseLabels.forEach(function(label) {
      html += '<span class="label-chip" data-label-id="' + label.id + '">';
      html += '<span class="label-chip-name">' + escapeHtml(label.name) + '</span>';
      html += '<button type="button" class="label-chip-remove" onclick="removeSelectedLabel(' + label.id + ')">&times;</button>';
      html += '</span>';
    });

    container.innerHTML = html;

    // Update hidden input with label IDs
    if (hiddenInput) {
      var ids = window.selectedCaseLabels.map(function(l) { return l.id; });
      hiddenInput.value = JSON.stringify(ids);
    }
  }

  /**
   * Clear all selected labels
   */
  function clearSelectedLabels() {
    window.selectedCaseLabels = [];
    renderSelectedLabels();
  }

  /**
   * Set selected labels (for editing existing case)
   */
  function setSelectedLabels(labels) {
    window.selectedCaseLabels = labels || [];
    renderSelectedLabels();
  }

  /**
   * Get array of selected label IDs
   */
  function getSelectedLabelIds() {
    return window.selectedCaseLabels.map(function(l) { return l.id; });
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Remove a label from a case card (inline removal)
   */
  window.removeLabelFromCaseCard = function(caseId, labelId, element) {
    if (!caseId || !labelId) return;

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/case-labels.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        action: 'remove',
        case_id: caseId,
        label_id: labelId
      }),
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        // Remove the badge from the UI
        var badge = element.closest('.case-label-badge');
        if (badge) {
          badge.remove();
        }
        
        // Update the case data in the card
        var card = element.closest('.kanban-card');
        if (card && card.dataset.caseJson) {
          try {
            var caseData = JSON.parse(card.dataset.caseJson);
            if (caseData.labels) {
              caseData.labels = caseData.labels.filter(function(l) {
                return l.id !== parseInt(labelId, 10);
              });
              card.dataset.caseJson = JSON.stringify(caseData);
              
              // Check if card should be hidden due to active label filter
              if (window.selectedFilterLabels && window.selectedFilterLabels.length > 0) {
                if (!window.caseMatchesLabelFilter(caseData)) {
                  // Card no longer matches filter - hide it
                  card.style.display = 'none';
                }
              }
            }
          } catch (e) {}
        }
      }
    })
    .catch(function(error) {
      console.error('Error removing label:', error);
    });
  };

  /**
   * Render labels on a case card
   */
  window.renderCaseCardLabels = function(labels, caseId, maxVisible) {
    if (!labels || !Array.isArray(labels) || labels.length === 0) {
      return '';
    }

    maxVisible = maxVisible || 3;
    var visibleLabels = labels.slice(0, maxVisible);
    var hiddenCount = labels.length - maxVisible;

    var html = '<div class="case-card-labels">';
    
    visibleLabels.forEach(function(label) {
      html += '<span class="case-label-badge" title="' + escapeHtml(label.name) + '">';
      html += '<span class="case-label-badge-name">' + escapeHtml(label.name) + '</span>';
      html += '<button type="button" class="case-label-badge-remove" onclick="event.stopPropagation(); removeLabelFromCaseCard(\'' + caseId + '\', ' + label.id + ', this)">&times;</button>';
      html += '</span>';
    });

    if (hiddenCount > 0) {
      html += '<span class="case-labels-more">+' + hiddenCount + '</span>';
    }

    html += '</div>';
    return html;
  };

  // ============================================
  // LABEL FILTERING
  // ============================================
  
  // Store selected filter labels
  window.selectedFilterLabels = [];
  
  // Track if filter labels have been initialized
  var filterLabelsInitialized = false;

  /**
   * Initialize label filters in the kanban filter bar (typeahead style)
   */
  window.initLabelFilters = function() {
    // Prevent duplicate initialization
    if (filterLabelsInitialized) {
      return;
    }
    
    var input = document.getElementById('filterLabelInput');
    var dropdown = document.getElementById('filterLabelsDropdown');
    var selectedContainer = document.getElementById('filterSelectedLabels');
    var container = document.getElementById('filterLabelsContainer');
    
    if (!input || !dropdown || !selectedContainer) {
      return;
    }
    
    filterLabelsInitialized = true;

    var filterHighlightedIndex = -1;

    // Click on container focuses input
    if (container) {
      container.addEventListener('click', function(e) {
        if (e.target === container || e.target === selectedContainer) {
          input.focus();
        }
      });
    }

    // Handle input changes
    input.addEventListener('input', function() {
      var query = this.value.trim().toLowerCase();
      showFilterDropdown(query);
      filterHighlightedIndex = -1;
    });

    // Handle focus - show dropdown immediately
    input.addEventListener('focus', function() {
      showFilterDropdown('');
    });

    // Handle blur
    input.addEventListener('blur', function() {
      setTimeout(function() {
        hideFilterDropdown();
      }, 250);
    });

    // Handle keyboard navigation
    input.addEventListener('keydown', function(e) {
      var items = dropdown.querySelectorAll('.filter-labels-dropdown-item');
      
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        filterHighlightedIndex = Math.min(filterHighlightedIndex + 1, items.length - 1);
        updateFilterHighlight(items, filterHighlightedIndex);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        filterHighlightedIndex = Math.max(filterHighlightedIndex - 1, 0);
        updateFilterHighlight(items, filterHighlightedIndex);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (filterHighlightedIndex >= 0 && items[filterHighlightedIndex]) {
          items[filterHighlightedIndex].click();
        }
      } else if (e.key === 'Escape') {
        hideFilterDropdown();
        this.blur();
      } else if (e.key === 'Backspace' && !this.value) {
        // Remove last selected filter label
        if (window.selectedFilterLabels.length > 0) {
          window.selectedFilterLabels.pop();
          renderSelectedFilterLabels();
          triggerFilterUpdate();
        }
      }
    });

    // Initial render
    renderSelectedFilterLabels();
  };

  function showFilterDropdown(query) {
    var dropdown = document.getElementById('filterLabelsDropdown');
    if (!dropdown) return;

    query = (query || '').toLowerCase();
    
    // Filter labels that match query and aren't already selected
    var selectedIds = window.selectedFilterLabels.map(function(l) { return l.id; });
    var filteredLabels = window.practiceLabels.filter(function(label) {
      var matchesQuery = !query || label.name.toLowerCase().indexOf(query) !== -1;
      var notSelected = selectedIds.indexOf(label.id) === -1;
      return matchesQuery && notSelected;
    });

    if (filteredLabels.length === 0) {
      if (query) {
        dropdown.innerHTML = '<div class="filter-labels-dropdown-empty">No matching labels</div>';
      } else if (window.practiceLabels.length === 0) {
        dropdown.innerHTML = '<div class="filter-labels-dropdown-empty">No labels created yet</div>';
      } else {
        dropdown.innerHTML = '<div class="filter-labels-dropdown-empty">All labels selected</div>';
      }
    } else {
      var html = '';
      filteredLabels.forEach(function(label) {
        html += '<div class="filter-labels-dropdown-item" data-label-id="' + label.id + '" data-label-name="' + escapeHtml(label.name) + '">';
        html += escapeHtml(label.name);
        html += '</div>';
      });
      dropdown.innerHTML = html;

      // Add click handlers
      dropdown.querySelectorAll('.filter-labels-dropdown-item').forEach(function(item) {
        item.addEventListener('click', function() {
          var labelId = parseInt(this.dataset.labelId, 10);
          var labelName = this.dataset.labelName;
          selectFilterLabel({ id: labelId, name: labelName });
        });
      });
    }

    dropdown.classList.add('show');
  }

  function hideFilterDropdown() {
    var dropdown = document.getElementById('filterLabelsDropdown');
    if (dropdown) {
      dropdown.classList.remove('show');
    }
  }

  function updateFilterHighlight(items, index) {
    items.forEach(function(item, i) {
      item.classList.toggle('highlighted', i === index);
    });
  }

  function selectFilterLabel(label) {
    if (!label || !label.id) return;

    // Check if already selected
    if (window.selectedFilterLabels.some(function(l) { return l.id === label.id; })) {
      return;
    }

    window.selectedFilterLabels.push(label);
    renderSelectedFilterLabels();
    
    // Clear input and refocus
    var input = document.getElementById('filterLabelInput');
    if (input) {
      input.value = '';
      setTimeout(function() {
        input.focus();
      }, 10);
    }
    hideFilterDropdown();
    
    triggerFilterUpdate();
  }

  function removeFilterLabel(labelId) {
    var index = window.selectedFilterLabels.findIndex(function(l) { return l.id === labelId; });
    if (index >= 0) {
      window.selectedFilterLabels.splice(index, 1);
      renderSelectedFilterLabels();
      triggerFilterUpdate();
    }
  }

  function renderSelectedFilterLabels() {
    var container = document.getElementById('filterSelectedLabels');
    if (!container) return;

    if (window.selectedFilterLabels.length === 0) {
      container.innerHTML = '';
      return;
    }

    var html = '';
    window.selectedFilterLabels.forEach(function(label) {
      html += '<span class="filter-label-chip" data-label-id="' + label.id + '">';
      html += escapeHtml(label.name);
      html += '<button type="button" class="filter-label-chip-remove" data-label-id="' + label.id + '">&times;</button>';
      html += '</span>';
    });

    container.innerHTML = html;

    // Add remove handlers
    container.querySelectorAll('.filter-label-chip-remove').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var labelId = parseInt(this.dataset.labelId, 10);
        removeFilterLabel(labelId);
      });
    });
  }

  function triggerFilterUpdate() {
    // Trigger filter application
    if (typeof window.applyFilters === 'function') {
      window.applyFilters();
    }
    
    // Update filter active indicator
    updateFilterActiveIndicator();
  }

  /**
   * Clear all label filters
   */
  window.clearLabelFilters = function() {
    window.selectedFilterLabels = [];
    renderSelectedFilterLabels();
    var input = document.getElementById('filterLabelInput');
    if (input) {
      input.value = '';
    }
  };

  /**
   * Check if a case matches the selected label filters
   * Returns true if case has ANY of the selected labels (inclusive OR)
   */
  window.caseMatchesLabelFilter = function(caseData) {
    // If no labels selected, all cases match
    if (!window.selectedFilterLabels || window.selectedFilterLabels.length === 0) {
      return true;
    }

    // If case has no labels, it doesn't match
    if (!caseData.labels || !Array.isArray(caseData.labels) || caseData.labels.length === 0) {
      return false;
    }

    // Check if case has ANY of the selected labels
    var selectedIds = window.selectedFilterLabels.map(function(l) { return l.id; });
    return caseData.labels.some(function(label) {
      return selectedIds.indexOf(label.id) >= 0;
    });
  };

  /**
   * Update the filter active indicator dot
   */
  function updateFilterActiveIndicator() {
    var dot = document.getElementById('kanbanFilterActiveDot');
    if (!dot) return;

    var hasActiveFilters = (window.selectedFilterLabels && window.selectedFilterLabels.length > 0);
    
    // Also check other filters
    var patientSearch = document.getElementById('patientSearch');
    var filterCaseType = document.getElementById('filterCaseType');
    var filterAssignedTo = document.getElementById('filterAssignedTo');
    var filterLateCases = document.getElementById('filterLateCases');

    if (patientSearch && patientSearch.value) hasActiveFilters = true;
    if (filterCaseType && filterCaseType.value) hasActiveFilters = true;
    if (filterAssignedTo && filterAssignedTo.value) hasActiveFilters = true;
    if (filterLateCases && filterLateCases.checked) hasActiveFilters = true;

    dot.style.display = hasActiveFilters ? 'inline-block' : 'none';
  }

  // Note: initLabelFilters is called from loadPracticeLabels after labels are loaded

})();
