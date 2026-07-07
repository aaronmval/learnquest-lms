/* CHART DATA PER QUARTER*/
const CHART_DATA = {

    /* No filter — all data (default) */
    all: {
        radar:       [95, 60, 65, 55],
        performance: { labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], data: [75, 79, 83, 86, 87, 90] },
        mastery:     [85, 88, 75, 70]
    },

    /* 1st Quarter — January to March */
    1: {
        radar:       [70, 55, 60, 50],
        performance: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [70, 73, 76, 79] },
        mastery:     [72, 75, 68, 60]
    },

    /* 2nd Quarter — April to June */
    2: {
        radar:       [80, 62, 70, 58],
        performance: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [78, 80, 83, 85] },
        mastery:     [80, 84, 74, 68]
    },

    /* 3rd Quarter — July to September */
    3: {
        radar:       [88, 66, 72, 60],
        performance: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [82, 84, 86, 88] },
        mastery:     [84, 88, 76, 72]
    },

    /* 4th Quarter — October to December */
    4: {
        radar:       [95, 60, 65, 55],
        performance: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [85, 87, 88, 90] },
        mastery:     [85, 88, 75, 70]
    }
};

/* Mock record counts per quarter */
const RECORD_COUNTS = { all: 'All Records (124)', 1: '31 Records', 2: '30 Records', 3: '32 Records', 4: '31 Records' };

/* Quarter display names */
const QUARTER_NAMES = { '': 'All Quarters', '1': '1st Quarter', '2': '2nd Quarter', '3': '3rd Quarter', '4': '4th Quarter' };


/* CHART INSTANCES */
let radarChart       = null;
let performanceChart = null;
let masteryChart     = null;


/* SHARED CHART SETTINGS */
const axisColor = 'rgba(241, 245, 249, 0.65)';
const gridColor = 'rgba(148, 163, 184, 0.15)';

Chart.defaults.color       = axisColor;
Chart.defaults.font.family = "'Inter', sans-serif";


/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    // Build all three charts with default (all) data
    buildCharts('all');

    // Wire up filter inputs — update charts on any change
    document.getElementById('quarterSelect').addEventListener('change', onFilterChange);
    document.getElementById('startDate').addEventListener('change', onFilterChange);
    document.getElementById('endDate').addEventListener('change', onFilterChange);

    // Reset button
    document.getElementById('resetBtn').addEventListener('click', resetFilters);

    // Export button — opens the modal
    document.getElementById('exportBtn').addEventListener('click', openExportModal);

    // Export modal controls
    document.getElementById('exportCloseBtn').addEventListener('click', closeExportModal);
    document.getElementById('exportModal').addEventListener('click', e => {
        if (e.target === document.getElementById('exportModal')) closeExportModal();
    });

    // Format picker buttons
    document.querySelectorAll('.export-format-btn').forEach(btn => {
        btn.addEventListener('click', () => selectExportFormat(btn));
    });

    // Download / confirm button
    document.getElementById('exportConfirmBtn').addEventListener('click', confirmExport);

    // Hide page loader
    setTimeout(() => {
        const loader = document.getElementById('pageLoader');
        if (loader) loader.classList.add('hidden');
    }, 700);

});


