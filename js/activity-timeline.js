/**
 * Activity Timeline JavaScript
 * Loads and displays case activity events in a condensed horizontal timeline
 */

(function() {
  'use strict';

  /**
   * Format a timestamp as short relative time (e.g., "2h", "3d")
   */
  function formatShortTime(dateString) {
    if (!dateString) return '';
    
    var date = new Date(dateString);
    var now = new Date();
    var diffMs = now - date;
    var diffSec = Math.floor(diffMs / 1000);
    var diffMin = Math.floor(diffSec / 60);
    var diffHour = Math.floor(diffMin / 60);
    var diffDay = Math.floor(diffHour / 24);
    var diffWeek = Math.floor(diffDay / 7);
    
    if (diffSec < 60) {
      return 'now';
    } else if (diffMin < 60) {
      return diffMin + 'm';
    } else if (diffHour < 24) {
      return diffHour + 'h';
    } else if (diffDay < 7) {
      return diffDay + 'd';
    } else if (diffWeek < 4) {
      return diffWeek + 'w';
    } else {
      return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
  }

  /**
   * Convert event type and data to short description
   */
  function formatShortDescription(event) {
    var eventType = event.event_type;
    var oldStatus = event.old_status;
    var newStatus = event.new_status;
    var meta = event.meta || {};
    
    switch (eventType) {
      case 'case_created':
        return 'Created';
      
      case 'status_changed':
        if (newStatus) {
          return 'Changed status to ' + newStatus;
        }
        return 'Changed status';
      
      case 'assignment_changed':
        return 'Reassigned';
      
      case 'case_updated':
      case 'fields_updated':
        if (meta.changed_fields && Array.isArray(meta.changed_fields)) {
          if (meta.changed_fields.length === 1) {
            return formatFieldName(meta.changed_fields[0]);
          }
          return meta.changed_fields.length + ' fields';
        }
        return 'Updated';
      
      case 'notes_updated':
        return 'Added note';
      
      case 'attachments_added':
        var count = meta.count || meta.attachment_count || 1;
        return count === 1 ? '+1 file' : '+' + count + ' files';
      
      case 'attachment_deleted':
        return '-1 file';
      
      case 'case_archived':
        return 'Archived';
      
      case 'case_restored':
        return 'Restored';
      
      case 'labels_updated':
        return 'Labels';
      
      case 'due_date_changed':
        return 'Due date';
      
      case 'case_revision':
        if (newStatus) {
          return 'Changed status to ' + newStatus;
        }
        return 'Changed status to Originated';
      
      case 'case_regression':
        if (newStatus) {
          return 'Changed status to ' + newStatus;
        }
        return 'Changed status';
      
      default:
        return eventType.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
          return l.toUpperCase();
        });
    }
  }

  /**
   * Format field names for display
   */
  function formatFieldName(fieldName) {
    var fieldMap = {
      'patientFirstName': 'Name',
      'patientLastName': 'Name',
      'patientDOB': 'DOB',
      'dentistName': 'Dentist',
      'caseType': 'Type',
      'toothShade': 'Shade',
      'material': 'Material',
      'dueDate': 'Due date',
      'status': 'Status',
      'assignedTo': 'Assigned',
      'notes': 'Notes'
    };
    
    return fieldMap[fieldName] || fieldName;
  }

  /**
   * Render a single timeline event as a chip
   */
  function renderTimelineChip(event) {
    var chip = document.createElement('div');
    chip.className = 'activity-event event-' + event.event_type;
    
    var description = formatShortDescription(event);
    var time = formatShortTime(event.created_at);
    var user = event.user_email ? event.user_email.split('@')[0] : 'System';
    
    chip.innerHTML = 
      '<span class="activity-event-dot"></span>' +
      '<span class="activity-event-text">' + escapeHtml(description) + '</span>' +
      '<span class="activity-event-user">by ' + escapeHtml(user) + '</span>' +
      '<span class="activity-event-time">' + escapeHtml(time) + '</span>';
    
    // Add tooltip with full details
    var fullDescription = getFullDescription(event);
    chip.title = fullDescription;
    
    return chip;
  }

  /**
   * Get full description for tooltip
   */
  function getFullDescription(event) {
    var eventType = event.event_type;
    var oldStatus = event.old_status;
    var newStatus = event.new_status;
    var meta = event.meta || {};
    var user = event.user_email ? event.user_email.split('@')[0] : 'System';
    var date = new Date(event.created_at);
    var dateStr = date.toLocaleDateString('en-US', { 
      month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit'
    });
    
    var desc = '';
    
    switch (eventType) {
      case 'case_created':
        desc = 'Case created';
        break;
      case 'status_changed':
        if (oldStatus && newStatus) {
          desc = 'Changed status from ' + oldStatus + ' to ' + newStatus;
        } else if (newStatus) {
          desc = 'Changed status to ' + newStatus;
        } else {
          desc = 'Changed status';
        }
        break;
      case 'assignment_changed':
        desc = 'Case reassigned';
        break;
      case 'case_updated':
      case 'fields_updated':
        if (meta.changed_fields && Array.isArray(meta.changed_fields)) {
          desc = 'Updated: ' + meta.changed_fields.join(', ');
        } else {
          desc = 'Case details updated';
        }
        break;
      case 'attachments_added':
        var count = meta.count || meta.attachment_count || 1;
        desc = count + ' attachment(s) added';
        break;
      case 'attachment_deleted':
        desc = 'Attachment removed';
        break;
      case 'case_revision':
        if (oldStatus && newStatus) {
          desc = 'Changed status from ' + oldStatus + ' to ' + newStatus + ' (revision)';
        } else if (newStatus) {
          desc = 'Changed status to ' + newStatus + ' (revision)';
        } else {
          desc = 'Changed status to Originated (revision)';
        }
        break;
      case 'case_regression':
        if (oldStatus && newStatus) {
          desc = 'Changed status from ' + oldStatus + ' to ' + newStatus + ' (revision)';
        } else if (newStatus) {
          desc = 'Changed status to ' + newStatus + ' (revision)';
        } else {
          desc = 'Changed status (revision)';
        }
        break;
      default:
        desc = eventType.replace(/_/g, ' ');
    }
    
    return desc + '\nBy ' + user + '\n' + dateStr;
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Create connector element between chips
   */
  function createConnector() {
    var connector = document.createElement('div');
    connector.className = 'activity-connector';
    return connector;
  }

  /**
   * Load and display activity timeline for a case
   */
  window.loadActivityTimeline = function(caseId) {
    var container = document.getElementById('caseActivityTimeline');
    var content = document.getElementById('activityTimelineContent');
    
    if (!container || !content) return;
    
    // Show the timeline section
    container.style.display = 'block';
    
    // Show loading state
    content.innerHTML = '<div class="activity-loading"><div class="activity-loading-spinner"></div>Loading...</div>';
    
    // Fetch activity data
    fetch('api/get-case-activity.php?caseId=' + encodeURIComponent(caseId), {
      credentials: 'same-origin'
    })
    .then(function(response) {
      return response.json();
    })
    .then(function(data) {
      if (!data.success || !data.events || data.events.length === 0) {
        content.innerHTML = '<p class="activity-empty-state">No activity recorded yet.</p>';
        return;
      }
      
      // Clear content
      content.innerHTML = '';
      
      // Render events in reverse order (oldest first for horizontal display)
      var events = data.events.slice().reverse();
      
      // Limit to most recent 10 events for compact display
      if (events.length > 10) {
        events = events.slice(events.length - 10);
      }
      
      events.forEach(function(event, index) {
        content.appendChild(renderTimelineChip(event));
        
        // Add connector between events (not after last one)
        if (index < events.length - 1) {
          content.appendChild(createConnector());
        }
      });
    })
    .catch(function(error) {
      console.error('Error loading activity timeline:', error);
      content.innerHTML = '<p class="activity-empty-state">Unable to load activity.</p>';
    });
  };

  /**
   * Hide the activity timeline (for new case creation)
   */
  window.hideActivityTimeline = function() {
    var container = document.getElementById('caseActivityTimeline');
    if (container) {
      container.style.display = 'none';
    }
  };

  /**
   * Clear the activity timeline content
   */
  window.clearActivityTimeline = function() {
    var content = document.getElementById('activityTimelineContent');
    if (content) {
      content.innerHTML = '<p class="activity-empty-state">No activity recorded yet.</p>';
    }
    hideActivityTimeline();
  };

  /**
   * Initialize toggle functionality
   */
  function initToggle() {
    var toggle = document.getElementById('activityTimelineToggle');
    var container = document.getElementById('caseActivityTimeline');
    
    if (toggle && container) {
      toggle.addEventListener('click', function() {
        container.classList.toggle('collapsed');
      });
    }
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initToggle);
  } else {
    initToggle();
  }

})();
