/* DATA*/
const SECTION_NAMES = {
    '':         'All Sections',
    'amethyst': 'STEM - Amethyst',
    'sapphire': 'STEM - Sapphire',
    'topaz':    'STEM - Topaz'
};

const QUARTER_NAMES = { '': 'All Quarters', '1': '1st Quarter', '2': '2nd Quarter', '3': '3rd Quarter', '4': '4th Quarter' };

/* Mock record counts keyed by "section|quarter" */
const RECORD_COUNTS = {
    'all|all': 'All Records (124)',
    'amethyst|all': '42 Records', 'sapphire|all': '39 Records', 'topaz|all': '43 Records',
    'all|1': '31 Records', 'all|2': '30 Records', 'all|3': '32 Records', 'all|4': '31 Records'
};

/* Per-section dataset */
const SECTION_DATA = {

    /*  STEM - Amethyst (Ma'am Mila's primary/current section) */
    amethyst: {
        radar: {
            all: [95, 78, 88, 82, 75],
            1:   [90, 70, 80, 74, 68],
            2:   [93, 75, 84, 78, 72],
            3:   [96, 80, 90, 85, 78],
            4:   [97, 82, 92, 88, 80]
        },
        performance: {
            all: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'], data: [70, 73, 77, 80, 82, 84] },
            1:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [68, 71, 74, 76] },
            2:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [75, 77, 79, 81] },
            3:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [80, 82, 84, 86] },
            4:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [83, 85, 87, 88] }
        },
        mastery: {
            all: { 'Atomic Structure': 90, 'Chemical Bonding': 85, 'Stoichiometry': 80, 'Acids & Bases': 75 },
            1:   { 'Atomic Structure': 82, 'Chemical Bonding': 76, 'Stoichiometry': 70, 'Acids & Bases': 65 },
            2:   { 'Atomic Structure': 86, 'Chemical Bonding': 80, 'Stoichiometry': 74, 'Acids & Bases': 70 },
            3:   { 'Atomic Structure': 91, 'Chemical Bonding': 86, 'Stoichiometry': 82, 'Acids & Bases': 78 },
            4:   { 'Atomic Structure': 94, 'Chemical Bonding': 90, 'Stoichiometry': 86, 'Acids & Bases': 82 }
        },
        insights: {
            all: [
                { type: 'positive', title: 'Steady Progress',  desc: 'Students showing consistent improvement in problem-solving across all quarters.' },
                { type: 'positive', title: 'Lab Performance',  desc: 'Excellent results in practical experiments, especially titration and bonding labs.' },
                { type: 'warning',  title: 'Needs Attention',  desc: 'Some students still struggling with stoichiometric calculations.' }
            ],
            1: [
                { type: 'warning', title: 'Slow Start',        desc: 'Class average is below target heading into the first quarter assessments.' },
                { type: 'alert',   title: 'Needs Attention',   desc: 'Acids & Bases concepts show the weakest early mastery — consider a review session.' }
            ],
            2: [
                { type: 'positive', title: 'Improving Trend',  desc: 'Performance picked up noticeably after the bonding unit review.' },
                { type: 'warning',  title: 'Watch List',       desc: 'A handful of students remain below the class average in Stoichiometry.' }
            ],
            3: [
                { type: 'positive', title: 'Strong Quarter',   desc: 'Best quarter so far — mastery is climbing across every topic.' },
                { type: 'positive', title: 'Lab Performance',  desc: 'Practical exam scores reflect strong hands-on understanding.' }
            ],
            4: [
                { type: 'positive', title: 'Steady Progress',  desc: 'Students showing consistent improvement in problem-solving.' },
                { type: 'positive', title: 'Lab Performance',  desc: 'Excellent results in practical experiments.' },
                { type: 'warning',  title: 'Needs Attention',  desc: 'Some students struggling with advanced acid-base titration.' }
            ]
        }
    },

    /*  STEM - Sapphire */
    sapphire: {
        radar: {
            all: [88, 70, 80, 74, 68],
            1:   [82, 64, 73, 66, 60],
            2:   [86, 68, 77, 70, 64],
            3:   [90, 73, 82, 77, 71],
            4:   [92, 76, 85, 80, 74]
        },
        performance: {
            all: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'], data: [65, 68, 71, 74, 76, 78] },
            1:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [62, 65, 67, 69] },
            2:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [69, 71, 73, 75] },
            3:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [74, 76, 78, 80] },
            4:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [77, 79, 81, 83] }
        },
        mastery: {
            all: { 'Atomic Structure': 84, 'Chemical Bonding': 78, 'Stoichiometry': 70, 'Acids & Bases': 66 },
            1:   { 'Atomic Structure': 76, 'Chemical Bonding': 68, 'Stoichiometry': 60, 'Acids & Bases': 55 },
            2:   { 'Atomic Structure': 80, 'Chemical Bonding': 73, 'Stoichiometry': 64, 'Acids & Bases': 60 },
            3:   { 'Atomic Structure': 86, 'Chemical Bonding': 80, 'Stoichiometry': 73, 'Acids & Bases': 69 },
            4:   { 'Atomic Structure': 90, 'Chemical Bonding': 84, 'Stoichiometry': 78, 'Acids & Bases': 74 }
        },
        insights: {
            all: [
                { type: 'warning',  title: 'Mixed Results',     desc: 'Performance varies widely between students — consider grouped review sessions.' },
                { type: 'positive', title: 'Engagement Up',     desc: 'Participation in recitation has improved since last quarter.' },
                { type: 'alert',    title: 'Needs Attention',   desc: 'Stoichiometry and Acids & Bases remain the weakest topics overall.' }
            ],
            1: [
                { type: 'alert',   title: 'Slow Start',         desc: 'Class average trails behind other sections in the first quarter.' }
            ],
            2: [
                { type: 'warning', title: 'Gradual Improvement', desc: 'Scores are inching up but still below target.' }
            ],
            3: [
                { type: 'positive', title: 'Catching Up',       desc: 'Noticeable gains this quarter, especially in bonding topics.' }
            ],
            4: [
                { type: 'positive', title: 'Strong Finish',     desc: 'Best quarter yet for this section — keep up the momentum.' }
            ]
        }
    },

    /*  STEM - Topaz */
    topaz: {
        radar: {
            all: [92, 85, 90, 86, 80],
            1:   [88, 80, 85, 80, 74],
            2:   [90, 83, 87, 83, 77],
            3:   [93, 86, 91, 88, 82],
            4:   [95, 89, 93, 90, 85]
        },
        performance: {
            all: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'], data: [78, 81, 84, 87, 89, 91] },
            1:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [75, 78, 80, 82] },
            2:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [82, 84, 86, 88] },
            3:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [86, 88, 89, 91] },
            4:   { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], data: [89, 90, 92, 93] }
        },
        mastery: {
            all: { 'Atomic Structure': 93, 'Chemical Bonding': 90, 'Stoichiometry': 86, 'Acids & Bases': 84 },
            1:   { 'Atomic Structure': 88, 'Chemical Bonding': 84, 'Stoichiometry': 78, 'Acids & Bases': 75 },
            2:   { 'Atomic Structure': 91, 'Chemical Bonding': 87, 'Stoichiometry': 82, 'Acids & Bases': 79 },
            3:   { 'Atomic Structure': 94, 'Chemical Bonding': 91, 'Stoichiometry': 88, 'Acids & Bases': 86 },
            4:   { 'Atomic Structure': 96, 'Chemical Bonding': 94, 'Stoichiometry': 91, 'Acids & Bases': 89 }
        },
        insights: {
            all: [
                { type: 'positive', title: 'Top Performing Section', desc: 'Consistently the strongest scores across every Chemistry topic.' },
                { type: 'positive', title: 'High Engagement',        desc: 'Attendance and participation remain excellent all quarter.' },
                { type: 'warning',  title: 'Watch List',             desc: 'A small group could still use extra support in Acids & Bases.' }
            ],
            1: [
                { type: 'positive', title: 'Strong Start',  desc: 'Already ahead of pace compared to other sections.' }
            ],
            2: [
                { type: 'positive', title: 'Continued Growth', desc: 'Scores keep climbing steadily quarter over quarter.' }
            ],
            3: [
                { type: 'positive', title: 'Excellent Quarter', desc: 'Near-mastery levels across all topics.' }
            ],
            4: [
                { type: 'positive', title: 'Top Performing Section', desc: 'Finishing the year with the highest averages in Chemistry.' }
            ]
        }
    }
};


