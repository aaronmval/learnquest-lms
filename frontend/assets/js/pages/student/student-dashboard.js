/* STUDENT DASHBOARD — live analytics from /student/analytics.
   Mastery values are BKT estimates (replayed from the student's quiz
   responses); quiz scores are raw percentages. The charts only visualize —
   the database/BKT records are the source of truth. */

/* Quarter display names */
const QUARTER_NAMES = {
    "": "All Quarters",
    1: "1st Quarter",
    2: "2nd Quarter",
    3: "3rd Quarter",
    4: "4th Quarter",
};

const EMPTY_MESSAGES = {
    noClasses: "Join a class to see your analytics.",
    noCompetencies: "Your teachers haven't set up competencies for your subjects yet.",
    noActivity: "No quiz activity for these filters yet.",
    loadFailed: "Could not load your analytics. Please try again.",
};

/* STATE */
let dashboardData = null;
let selectedClassId = null;
let loadController = null;

/* CHART INSTANCES */
let radarChart = null;
let performanceChart = null;
let masteryChart = null;
let subjectRadarChart = null;

/* SHARED CHART SETTINGS */
const axisColor = "rgba(241, 245, 249, 0.65)";
const gridColor = "rgba(148, 163, 184, 0.15)";

Chart.defaults.color = axisColor;
Chart.defaults.font.family = "'Inter', sans-serif";

const legendOptions = {
    position: "top",
    align: "start",
    labels: { boxWidth: 14, color: "#e2e8f0" },
};

const radarScale = {
    min: 0,
    max: 100,
    ticks: { stepSize: 20, color: axisColor, backdropColor: "transparent" },
    grid: { color: gridColor },
    angleLines: { color: gridColor },
    pointLabels: { color: "#e2e8f0", font: { size: 11 } },
};

const percentAxis = {
    min: 0,
    max: 100,
    ticks: { color: axisColor, stepSize: 20, callback: (v) => `${v}%` },
    grid: { color: gridColor },
};

/* INIT */
document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("quarterSelect")?.addEventListener("change", onFilterChange);
    document.getElementById("startDate")?.addEventListener("change", onFilterChange);
    document.getElementById("endDate")?.addEventListener("change", onFilterChange);
    document.getElementById("resetBtn")?.addEventListener("click", resetFilters);
    document.getElementById("exportBtn")?.addEventListener("click", exportCsv);

    updateFilterBadges("");
    loadDashboard();
});

/* DATA LOADING */
function currentFilters() {
    return {
        quarter: document.getElementById("quarterSelect")?.value || "",
        start: document.getElementById("startDate")?.value || "",
        end: document.getElementById("endDate")?.value || "",
    };
}

