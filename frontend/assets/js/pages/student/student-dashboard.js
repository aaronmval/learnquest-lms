/* SUBJECT CONSTANTS */
const SUBJECTS = [
    { key: "chemistry", label: "Chemistry" },
    { key: "generalBiology", label: "General Biology" },
    { key: "earthScience", label: "Earth Science" },
    { key: "physics", label: "Physics" },
];

const SUBJECT_LABELS = SUBJECTS.map((subject) => subject.label);
const SUBJECT_KEYS = SUBJECTS.map((subject) => subject.key);
const SUBTOPIC_LABELS = [
    "Fundamentals",
    "Problem Solving",
    "Analysis",
    "Application",
    "Retention",
    "Critical Thinking",
];

/* CHART DATA PER QUARTER */
const CHART_DATA = {
    /* No filter — all data (default) */
    all: {
        radarMastery: [88, 82, 76, 80],
        performance: {
            labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
            data: [74, 78, 81, 84, 86, 89],
        },
        masteryImprovement: {
            labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
            data: [62, 66, 70, 75, 79, 83],
        },
    },

    /* 1st Quarter — January to March */
    1: {
        radarMastery: [78, 73, 68, 72],
        performance: { labels: ["Jan", "Feb", "Mar"], data: [72, 76, 80] },
        masteryImprovement: {
            labels: ["Jan", "Feb", "Mar"],
            data: [60, 65, 70],
        },
    },

    /* 2nd Quarter — April to June */
    2: {
        radarMastery: [84, 79, 74, 77],
        performance: { labels: ["Apr", "May", "Jun"], data: [81, 84, 87] },
        masteryImprovement: {
            labels: ["Apr", "May", "Jun"],
            data: [72, 77, 82],
        },
    },

    /* 3rd Quarter — July to September */
    3: {
        radarMastery: [86, 81, 76, 80],
        performance: { labels: ["Jul", "Aug", "Sep"], data: [83, 85, 87] },
        masteryImprovement: {
            labels: ["Jul", "Aug", "Sep"],
            data: [74, 78, 81],
        },
    },

    /* 4th Quarter — October to December */
    4: {
        radarMastery: [90, 85, 79, 84],
        performance: { labels: ["Oct", "Nov", "Dec"], data: [86, 88, 91] },
        masteryImprovement: {
            labels: ["Oct", "Nov", "Dec"],
            data: [78, 82, 86],
        },
    },
};

/* SUB-TOPIC MASTERY DATA PER SUBJECT */
const SUBJECT_SUBTOPIC_DATA = {
    all: {
        chemistry: [90, 86, 82, 88, 84, 89],
        generalBiology: [84, 80, 79, 83, 81, 85],
        earthScience: [78, 73, 76, 74, 72, 77],
        physics: [82, 77, 79, 81, 78, 83],
    },
    1: {
        chemistry: [80, 76, 72, 78, 74, 79],
        generalBiology: [75, 71, 70, 73, 72, 76],
        earthScience: [70, 66, 69, 67, 65, 70],
        physics: [74, 69, 71, 73, 70, 75],
    },
    2: {
        chemistry: [86, 82, 78, 84, 80, 85],
        generalBiology: [81, 77, 76, 80, 78, 82],
        earthScience: [75, 71, 73, 72, 70, 74],
        physics: [79, 74, 76, 78, 75, 80],
    },
    3: {
        chemistry: [88, 84, 80, 86, 82, 87],
        generalBiology: [83, 79, 78, 82, 80, 84],
        earthScience: [77, 73, 75, 74, 72, 76],
        physics: [81, 76, 78, 80, 77, 82],
    },
    4: {
        chemistry: [92, 88, 84, 90, 86, 91],
        generalBiology: [87, 83, 82, 86, 84, 88],
        earthScience: [80, 76, 78, 77, 75, 79],
        physics: [85, 80, 82, 84, 81, 86],
    },
};

/* Mock record counts per quarter */
const RECORD_COUNTS = {
    all: "All Records (124)",
    1: "31 Records",
    2: "30 Records",
    3: "32 Records",
    4: "31 Records",
};

/* Quarter display names */
const QUARTER_NAMES = {
    "": "All Quarters",
    1: "1st Quarter",
    2: "2nd Quarter",
    3: "3rd Quarter",
    4: "4th Quarter",
};

/* CHART INSTANCES */
let radarChart = null;
let performanceChart = null;
let masteryChart = null;
let subjectRadarChart = null;
let selectedSubjectKey = SUBJECT_KEYS[0];
let activeQuarterKey = "all";

/* SHARED CHART SETTINGS */
const axisColor = "rgba(241, 245, 249, 0.65)";
const gridColor = "rgba(148, 163, 184, 0.15)";

