/**
 * Case Assignment JavaScript
 * Handles the functionality for editing case assignments directly from Kanban cards
 */

/**
 * Get the current user's email
 */
function getCurrentUserEmail() {
  // Try to get from the UI first
  const userEmailElement = document.querySelector('.user-email');
  if (userEmailElement) {
    return userEmailElement.textContent.trim();
  }
  
  // Try session storage as a fallback
  return sessionStorage.getItem('userEmail') || '';
}

// Store the current user's information
window.currentUser = {
  id: null,
  email: getCurrentUserEmail(),
  profile_picture: null
};

// Main initialization function
document.addEventListener('DOMContentLoaded', function() {
  // Pre-load all assignments for faster dropdown population
  fetchAllCaseAssignments();
  
  // Set up event delegation for assignment dropdowns
  document.body.addEventListener('change', function(e) {
    // Check if the changed element is an assignment select
    if (e.target.matches('.assignment-select')) {
      const caseId = e.target.dataset.caseId;
      const assignedTo = e.target.value;
      saveAssignment(caseId, assignedTo, e.target);
    }
  });
});

/**
 * Initialize the assignment dropdown for a case
 * @param {HTMLElement} selectElement - The select element to initialize
 * @param {string} caseId - The ID of the case
 * @param {string} currentAssignee - The current assignee email or empty string
 */
function initializeAssignmentDropdown(selectElement, caseId, currentAssignee) {
  if (!selectElement) return;
  
  // Clear existing options first
  selectElement.innerHTML = '';
  
  // Add loading option
  const loadingOption = document.createElement('option');
  loadingOption.value = 'loading';
  loadingOption.textContent = 'Loading...';
  loadingOption.disabled = true;
  loadingOption.selected = true;
  selectElement.appendChild(loadingOption);
  
  // First, check if this case has an assignment in the cache
  if (caseId && typeof window.caseAssignments === 'object' && window.caseAssignments[caseId]) {
    const cachedAssignment = window.caseAssignments[caseId];
    if (cachedAssignment && cachedAssignment !== currentAssignee) {
      currentAssignee = cachedAssignment;
    }
  }
  
  // Load available users
  loadAvailableUsers().then(users => {
    // Remove loading option
    selectElement.innerHTML = '';
    
    // Add a "None" option
    const noneOption = document.createElement('option');
    noneOption.value = '';
    noneOption.textContent = 'None';
    selectElement.appendChild(noneOption);
    
    // Add current user first if not already in the list
    const currentUserEmail = getCurrentUserEmail();
    let currentUserIncluded = false;
    
    if (currentUserEmail && !users.includes(currentUserEmail)) {
      const currentUserOption = document.createElement('option');
      currentUserOption.value = currentUserEmail;
      currentUserOption.textContent = currentUserEmail + ' (You)';
      selectElement.appendChild(currentUserOption);
      currentUserIncluded = true;
      
      // Also add to the global array if needed
      if (!window.gmailUsers) window.gmailUsers = [];
      if (!window.gmailUsers.includes(currentUserEmail)) {
        window.gmailUsers.push(currentUserEmail);
      }
    }
    
    // Add other users
    users.forEach(user => {
      // Skip if this is the current user and we already added them
      if (user === currentUserEmail && currentUserIncluded) return;
      
      const option = document.createElement('option');
      option.value = user;
      option.textContent = user;
      
      // Mark as "You" if this is the current user
      if (user === currentUserEmail) {
        option.textContent += ' (You)';
      }
      
      selectElement.appendChild(option);
    });
    
    // Set the current assignee as selected
    if (currentAssignee) {
      try {
        // First try to find by exact value
        selectElement.value = currentAssignee;
        
        // If that didn't work, try forcing the selection
        if (selectElement.selectedIndex === -1 || selectElement.value !== currentAssignee) {
          let foundOption = false;
          
          // Try finding by iterating options
          for (let i = 0; i < selectElement.options.length; i++) {
            if (selectElement.options[i].value === currentAssignee) {
              selectElement.selectedIndex = i;
              foundOption = true;
              break;
            }
          }
          
          // If not found, create a new option for this assigned email
          if (!foundOption) {
            const option = document.createElement('option');
            option.value = currentAssignee;
            option.textContent = currentAssignee;
            selectElement.appendChild(option);
            selectElement.value = currentAssignee;
          }
        }
      } catch (e) {
        // Error setting selection
      }
    } else {
      // Select "None" if no current assignee
      try {
        selectElement.value = '';
      } catch (e) {
        // Error setting selection to None
      }
    }
  });
}

/**
 * Load available users for assignment
 * NOTE: Only returns practice users (admins + regular users), NOT assignment labels
 * Labels are now a separate concept from case ownership
 */
