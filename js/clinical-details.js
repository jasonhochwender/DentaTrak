/**
 * Clinical Details JavaScript
 * Handles case-type-specific clinical fields visibility and data management
 */

(function() {
  'use strict';

  // Field mapping: maps clinical field IDs to their data keys
  var clinicalFieldMapping = {
    // Crown
    'clinicalToothNumber': 'toothNumber',
    // Bridge
    'clinicalAbutmentTeeth': 'abutmentTeeth',
    'clinicalPonticTeeth': 'ponticTeeth',
    // Implant Crown
    'clinicalImplantToothNumber': 'implantToothNumber',
    'clinicalAbutmentType': 'abutmentType',
    'clinicalImplantSystem': 'implantSystem',
    'clinicalPlatformSize': 'platformSize',
    'clinicalScanBodyUsed': 'scanBodyUsed',
    // Implant Surgical Guide
    'clinicalImplantSites': 'implantSites',
    // Denture
    'clinicalDentureJaw': 'dentureJaw',
    'clinicalDentureType': 'dentureType',
    'clinicalGingivalShade': 'gingivalShade',
    // Partial
    'clinicalPartialJaw': 'partialJaw',
    'clinicalTeethToReplace': 'teethToReplace',
    'clinicalPartialMaterial': 'partialMaterial',
    'clinicalPartialGingivalShade': 'partialGingivalShade'
  };

  /**
   * Update clinical fields visibility based on selected case type
   */
  function updateClinicalFieldsVisibility(caseType) {
    var section = document.getElementById('clinicalDetailsSection');
    var fields = document.querySelectorAll('.clinical-field');
    
    if (!section || !fields.length) return;
    
    var hasVisibleFields = false;
    
    fields.forEach(function(field) {
      var caseTypes = field.dataset.caseTypes || '';
      var typesList = caseTypes.split(',').map(function(t) { return t.trim(); });
      
      if (typesList.indexOf(caseType) !== -1) {
        field.classList.add('visible');
        hasVisibleFields = true;
      } else {
        field.classList.remove('visible');
        // Clear field value when hidden
        var input = field.querySelector('input, select');
        if (input) {
          input.value = '';
        }
      }
    });
    
    // Show/hide the entire section
    section.style.display = hasVisibleFields ? 'block' : 'none';

    // Update the tooth selector visibility and active field
    if (typeof updateToothSelectorVisibility === 'function') {
      updateToothSelectorVisibility();
    }
  }

  /**
   * Get clinical details data from form fields
   */
  window.getClinicalDetailsData = function() {
    var data = {};
    var caseTypeSelect = document.getElementById('caseType');
    var caseType = caseTypeSelect ? caseTypeSelect.value : '';
    
    if (!caseType) return data;
    
    // Only collect data from visible fields
    var visibleFields = document.querySelectorAll('.clinical-field.visible');
    
    visibleFields.forEach(function(field) {
      var input = field.querySelector('input, select');
      if (input && input.value) {
        var fieldId = input.id;
        var dataKey = clinicalFieldMapping[fieldId];
        if (dataKey) {
          data[dataKey] = input.value;
        }
      }
    });
    
    return data;
  };

  /**
   * Set clinical details data to form fields
   */
  window.setClinicalDetailsData = function(clinicalDetails, caseType) {
    if (!clinicalDetails || typeof clinicalDetails !== 'object') {
      clinicalDetails = {};
    }
    
    // First update visibility based on case type
    updateClinicalFieldsVisibility(caseType);
    
    // Then populate the fields
    Object.keys(clinicalFieldMapping).forEach(function(fieldId) {
      var dataKey = clinicalFieldMapping[fieldId];
      var input = document.getElementById(fieldId);

      if (input && clinicalDetails[dataKey] !== undefined) {
        input.value = clinicalDetails[dataKey];
      }
    });

    // Sync the tooth selector with the active field after values are set
    if (typeof updateToothSelectorVisibility === 'function') {
      updateToothSelectorVisibility();
    }
  };

  /**
   * Clear all clinical details fields
   */
  window.clearClinicalDetailsFields = function() {
    Object.keys(clinicalFieldMapping).forEach(function(fieldId) {
      var input = document.getElementById(fieldId);
      if (input) {
        input.value = '';
      }
    });
    
    // Hide the section
    var section = document.getElementById('clinicalDetailsSection');
    if (section) {
      section.style.display = 'none';
    }
    
    // Remove visible class from all fields
    var fields = document.querySelectorAll('.clinical-field');
    fields.forEach(function(field) {
      field.classList.remove('visible');
    });
  };

  /**
   * Initialize clinical details functionality
   */
  function initClinicalDetails() {
    var caseTypeSelect = document.getElementById('caseType');
    
    if (!caseTypeSelect) return;
    
    // Listen for case type changes
    caseTypeSelect.addEventListener('change', function() {
      updateClinicalFieldsVisibility(this.value);
    });
    
    // Initial visibility update
    updateClinicalFieldsVisibility(caseTypeSelect.value);
  }

  // Make updateClinicalFieldsVisibility available globally
  window.updateClinicalFieldsVisibility = updateClinicalFieldsVisibility;

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initClinicalDetails);
  } else {
    initClinicalDetails();
  }

  /* ---------------------------------------------------------------
     Tooth selector
  --------------------------------------------------------------- */
  var upperTeeth = document.getElementById('upperTeeth');
  var lowerTeeth = document.getElementById('lowerTeeth');
  var toothSelectorContainer = document.getElementById('toothSelectorContainer');
  var toothSelectorActiveField = document.getElementById('toothSelectorActiveField');
  var toothSelectorError = document.getElementById('toothSelectorError');
  var activeToothInput = null;

  // Build tooth buttons (1-16 upper, 32-17 lower)
  function buildToothButtons() {
    for (var n = 1; n <= 16; n++) {
      upperTeeth.appendChild(makeToothButton(n));
    }
    for (var m = 32; m >= 17; m--) {
      lowerTeeth.appendChild(makeToothButton(m));
    }
  }

  function makeToothButton(num) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tooth-button';
    btn.textContent = num;
    btn.setAttribute('data-tooth', num);
    btn.setAttribute('aria-pressed', 'false');
    btn.setAttribute('aria-label', 'Tooth ' + num);
    btn.addEventListener('click', function() { toggleTooth(num); });
    return btn;
  }

  function parseToothString(str) {
    var selected = new Set();
    var invalid = [];
    if (!str || typeof str !== 'string') return { selected: selected, invalid: invalid };

    var parts = str.split(/[\s,]+/).filter(function(p) { return p.length > 0; });
    parts.forEach(function(part) {
      var range = part.split('-');
      if (range.length === 1) {
        var n = parseInt(part, 10);
        if (isNaN(n) || n < 1 || n > 32) {
          invalid.push(part);
        } else {
          selected.add(n);
        }
      } else if (range.length === 2) {
        var start = parseInt(range[0], 10);
        var end = parseInt(range[1], 10);
        if (isNaN(start) || isNaN(end) || start < 1 || end > 32 || start > end) {
          invalid.push(part);
        } else {
          for (var k = start; k <= end; k++) selected.add(k);
        }
      } else {
        invalid.push(part);
      }
    });

    return { selected: selected, invalid: invalid };
  }

  function formatToothString(nums) {
    var sorted = Array.from(nums).sort(function(a, b) { return a - b; });
    var result = [];
    var i = 0;
    while (i < sorted.length) {
      var start = sorted[i];
      var end = start;
      while (i + 1 < sorted.length && sorted[i + 1] === end + 1) {
        end = sorted[++i];
      }
      if (end - start >= 2) {
        result.push(start + '-' + end);
      } else if (end === start + 1) {
        result.push(start + ', ' + end);
      } else {
        result.push(start);
      }
      i++;
    }
    return result.join(', ');
  }

  function updateToothChart(selected) {
    document.querySelectorAll('.tooth-button').forEach(function(btn) {
      var n = parseInt(btn.getAttribute('data-tooth'), 10);
      var isSelected = selected.has(n);
      btn.classList.toggle('selected', isSelected);
      btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    });
  }

  function toggleTooth(num) {
    if (!activeToothInput) return;
    var parsed = parseToothString(activeToothInput.value);
    if (parsed.selected.has(num)) {
      parsed.selected.delete(num);
    } else {
      parsed.selected.add(num);
    }
    activeToothInput.value = formatToothString(parsed.selected);
    activeToothInput.dispatchEvent(new Event('input', { bubbles: true }));
    activeToothInput.dispatchEvent(new Event('change', { bubbles: true }));
    syncActiveField(false);
  }

  function showToothError(msg) {
    if (!toothSelectorError) return;
    toothSelectorError.textContent = msg;
    toothSelectorError.classList.toggle('visible', !!msg);
    if (activeToothInput) activeToothInput.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }

  function syncActiveField(parseInput) {
    if (!activeToothInput) return;
    var rawValue = activeToothInput.value.trim();
    var parsed = parseToothString(rawValue);
    var sortedInvalid = parsed.invalid.filter(function(v, i, a) { return a.indexOf(v) === i; });

    if (sortedInvalid.length > 0 || (rawValue && parsed.selected.size === 0 && rawValue !== '')) {
      showToothError('Enter tooth numbers from 1 to 32, separated by commas or ranges such as 2-4.');
    } else if (rawValue === '') {
      showToothError('');
    } else {
      showToothError('');
    }

    updateToothChart(parsed.selected);

    if (parseInput && activeToothInput && parsed.invalid.length === 0) {
      var formatted = formatToothString(parsed.selected);
      if (formatted !== rawValue) {
        activeToothInput.value = formatted;
      }
    }
  }

  function setActiveToothInput(input) {
    if (activeToothInput === input) return;
    activeToothInput = input;
    if (toothSelectorActiveField) {
      var label = input ? input.previousElementSibling : null;
      var labelText = label ? label.textContent.replace(/\*/g, '').trim() : 'selected field';
      toothSelectorActiveField.textContent = input ? labelText : '';
    }
    // Move the chart directly beneath the active tooth field
    if (input && toothSelectorContainer) {
      toothSelectorContainer.style.display = 'block';
      input.parentElement.appendChild(toothSelectorContainer);
    }
    syncActiveField(false);
  }

  function updateToothSelectorVisibility() {
    if (!toothSelectorContainer) return;
    var visibleToothInputs = document.querySelectorAll('.clinical-field.visible input[data-tooth-selector="true"]');
    var hasAny = visibleToothInputs.length > 0;
    toothSelectorContainer.style.display = hasAny ? 'block' : 'none';

    if (hasAny) {
      // Select the first visible tooth field by default (or keep the active one if it is still visible)
      var inputs = Array.from(visibleToothInputs);
      var target = activeToothInput && inputs.indexOf(activeToothInput) !== -1 ? activeToothInput : inputs[0];
      setActiveToothInput(target);
    } else {
      activeToothInput = null;
    }
  }

  function attachToothFieldHandlers() {
    var toothInputs = document.querySelectorAll('input[data-tooth-selector="true"]');
    toothInputs.forEach(function(input) {
      input.addEventListener('focus', function() { setActiveToothInput(input); });
      input.addEventListener('input', function() { if (activeToothInput === input) syncActiveField(false); });
      input.addEventListener('blur', function() {
        if (activeToothInput === input) {
          syncActiveField(true);
        }
      });
    });
  }

  // Hook into existing visibility update
  var originalUpdateVisibility = window.updateClinicalFieldsVisibility;
  window.updateClinicalFieldsVisibility = function(caseType) {
    originalUpdateVisibility(caseType);
    // Need to let the original run first so visibility classes are updated
    window.setTimeout(updateToothSelectorVisibility, 0);
  };

  function initToothSelector() {
    if (!upperTeeth || !lowerTeeth) return;
    buildToothButtons();
    attachToothFieldHandlers();
    // Show/hide after initial render
    var caseTypeSelect = document.getElementById('caseType');
    if (caseTypeSelect) {
      updateToothSelectorVisibility();
    }
  }

  // Run when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initToothSelector);
  } else {
    initToothSelector();
  }

})();
