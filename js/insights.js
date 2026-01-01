/**
 * Insights Tab JavaScript
 * Handles loading and displaying AI-powered operational insights
 */

(function() {
  'use strict';

  var insightsLoaded = false;
  var isLoading = false;

  /**
   * Initialize insights when tab is shown
   */
  function initInsights() {
    var refreshBtn = document.getElementById('insightsRefreshBtn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function() {
        loadInsights(true);
      });
    }
  }

  /**
   * Load insights data
   */
  function loadInsights(forceRefresh) {
    if (isLoading) return;
    
    // Only auto-load once unless forced
    if (insightsLoaded && !forceRefresh) return;
    
    isLoading = true;
    
    var refreshBtn = document.getElementById('insightsRefreshBtn');
    if (refreshBtn) {
      refreshBtn.classList.add('loading');
    }
    
    // Load quick stats first (fast, from existing data)
    loadQuickStats();
    
    // Then load AI recommendations
    loadAIRecommendations();
  }

  /**
   * Load quick stats from case data
   */
  function loadQuickStats() {
    fetch('api/get-analytics.php', {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        updateQuickStats(data);
      }
    })
    .catch(function(error) {
      console.error('Error loading quick stats:', error);
    });
  }

  /**
   * Update quick stats display
   */
  function updateQuickStats(data) {
    // Extract metrics from the response structure
    var metrics = data.data && data.data.metrics ? data.data.metrics : {};
    var charts = data.data && data.data.charts ? data.data.charts : {};
    
    // Late cases (past due)
    var lateCasesEl = document.getElementById('insightsLateCases');
    if (lateCasesEl) {
      var lateCases = metrics.casesPastDue || 0;
      lateCasesEl.textContent = lateCases;
      lateCasesEl.parentElement.parentElement.classList.toggle('has-issues', lateCases > 0);
    }
    
    // Due this week
    var dueThisWeekEl = document.getElementById('insightsDueThisWeek');
    if (dueThisWeekEl) {
      dueThisWeekEl.textContent = metrics.casesDueThisWeek || 0;
    }
    
    // Unassigned
    var unassignedEl = document.getElementById('insightsUnassigned');
    if (unassignedEl) {
      var unassigned = metrics.unassignedCases || 0;
      unassignedEl.textContent = unassigned;
      unassignedEl.parentElement.parentElement.classList.toggle('has-issues', unassigned > 0);
    }
    
    // Active cases
    var activeCasesEl = document.getElementById('insightsActiveCases');
    if (activeCasesEl) {
      activeCasesEl.textContent = metrics.totalActiveCases || 0;
    }
    
    // At Risk cases
    var atRiskEl = document.getElementById('insightsAtRiskCases');
    if (atRiskEl) {
      var atRiskCases = metrics.atRiskCases || 0;
      atRiskEl.textContent = atRiskCases;
      atRiskEl.parentElement.parentElement.classList.toggle('has-issues', atRiskCases > 0);
    }
    
    // Update bottlenecks based on status distribution
    updateBottlenecks(metrics, charts);
  }

  /**
   * Update bottlenecks display
   */
  function updateBottlenecks(metrics, charts) {
    var container = document.getElementById('insightsBottlenecks');
    if (!container) return;
    
    // Build status counts from charts.statusDistribution array
    var statusCounts = {};
    if (charts && charts.statusDistribution && Array.isArray(charts.statusDistribution)) {
      charts.statusDistribution.forEach(function(item) {
        if (item.status) {
          statusCounts[item.status] = item.count || 0;
        }
      });
    }
    var bottlenecks = [];
    
    // Define thresholds for bottleneck detection
    var warningThreshold = 5;
    var criticalThreshold = 10;
    
    // Check each status for potential bottlenecks
    var statusOrder = ['Originated', 'Sent To External Lab', 'Designed', 'Manufactured', 'Received From External Lab'];
    
    statusOrder.forEach(function(status) {
      var count = parseInt(statusCounts[status] || 0, 10);
      if (count >= warningThreshold) {
        bottlenecks.push({
          stage: status,
          count: count,
          severity: count >= criticalThreshold ? 'critical' : 'warning',
          message: count >= criticalThreshold 
            ? 'Critical backlog - immediate attention needed'
            : 'Building up - consider prioritizing'
        });
      }
    });
    
    // Check for overdue cases
    var overdueCases = metrics.casesPastDue || 0;
    if (overdueCases > 0) {
      bottlenecks.unshift({
        stage: 'Overdue Cases',
        count: overdueCases,
        severity: overdueCases >= 5 ? 'critical' : 'warning',
        message: overdueCases >= 5 
          ? 'Multiple cases past due date - review immediately'
          : 'Cases past due date - follow up needed'
      });
    }
    
    // Check for unassigned cases
    var unassignedCases = metrics.unassignedCases || 0;
    if (unassignedCases > 0) {
      bottlenecks.push({
        stage: 'Unassigned Cases',
        count: unassignedCases,
        severity: unassignedCases >= 5 ? 'critical' : 'warning',
        message: 'Cases without an owner - assign to team members'
      });
    }
    
    // Check for cases with regressions (backward stage movements)
    var casesWithRegressions = metrics.casesWithRegressions || 0;
    if (casesWithRegressions > 0) {
      var multipleRegressions = metrics.casesWithMultipleRegressions || 0;
      bottlenecks.push({
        stage: 'Cases with Regressions',
        count: casesWithRegressions,
        severity: multipleRegressions >= 3 ? 'critical' : 'warning',
        message: multipleRegressions > 0 
          ? multipleRegressions + ' case(s) with multiple regressions - review quality'
          : 'Cases moved backward in workflow - may indicate rework'
      });
    }
    
    if (bottlenecks.length === 0) {
      container.innerHTML = '<p class="insights-empty-state">No bottlenecks detected. Your workflow is running smoothly!</p>';
      return;
    }
    
    var html = '';
    bottlenecks.forEach(function(item) {
      html += '<div class="insights-bottleneck-item ' + item.severity + '">' +
        '<span class="insights-bottleneck-stage">' + escapeHtml(item.stage) + '</span>' +
        '<span class="insights-bottleneck-count">' + item.count + '</span>' +
        '<span class="insights-bottleneck-message">' + escapeHtml(item.message) + '</span>' +
        '</div>';
    });
    
    container.innerHTML = html;
  }

  /**
   * Load AI recommendations
   */
  function loadAIRecommendations() {
    var container = document.getElementById('insightsRecommendations');
    var timestampEl = document.getElementById('insightsTimestamp');
    
    if (!container) return;
    
    container.innerHTML = '<div class="insights-loading">' +
      '<div class="insights-loading-spinner"></div>' +
      '<p>Analyzing your practice data...</p>' +
      '</div>';
    
    fetch('api/ai-recommendations.php', {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      isLoading = false;
      insightsLoaded = true;
      
      var refreshBtn = document.getElementById('insightsRefreshBtn');
      if (refreshBtn) {
        refreshBtn.classList.remove('loading');
      }
      
      if (data.error) {
        showRecommendationsError(container, data.error, data.error_code);
        return;
      }
      
      if (data.success && data.recommendations) {
        renderRecommendations(container, data.recommendations);
        
        if (timestampEl && data.generated_at) {
          timestampEl.textContent = 'Generated ' + formatRelativeTime(data.generated_at);
        }
      } else {
        container.innerHTML = '<p class="insights-empty-state">No recommendations available at this time.</p>';
      }
    })
    .catch(function(error) {
      isLoading = false;
      
      var refreshBtn = document.getElementById('insightsRefreshBtn');
      if (refreshBtn) {
        refreshBtn.classList.remove('loading');
      }
      
      console.error('Error loading AI recommendations:', error);
      showRecommendationsError(container, 'Unable to load recommendations. Please try again.');
    });
  }

  /**
   * Render AI recommendations
   */
  function renderRecommendations(container, recommendations) {
    if (!recommendations || recommendations.length === 0) {
      container.innerHTML = '<p class="insights-empty-state">No specific recommendations at this time. Your practice is running well!</p>';
      return;
    }
    
    var html = '';
    
    recommendations.forEach(function(rec, index) {
      var priority = rec.priority || 'medium';
      var title = rec.title || rec.category || 'Recommendation ' + (index + 1);
      var text = rec.recommendation || rec.text || rec.description || '';
      
      html += '<div class="insights-recommendation-item">' +
        '<div class="insights-recommendation-icon">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M12 2L2 7l10 5 10-5-10-5z"></path>' +
        '<path d="M2 17l10 5 10-5"></path>' +
        '<path d="M2 12l10 5 10-5"></path>' +
        '</svg>' +
        '</div>' +
        '<div class="insights-recommendation-content">' +
        '<h4 class="insights-recommendation-title">' + escapeHtml(title) + '</h4>' +
        '<p class="insights-recommendation-text">' + escapeHtml(text) + '</p>' +
        '<span class="insights-recommendation-priority ' + priority.toLowerCase() + '">' + priority + ' priority</span>' +
        '</div>' +
        '</div>';
    });
    
    container.innerHTML = html;
  }

  /**
   * Show error state for recommendations
   */
  function showRecommendationsError(container, message, errorCode) {
    var retryText = errorCode === 'quota' ? 'Try again in a minute' : 'Try Again';
    
    container.innerHTML = '<div class="insights-error">' +
      '<svg class="insights-error-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<circle cx="12" cy="12" r="10"></circle>' +
      '<line x1="12" y1="8" x2="12" y2="12"></line>' +
      '<line x1="12" y1="16" x2="12.01" y2="16"></line>' +
      '</svg>' +
      '<p class="insights-error-message">' + escapeHtml(message) + '</p>' +
      '<button type="button" class="insights-retry-btn" onclick="window.loadInsights(true)">' + retryText + '</button>' +
      '</div>';
  }

  /**
   * Format relative time
   */
  function formatRelativeTime(dateString) {
    if (!dateString) return '';
    
    var date = new Date(dateString);
    var now = new Date();
    var diffMs = now - date;
    var diffMin = Math.floor(diffMs / 60000);
    
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return diffMin + ' minute' + (diffMin === 1 ? '' : 's') + ' ago';
    
    var diffHour = Math.floor(diffMin / 60);
    if (diffHour < 24) return diffHour + ' hour' + (diffHour === 1 ? '' : 's') + ' ago';
    
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  /**
   * Escape HTML
   */
  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Called when insights tab becomes visible
   */
  window.onInsightsTabShown = function() {
    if (!insightsLoaded) {
      loadInsights(false);
    }
  };

  /**
   * Public method to load/refresh insights
   */
  window.loadInsights = function(forceRefresh) {
    loadInsights(forceRefresh);
  };

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initInsights);
  } else {
    initInsights();
  }

})();
