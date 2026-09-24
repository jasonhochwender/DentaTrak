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
      return t('cases.activity.timeline.rel_now');
    } else if (diffMin < 60) {
      return t('cases.activity.timeline.rel_min', { count: diffMin });
    } else if (diffHour < 24) {
      return t('cases.activity.timeline.rel_hour', { count: diffHour });
    } else if (diffDay < 7) {
      return t('cases.activity.timeline.rel_day', { count: diffDay });
    } else if (diffWeek < 4) {
      return t('cases.activity.timeline.rel_week', { count: diffWeek });
    } else {
      return date.toLocaleDateString((window.I18n && I18n.locale) || 'en-US', { month: 'short', day: 'numeric' });
    }
  }

  /**
   * Convert event type and data to short description
   */
  function formatShortDescription(event) {
    var eventType = event.event_type;
    // Raw old_status/new_status remain stored/read as-is; only this
    // render-time text resolves them to the practice's current display
    // labels (see getStageLabel() in js/app.js).
    var oldStatus = event.old_status ? getStageLabel(event.old_status) : event.old_status;
    var newStatus = event.new_status ? getStageLabel(event.new_status) : event.new_status;
    var meta = event.meta || {};
    
    var tlKey = 'cases.activity.timeline.';
    switch (eventType) {
      case 'case_created':
        return t(tlKey + 'created');
      
      case 'status_changed':
        if (newStatus) {
          return t(tlKey + 'status_to', { status: newStatus });
        }
        return t(tlKey + 'status_changed');
      
      case 'assignment_changed':
        return t(tlKey + 'reassigned');
      
      case 'case_updated':
      case 'fields_updated':
        if (meta.changed_fields && Array.isArray(meta.changed_fields)) {
          if (meta.changed_fields.length === 1) {
            return formatFieldName(meta.changed_fields[0]);
          }
          return I18n.pluralize(meta.changed_fields.length, tlKey + 'fields_count', { count: meta.changed_fields.length });
        }
        return t(tlKey + 'updated');
      
      case 'notes_updated':
        return t(tlKey + 'note_added');
      
      case 'attachments_added':
        var count = meta.count || meta.attachment_count || 1;
        return I18n.pluralize(count, tlKey + 'file_added', { count: count });
      
      case 'attachment_deleted':
        return t(tlKey + 'file_removed');
      
      case 'case_archived':
        return t(tlKey + 'archived');
      
      case 'case_restored':
        return t(tlKey + 'restored');

      case 'remake_initiated':
        var remakeReason = meta.remake_reason ? t('remakes.reasons.' + meta.remake_reason) : '';
        if (remakeReason && remakeReason.indexOf('remakes.') !== 0) {
          return t(tlKey + 'remake_reason', { number: (meta.remake_number || '?'), reason: remakeReason });
        }
        return t(tlKey + 'remake_recorded', { number: (meta.remake_number || '?') });

      case 'remake_completed':
        return t(tlKey + 'remake_completed', { number: (meta.remake_number || '?') });
      
      case 'labels_updated':
        return t(tlKey + 'labels');
      
      case 'due_date_changed':
        return t(tlKey + (meta.due_date_removed ? 'due_date_removed' : 'due_date'));
      
      case 'case_revision':
        if (newStatus) {
          return t(tlKey + 'status_to', { status: newStatus });
        }
        return t(tlKey + 'status_to', { status: (typeof getStageLabel === 'function' ? getStageLabel('Originated') : 'Originated') });
      
      case 'case_regression':
        if (newStatus) {
          return t(tlKey + 'status_to', { status: newStatus });
        }
        return t(tlKey + 'status_changed');

      case 'review_status_changed':
        if (newStatus === 'reviewed') {
          return t(tlKey + 'review_marked', { status: t('cases.reviewed').toLowerCase() });
        }
        return t(tlKey + 'review_marked', { status: t('cases.needs_review').toLowerCase() });

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
    var translated = t('cases.activity.timeline.fields.' + fieldName);
    return translated || fieldName;
  }

  /**
   * Render a single timeline event as a chip
   */
  function renderTimelineChip(event) {
    var chip = document.createElement('div');
    chip.className = 'activity-event event-' + event.event_type;
    
    var description = formatShortDescription(event);
    var time = formatShortTime(event.created_at);
    var user = event.user_email ? event.user_email.split('@')[0] : t('cases.activity.timeline.system');

    chip.innerHTML =
      '<span class="activity-event-dot"></span>' +
      '<span class="activity-event-text">' + escapeHtml(description) + '</span>' +
      '<span class="activity-event-user">' + escapeHtml(t('cases.activity.timeline.by_user', { user: user })) + '</span>' +
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
    // Raw old_status/new_status remain stored/read as-is; only this
    // render-time text resolves them to the practice's current display
    // labels (see getStageLabel() in js/app.js).
    var oldStatus = event.old_status ? getStageLabel(event.old_status) : event.old_status;
    var newStatus = event.new_status ? getStageLabel(event.new_status) : event.new_status;
    var meta = event.meta || {};
    var user = event.user_email ? event.user_email.split('@')[0] : t('cases.activity.timeline.system');
    var tlKey = 'cases.activity.timeline.';
    var date = new Date(event.created_at);
    var dateStr = date.toLocaleDateString((window.I18n && I18n.locale) || 'en-US', {
      month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit'
    });
    
    var desc = '';
    
    switch (eventType) {
      case 'case_created':
        desc = t(tlKey + 'full_created');
        break;
      case 'status_changed':
        if (oldStatus && newStatus) {
          desc = t(tlKey + 'full_status_from_to', { old: oldStatus, new: newStatus });
        } else if (newStatus) {
          desc = t(tlKey + 'full_status_to', { new: newStatus });
        } else {
          desc = t(tlKey + 'full_status_changed');
        }
        break;
      case 'assignment_changed':
        desc = t(tlKey + 'full_reassigned');
        break;
      case 'case_updated':
      case 'fields_updated':
        if (meta.changed_fields && Array.isArray(meta.changed_fields)) {
          desc = t(tlKey + 'full_updated_fields', { fields: meta.changed_fields.join(', ') });
        } else {
          desc = t(tlKey + 'full_updated');
        }
        break;
      case 'attachments_added':
        var count = meta.count || meta.attachment_count || 1;
        desc = I18n.pluralize(count, tlKey + 'full_attachments_added', { count: count });
        break;
      case 'attachment_deleted':
        desc = t(tlKey + 'full_attachment_removed');
        break;
      case 'case_revision':
        if (oldStatus && newStatus) {
          desc = t(tlKey + 'full_status_from_to_revision', { old: oldStatus, new: newStatus });
        } else if (newStatus) {
          desc = t(tlKey + 'full_status_to_revision', { new: newStatus });
        } else {
          desc = t(tlKey + 'full_status_to_revision', { new: (typeof getStageLabel === 'function' ? getStageLabel('Originated') : 'Originated') });
        }
        break;
      case 'case_regression':
        if (oldStatus && newStatus) {
          desc = t(tlKey + 'full_status_from_to_revision', { old: oldStatus, new: newStatus });
        } else if (newStatus) {
          desc = t(tlKey + 'full_status_to_revision', { new: newStatus });
        } else {
          desc = t(tlKey + 'full_status_changed_revision');
        }
        break;
      case 'review_status_changed':
        var reviewLabelReviewed = t('cases.reviewed');
        var reviewLabelNeedsReview = t('cases.needs_review');
        var resolvedOld = oldStatus === 'reviewed' ? reviewLabelReviewed : (oldStatus === 'needs_review' ? reviewLabelNeedsReview : oldStatus);
        var resolvedNew = newStatus === 'reviewed' ? reviewLabelReviewed : (newStatus === 'needs_review' ? reviewLabelNeedsReview : newStatus);
        if (resolvedOld && resolvedNew) {
          desc = t(tlKey + 'full_review_from_to', { old: resolvedOld, new: resolvedNew });
        } else if (resolvedNew) {
          desc = t(tlKey + 'full_review_to', { new: resolvedNew });
        } else {
          desc = t(tlKey + 'full_review_changed');
        }
        break;
      default:
        desc = eventType.replace(/_/g, ' ');
    }
    
    return desc + '\n' + t(tlKey + 'by_user_line', { user: user }) + '\n' + dateStr;
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
    content.innerHTML = '<div class="activity-loading"><div class="activity-loading-spinner"></div>' + escapeHtml(t('common.loading')) + '</div>';
    
    // Fetch activity data
    fetch('api/get-case-activity.php?caseId=' + encodeURIComponent(caseId), {
      credentials: 'same-origin'
    })
    .then(function(response) {
      return response.json();
    })
    .then(function(data) {
      if (!data.success || !data.events || data.events.length === 0) {
        content.innerHTML = '<p class="activity-empty-state">' + escapeHtml(t('cases.activity.empty')) + '</p>';
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
      content.innerHTML = '<p class="activity-empty-state">' + escapeHtml(t('cases.activity.error')) + '</p>';
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
      content.innerHTML = '<p class="activity-empty-state">' + escapeHtml(t('cases.activity.empty')) + '</p>';
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