function loadAvailableUsers() {
  return new Promise((resolve, reject) => {
    // Default users list with current user (if available)
    const defaultUsers = [];
    const currentUserEmail = getCurrentUserEmail();
    if (currentUserEmail) {
      defaultUsers.push(currentUserEmail);
    }
    
    // Only use practice users (gmailUsers + adminUsers), NOT assignmentLabels
    // Assigned To must be a real human user in the practice
    if ((window.gmailUsers && window.gmailUsers.length > 0) || (window.adminUsers && window.adminUsers.length > 0)) {
      const combinedCached = [];
      const seenCached = {};

      function addCached(value) {
        if (!value) return;
        const key = value.toLowerCase();
        if (seenCached[key]) return;
        seenCached[key] = true;
        combinedCached.push(value);
      }

      // Add admin users first
      if (window.adminUsers && window.adminUsers.length > 0) {
        window.adminUsers.forEach(addCached);
      }

      // Add regular users
      if (window.gmailUsers && window.gmailUsers.length > 0) {
        window.gmailUsers.forEach(addCached);
      }

      resolve(combinedCached.length > 0 ? combinedCached : defaultUsers);
      return;
    }
    
    // Wait briefly for app.js to load settings into window.gmailUsers/window.adminUsers
    setTimeout(() => {
      if ((window.gmailUsers && window.gmailUsers.length > 0) || (window.adminUsers && window.adminUsers.length > 0)) {
        const combined = [];
        const seen = {};

        function addItem(value) {
          if (!value) return;
          const key = value.toLowerCase();
          if (seen[key]) return;
          seen[key] = true;
          combined.push(value);
        }

        if (window.adminUsers) window.adminUsers.forEach(addItem);
        if (window.gmailUsers) window.gmailUsers.forEach(addItem);

        resolve(combined.length > 0 ? combined : defaultUsers);
      } else {
        resolve(defaultUsers);
      }
    }, 500);
  });
}

/**
 * Pre-fetch all case assignments to cache them
 */
function fetchAllCaseAssignments() {
  // Initialize the global assignments object if it doesn't exist
  if (typeof window.caseAssignments !== 'object') {
    window.caseAssignments = {};
  }
  
  fetch('api/get-all-case-assignments.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.assignments && data.assignments.length > 0) {
        // Store assignments in the global object
        data.assignments.forEach(assignment => {
          if (assignment.case_id && assignment.email) {
            window.caseAssignments[assignment.case_id] = assignment.email;
          }
        });
        
        // Update dropdowns that are already on the page
        const assignmentDropdowns = document.querySelectorAll('.assignment-select');
        
        assignmentDropdowns.forEach(selectElement => {
          try {
            const caseId = selectElement.dataset.caseId;
            if (caseId && window.caseAssignments[caseId]) {
              const assignedEmail = window.caseAssignments[caseId];
              
              // First try simple setting
              selectElement.value = assignedEmail;
              
              // If that didn't work, we may need to add the option
              if (selectElement.value !== assignedEmail) {
                let hasOption = false;
                
                // Check if option exists
                for (let i = 0; i < selectElement.options.length; i++) {
                  if (selectElement.options[i].value === assignedEmail) {
                    selectElement.selectedIndex = i;
                    hasOption = true;
                    break;
                  }
                }
                
                // If not, create it
                if (!hasOption) {
                  const option = document.createElement('option');
                  option.value = assignedEmail;
                  option.textContent = assignedEmail;
                  selectElement.appendChild(option);
                  selectElement.value = assignedEmail;
                }
              }
            }
          } catch (e) {
            // Error updating dropdown
          }
        });
      }
    })
    .catch(error => {
      // Error fetching case assignments
    });
}

/**
 * Save the assignment for a case
 */
function saveAssignment(caseId, assignedTo, selectElement) {
  // Find the card element that contains this assignment
  const card = selectElement.closest('.kanban-card');
  if (!card) {
    return;
  }
  
  // Get the case data from the card
  let caseData;
  try {
    caseData = JSON.parse(card.dataset.caseJson);
  } catch (e) {
    return;
  }
  
  // Update the assigned user in case data
  caseData.assignedTo = assignedTo;
  
  // Update the case JSON data attribute on the card
  card.dataset.caseJson = JSON.stringify(caseData);
  
  // Show a temporary loading indicator
  const originalText = selectElement.options[selectElement.selectedIndex].text;
  selectElement.options[selectElement.selectedIndex].text = 'Saving...';
  selectElement.disabled = true;
  
  // Save to server - ensure clean data
  const payload = {
    caseId: caseId,
    assignedTo: assignedTo
  };
  
  // Temporarily add debug alert to see what's happening
  if (selectElement.parentNode.querySelector('.debug-info')) {
    selectElement.parentNode.querySelector('.debug-info').remove();
  }
  
  const debugInfo = document.createElement('span');
  debugInfo.className = 'debug-info';
  debugInfo.style.display = 'none';
  debugInfo.textContent = 'Saving: ' + caseId + ' -> ' + assignedTo;
  selectElement.parentNode.appendChild(debugInfo);
  
  var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  fetch('api/update-case-assignment.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify(payload)
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Ensure the card data is updated with the new assignment
      caseData.assignedTo = assignedTo;
      card.dataset.caseJson = JSON.stringify(caseData);
    }
    
    // Reset the dropdown
    selectElement.options[selectElement.selectedIndex].text = originalText;
    selectElement.disabled = false;
  })
  .catch(error => {
    // Error updating assignment
    selectElement.options[selectElement.selectedIndex].text = originalText;
    selectElement.disabled = false;
  });
}
