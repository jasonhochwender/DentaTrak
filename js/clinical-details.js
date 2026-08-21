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

})();