/* Build the combined "All Sections" view by averaging the three
   sections together — keeps the All Sections option meaningful
   instead of just defaulting to one section's numbers. */
function buildAllSectionsData() {
    const sectionKeys = Object.keys(SECTION_DATA);
    const quarterKeys = ['all', '1', '2', '3', '4'];

    const radar = {};
    const performance = {};
    const mastery = {};
    const insights = {};

    quarterKeys.forEach(q => {
        // Radar — average each of the 5 axes across sections
        const radarLists = sectionKeys.map(s => SECTION_DATA[s].radar[q]);
        radar[q] = radarLists[0].map((_, i) =>
            Math.round(radarLists.reduce((sum, list) => sum + list[i], 0) / radarLists.length)
        );

        // Performance — average each week's value across sections (use Amethyst's labels)
        const perfLists = sectionKeys.map(s => SECTION_DATA[s].performance[q].data);
        const avgPerf = perfLists[0].map((_, i) =>
            Math.round(perfLists.reduce((sum, list) => sum + list[i], 0) / perfLists.length)
        );
        performance[q] = { labels: SECTION_DATA['amethyst'].performance[q].labels, data: avgPerf };

        // Mastery — average each topic % across sections
        const masteryObjs = sectionKeys.map(s => SECTION_DATA[s].mastery[q]);
        const topics = Object.keys(masteryObjs[0]);
        const avgMastery = {};
        topics.forEach(topic => {
            avgMastery[topic] = Math.round(masteryObjs.reduce((sum, obj) => sum + obj[topic], 0) / masteryObjs.length);
        });
        mastery[q] = avgMastery;

        // Insights — combine one insight from each section so "All Sections" still feels informative
        insights[q] = sectionKeys.flatMap(s => SECTION_DATA[s].insights[q] || SECTION_DATA[s].insights['all']).slice(0, 4);
    });

    return { radar, performance, mastery, insights };
}

