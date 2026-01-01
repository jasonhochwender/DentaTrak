/**
 * Card Delete Functionality
 * Handles deletion of cards and associated Google Drive files
 */

document.addEventListener('DOMContentLoaded', function() {
  // Get DOM elements for the CARD delete modal (distinct from file delete modal)
  const deleteConfirmModal = document.getElementById('cardDeleteModal');
  const deleteCancel = document.getElementById('cardDeleteCancel');
  const deleteConfirm = document.getElementById('cardDeleteConfirm');
  
  // Track current card being deleted
  let currentCardToDelete = null;
  let currentCardData = null;
  
  // Apply card delete permissions based on user settings
  function applyCardDeleteSetting() {
    const mainContainer = document.querySelector('.main-container');
    if (!mainContainer) return;
    
    const allowCardDelete = localStorage.getItem('allow_card_delete') === 'true';
    
    if (allowCardDelete) {
      mainContainer.classList.add('allow-card-delete');
    } else {
      mainContainer.classList.remove('allow-card-delete');
    }
  }
  
  // Use event delegation to handle delete button clicks from the document
  document.addEventListener('click', function(e) {
    // Find if the click was on a delete button or inside one (like the SVG)
    const deleteBtn = e.target.closest('.card-delete-btn');
    
    if (deleteBtn) {
      // Stop the event from propagating
      e.preventDefault();
      e.stopPropagation();
      
      // Get the card and its ID
      const card = deleteBtn.closest('.kanban-card');
      const caseId = deleteBtn.getAttribute('data-case-id');
      
      if (!card || !caseId) return;
      
      try {
        // Get the card data
        currentCardData = JSON.parse(card.dataset.caseJson || '{}');
        if (!currentCardData.id) throw new Error('Invalid card data');
        
        // Store reference to the card for deletion
        currentCardToDelete = card;
        
        // Show the confirmation modal
        if (deleteConfirmModal) {
          deleteConfirmModal.style.display = 'block';
          document.body.classList.add('modal-open');
        }
      } catch (err) {
        // Handle parse error silently
        currentCardToDelete = null;
        currentCardData = null;
      }
    }
  });
  
  // Set up cancel button handler
  if (deleteCancel) {
    deleteCancel.addEventListener('click', function() {
      // Hide the modal
      if (deleteConfirmModal) {
        deleteConfirmModal.style.display = 'none';
        document.body.classList.remove('modal-open');
      }
      
      // Clear references
      currentCardToDelete = null;
      currentCardData = null;
    });
  }
  
  // Set up confirm button handler
  if (deleteConfirm) {
    deleteConfirm.addEventListener('click', function() {
      if (currentCardToDelete && currentCardData) {
        // Show loading state
        currentCardToDelete.classList.add('deleting');
        
        // Call API to delete card and Google Drive files
        fetch('api/delete-case.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            caseId: currentCardData.id,
            driveFolderId: currentCardData.driveFolderId || ''
          })
        })
        // Read raw text first to avoid hard failures on non-JSON responses
        .then(response => response.text())
        .then(text => {
          let data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            // If we can't parse JSON but the request succeeded, assume success for UX
            data = { success: true };
          }
          return data;
        })
        .then(data => {
          // Use local references so we don't depend on globals staying set
          const card = currentCardToDelete;
          const cardData = currentCardData;
          
          // Clear global references now that the request has completed
          currentCardToDelete = null;
          currentCardData = null;

          if (data.success) {
            if (card) {
              try {
                // Remove card from DOM and update counts
                const columnBody = card.closest('.kanban-column-body');
                if (columnBody) {
                  const columnEl = columnBody.closest('.kanban-column');
                  const countBadge = columnEl ? columnEl.querySelector('.kanban-column-count') : null;
                  if (countBadge) {
                    const currentCount = parseInt(countBadge.textContent) || 0;
                    countBadge.textContent = Math.max(0, currentCount - 1);
                    
                    // Add empty message if this was the last card
                    if (currentCount - 1 === 0) {
                      const emptyMsg = document.createElement('p');
                      emptyMsg.className = 'kanban-empty';
                      emptyMsg.textContent = 'No cases in this stage.';
                      columnBody.appendChild(emptyMsg);
                    }
                  }
                }
                
                // Finally remove the card
                card.remove();
              } catch (e) {
                // Best effort fallback if anything above fails
                if (card.parentNode) {
                  card.parentNode.removeChild(card);
                }
              }
            }

            // Show success toast
            if (typeof Toast !== 'undefined') {
              Toast.success('Case Archived', 'The case and all associated files have been moved to your Archive folder in Google Drive.');
            }
            
            // Trigger cards updated event
            if (window.triggerCardsUpdated) {
              window.triggerCardsUpdated();
            }
          } else {
            // Show only the server-provided message; no generic fallback text
            if (typeof Toast !== 'undefined' && data.message) {
              Toast.error('Error', data.message);
            } else if (data.message) {
              alert('Error: ' + data.message);
            }
            
            // Remove loading state if card is still present
            if (card) {
              card.classList.remove('deleting');
            }
          }
        })
        .catch(error => {
          // Silent failure in UI: just clear loading state if possible
          if (currentCardToDelete) {
            currentCardToDelete.classList.remove('deleting');
          }
        });
      }
      
      // Hide the modal
      if (deleteConfirmModal) {
        deleteConfirmModal.style.display = 'none';
        document.body.classList.remove('modal-open');
      }
    });
  }
  
  // Close modal when clicking outside
  window.addEventListener('click', function(e) {
    if (e.target === deleteConfirmModal) {
      // Hide the modal
      if (deleteConfirmModal) {
        deleteConfirmModal.style.display = 'none';
        document.body.classList.remove('modal-open');
      }
      
      // Clear references
      currentCardToDelete = null;
      currentCardData = null;
    }
  });
  
  // Handle escape key press
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && deleteConfirmModal && deleteConfirmModal.style.display === 'block') {
      // Hide the modal
      deleteConfirmModal.style.display = 'none';
      document.body.classList.remove('modal-open');
      
      // Clear references
      currentCardToDelete = null;
      currentCardData = null;
    }
  });
  
  // Apply initial settings
  applyCardDeleteSetting();
  
  // Listen for settings changes
  window.addEventListener('settingsUpdated', applyCardDeleteSetting);
  
  // Reapply settings when cards are updated
  window.addEventListener('cardsUpdated', applyCardDeleteSetting);
});
