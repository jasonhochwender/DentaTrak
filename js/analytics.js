// Analytics Dashboard Functionality
document.addEventListener('DOMContentLoaded', function() {
  let analyticsData = null;
  let analyticsLoaded = false;
  
  // Chart instances to prevent reuse errors
  const chartInstances = {};
  
  function destroyChart(chartId) {
    if (chartInstances[chartId]) {
      chartInstances[chartId].destroy();
      delete chartInstances[chartId];
    }
  }
  
  function destroyAllCharts() {
    Object.keys(chartInstances).forEach(chartId => {
      destroyChart(chartId);
    });
  }
  
  // Expose loadAnalytics globally so app.js can call it when analytics tab is clicked
  window.loadAnalyticsData = function() {
    // Always load analytics when tab is clicked to ensure charts render properly
    // Charts may fail to render if tab was hidden, so we reload each time
    loadAnalytics();
    analyticsLoaded = true;
  };
  
  // Also expose a force reload function for dropdown changes
  window.reloadAnalyticsData = function() {
    loadAnalytics();
  };
  
  // Check if analytics tab is already active on page load
  const activeTab = document.querySelector('.main-tab.active');
  if (activeTab && activeTab.getAttribute('data-tab') === 'analytics') {
    setTimeout(() => {
      loadAnalytics();
      analyticsLoaded = true;
    }, 100);
  }
  
  // Helper mappers to normalize API data shape
  function normalizeMetrics(raw) {
    const metrics = raw || {};
    return {
      cases_this_month: metrics.casesThisMonth ?? 0,
      avg_turnaround_days: metrics.averageCaseDuration ?? 0,
      completion_rate: metrics.completionRate ?? 0,
      active_cases: metrics.totalActiveCases ?? 0,
      total_delivered_cases: metrics.totalDeliveredCases ?? 0,
      cases_past_due: metrics.casesPastDue ?? 0,
      total_archived_cases: metrics.totalArchivedCases ?? 0
    };
  }

  function normalizeStatusDistribution(rows) {
    const result = {};
    (rows || []).forEach(row => {
      const key = row.status || 'Unspecified';
      const value = Number(row.count || 0);
      result[key] = (result[key] || 0) + value;
    });
    return result;
  }

  function normalizeMonthlyVolume(rows, field = 'cases_created') {
    const result = {
      labels: [],
      created: [],
      delivered: []
    };
    
    if (!rows || !Array.isArray(rows)) {
      return result;
    }
    
    // Sort by month to ensure chronological order
    const sortedRows = rows.sort((a, b) => {
      const monthA = new Date(a.month + '-01');
      const monthB = new Date(b.month + '-01');
      return monthA - monthB;
    });
    
    sortedRows.forEach(row => {
      const month = row.month || 'Unknown';
      const created = Number(row.cases_created || 0);
      const delivered = Number(row.cases_delivered || 0);
      
      result.labels.push(month);
      result.created.push(created);
      result.delivered.push(delivered);
    });
    
    return result;
  }

  function normalizeTypeDistribution(rows) {
    const result = {};
    (rows || []).forEach(row => {
      const key = row.case_type || 'Unspecified';
      const value = Number(row.count || 0);
      result[key] = (result[key] || 0) + value;
    });
    return result;
  }

  function normalizeTeamPerformance(rows) {
    const result = {};
    (rows || []).forEach(row => {
      const key = row.assignee || 'Unassigned';
      const value = Number(row.cases_count || 0);
      result[key] = (result[key] || 0) + value;
    });
    return result;
  }

  // Load analytics data
  function loadAnalytics() {
    showLoading(true);
    
    // Get saved filter values or use defaults
    const teamPeriod = localStorage.getItem('analytics_team_period') || '12';
    const volumePeriod = localStorage.getItem('analytics_volume_period') || '12';
    const statusPeriod = localStorage.getItem('analytics_status_period') || 'active';
    const typePeriod = localStorage.getItem('analytics_type_period') || 'active';
    
    
    // Set dropdowns to saved values
    if (document.getElementById('teamPerformancePeriod')) {
      document.getElementById('teamPerformancePeriod').value = teamPeriod;
      
      // Handle custom months display
      if (teamPeriod === 'custom') {
        const customTeamValue = localStorage.getItem('analytics_team_custom_months') || '12';
        document.getElementById('customTeamMonths').value = customTeamValue;
        document.getElementById('customTeamMonths').style.display = 'inline-block';
      }
    }
    
    if (document.getElementById('monthlyVolumePeriod')) {
      document.getElementById('monthlyVolumePeriod').value = volumePeriod;
      
      // Handle custom months display
      if (volumePeriod === 'custom') {
        const customVolumeValue = localStorage.getItem('analytics_volume_custom_months') || '12';
        document.getElementById('customVolumeMonths').value = customVolumeValue;
        document.getElementById('customVolumeMonths').style.display = 'inline-block';
      }
    }
    
    if (document.getElementById('statusPeriod')) {
      document.getElementById('statusPeriod').value = statusPeriod;
      
      // Handle custom months display
      if (statusPeriod === 'custom') {
        const customStatusValue = localStorage.getItem('analytics_status_custom_months') || '12';
        document.getElementById('customStatusMonths').value = customStatusValue;
        document.getElementById('customStatusMonths').style.display = 'inline-block';
        document.getElementById('applyStatusFilter').style.display = 'inline-block';
      }
    }
    
    if (document.getElementById('typePeriod')) {
      document.getElementById('typePeriod').value = typePeriod;
      
      // Handle custom months display
      if (typePeriod === 'custom') {
        const customTypeValue = localStorage.getItem('analytics_type_custom_months') || '12';
        document.getElementById('customTypeMonths').value = customTypeValue;
        document.getElementById('customTypeMonths').style.display = 'inline-block';
        document.getElementById('applyTypeFilter').style.display = 'inline-block';
      }
    }
    
    // Use actual values for API call (custom months if needed)
    const actualTeamPeriod = teamPeriod === 'custom' ? (localStorage.getItem('analytics_team_custom_months') || '12') : teamPeriod;
    const actualVolumePeriod = volumePeriod === 'custom' ? (localStorage.getItem('analytics_volume_custom_months') || '12') : volumePeriod;
    const actualStatusPeriod = statusPeriod === 'custom' ? (localStorage.getItem('analytics_status_custom_months') || '12') : statusPeriod;
    const actualTypePeriod = typePeriod === 'custom' ? (localStorage.getItem('analytics_type_custom_months') || '12') : typePeriod;
    
    const apiUrl = 'api/get-analytics.php?team_period=' + actualTeamPeriod + '&volume_period=' + actualVolumePeriod + '&status_period=' + actualStatusPeriod + '&type_period=' + actualTypePeriod;
    
    fetch(apiUrl)
      .then(response => {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(data => {
        if (data && data.success) {
          const payload = data.data || {};
          
          const metrics = normalizeMetrics(payload.metrics);
          const charts = payload.charts || {};
          const advancedInsights = payload.advancedInsights || payload.advanced_insights || {};

          analyticsData = payload;

          // Metrics
          updateMetrics(metrics);

          // Chart datasets
          const statusDistribution = normalizeStatusDistribution(charts.statusDistribution);
          const monthlyVolume = normalizeMonthlyVolume(charts.monthlyVolume);
          const teamPerformance = normalizeTeamPerformance(charts.teamPerformance);
          const typeDistribution = normalizeTypeDistribution(charts.caseTypeBreakdown);
          
          // Destroy all existing charts before creating new ones
          destroyAllCharts();
          
          // Render all charts using individual update functions
          // Each function handles its own chart destruction and creation
          updateStatusDistributionChart(statusDistribution);
          updateTypeDistributionChart(typeDistribution);
          updateMonthlyVolumeChart(monthlyVolume);
          updateTeamPerformanceChart(teamPerformance);
          updateTrendsChart(advancedInsights.trends);
          updateAdvancedInsights(advancedInsights);

          showLoading(false);
          
          // Load AI recommendations after charts are rendered, but only if there are cases
          const totalCases = (metrics.active_cases || 0) + (metrics.total_delivered_cases || 0) + (metrics.total_archived_cases || 0);
          const aiSection = document.getElementById('aiRecommendationsSection');
          
          if (totalCases > 0) {
            if (aiSection) aiSection.style.display = 'block';
            if (typeof loadAIRecommendations === 'function') {
              setTimeout(loadAIRecommendations, 300);
            }
          } else {
            // Hide AI recommendations section when there are no cases
            if (aiSection) aiSection.style.display = 'none';
          }
        } else {
          showLoading(false);
          const msg = data && data.message ? data.message : 'Unknown analytics error';
          console.error('Analytics API error:', msg);
          showToast('Error loading analytics data: ' + msg, 'error');
        }
      })
      .catch(error => {
        console.error('Analytics fetch error:', error);
        showLoading(false);
        showToast('Error loading analytics: ' + error.message, 'error');
      });
  }
  
  // Individual section update functions
  function updateTeamPerformanceSection() {
    const teamPeriod = localStorage.getItem('analytics_team_period') || '12';
    const actualTeamPeriod = teamPeriod === 'custom' ? (localStorage.getItem('analytics_team_custom_months') || '12') : teamPeriod;
    
    // Use existing API with current values but only update team performance chart
    const apiUrl = 'api/get-analytics.php?team_period=' + actualTeamPeriod;
    
    fetch(apiUrl)
      .then(response => response.json())
      .then(data => {
        if (data && data.success) {
          const teamPerformance = normalizeTeamPerformance(data.data?.charts?.teamPerformance || []);
          destroyChart('teamPerformanceChart');
          updateTeamPerformanceChart(teamPerformance);
        }
      })
      .catch(error => {
        // Silently handle errors
      });
  }
  
  function updateMonthlyVolumeSection() {
    const volumePeriod = localStorage.getItem('analytics_volume_period') || '12';
    const actualVolumePeriod = volumePeriod === 'custom' ? (localStorage.getItem('analytics_volume_custom_months') || '12') : volumePeriod;
    
    // Use existing API with current values but only update monthly volume chart
    const apiUrl = 'api/get-analytics.php?volume_period=' + actualVolumePeriod;
    
    fetch(apiUrl)
      .then(response => response.json())
      .then(data => {
        if (data && data.success) {
          const monthlyVolume = normalizeMonthlyVolume(data.data?.charts?.monthlyVolume || []);
          destroyChart('monthlyVolumeChart');
          updateMonthlyVolumeChart(monthlyVolume);
        }
      })
      .catch(error => {
        // Silently handle errors
      });
  }
  
  function updateStatusDistributionSection() {
    const statusPeriod = localStorage.getItem('analytics_status_period') || 'active';
    const actualStatusPeriod = statusPeriod === 'custom' ? (localStorage.getItem('analytics_status_custom_months') || '12') : statusPeriod;
    
    // Use existing API with current values but only update status distribution chart
    const apiUrl = 'api/get-analytics.php?status_period=' + actualStatusPeriod;
    
    fetch(apiUrl)
      .then(response => response.json())
      .then(data => {
        if (data && data.success) {
          const statusDistribution = normalizeStatusDistribution(data.data?.charts?.statusDistribution || []);
          destroyChart('statusDistributionChart');
          updateStatusDistributionChart(statusDistribution);
        }
      })
      .catch(error => {
        // Silently handle errors
      });
  }
  
  function updateTypeDistributionSection() {
    const typePeriod = localStorage.getItem('analytics_type_period') || 'active';
    const actualTypePeriod = typePeriod === 'custom' ? (localStorage.getItem('analytics_type_custom_months') || '12') : typePeriod;
    
    // Use existing API with current values but only update type distribution chart
    const apiUrl = 'api/get-analytics.php?type_period=' + actualTypePeriod;
    
    fetch(apiUrl)
      .then(response => response.json())
      .then(data => {
        if (data && data.success) {
          const typeDistribution = normalizeTypeDistribution(data.data?.charts?.caseTypeBreakdown || []);
          destroyChart('typeDistributionChart');
          updateTypeDistributionChart(typeDistribution);
        }
      })
      .catch(error => {
        // Silently handle errors
      });
  }
  
  // Add event listeners for dropdowns
  function setupDropdownListeners() {
    // Team Performance Period
    const teamPeriod = document.getElementById('teamPerformancePeriod');
    if (teamPeriod) {
      teamPeriod.addEventListener('change', function() {
        const value = this.value;
        localStorage.setItem('analytics_team_period', value);
        
        // Show/hide custom months input
        const customMonths = document.getElementById('customTeamMonths');
        if (value === 'custom') {
          customMonths.style.display = 'inline-block';
        } else {
          customMonths.style.display = 'none';
          // Reload all analytics data with new period
          loadAnalytics();
        }
      });
    }
    
    // Monthly Volume Period
    const monthlyVolumePeriod = document.getElementById('monthlyVolumePeriod');
    if (monthlyVolumePeriod) {
      monthlyVolumePeriod.addEventListener('change', function() {
        const value = this.value;
        localStorage.setItem('analytics_volume_period', value);
        
        const customMonths = document.getElementById('customVolumeMonths');
        if (value === 'custom') {
          customMonths.style.display = 'inline-block';
        } else {
          customMonths.style.display = 'none';
          // Reload all analytics data with new period
          loadAnalytics();
        }
      });
    }
    
    // Status Period
    const statusPeriod = document.getElementById('statusPeriod');
    if (statusPeriod) {
      statusPeriod.addEventListener('change', function() {
        const value = this.value;
        localStorage.setItem('analytics_status_period', value);
        
        const customMonths = document.getElementById('customStatusMonths');
        const applyButton = document.getElementById('applyStatusFilter');
        if (value === 'custom') {
          customMonths.style.display = 'inline-block';
          applyButton.style.display = 'inline-block';
        } else {
          customMonths.style.display = 'none';
          applyButton.style.display = 'none';
          // Reload all analytics data with new period
          loadAnalytics();
        }
      });
    }
    
    // Type Period
    const typePeriod = document.getElementById('typePeriod');
    if (typePeriod) {
      typePeriod.addEventListener('change', function() {
        const value = this.value;
        localStorage.setItem('analytics_type_period', value);
        
        const customMonths = document.getElementById('customTypeMonths');
        const applyButton = document.getElementById('applyTypeFilter');
        if (value === 'custom') {
          customMonths.style.display = 'inline-block';
          applyButton.style.display = 'inline-block';
        } else {
          customMonths.style.display = 'none';
          applyButton.style.display = 'none';
          // Reload all analytics data with new period
          loadAnalytics();
        }
      });
    }
    
    // Custom months apply buttons
    const applyStatusBtn = document.getElementById('applyStatusFilter');
    if (applyStatusBtn) {
      applyStatusBtn.addEventListener('click', function() {
        const months = document.getElementById('customStatusMonths').value;
        localStorage.setItem('analytics_status_custom_months', months);
        // Reload all analytics data with custom period
        loadAnalytics();
      });
    }
    
    const applyTypeBtn = document.getElementById('applyTypeFilter');
    if (applyTypeBtn) {
      applyTypeBtn.addEventListener('click', function() {
        const months = document.getElementById('customTypeMonths').value;
        localStorage.setItem('analytics_type_custom_months', months);
        // Reload all analytics data with custom period
        loadAnalytics();
      });
    }
    
    // Custom team months input
    const customTeamMonths = document.getElementById('customTeamMonths');
    if (customTeamMonths) {
      customTeamMonths.addEventListener('change', function() {
        const months = this.value;
        localStorage.setItem('analytics_team_custom_months', months);
      });
      
      // Add Enter key support
      customTeamMonths.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          const months = this.value;
          localStorage.setItem('analytics_team_custom_months', months);
          // Reload all analytics data with custom period
          loadAnalytics();
        }
      });
    }
    
    // Custom volume months input
    const customVolumeMonths = document.getElementById('customVolumeMonths');
    if (customVolumeMonths) {
      customVolumeMonths.addEventListener('change', function() {
        const months = this.value;
        localStorage.setItem('analytics_volume_custom_months', months);
      });
      
      // Add Enter key support
      customVolumeMonths.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          const months = this.value;
          localStorage.setItem('analytics_volume_custom_months', months);
          // Reload all analytics data with custom period
          loadAnalytics();
        }
      });
    }
    
    // Custom status months input
    const customStatusMonths = document.getElementById('customStatusMonths');
    if (customStatusMonths) {
      customStatusMonths.addEventListener('change', function() {
        const months = this.value;
        localStorage.setItem('analytics_status_custom_months', months);
      });
      
      // Add Enter key support
      customStatusMonths.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          const months = this.value;
          localStorage.setItem('analytics_status_custom_months', months);
          // Reload all analytics data with custom period
          loadAnalytics();
        }
      });
      
      const applyStatusBtn = document.getElementById('applyStatusFilter');
      if (applyStatusBtn) {
        applyStatusBtn.addEventListener('click', function() {
          const months = document.getElementById('customStatusMonths').value;
          localStorage.setItem('analytics_status_custom_months', months);
          // Reload all analytics data with custom period
          loadAnalytics();
        });
      }
    }
    
    // Custom type months input
    const customTypeMonths = document.getElementById('customTypeMonths');
    if (customTypeMonths) {
      customTypeMonths.addEventListener('change', function() {
        const months = this.value;
        localStorage.setItem('analytics_type_custom_months', months);
      });
      
      // Add Enter key support
      customTypeMonths.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          const months = this.value;
          localStorage.setItem('analytics_type_custom_months', months);
          // Reload all analytics data with custom period
          loadAnalytics();
        }
      });
      
      const applyTypeBtn = document.getElementById('applyTypeFilter');
      if (applyTypeBtn) {
        applyTypeBtn.addEventListener('click', function() {
          const months = document.getElementById('customTypeMonths').value;
          localStorage.setItem('analytics_type_custom_months', months);
          // Reload all analytics data with custom period
          loadAnalytics();
        });
      }
    }
  }
  
  // Initialize dropdown listeners
  setupDropdownListeners();
  
  function updateMetrics(data) {
    if (!data) return;
    
    // Top metrics section (ap- prefix elements)
    const apActiveCases = document.getElementById('apActiveCases');
    const apDelivered = document.getElementById('apDelivered');
    const apArchived = document.getElementById('apArchived');
    
    if (apActiveCases) apActiveCases.textContent = data.active_cases || 0;
    if (apDelivered) apDelivered.textContent = data.total_delivered_cases || 0;
    if (apArchived) apArchived.textContent = data.total_archived_cases || 0;
    
    // Legacy element IDs (if they exist)
    const casesThisMonth = document.getElementById('casesThisMonth');
    const totalActiveCases = document.getElementById('totalActiveCases');
    const totalArchivedCases = document.getElementById('totalArchivedCases');
    
    if (casesThisMonth) casesThisMonth.textContent = data.cases_this_month || 0;
    if (totalActiveCases) totalActiveCases.textContent = data.active_cases || 0;
    if (totalArchivedCases) totalArchivedCases.textContent = data.total_archived_cases || 0;
  }
  
  function createCharts(data) {
    // Create status distribution chart
    const statusCtx = document.getElementById('statusChart')?.getContext('2d');
    if (statusCtx && data.status_distribution) {
      chartInstances['statusChart'] = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: Object.keys(data.status_distribution),
          datasets: [{
            data: Object.values(data.status_distribution),
            backgroundColor: [
              '#1e40af', '#f97316', '#fb923c', '#fbbf24', '#22d3ee', '#34d399'
            ]
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom'
            }
          }
        }
      });
    }
    
    // Create monthly volume chart
    const volumeCtx = document.getElementById('volumeChart')?.getContext('2d');
    if (volumeCtx && data.monthly_volume) {
      chartInstances['volumeChart'] = new Chart(volumeCtx, {
        type: 'line',
        data: {
          labels: data.monthly_volume.labels || [],
          datasets: [
            {
              label: 'Cases Created',
              data: data.monthly_volume.created || [],
              borderColor: '#1e40af',
              backgroundColor: 'rgba(30, 64, 175, 0.1)',
              fill: true
            },
            {
              label: 'Cases Delivered',
              data: data.monthly_volume.delivered || [],
              borderColor: '#34d399',
              backgroundColor: 'rgba(52, 211, 153, 0.1)',
              fill: true
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom'
            }
          },
          scales: {
            y: {
              beginAtZero: true
            }
          }
        }
      });
    }
  }
  
  function updateTeamPerformanceChart(data) {
    destroyChart('teamPerformanceChart');
    const ctx = document.getElementById('teamPerformanceChart')?.getContext('2d');
    if (!ctx) return;
    
    chartInstances['teamPerformanceChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: Object.keys(data),
        datasets: [{
          label: 'Cases Completed',
          data: Object.values(data),
          backgroundColor: '#1e40af'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          }
        }
      }
    });
  }
  
  function updateMonthlyVolumeChart(data) {
    destroyChart('volumeChart');
    const ctx = document.getElementById('volumeChart')?.getContext('2d');
    if (!ctx) return;
    
    chartInstances['volumeChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.labels || [],
        datasets: [
          {
            label: 'Cases Created',
            data: data.created || [],
            borderColor: '#1e40af',
            backgroundColor: 'rgba(30, 64, 175, 0.1)',
            fill: true,
            tension: 0.4
          },
          {
            label: 'Cases Delivered',
            data: data.delivered || [],
            borderColor: '#34d399',
            backgroundColor: 'rgba(52, 211, 153, 0.1)',
            fill: true,
            tension: 0.4
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom'
          }
        },
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  }
  
  function updateStatusDistributionChart(data) {
    destroyChart('statusChart');
    const ctx = document.getElementById('statusChart')?.getContext('2d');
    if (!ctx) return;
    
    chartInstances['statusChart'] = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: Object.keys(data),
        datasets: [{
          data: Object.values(data),
          backgroundColor: [
            '#1e40af',
            '#f97316',
            '#fb923c',
            '#fbbf24',
            '#22d3ee',
            '#34d399'
          ]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom'
          }
        }
      }
    });
  }
  
  function updateTypeDistributionChart(data) {
    destroyChart('typeDistributionChart');
    const ctx = document.getElementById('typeDistributionChart')?.getContext('2d');
    if (!ctx) return;
    
    // Check if data is empty
    if (!data || Object.keys(data).length === 0) return;
    
    chartInstances['typeDistributionChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: Object.keys(data),
        datasets: [{
          label: 'Case Types',
          data: Object.values(data),
          backgroundColor: '#fb923c'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          }
        }
      }
    });
  }
  
  function updateAdvancedInsights(data) {
    if (!data) return;
    
    // Update completion predictions
    const onTrackCount = document.getElementById('onTrackCount');
    const atRiskCount = document.getElementById('atRiskCount');
    const delayedCount = document.getElementById('delayedCount');
    
    if (onTrackCount) onTrackCount.textContent = data.completion?.onTrack || 0;
    if (atRiskCount) atRiskCount.textContent = data.completion?.atRisk || 0;
    if (delayedCount) delayedCount.textContent = data.completion?.delayed || 0;
    
    // Update workload analysis
    const teamUtilization = document.getElementById('teamUtilization');
    const topPerformer = document.getElementById('topPerformer');
    const busiestMember = document.getElementById('busiestMember');
    const capacityAlert = document.getElementById('capacityAlert');
    
    if (teamUtilization) teamUtilization.textContent = (data.workload?.utilization || 0) + '%';
    if (topPerformer) topPerformer.textContent = data.workload?.topPerformer || 'N/A';
    if (busiestMember) busiestMember.textContent = data.workload?.busiest || 'N/A';
    if (capacityAlert) capacityAlert.textContent = data.workload?.capacity || 'Optimal';
    
    // Update trends insights
    const growthRate = document.getElementById('growthRate');
    const peakMonth = document.getElementById('peakMonth');
    const nextPeak = document.getElementById('nextPeak');
    
    if (growthRate) growthRate.textContent = '+' + (data.trends?.growthRate || 0) + '%';
    if (peakMonth) peakMonth.textContent = data.trends?.peakMonth || 'N/A';
    if (nextPeak) nextPeak.textContent = data.trends?.nextPeak || 'N/A';
  }
  
  function updateTrendsChart(trendsData) {
    destroyChart('trendsChart');
    const ctx = document.getElementById('trendsChart')?.getContext('2d');
    if (!ctx) return;
    
    if (!trendsData) return;
    
    // Process monthly data for the chart
    const monthlyData = trendsData.monthlyData || [];
    const labels = [];
    const currentYearData = [];
    const lastYearData = [];
    
    // Group data by year
    const currentYear = trendsData.currentYear || new Date().getFullYear();
    const lastYearNum = currentYear - 1;
    
    // Process the monthly data
    monthlyData.forEach(item => {
      const month = new Date(item.month + '-01').getMonth();
      const monthName = new Date(currentYear, month).toLocaleDateString('en-US', { month: 'short' });
      
      if (!labels.includes(monthName)) {
        labels.push(monthName);
        currentYearData.push(item.currentYear || 0);
        lastYearData.push(item.lastYear || 0);
      }
    });
    
    // Sort by month
    const monthOrder = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    labels.sort((a, b) => monthOrder.indexOf(a) - monthOrder.indexOf(b));
    
    chartInstances['trendsChart'] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: currentYear.toString(),
            data: currentYearData,
            borderColor: '#1e40af',
            backgroundColor: 'rgba(30, 64, 175, 0.1)',
            tension: 0.4,
            fill: true
          },
          {
            label: lastYearNum.toString(),
            data: lastYearData,
            borderColor: '#94a3b8',
            backgroundColor: 'rgba(148, 163, 184, 0.1)',
            tension: 0.4,
            fill: true
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top'
          },
          title: {
            display: true,
            text: 'Monthly Case Volume Trends'
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Number of Cases'
            }
          }
        }
      }
    });
  }
  
  function showLoading(show) {
    // Hide main loading overlay
    const loadingOverlay = document.getElementById('analyticsLoadingOverlay');
    if (loadingOverlay) {
      loadingOverlay.style.display = show ? 'flex' : 'none';
    }
    
    // Hide analytics loading section
    const analyticsLoading = document.getElementById('analyticsLoading');
    if (analyticsLoading) {
      analyticsLoading.style.display = show ? 'block' : 'none';
    }
    
    // Hide trends loading placeholder
    const seasonalChart = document.getElementById('seasonalChart');
    if (seasonalChart && !show) {
      const placeholder = seasonalChart.querySelector('.chart-placeholder');
      if (placeholder) {
        placeholder.style.display = 'none';
      }
    }
  }
  
  // ========================================
  // AI-Powered Recommendations
  // ========================================
  
  let aiRecommendationsLoaded = false;
  
  function loadAIRecommendations() {
    const container = document.getElementById('aiRecommendations');
    const loadingEl = document.getElementById('aiRecommendationsLoading');
    const errorEl = document.getElementById('aiRecommendationsError');
    
    if (!container) return;
    
    // Show loading state
    if (loadingEl) loadingEl.style.display = 'flex';
    if (errorEl) errorEl.style.display = 'none';
    
    // Clear previous recommendations (keep loading element)
    const existingItems = container.querySelectorAll('.recommendation-item');
    existingItems.forEach(item => item.remove());
    
    fetch('api/ai-recommendations.php')
      .then(response => response.json())
      .then(data => {
        if (loadingEl) loadingEl.style.display = 'none';
        
        if (data.error) {
          showAIError(data.error, data.error_code, data.retry_after);
          return;
        }
        
        if (data.success && data.recommendations) {
          displayAIRecommendations(data.recommendations);
          aiRecommendationsLoaded = true;
        } else {
          showAIError('No recommendations available');
        }
      })
      .catch(error => {
        console.error('AI Recommendations error:', error);
        if (loadingEl) loadingEl.style.display = 'none';
        showAIError('Failed to connect to AI service. Please check your connection and try again.');
      });
  }
  
  function displayAIRecommendations(recommendations) {
    const container = document.getElementById('aiRecommendations');
    if (!container) return;
    
    // Priority icons and colors
    const priorityConfig = {
      high: { icon: '🔴', class: 'priority-high' },
      medium: { icon: '🟡', class: 'priority-medium' },
      low: { icon: '🟢', class: 'priority-low' }
    };
    
    // Category icons
    const categoryIcons = {
      efficiency: '⚡',
      quality: '✨',
      scheduling: '📅',
      workload: '👥',
      communication: '💬'
    };
    
    recommendations.forEach((rec, index) => {
      const priority = priorityConfig[rec.priority] || priorityConfig.medium;
      const categoryIcon = categoryIcons[rec.category] || '💡';
      
      const item = document.createElement('div');
      item.className = `recommendation-item ai-recommendation ${priority.class}`;
      item.style.animationDelay = `${index * 0.1}s`;
      
      item.innerHTML = `
        <div class="recommendation-icon">${categoryIcon}</div>
        <div class="recommendation-content">
          <div class="recommendation-header">
            <strong class="recommendation-title">${rec.title}</strong>
            <span class="recommendation-priority ${priority.class}" title="${rec.priority} priority">
              ${priority.icon} ${rec.priority.charAt(0).toUpperCase() + rec.priority.slice(1)}
            </span>
          </div>
          <div class="recommendation-text">${rec.description}</div>
          <div class="recommendation-category">
            <span class="category-tag">${rec.category}</span>
          </div>
        </div>
      `;
      
      container.appendChild(item);
    });
  }
  
  function showAIError(message, errorCode, retryAfter) {
    const errorEl = document.getElementById('aiRecommendationsError');
    if (errorEl) {
      errorEl.style.display = 'flex';
      
      // Update icon based on error type
      const iconEl = errorEl.querySelector('.ai-error-icon');
      if (iconEl) {
        iconEl.textContent = errorCode === 'quota' ? '⏳' : '⚠️';
      }
      
      const msgEl = errorEl.querySelector('p');
      if (msgEl) msgEl.textContent = message || 'Unable to generate recommendations.';
      
      // Show retry countdown if applicable
      const retryBtn = document.getElementById('retryAiRecommendations');
      if (retryBtn && retryAfter) {
        retryBtn.disabled = true;
        retryBtn.textContent = `Try Again (${retryAfter}s)`;
        
        let countdown = retryAfter;
        const countdownInterval = setInterval(() => {
          countdown--;
          if (countdown <= 0) {
            clearInterval(countdownInterval);
            retryBtn.disabled = false;
            retryBtn.textContent = 'Try Again';
          } else {
            retryBtn.textContent = `Try Again (${countdown}s)`;
          }
        }, 1000);
      }
    }
  }
  
  // Refresh button handler
  const refreshBtn = document.getElementById('refreshAiRecommendations');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function() {
      this.classList.add('spinning');
      loadAIRecommendations();
      setTimeout(() => this.classList.remove('spinning'), 1000);
    });
  }
  
  // Retry button handler
  const retryBtn = document.getElementById('retryAiRecommendations');
  if (retryBtn) {
    retryBtn.addEventListener('click', loadAIRecommendations);
  }
});