SECTION_DATA.all = buildAllSectionsData();


/* Convenience getter — falls back gracefully if a quarter/section combo is missing */
function getFilteredData(sectionKey, quarterKey) {
    const section = SECTION_DATA[sectionKey] || SECTION_DATA['all'];
    const q = quarterKey || 'all';

    return {
        radar:       section.radar[q]       || section.radar['all'],
        performance: section.performance[q] || section.performance['all'],
        mastery:     section.mastery[q]     || section.mastery['all'],
        insights:    section.insights[q]    || section.insights['all']
    };
}


/* CHART INSTANCES */
let radarChart       = null;
let performanceChart = null;


/* SHARED CHART SETTINGS */
const axisColor = 'rgba(241, 245, 249, 0.65)';
const gridColor = 'rgba(148, 163, 184, 0.15)';

Chart.defaults.color       = axisColor;
Chart.defaults.font.family = "'Inter', sans-serif";


/* INIT — runs when page loads*/
document.addEventListener('DOMContentLoaded', () => {

    // Build everything with default (all sections, all quarters) data
    refreshDashboard();

    // Wire up filter inputs — update everything on any change
    document.getElementById('sectionSelect').addEventListener('change', onFilterChange);
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

    // My Classes sidebar dropdown
    wireMyClassesDropdown();

    // Create Class modal
    wireCreateClassModal();

    // Hide page loader
    setTimeout(() => {
        const loader = document.getElementById('pageLoader');
        if (loader) loader.classList.add('hidden');
    }, 700);

});