async function loadDashboard() {
    const { quarter, start, end } = currentFilters();

    // An end date before the start date can't match anything — the server
    // rejects it too — so wait for the student to finish picking.
    if (start && end && end < start) {
        showToast("End date must be on or after the start date");
        return;
    }

    const params = new URLSearchParams();
    if (quarter) params.set("quarter", quarter);
    if (start) params.set("start", start);
    if (end) params.set("end", end);

    // Drop any in-flight request so a slow earlier response can't overwrite a newer one.
    loadController?.abort();
    loadController = new AbortController();

    try {
        const res = await fetch(`/student/analytics?${params.toString()}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            signal: loadController.signal,
        });
        if (!res.ok) throw new Error("failed to load analytics");

        dashboardData = await res.json();
        renderCharts();
    } catch (e) {
        if (e.name === "AbortError") return;
        dashboardData = null;
        destroyCharts();
        ["radarChart", "subjectRadarChart", "performanceChart", "masteryChart"].forEach((id) =>
            setEmptyState(id, EMPTY_MESSAGES.loadFailed)
        );
    }
}

/* RENDERING */
function radarSubjects() {
    return (dashboardData?.subjects || []).filter((s) => s.mastery !== null);
}

function destroyCharts() {
    [radarChart, performanceChart, masteryChart, subjectRadarChart].forEach((chart) => chart?.destroy());
    radarChart = performanceChart = masteryChart = subjectRadarChart = null;
}

/* Overlay a message on a chart card (or clear it with message = null). */
function setEmptyState(canvasId, message) {
    const canvas = document.getElementById(canvasId);
    const wrap = canvas?.closest(".chart-canvas-wrap");
    if (!wrap) return;

    let overlay = wrap.querySelector(".chart-empty");
    if (!message) {
        overlay?.remove();
        canvas.style.visibility = "";
        return;
    }

    if (!overlay) {
        overlay = document.createElement("div");
        overlay.className = "chart-empty";
        wrap.appendChild(overlay);
    }
    overlay.innerHTML = '<i class="fas fa-chart-simple"></i><p></p>';
    overlay.querySelector("p").textContent = message;
    canvas.style.visibility = "hidden";
}

function renderCharts() {
    destroyCharts();

    const subjects = radarSubjects();
    if (!subjects.some((s) => s.class_id === selectedClassId)) {
        selectedClassId = subjects[0]?.class_id ?? null;
    }

    renderSubjectRadar(subjects);
    renderCompetencyRadar();
    renderQuizScores();
    renderMasteryTrend();
}

function subjectsEmptyMessage() {
    return (dashboardData?.subjects || []).length ? EMPTY_MESSAGES.noCompetencies : EMPTY_MESSAGES.noClasses;
}

function renderSubjectRadar(subjects) {
    const canvas = document.getElementById("radarChart");
    if (!canvas) return;

    if (!subjects.length) {
        setEmptyState("radarChart", subjectsEmptyMessage());
        return;
    }
    setEmptyState("radarChart", null);

    radarChart = new Chart(canvas, {
        type: "radar",
        data: {
            labels: subjects.map((s) => s.label),
            datasets: [
                {
                    label: "BKT Mastery Level (%)",
                    data: subjects.map((s) => s.mastery),
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
                if (!activeElements.length) return;
                const subject = subjects[activeElements[0].index];
                if (subject) {
                    selectedClassId = subject.class_id;
                    renderCompetencyRadar();
                }
            },
            onHover: (event, activeElements) => {
                if (event?.native?.target) {
                    event.native.target.style.cursor = activeElements.length ? "pointer" : "default";
                }
            },
            plugins: {
                legend: legendOptions,
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const s = subjects[ctx.dataIndex];
                            const note = s.assessed ? "" : " (not yet assessed)";
                            return ` ${ctx.formattedValue}% mastery${note}`;
                        },
                    },
                },
            },
            scales: { r: radarScale },
        },
    });
}

function renderCompetencyRadar() {
    const canvas = document.getElementById("subjectRadarChart");
    const subtitle = document.getElementById("subjectRadarTitle");
    if (!canvas) return;

    subjectRadarChart?.destroy();
    subjectRadarChart = null;

    const subject = radarSubjects().find((s) => s.class_id === selectedClassId);

    if (!subject) {
        if (subtitle) subtitle.textContent = "Select a subject from the radar chart";
        setEmptyState("subjectRadarChart", subjectsEmptyMessage());
        return;
    }

    if (subtitle) subtitle.textContent = `${subject.label} mastery by competency`;
    setEmptyState("subjectRadarChart", null);

    subjectRadarChart = new Chart(canvas, {
        type: "radar",
        data: {
            labels: subject.competencies.map((c) => c.name),
            datasets: [
                {
                    label: `${subject.label} Competency Mastery (%)`,
                    data: subject.competencies.map((c) => c.mastery),
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
                legend: { ...legendOptions, labels: { ...legendOptions.labels, boxWidth: 12 } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const c = subject.competencies[ctx.dataIndex];
                            const answered = c.observations === 1 ? "1 answer" : `${c.observations} answers`;
                            return ` ${ctx.formattedValue}% (${c.level}, ${answered})`;
                        },
                    },
                },
            },
            scales: { r: radarScale },
        },
    });
}

function renderQuizScores() {
    const canvas = document.getElementById("performanceChart");
    if (!canvas) return;

    const points = dashboardData?.quiz_scores || [];
    if (!points.length) {
        setEmptyState("performanceChart", EMPTY_MESSAGES.noActivity);
        return;
    }
    setEmptyState("performanceChart", null);

    performanceChart = new Chart(canvas, {
        type: "line",
        data: {
            labels: points.map((p) => p.label),
            datasets: [
                {
                    label: "Average Quiz Score (%)",
                    data: points.map((p) => p.average),
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
                legend: legendOptions,
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const n = points[ctx.dataIndex].attempts;
                            return ` ${ctx.formattedValue}% across ${n} attempt${n === 1 ? "" : "s"}`;
                        },
                    },
                },
            },
            scales: {
                x: { ticks: { color: axisColor }, grid: { display: false } },
                y: percentAxis,
            },
        },
    });
}

function renderMasteryTrend() {
    const canvas = document.getElementById("masteryChart");
    if (!canvas) return;

    const points = dashboardData?.mastery_trend || [];
    if (!points.length) {
        setEmptyState("masteryChart", EMPTY_MESSAGES.noActivity);
        return;
    }
    setEmptyState("masteryChart", null);

    masteryChart = new Chart(canvas, {
        type: "line",
        data: {
            labels: points.map((p) => p.label),
            datasets: [
                {
                    label: "BKT Mastery Level (%)",
                    data: points.map((p) => p.mastery),
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
                legend: legendOptions,
                tooltip: {
                    callbacks: { label: (ctx) => ` ${ctx.formattedValue}% average mastery at month end` },
                },
            },
            scales: {
                x: { ticks: { color: axisColor }, grid: { display: false } },
                y: percentAxis,
            },
        },
    });
}

/* FILTERS */
function onFilterChange() {
    const { quarter, start, end } = currentFilters();
    updateFilterBadges(buildBadgeText(quarter, start, end));
    loadDashboard();
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
    ["radarBadge", "subjectBadge", "performanceBadge", "masteryBadge"].forEach((id) => {
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

function resetFilters() {
    document.getElementById("startDate").value = "";
    document.getElementById("endDate").value = "";
    document.getElementById("quarterSelect").value = "";

    updateFilterBadges("");
    loadDashboard();
    showToast("Filters reset");
}

/* EXPORT — CSV of exactly what the charts are showing */
function csvCell(value) {
    const text = value === null || value === undefined ? "" : String(value);
    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function exportCsv() {
    if (!dashboardData) {
        showToast("Analytics are still loading");
        return;
    }

    const { quarter, start, end } = currentFilters();
    const rows = [];
    const add = (...cells) => rows.push(cells.map(csvCell).join(","));

    add("LearnQuest Student Dashboard Export");
    add("Quarter", QUARTER_NAMES[quarter] || "All Quarters");
    add("Date range", start || "Any", end || "Any");
    add("Quiz responses counted", dashboardData.record_count);
    add();

    add("Subject mastery (BKT)");
    add("Subject", "Class", "Section", "Mastery %", "Assessed");
    dashboardData.subjects.forEach((s) =>
        add(s.label, s.class_name, s.section, s.mastery, s.assessed ? "Yes" : "No")
    );
    add();

    add("Competency mastery (BKT)");
    add("Subject", "Competency", "Mastery %", "Level", "Answers");
    dashboardData.subjects.forEach((s) =>
        s.competencies.forEach((c) => add(s.label, c.name, c.mastery, c.level, c.observations))
    );
    add();

    add("Average quiz scores (raw %)");
    add("Month", "Average %", "Attempts");
    dashboardData.quiz_scores.forEach((p) => add(p.label, p.average, p.attempts));
    add();

    add("Mastery over time (BKT, month end)");
    add("Month", "Mastery %");
    dashboardData.mastery_trend.forEach((p) => add(p.label, p.mastery));

    const blob = new Blob([rows.join("\r\n")], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `learnquest-dashboard-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    showToast("Dashboard exported as CSV");
}

/* TOAST */
function showToast(message) {
    if (typeof window.parent?.showToast === "function" && window.parent !== window) {
        window.parent.showToast(message);
        return;
    }

    const toast = document.getElementById("toast");
    const toastMessage = document.getElementById("toastMessage");
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add("visible");
    setTimeout(() => toast.classList.remove("visible"), 2500);
}
