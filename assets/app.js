/**
 * FILE: assets/app.js
 * PURPOSE: Client-side data visualization engine using Chart.js.
 * SYSTEM ROLE: Reads JSON datasets injected by index.php (window.dashboardData and
 *              window.analyticsData) to render responsive Line, Bar, and Doughnut charts.
 */

document.addEventListener('DOMContentLoaded', function () {
  // Standardized color palette across all system charts
  const palette = ['#2563eb', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#64748b'];

  /**
   * Renders a smooth area-filled line chart (used for monthly case trends).
   */
  function renderLineChart(canvasId, labels, values, labelText, color = '#2563eb') {
    const el = document.getElementById(canvasId);
    if (!el) return;
    new Chart(el, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: labelText,
          data: values,
          borderColor: color,
          backgroundColor: 'rgba(37, 99, 235, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: 4,
          pointBackgroundColor: color,
          borderWidth: 2.5
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#64748b' } },
          y: { beginAtZero: true, grid: { color: '#edf1f6' }, ticks: { precision: 0, color: '#64748b' } }
        }
      }
    });
  }

  /**
   * Renders a vertical bar chart (used for comparing total cases per disease).
   */
  function renderBarChart(canvasId, labels, values, labelText = 'Cases') {
    const el = document.getElementById(canvasId);
    if (!el) return;
    new Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: labelText,
          data: values,
          backgroundColor: labels.map((_, i) => palette[i % palette.length]),
          borderRadius: 6,
          maxBarThickness: 42
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#64748b' } },
          y: { beginAtZero: true, grid: { color: '#edf1f6' }, ticks: { precision: 0, color: '#64748b' } }
        }
      }
    });
  }

  /**
   * Renders a doughnut chart and optionally populates a custom HTML legend container.
   */
  function renderDoughnutChart(canvasId, labels, values, legendId = null, customColors = null) {
    const el = document.getElementById(canvasId);
    if (!el) return;
    const colors = customColors || labels.map((_, i) => palette[i % palette.length]);

    new Chart(el, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data: values,
          backgroundColor: colors,
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
          legend: {
            display: !legendId,
            position: 'bottom',
            labels: { usePointStyle: true, padding: 14, color: '#52627a', font: { size: 11 } }
          }
        }
      }
    });

    if (legendId) {
      const legendEl = document.getElementById(legendId);
      if (legendEl) {
        legendEl.innerHTML = labels.map((lbl, i) =>
          `<span class="legend-item"><i style="background:${colors[i]}"></i>${lbl} (${values[i]})</span>`
        ).join('');
      }
    }
  }

  // Initialize Dashboard Visualizations
  if (window.dashboardData) {
    const d = window.dashboardData;
    renderLineChart('casesChart', d.months || [], d.values || [], 'Reported Cases');
    renderDoughnutChart('diseaseChart', d.diseases || [], d.diseaseValues || [], 'diseaseLegend');
  }

  // Initialize Analytics Visualizations with semantic status colors
  if (window.analyticsData) {
    const a = window.analyticsData;
    const statusColors = (a.statuses || []).map(s => {
      if (s === 'Active') return '#ef4444';    // Red for active cases
      if (s === 'Recovered') return '#10b981'; // Green for recovered
      if (s === 'Deceased') return '#64748b';  // Slate gray for deceased
      return '#3b82f6';
    });

    renderBarChart('analyticsDisease', a.diseases || [], a.diseaseValues || [], 'Total Cases');
    renderDoughnutChart('analyticsStatus', a.statuses || [], a.statusValues || [], null, statusColors);
    renderLineChart('analyticsTrend', a.periods || [], a.trendValues || [], 'Monthly Cases');
  }
});