/* REFRESH EVERYTHING  */
function refreshDashboard() {
    const sectionKey = document.getElementById('sectionSelect').value || 'all';
    const quarterKey = document.getElementById('quarterSelect').value || 'all';

    const d = getFilteredData(sectionKey, quarterKey);

    buildOrUpdateCharts(d);
    renderInsights(d.insights);
    renderMasteryBoxes(d.mastery);
}


/* BUILD / UPDATE CHARTS (radar + line) */
function buildOrUpdateCharts(d) {
    // Radar Chart
    const radarCtx = document.getElementById('radarChart');
    if (radarCtx) {
        if (!radarChart) {
            radarChart = new Chart(radarCtx, {
                type: 'radar',
                data: {
                    labels: ['Attendance', 'Participation', 'Assignments', 'Quizzes', 'Exams'],
                    datasets: [{
                        label: 'Performance Metrics',
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
        } else {
            radarChart.data.datasets[0].data = d.radar;
            radarChart.update();
        }
    }

    // Class Performance Overview — Line Chart
    const perfCtx = document.getElementById('performanceChart');
    if (perfCtx) {
        if (!performanceChart) {
            performanceChart = new Chart(perfCtx, {
                type: 'line',
                data: {
                    labels: d.performance.labels,
                    datasets: [{
                        label: 'Class Average',
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
                            min: 0, max: 100,
                            ticks: { color: axisColor, stepSize: 10 },
                            grid: { color: gridColor }
                        }
                    }
                }
            });
        } else {
            performanceChart.data.labels           = d.performance.labels;
            performanceChart.data.datasets[0].data = d.performance.data;
            performanceChart.update();
        }
    }
}


/* AI INSIGHTS AND RECOMMENDATION*/
function renderInsights(insightList) {
    const container = document.getElementById('insightsList');
    if (!container) return;

    if (!insightList || !insightList.length) {
        container.innerHTML = '<div class="insights-empty">No insights available for this selection.</div>';
        return;
    }

    const typeClassMap = { positive: 'insight-positive', warning: 'insight-warning', alert: 'insight-alert' };

    container.innerHTML = insightList.map(item => `
        <div class="insight-item ${typeClassMap[item.type] || ''}">
            <div class="insight-title">${escapeHtml(item.title)}</div>
            <div class="insight-desc">${escapeHtml(item.desc)}</div>
        </div>
    `).join('');
}


/* AVERAGE MASTERY PER LESSON  */
function renderMasteryBoxes(masteryObj) {
    const container = document.getElementById('masteryGrid');
    if (!container) return;

    const topics = Object.keys(masteryObj || {});
    if (!topics.length) {
        container.innerHTML = '<div class="insights-empty">No mastery data available.</div>';
        return;
    }

    container.innerHTML = topics.map(topic => `
        <div class="mastery-box">
            <span class="mastery-box-label">${escapeHtml(topic)}</span>
            <span class="mastery-box-value">${masteryObj[topic]}%</span>
        </div>
    `).join('');
}


function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}


/* ON FILTER CHANGE */
function onFilterChange() {
    const section   = document.getElementById('sectionSelect').value;
    const quarter   = document.getElementById('quarterSelect').value;
    const startDate = document.getElementById('startDate').value;
    const endDate   = document.getElementById('endDate').value;

    // Re-render charts, insights, and mastery boxes for the new filter combo
    refreshDashboard();

    // Show/hide active filter badges on each chart card
    const badgeText = buildBadgeText(section, quarter, startDate, endDate);
    updateFilterBadges(badgeText);
}


/* Build a short badge label from active filters */
function buildBadgeText(section, quarter, startDate, endDate) {
    const parts = [];
    if (section)               parts.push(SECTION_NAMES[section]);
    if (quarter)               parts.push(QUARTER_NAMES[quarter]);
    if (startDate && endDate)  parts.push(`${startDate} → ${endDate}`);
    else if (startDate)        parts.push(`From ${startDate}`);
    else if (endDate)          parts.push(`Until ${endDate}`);
    return parts.join(' · ');
}


/* Show or hide the filter badge on all chart cards */
function updateFilterBadges(text) {
    const badges = ['radarBadge', 'performanceBadge', 'insightsBadge', 'masteryBadge'];
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
    document.getElementById('sectionSelect').value = '';
    document.getElementById('startDate').value     = '';
    document.getElementById('endDate').value       = '';
    document.getElementById('quarterSelect').value = '';

    // Restore all-data view
    refreshDashboard();

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
    const section   = document.getElementById('sectionSelect').value;
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
        (section ? SECTION_NAMES[section] + ' · ' : '') + (QUARTER_NAMES[quarter] || 'All Quarters');

    const countKey = `${section || 'all'}|${quarter || 'all'}`;
    document.getElementById('exportSummaryCount').textContent =
        RECORD_COUNTS[countKey] || RECORD_COUNTS['all|all'];

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
    const section   = document.getElementById('sectionSelect').value;
    const quarter   = document.getElementById('quarterSelect').value;
    const label     = (section ? SECTION_NAMES[section] + ' · ' : '') + (QUARTER_NAMES[quarter] || 'All Quarters');
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


/* MY CLASSES SIDEBAR DROPDOWN*/
function wireMyClassesDropdown() {
    const toggleBtn = document.getElementById('myClassesToggleBtn');
    const container = document.getElementById('myClassesDropdownContainer');
    const chevron    = document.getElementById('myClassesChevron');
    const sidebar    = document.getElementById('sidebar');

    if (!toggleBtn || !container || !chevron) return;

    function openDropdown() {
        container.classList.add('open');
        chevron.classList.add('rotated');
        requestAnimationFrame(() => {
            container.style.maxHeight = container.scrollHeight + 'px';
        });
    }

    function closeDropdown() {
        container.style.maxHeight = '0px';
        container.classList.remove('open');
        chevron.classList.remove('rotated');
    }

    function toggleDropdown() {
        // Kung collapsed ang sidebar, i-expand muna
        if (sidebar && sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarState', 'expanded');
        }

        if (container.classList.contains('open')) {
            closeDropdown();
        } else {
            openDropdown();
        }
    }

    toggleBtn.addEventListener('click', toggleDropdown);

    // I-expand by default sa simula para makita agad ang Chemistry link
    requestAnimationFrame(() => openDropdown());
}


/* CREATE CLASS MODAL  */
function wireCreateClassModal() {
    const createBtn  = document.getElementById('createClassBtn');
    const modal       = document.getElementById('createClassModal');
    const modalCard    = document.getElementById('createClassModalCard');
    const closeBtn     = document.getElementById('createClassCloseBtn');
    const cancelBtn     = document.getElementById('createClassCancelBtn');
    const confirmBtn    = document.getElementById('createClassConfirmBtn');

    const nameInput     = document.getElementById('ccClassName');
    const sectionInput  = document.getElementById('ccSection');
    const subjectInput  = document.getElementById('ccSubject');
    const roomInput     = document.getElementById('ccRoom');
    const nameError     = document.getElementById('ccNameError');

    if (!createBtn || !modal) return;

    function resetForm() {
        nameInput.value    = '';
        sectionInput.value = '';
        subjectInput.value = '';
        roomInput.value    = '';
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    }

    function openModal() {
        resetForm();
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.opacity = '1';
            modalCard.classList.add('scaled');
            nameInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.style.opacity = '0';
        modalCard.classList.remove('scaled');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    function confirmCreate() {
        const className   = nameInput.value.trim();

        if (!className) {
            nameError.textContent = '*Required';
            nameError.classList.remove('hidden');
            nameInput.classList.add('error');
            nameInput.focus();
            return;
        }

        nameError.classList.add('hidden');
        nameInput.classList.remove('error');

        closeModal();
        showToast(`Class "${className}" created!`);
    }

    createBtn.addEventListener('click', openModal);
    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)  cancelBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', confirmCreate);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    [nameInput, sectionInput, subjectInput, roomInput].forEach(field => {
        field.addEventListener('keydown', e => {
            if (e.key === 'Enter') confirmCreate();
        });
    });

    // Clear error state habang nagtatype ang professor
    nameInput.addEventListener('input', () => {
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    });
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