Chart.defaults.color = axisColor;
Chart.defaults.font.family = "'Inter', sans-serif";

/* INIT — runs when page loads */
document.addEventListener("DOMContentLoaded", () => {
    // Build all three charts with default (all) data
    buildCharts("all");

    // Wire up filter inputs — update charts on any change
    const quarterSelect = document.getElementById("quarterSelect");
    const startDate = document.getElementById("startDate");
    const endDate = document.getElementById("endDate");

    if (quarterSelect) {
        quarterSelect.addEventListener("change", onFilterChange);
    }

    if (startDate) {
        startDate.addEventListener("change", onFilterChange);
    }

    if (endDate) {
        endDate.addEventListener("change", onFilterChange);
    }

    // Reset button
    const resetBtn = document.getElementById("resetBtn");
    if (resetBtn) {
        resetBtn.addEventListener("click", resetFilters);
    }

    // Export button — opens the modal
    const exportBtn = document.getElementById("exportBtn");
    if (exportBtn) {
        exportBtn.addEventListener("click", openExportModal);
    }

    // Export modal controls
    const exportCloseBtn = document.getElementById("exportCloseBtn");
    const exportModalElement = document.getElementById("exportModal");
    if (exportCloseBtn) {
        exportCloseBtn.addEventListener("click", closeExportModal);
    }

    if (exportModalElement) {
        exportModalElement.addEventListener("click", (e) => {
            if (e.target === exportModalElement) {
                closeExportModal();
            }
        });
    }

    // Format picker buttons
    document.querySelectorAll(".export-format-btn").forEach((btn) => {
        btn.addEventListener("click", () => selectExportFormat(btn));
    });

    // Download / confirm button
    const exportConfirmBtn = document.getElementById("exportConfirmBtn");
    if (exportConfirmBtn) {
        exportConfirmBtn.addEventListener("click", confirmExport);
    }

    // Hide page loader
    setTimeout(() => {
        const loader = document.getElementById("pageLoader");
        if (loader) loader.classList.add("hidden");
    }, 700);
});