/* BUILD CHARTS*/
function buildCharts(quarterKey) {
    const d = CHART_DATA[quarterKey] || CHART_DATA['all'];

    // Destroy existing charts before rebuilding
    if (radarChart)       { radarChart.destroy();       radarChart = null; }
    if (performanceChart) { performanceChart.destroy(); performanceChart = null; }
    if (masteryChart)     { masteryChart.destroy();     masteryChart = null; }

    // Radar Chart
    const radarCtx = document.getElementById('radarChart');
    if (radarCtx) {
        radarChart = new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: ['Chemistry', 'General Biology', 'Earth Science', 'Physics'],
                datasets: [{
                    label: 'Skills',
                    data: d.radar,
                    backgroundColor: 'rgba(96, 165, 250, 0.25)',
                    borderColor: '#60a5fa',
                    borderWidth: 2,
                    pointBackgroundColor: '#60a5fa',
                    pointBorderColor: '#fff',
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: { boxWidth: 14, color: '#e2e8f0' }
                    }
                },
                scales: {
                    r: {
                        min: 0, max: 100,
                        ticks: { stepSize: 20, color: axisColor, backdropColor: 'transparent' },
                        grid: { color: gridColor },
                        angleLines: { color: gridColor },
                        pointLabels: { color: '#e2e8f0', font: { size: 11 } }
                    }
                }
            }
        });
    }

    // Performance Line Chart
    const perfCtx = document.getElementById('performanceChart');
    if (perfCtx) {
        performanceChart = new Chart(perfCtx, {
            type: 'line',
            data: {
                labels: d.performance.labels,
                datasets: [{
                    label: 'Grade Average',
                    data: d.performance.data,
                    borderColor: '#60a5fa',
                    backgroundColor: 'rgba(96, 165, 250, 0.15)',
                    borderWidth: 2,
                    pointBackgroundColor: '#60a5fa',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: { boxWidth: 14, color: '#e2e8f0' }
                    }
                },
                scales: {
                    x: { ticks: { color: axisColor }, grid: { display: false } },
                    y: {
                        min: 60, max: 100,
                        ticks: { color: axisColor, stepSize: 5 },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }

    // Mastery Bar Chart
    const masteryCtx = document.getElementById('masteryChart');
    if (masteryCtx) {
        masteryChart = new Chart(masteryCtx, {
            type: 'bar',
            data: {
                labels: ['Chemistry', 'General Biology', 'Earth Science', 'Physics'],
                datasets: [{
                    label: 'Mastery %',
                    data: d.mastery,
                    backgroundColor: ['#38bdf8', '#4ade80', '#fb923c', '#a855f7'],
                    borderRadius: 6,
                    barThickness: 'flex',
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: { boxWidth: 14, color: '#e2e8f0' }
                    }
                },
                scales: {
                    x: { ticks: { color: axisColor }, grid: { display: false } },
                    y: {
                        min: 0, max: 100,
                        ticks: { color: axisColor, stepSize: 10 },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }
}


/* UPDATE CHARTS LIVE */
function updateChartsLive(quarterKey) {
    const d = CHART_DATA[quarterKey] || CHART_DATA['all'];

    // Update Radar
    if (radarChart) {
        radarChart.data.datasets[0].data = d.radar;
        radarChart.update();
    }

    // Update Performance — labels also change per quarter
    if (performanceChart) {
        performanceChart.data.labels                  = d.performance.labels;
        performanceChart.data.datasets[0].data        = d.performance.data;
        performanceChart.update();
    }

    // Update Mastery
    if (masteryChart) {
        masteryChart.data.datasets[0].data = d.mastery;
        masteryChart.update();
    }
}


/* ON FILTER CHANGE */
function onFilterChange() {
    const quarter   = document.getElementById('quarterSelect').value;
    const startDate = document.getElementById('startDate').value;
    const endDate   = document.getElementById('endDate').value;

    // Use quarter data if selected, otherwise use all
    const key = quarter || 'all';

    // Live-update the charts
    updateChartsLive(key);

    // Show/hide active filter badges on each chart card
    const badgeText = buildBadgeText(quarter, startDate, endDate);
    updateFilterBadges(badgeText);
}


/* Build a short badge label from active filters */
function buildBadgeText(quarter, startDate, endDate) {
    const parts = [];
    if (quarter)              parts.push(QUARTER_NAMES[quarter]);
    if (startDate && endDate) parts.push(`${startDate} → ${endDate}`);
    else if (startDate)       parts.push(`From ${startDate}`);
    else if (endDate)         parts.push(`Until ${endDate}`);
    return parts.join(' · ');
}


/* Show or hide the filter badge on all chart cards */
function updateFilterBadges(text) {
    const badges = ['radarBadge', 'performanceBadge', 'masteryBadge'];
    badges.forEach(id => {
        const badge = document.getElementById(id);
        if (!badge) return;
        if (text) {
            badge.style.display = 'inline-flex';
            badge.querySelector('span').textContent = text;
        } else {
            badge.style.display = 'none';
        }
    });
}


/* RESET FILTERS
   Clears all inputs and restores default data*/
function resetFilters() {
    document.getElementById('startDate').value    = '';
    document.getElementById('endDate').value      = '';
    document.getElementById('quarterSelect').value = '';

    // Restore all-data charts
    updateChartsLive('all');

    // Hide badges
    updateFilterBadges('');

    showToast('Filters reset');
}


/* EXPORT MODAL */
const exportModal     = document.getElementById('exportModal');
const exportModalCard = document.getElementById('exportModalCard');
let selectedFormat    = 'csv';  

function openExportModal() {
    // Read current filter values to show in summary
    const quarter   = document.getElementById('quarterSelect').value;
    const startDate = document.getElementById('startDate').value;
    const endDate   = document.getElementById('endDate').value;

    // Fill in summary row values
    if (startDate && endDate) {
        document.getElementById('exportSummaryDate').textContent = `${startDate} to ${endDate}`;
    } else if (startDate) {
        document.getElementById('exportSummaryDate').textContent = `From ${startDate}`;
    } else if (endDate) {
        document.getElementById('exportSummaryDate').textContent = `Until ${endDate}`;
    } else {
        document.getElementById('exportSummaryDate').textContent = 'All dates';
    }

    document.getElementById('exportSummaryQuarter').textContent =
        QUARTER_NAMES[quarter] || 'All Quarters';

    document.getElementById('exportSummaryCount').textContent =
        RECORD_COUNTS[quarter || 'all'];

    // Open modal
    exportModal.style.display = 'flex';
    setTimeout(() => {
        exportModal.style.opacity = '1';
        exportModalCard.classList.add('scaled');
    }, 10);
}

function closeExportModal() {
    exportModal.style.opacity = '0';
    exportModalCard.classList.remove('scaled');
    setTimeout(() => { exportModal.style.display = 'none'; }, 300);
}

/* Clicking a format button selects it */
function selectExportFormat(btn) {
    document.querySelectorAll('.export-format-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedFormat = btn.dataset.format;
}

/* Download / confirm export */
function confirmExport() {
    const quarter   = document.getElementById('quarterSelect').value;
    const label     = QUARTER_NAMES[quarter] || 'All Quarters';
    const format    = selectedFormat.toUpperCase();

    // Show loading state on the button
    const confirmBtn = document.getElementById('exportConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';

    // Simulate export delay (replace with real download logic)
    setTimeout(() => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-download"></i> Download';

        closeExportModal();
        showToast(`Exported ${label} as ${format} successfully!`);
    }, 1500);
}


/* TOAST*/
function showToast(message) {
    const toast        = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add('visible');
    setTimeout(() => toast.classList.remove('visible'), 2500);
}