/* BUILD CHARTS*/
function buildCharts(quarterKey) {
    activeQuarterKey = quarterKey;
    const d = CHART_DATA[quarterKey] || CHART_DATA["all"];

    // Destroy existing charts before rebuilding
    if (radarChart) {
        radarChart.destroy();
        radarChart = null;
    }
    if (performanceChart) {
        performanceChart.destroy();
        performanceChart = null;
    }
    if (masteryChart) {
        masteryChart.destroy();
        masteryChart = null;
    }
    if (subjectRadarChart) {
        subjectRadarChart.destroy();
        subjectRadarChart = null;
    }

    // Radar Chart
    const radarCtx = document.getElementById("radarChart");
    if (radarCtx) {
        radarChart = new Chart(radarCtx, {
            type: "radar",
            data: {
                labels: SUBJECT_LABELS,
                datasets: [
                    {
                        label: "Mastery Level",
                        data: d.radarMastery,
                        backgroundColor: "rgba(96, 165, 250, 0.25)",
                        borderColor: "#60a5fa",
                        borderWidth: 2,
                        pointBackgroundColor: "#60a5fa",
                        pointBorderColor: "#fff",
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                onClick: (_event, activeElements) => {
                    if (!activeElements.length) {
                        return;
                    }

                    const clickedIndex = activeElements[0].index;
                    const clickedSubjectKey = SUBJECT_KEYS[clickedIndex];

                    if (clickedSubjectKey) {
                        selectedSubjectKey = clickedSubjectKey;
                        updateSubjectRadar(activeQuarterKey);
                    }
                },
                onHover: (event, activeElements) => {
                    if (event?.native?.target) {
                        event.native.target.style.cursor = activeElements.length
                            ? "pointer"
                            : "default";
                    }
                },
                plugins: {
                    legend: {
                        position: "top",
                        align: "start",
                        labels: { boxWidth: 14, color: "#e2e8f0" },
                    },
                },
                scales: {
                    r: {
                        min: 0,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            color: axisColor,
                            backdropColor: "transparent",
                        },
                        grid: { color: gridColor },
                        angleLines: { color: gridColor },
                        pointLabels: { color: "#e2e8f0", font: { size: 11 } },
                    },
                },
            },
        });
    }

    // Performance Line Chart
    const perfCtx = document.getElementById("performanceChart");
    if (perfCtx) {
        performanceChart = new Chart(perfCtx, {
            type: "line",
            data: {
                labels: d.performance.labels,
                datasets: [
                    {
                        label: "Average Quiz Scores",
                        data: d.performance.data,
                        borderColor: "#60a5fa",
                        backgroundColor: "rgba(96, 165, 250, 0.15)",
                        borderWidth: 2,
                        pointBackgroundColor: "#60a5fa",
                        pointBorderColor: "#fff",
                        pointRadius: 4,
                        tension: 0.35,
                        fill: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                plugins: {
                    legend: {
                        position: "top",
                        align: "start",
                        labels: { boxWidth: 14, color: "#e2e8f0" },
                    },
                },
                scales: {
                    x: {
                        ticks: { color: axisColor },
                        grid: { display: false },
                    },
                    y: {
                        min: 60,
                        max: 100,
                        ticks: { color: axisColor, stepSize: 5 },
                        grid: { color: gridColor },
                    },
                },
            },
        });
    }

    // Mastery Improvement Line Chart
    const masteryCtx = document.getElementById("masteryChart");
    if (masteryCtx) {
        masteryChart = new Chart(masteryCtx, {
            type: "line",
            data: {
                labels: d.masteryImprovement.labels,
                datasets: [
                    {
                        label: "Mastery Level Improvement",
                        data: d.masteryImprovement.data,
                        borderColor: "#22d3ee",
                        backgroundColor: "rgba(34, 211, 238, 0.16)",
                        borderWidth: 2,
                        pointBackgroundColor: "#22d3ee",
                        pointBorderColor: "#fff",
                        pointRadius: 4,
                        tension: 0.35,
                        fill: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                plugins: {
                    legend: {
                        position: "top",
                        align: "start",
                        labels: { boxWidth: 14, color: "#e2e8f0" },
                    },
                },
                scales: {
                    x: {
                        ticks: { color: axisColor },
                        grid: { display: false },
                    },
                    y: {
                        min: 50,
                        max: 100,
                        ticks: { color: axisColor, stepSize: 10 },
                        grid: { color: gridColor },
                    },
                },
            },
        });
    }

    updateSubjectRadar(activeQuarterKey);
}

/* UPDATE CHARTS LIVE */
function updateChartsLive(quarterKey) {
    activeQuarterKey = quarterKey;
    const d = CHART_DATA[quarterKey] || CHART_DATA["all"];

    // Update Radar
    if (radarChart) {
        radarChart.data.datasets[0].data = d.radarMastery;
        radarChart.update();
    }

    // Update Performance — labels also change per quarter
    if (performanceChart) {
        performanceChart.data.labels = d.performance.labels;
        performanceChart.data.datasets[0].data = d.performance.data;
        performanceChart.update();
    }

    // Update Mastery Improvement
    if (masteryChart) {
        masteryChart.data.labels = d.masteryImprovement.labels;
        masteryChart.data.datasets[0].data = d.masteryImprovement.data;
        masteryChart.update();
    }

    updateSubjectRadar(activeQuarterKey);
}

/* UPDATE MINI RADAR FOR SELECTED SUBJECT */
function updateSubjectRadar(quarterKey) {
    const radarElement = document.getElementById("subjectRadarChart");
    if (!radarElement) {
        return;
    }

    const subjectTitle = document.getElementById("subjectRadarTitle");
    const currentQuarterSubjects =
        SUBJECT_SUBTOPIC_DATA[quarterKey] || SUBJECT_SUBTOPIC_DATA.all;
    const selectedSubjectData =
        currentQuarterSubjects[selectedSubjectKey] ||
        SUBJECT_SUBTOPIC_DATA.all[selectedSubjectKey];
    const selectedSubjectName = getSubjectLabel(selectedSubjectKey);

    if (subjectTitle) {
        subjectTitle.textContent = `${selectedSubjectName} mastery by sub-topic`;
    }

    if (!subjectRadarChart) {
        subjectRadarChart = new Chart(radarElement, {
            type: "radar",
            data: {
                labels: SUBTOPIC_LABELS,
                datasets: [
                    {
                        label: `${selectedSubjectName} Sub-topic Mastery`,
                        data: selectedSubjectData,
                        backgroundColor: "rgba(34, 197, 94, 0.22)",
                        borderColor: "#22c55e",
                        borderWidth: 2,
                        pointBackgroundColor: "#22c55e",
                        pointBorderColor: "#fff",
                        pointRadius: 3,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350 },
                plugins: {
                    legend: {
                        position: "top",
                        align: "start",
                        labels: { boxWidth: 12, color: "#e2e8f0" },
                    },
                },
                scales: {
                    r: {
                        min: 0,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            color: axisColor,
                            backdropColor: "transparent",
                        },
                        grid: { color: gridColor },
                        angleLines: { color: gridColor },
                        pointLabels: { color: "#e2e8f0", font: { size: 11 } },
                    },
                },
            },
        });
        return;
    }

    subjectRadarChart.data.datasets[0].label = `${selectedSubjectName} Sub-topic Mastery`;
    subjectRadarChart.data.datasets[0].data = selectedSubjectData;
    subjectRadarChart.update();
}

function getSubjectLabel(subjectKey) {
    const subject = SUBJECTS.find((item) => item.key === subjectKey);
    return subject ? subject.label : "Subject";
}

/* ON FILTER CHANGE */
function onFilterChange() {
    const quarter = document.getElementById("quarterSelect").value;
    const startDate = document.getElementById("startDate").value;
    const endDate = document.getElementById("endDate").value;

    // Use quarter data if selected, otherwise use all
    const key = quarter || "all";

    // Live-update the charts
    updateChartsLive(key);

    // Show/hide active filter badges on each chart card
    const badgeText = buildBadgeText(quarter, startDate, endDate);
    updateFilterBadges(badgeText);
}

/* Build a short badge label from active filters */
function buildBadgeText(quarter, startDate, endDate) {
    const parts = [];
    if (quarter) parts.push(QUARTER_NAMES[quarter]);
    if (startDate && endDate) parts.push(`${startDate} → ${endDate}`);
    else if (startDate) parts.push(`From ${startDate}`);
    else if (endDate) parts.push(`Until ${endDate}`);
    return parts.join(" · ");
}

/* Show or hide the filter badge on all chart cards */
function updateFilterBadges(text) {
    const badges = [
        "radarBadge",
        "subjectBadge",
        "performanceBadge",
        "masteryBadge",
    ];
    badges.forEach((id) => {
        const badge = document.getElementById(id);
        if (!badge) return;
        if (text) {
            badge.style.display = "inline-flex";
            badge.querySelector("span").textContent = text;
        } else {
            badge.style.display = "none";
        }
    });
}

/* RESET FILTERS
   Clears all inputs and restores default data*/
function resetFilters() {
    document.getElementById("startDate").value = "";
    document.getElementById("endDate").value = "";
    document.getElementById("quarterSelect").value = "";

    // Restore all-data charts
    updateChartsLive("all");

    // Hide badges
    updateFilterBadges("");

    showToast("Filters reset");
}

/* EXPORT MODAL */
const exportModal = document.getElementById("exportModal");
const exportModalCard = document.getElementById("exportModalCard");
let selectedFormat = "csv";

function openExportModal() {
    if (!exportModal || !exportModalCard) {
        showToast("Export is not available in this sample view");
        return;
    }

    // Read current filter values to show in summary
    const quarter = document.getElementById("quarterSelect").value;
    const startDate = document.getElementById("startDate").value;
    const endDate = document.getElementById("endDate").value;

    // Fill in summary row values
    if (startDate && endDate) {
        document.getElementById("exportSummaryDate").textContent =
            `${startDate} to ${endDate}`;
    } else if (startDate) {
        document.getElementById("exportSummaryDate").textContent =
            `From ${startDate}`;
    } else if (endDate) {
        document.getElementById("exportSummaryDate").textContent =
            `Until ${endDate}`;
    } else {
        document.getElementById("exportSummaryDate").textContent = "All dates";
    }

    document.getElementById("exportSummaryQuarter").textContent =
        QUARTER_NAMES[quarter] || "All Quarters";

    document.getElementById("exportSummaryCount").textContent =
        RECORD_COUNTS[quarter || "all"];

    // Open modal
    exportModal.style.display = "flex";
    setTimeout(() => {
        exportModal.style.opacity = "1";
        exportModalCard.classList.add("scaled");
    }, 10);
}

function closeExportModal() {
    if (!exportModal || !exportModalCard) {
        return;
    }

    exportModal.style.opacity = "0";
    exportModalCard.classList.remove("scaled");
    setTimeout(() => {
        exportModal.style.display = "none";
    }, 300);
}

/* Clicking a format button selects it */
function selectExportFormat(btn) {
    document
        .querySelectorAll(".export-format-btn")
        .forEach((b) => b.classList.remove("selected"));
    btn.classList.add("selected");
    selectedFormat = btn.dataset.format;
}

/* Download / confirm export */
function confirmExport() {
    const quarter = document.getElementById("quarterSelect").value;
    const label = QUARTER_NAMES[quarter] || "All Quarters";
    const format = selectedFormat.toUpperCase();

    // Show loading state on the button
    const confirmBtn = document.getElementById("exportConfirmBtn");
    if (!confirmBtn) {
        showToast("Export is not available in this sample view");
        return;
    }

    confirmBtn.disabled = true;
    confirmBtn.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Exporting...';

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
    const toast = document.getElementById("toast");
    const toastMessage = document.getElementById("toastMessage");
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add("visible");
    setTimeout(() => toast.classList.remove("visible"), 2500);
}
