/* PROFESSOR DASHBOARD — live analytics from /professor/analytics.
   Mastery values are class-level BKT estimates (replayed from students'
   quiz responses); quiz scores are raw percentages. The AI analysis notes
   come from /professor/analytics/insights (Llama phrasing BKT data, with a
   rule-based fallback). The charts only visualize — the database/BKT
   records are the source of truth. */

const QUARTER_NAMES = {
    "": "All Quarters",
    1: "1st Quarter",
    2: "2nd Quarter",
    3: "3rd Quarter",
    4: "4th Quarter",
};

const EMPTY_MESSAGES = {
    noSections: "Create a section and enroll students to see analytics.",
    noCompetencies: "Set up competencies for your subjects to track mastery.",
    noActivity: "No quiz activity for these filters yet.",
    loadFailed: "Could not load dashboard analytics. Please try again.",
};

/* STATE */
let dashboardData = null;
let currentStudents = [];
let allSections = [];
let filterOptionsLoaded = false;
let loadController = null;
let insightController = null;

let radarChart = null;
let performanceChart = null;
let strengthWeaknessChart = null;
let studentStatsChart = null;

const axisColor = "rgba(241, 245, 249, 0.65)";
const gridColor = "rgba(148, 163, 184, 0.15)";

Chart.defaults.color = axisColor;
Chart.defaults.font.family = "'Inter', sans-serif";

function bindById(id, event, handler) {
    const node = document.getElementById(id);
    if (node) node.addEventListener(event, handler);
}

function initDashboard() {
    bindById("subjectSelect", "change", onSubjectChange);
    bindById("sectionSelect", "change", onFilterChange);
    bindById("quarterSelect", "change", onFilterChange);
    bindById("startDate", "change", onFilterChange);
    bindById("endDate", "change", onFilterChange);

    bindById("resetBtn", "click", resetFilters);
    bindById("exportBtn", "click", exportCsv);

    wireStudentStatsModal();
    wireMyClassesDropdown();

    updateFilterBadges("");
    // The first-visit tour waits for the charts so it can point at them.
    const loaded = loadDashboard();
    window.LQPageTour?.init({ key: "tour_seen_dashboard", steps: dashboardTourSteps, ready: loaded });

    setTimeout(() => {
        const loader = document.getElementById("pageLoader");
        if (loader) loader.classList.add("hidden");
    }, 700);
}

// Nothing is fetched or drawn until the privacy cover is dismissed.
if (window.LQDashboardLock) {
    window.LQDashboardLock.whenUnlocked(initDashboard);
} else {
    document.addEventListener("DOMContentLoaded", initDashboard);
}

/* GUIDED TOUR — common/page-tour.js. Started only after the privacy cover
   is dismissed, since initDashboard runs then. */
function dashboardTourSteps() {
    return [
        {
            title: "Welcome to your Dashboard",
            body: "This page shows how your classes are doing: mastery per lesson, quiz results over time, and which students may need help. Here is a quick look around.",
        },
        {
            target: ".filter-controls .filter-group",
            title: "Filter the records",
            body: "Narrow everything on the page to a date range, a subject, a section or a quarter. All the charts and lists below update together.",
        },
        {
            target: ".filter-actions",
            title: "Export or reset",
            body: "Export downloads what you are viewing as a CSV file. Reset clears the filters.",
        },
        {
            target: ".chart-card-main",
            title: "Average class mastery by lesson",
            body: "Each point is a lesson; the further out, the higher the class's mastery. Mastery is estimated by Bayesian Knowledge Tracing from every answer students give, so it is not the same as a quiz percentage. Use the − and + buttons (or Ctrl + scroll) to zoom in on crowded labels.",
        },
        {
            target: ".chart-card-performance",
            title: "Quiz scores over time",
            body: "The class's average raw quiz score over time. Use it to spot trends; a dip often shows which period needs review.",
        },
        {
            target: ".chart-card-mastery-overview",
            title: "Average mastery per lesson",
            body: "The same mastery estimates as numbers, one box per lesson, so you can compare lessons at a glance.",
        },
        {
            target: ".student-mastery-card",
            title: "Student mastery level",
            body: "Every student ranked by overall mastery. Change the sort order with the button, and click a student to see their quiz average, strongest lesson and the lesson to work on next.",
        },
        {
            target: ".analysis-card",
            title: "Strengths vs weaknesses",
            body: "Lessons where the class is strong and where it is weak, based on the mastery estimates. The notes below are written by QuestAI from those results, as suggestions for where to focus.",
        },
        {
            target: ".hero-actions",
            title: "QuestAI Coach and privacy",
            body: 'Launch QuestAI Coach to ask about these results. If the dashboard cover is on, "Lock now" hides the page again until you choose to show it.',
        },
        {
            title: "That's the Dashboard",
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}

/* DATA LOADING */
function currentFilters() {
    return {
        subjectId: document.getElementById("subjectSelect")?.value || "",
        classId: document.getElementById("sectionSelect")?.value || "",
        quarter: document.getElementById("quarterSelect")?.value || "",
        start: document.getElementById("startDate")?.value || "",
        end: document.getElementById("endDate")?.value || "",
    };
}

function filterParams() {
    const { subjectId, classId, quarter, start, end } = currentFilters();
    const params = new URLSearchParams();
    if (subjectId) params.set("subject_id", subjectId);
    if (classId) params.set("class_id", classId);
    if (quarter) params.set("quarter", quarter);
    if (start) params.set("start", start);
    if (end) params.set("end", end);
    return params;
}

async function loadDashboard() {
    const { start, end } = currentFilters();

    // An end date before the start date can't match anything — the server
    // rejects it too — so wait for the professor to finish picking.
    if (start && end && end < start) {
        showToast("End date must be on or after the start date");
        return;
    }

    const params = filterParams();

    // Drop any in-flight request so a slow earlier response can't overwrite a newer one.
    loadController?.abort();
    loadController = new AbortController();

    try {
        const res = await fetch(`/professor/analytics?${params.toString()}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            signal: loadController.signal,
        });
        if (!res.ok) throw new Error("failed to load analytics");

        dashboardData = await res.json();
        populateFilterOptions(dashboardData);
        refreshDashboard();
        loadAiAnalysis(params);
    } catch (e) {
        if (e.name === "AbortError") return;
        dashboardData = null;
        refreshDashboard(EMPTY_MESSAGES.loadFailed);
    }
}

async function loadAiAnalysis(params) {
    const container = document.getElementById("analysisNotes");
    if (!container) return;

    insightController?.abort();

    const analysis = deriveStrengthWeakness(dashboardData?.competencies || []);
    if (!analysis.assessed.length) {
        renderAnalysisNotes(analysis);
        return;
    }

    insightController = new AbortController();
    container.innerHTML = `
        <div class="analysis-note positive">
            <i class="fas fa-spinner fa-spin"></i>
            <span>Analyzing class performance…</span>
        </div>
    `;

    try {
        const res = await fetch(`/professor/analytics/insights?${params.toString()}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            signal: insightController.signal,
        });
        if (!res.ok) throw new Error("failed to load AI analysis");

        const data = await res.json();
        if (!data.insights?.length) throw new Error("no insights");
        renderAiNotes(data.insights);
    } catch (e) {
        if (e.name === "AbortError") return;
        renderAnalysisNotes(analysis);
    }
}

/* Fill the Subject and Section filters with the professor's real ones (once). */
function populateFilterOptions(data) {
    if (filterOptionsLoaded) return;
    filterOptionsLoaded = true;

    allSections = data.sections || [];

    const subjectSelect = document.getElementById("subjectSelect");
    (data.subjects || []).forEach((subject) => {
        const option = document.createElement("option");
        option.value = String(subject.id);
        option.textContent = subject.name;
        subjectSelect?.appendChild(option);
    });

    renderSectionOptions();
}

/* The Section filter only lists sections of the chosen subject. */
function renderSectionOptions() {
    const select = document.getElementById("sectionSelect");
    if (!select) return;

    const subjectId = document.getElementById("subjectSelect")?.value || "";
    const previous = select.value;
    const sections = allSections.filter((s) => !subjectId || String(s.subject_id) === subjectId);

    select.innerHTML = '<option value="">All Sections</option>';
    sections.forEach((section) => {
        const option = document.createElement("option");
        option.value = String(section.id);
        option.textContent = section.label;
        select.appendChild(option);
    });

    // Keep the chosen section only if it belongs to the chosen subject.
    select.value = sections.some((s) => String(s.id) === previous) ? previous : "";
}

function selectedLabel(selectId) {
    const select = document.getElementById(selectId);
    return select?.value ? select.selectedOptions[0]?.textContent || "" : "";
}

/* DERIVED VIEW DATA */
function deriveRadarData(competencies) {
    return {
        labels: competencies.map((c) => c.name),
        data: competencies.map((c) => c.mastery),
    };
}

function deriveStrengthWeakness(competencies) {
    const assessed = competencies.filter((c) => c.assessed_students > 0);
    const labels = assessed.map((c) => c.name);
    const strengths = assessed.map((c) => (c.level === "high" ? c.mastery : 0));
    const weaknesses = assessed.map((c) => (c.level !== "high" ? Math.round((100 - c.mastery) * 10) / 10 : 0));

    const sorted = assessed
        .map((c) => ({ topic: c.name, value: c.mastery, level: c.level }))
        .sort((a, b) => b.value - a.value);

    return {
        assessed,
        labels,
        strengths,
        weaknesses,
        top: sorted.filter((c) => c.level === "high").slice(0, 2),
        bottom: sorted.filter((c) => c.level !== "high").slice(-2).reverse(),
    };
}

function performanceData(quizScores) {
    return {
        labels: quizScores.map((p) => p.label),
        data: quizScores.map((p) => p.average),
    };
}

/* RENDERING */
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

function emptyMessage() {
    if (!dashboardData) return EMPTY_MESSAGES.loadFailed;
    if (!dashboardData.sections.length || !dashboardData.student_count) return EMPTY_MESSAGES.noSections;
    if (!dashboardData.competencies.length) return EMPTY_MESSAGES.noCompetencies;
    return EMPTY_MESSAGES.noActivity;
}

function refreshDashboard(errorMessage = null) {
    const competencies = dashboardData?.competencies || [];
    const quizScores = dashboardData?.quiz_scores || [];
    currentStudents = dashboardData?.students || [];

    const message = errorMessage || emptyMessage();
    const analysis = deriveStrengthWeakness(competencies);
    const hasCompetencies = competencies.length > 0 && currentStudents.length > 0;

    setEmptyState("radarChart", hasCompetencies ? null : message);
    buildOrUpdateRadarChart(deriveRadarData(hasCompetencies ? competencies : []));

    setEmptyState("performanceChart", quizScores.length ? null : message);
    buildOrUpdatePerformanceChart(performanceData(quizScores));

    setEmptyState("strengthWeaknessChart", analysis.labels.length ? null : message);
    buildOrUpdateStrengthWeaknessChart(analysis);

    renderMasteryBoxes(hasCompetencies ? competencies : [], message);
    renderStudentMasteryList(currentStudents, errorMessage);
    if (errorMessage) renderAnalysisNotes(analysis);
}

function buildOrUpdateRadarChart(radar) {
    const radarCtx = document.getElementById("radarChart");
    if (!radarCtx) return;

    if (!radarChart) {
        radarChart = new Chart(radarCtx, {
            type: "radar",
            data: {
                labels: radar.labels,
                datasets: [
                    {
                        label: "Average Lesson Mastery",
                        data: radar.data,
                        backgroundColor: "rgba(34, 211, 238, 0.26)",
                        borderColor: "#22d3ee",
                        borderWidth: 2,
                        pointBackgroundColor: "#22d3ee",
                        pointBorderColor: "#fff",
                        pointRadius: 4,
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
                        pointLabels: { color: "#e2e8f0", font: { size: 12 } },
                    },
                },
            },
        });
    } else {
        radarChart.data.labels = radar.labels;
        radarChart.data.datasets[0].data = radar.data;
        radarChart.update();
    }
}

function buildOrUpdatePerformanceChart(performance) {
    const perfCtx = document.getElementById("performanceChart");
    if (!perfCtx) return;

    if (!performanceChart) {
        performanceChart = new Chart(perfCtx, {
            type: "line",
            data: {
                labels: performance.labels,
                datasets: [
                    {
                        label: "Average Quiz Score",
                        data: performance.data,
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
                        min: 0,
                        max: 100,
                        ticks: { color: axisColor, stepSize: 10 },
                        grid: { color: gridColor },
                    },
                },
            },
        });
    } else {
        performanceChart.data.labels = performance.labels;
        performanceChart.data.datasets[0].data = performance.data;
        performanceChart.update();
    }
}

function buildOrUpdateStrengthWeaknessChart(analysis) {
    const chartCtx = document.getElementById("strengthWeaknessChart");
    if (!chartCtx) return;

    if (!strengthWeaknessChart) {
        strengthWeaknessChart = new Chart(chartCtx, {
            type: "bar",
            data: {
                labels: analysis.labels,
                datasets: [
                    {
                        label: "Strength Level",
                        data: analysis.strengths,
                        backgroundColor: "rgba(34, 197, 94, 0.75)",
                        borderColor: "#22c55e",
                        borderWidth: 1,
                    },
                    {
                        label: "Improvement Priority",
                        data: analysis.weaknesses,
                        backgroundColor: "rgba(248, 113, 113, 0.72)",
                        borderColor: "#f87171",
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 450 },
                plugins: {
                    legend: {
                        position: "top",
                        align: "start",
                        labels: { boxWidth: 14, color: "#e2e8f0" },
                    },
                },
                scales: {
                    x: {
                        min: 0,
                        max: 100,
                        ticks: { color: axisColor, stepSize: 20 },
                        grid: { color: gridColor },
                    },
                    y: {
                        ticks: { color: axisColor },
                        grid: { display: false },
                    },
                },
            },
        });
    } else {
        strengthWeaknessChart.data.labels = analysis.labels;
        strengthWeaknessChart.data.datasets[0].data = analysis.strengths;
        strengthWeaknessChart.data.datasets[1].data = analysis.weaknesses;
        strengthWeaknessChart.update();
    }
}

function renderMasteryBoxes(competencies, message) {
    const container = document.getElementById("masteryGrid");
    if (!container) return;

    if (!competencies.length) {
        container.innerHTML = `<div class="insights-empty">${escapeHtml(message || "No mastery data available.")}</div>`;
        return;
    }

    container.innerHTML = competencies
        .map((c) => {
            const value = c.assessed_students > 0 ? `${c.mastery}%` : "—";
            const title = `${c.assessed_students} of ${c.students} students assessed`;
            return `
                <div class="mastery-box" title="${escapeHtml(title)}">
                    <span class="mastery-box-label">${escapeHtml(c.name)}</span>
                    <span class="mastery-box-value">${value}</span>
                </div>
            `;
        })
        .join("");
}

/* Student Mastery Level sort: "desc" = highest first, "asc" = lowest first. */
let studentSortDirection = "desc";
let lastStudentListArgs = [[], undefined];

function isStudentAssessed(student) {
    return Boolean(student.assessed) && student.masteryScore !== null;
}

/* Sorted copy for display. Students not yet assessed always go last, and
   each student's rank is their position from the top (highest = #1)
   whichever direction is shown. */
function sortStudentsForDisplay(students) {
    const ranked = [...students].sort((a, b) => {
        if (isStudentAssessed(a) !== isStudentAssessed(b)) return isStudentAssessed(a) ? -1 : 1;
        if (!isStudentAssessed(a)) return 0;
        return b.masteryScore - a.masteryScore;
    });
    const rows = ranked.map((student, index) => ({ student, rank: index + 1 }));

    if (studentSortDirection === "desc") return rows;

    const assessed = rows.filter((row) => isStudentAssessed(row.student)).reverse();
    return [...assessed, ...rows.filter((row) => !isStudentAssessed(row.student))];
}

function updateStudentSortButton() {
    const icon = document.getElementById("studentSortIcon");
    const label = document.getElementById("studentSortLabel");
    const highestFirst = studentSortDirection === "desc";
    if (icon) icon.className = `fas ${highestFirst ? "fa-arrow-down-wide-short" : "fa-arrow-up-short-wide"}`;
    if (label) label.textContent = highestFirst ? "Highest first" : "Lowest first";
}

document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("studentSortBtn")?.addEventListener("click", () => {
        studentSortDirection = studentSortDirection === "desc" ? "asc" : "desc";
        updateStudentSortButton();
        renderStudentMasteryList(...lastStudentListArgs);
    });
});

function renderStudentMasteryList(students, errorMessage) {
    lastStudentListArgs = [students, errorMessage];

    const container = document.getElementById("studentMasteryList");
    if (!container) return;

    if (!students.length) {
        const message = errorMessage || (dashboardData?.sections.length ? "No students found for this filter." : EMPTY_MESSAGES.noSections);
        container.innerHTML = `<div class="insights-empty">${escapeHtml(message)}</div>`;
        return;
    }

    container.innerHTML = sortStudentsForDisplay(students)
        .map(({ student, rank }) => {
            const value = !isStudentAssessed(student) ? "Not yet assessed" : `${student.masteryScore}%`;
            return `
                <button class="student-row" type="button" data-student-id="${escapeHtml(student.id)}">
                    <span class="student-rank">#${rank}</span>
                    <span class="student-name-wrap">
                        <span class="student-name">${escapeHtml(student.name)}</span>
                        <span class="student-section">${escapeHtml(student.section)}</span>
                    </span>
                    <span class="student-mastery-value">${value}</span>
                </button>
            `;
        })
        .join("");

    container.querySelectorAll(".student-row").forEach((row) => {
        row.addEventListener("click", () => {
            openStudentStatsModal(row.dataset.studentId);
        });
    });
}

/* Local strongest/focus notes — shown when the AI analysis is unavailable. */
function renderAnalysisNotes(analysis) {
    const container = document.getElementById("analysisNotes");
    if (!container) return;

    if (!analysis.assessed.length) {
        container.innerHTML = `<div class="insights-empty">${escapeHtml(emptyMessage())}</div>`;
        return;
    }

    const format = (items) => items.map((item) => `${item.topic} (${item.value}%)`).join(", ") || "None yet";

    container.innerHTML = `
        <div class="analysis-note positive">
            <i class="fas fa-circle-check"></i>
            <span><strong>Strongest lessons:</strong> ${escapeHtml(format(analysis.top))}</span>
        </div>
        <div class="analysis-note warning">
            <i class="fas fa-triangle-exclamation"></i>
            <span><strong>Focus lessons:</strong> ${escapeHtml(format(analysis.bottom))}</span>
        </div>
    `;
}

const AI_NOTE_STYLES = {
    strength: { cls: "positive", icon: "fa-circle-check", label: "Strength" },
    weakness: { cls: "warning", icon: "fa-triangle-exclamation", label: "Focus" },
    intervention: { cls: "warning", icon: "fa-user-clock", label: "Intervention" },
};

function renderAiNotes(insights) {
    const container = document.getElementById("analysisNotes");
    if (!container) return;

    container.innerHTML = insights
        .map((insight) => {
            const style = AI_NOTE_STYLES[insight.type] || AI_NOTE_STYLES.weakness;
            return `
                <div class="analysis-note ${style.cls}">
                    <i class="fas ${style.icon}"></i>
                    <span><strong>${style.label}:</strong> ${escapeHtml(insight.text)}</span>
                </div>
            `;
        })
        .join("");
}

/* STUDENT STATS MODAL */
function wireStudentStatsModal() {
    const closeBtn = document.getElementById("studentStatsCloseBtn");
    const modal = document.getElementById("studentStatsModal");

    if (closeBtn) closeBtn.addEventListener("click", closeStudentStatsModal);

    if (modal) {
        modal.addEventListener("click", (e) => {
            if (e.target === modal) closeStudentStatsModal();
        });
    }

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") closeStudentStatsModal();
    });
}

function openStudentStatsModal(studentId) {
    const student = currentStudents.find((item) => item.id === studentId);
    if (!student) return;

    const modal = document.getElementById("studentStatsModal");
    const card = document.getElementById("studentStatsModalCard");
    if (!modal || !card) return;

    document.getElementById("studentStatsName").textContent = student.name;
    document.getElementById("studentStatsSection").textContent = student.section;
    document.getElementById("studentStatsMastery").textContent =
        student.masteryScore === null ? "-" : student.masteryScore + "%";
    document.getElementById("studentStatsQuizAvg").textContent =
        student.quizAverage === null ? "-" : student.quizAverage + "%";
    document.getElementById("studentStatsStrength").textContent = student.strength || "-";
    document.getElementById("studentStatsWeakness").textContent = student.weakness || "-";

    buildOrUpdateStudentStatsChart(student);

    modal.style.display = "flex";
    setTimeout(() => {
        modal.style.opacity = "1";
        card.classList.add("scaled");
    }, 10);
}

function closeStudentStatsModal() {
    const modal = document.getElementById("studentStatsModal");
    const card = document.getElementById("studentStatsModalCard");
    if (!modal || !card) return;

    modal.style.opacity = "0";
    card.classList.remove("scaled");
    setTimeout(() => {
        modal.style.display = "none";
    }, 250);
}

function buildOrUpdateStudentStatsChart(student) {
    const chartNode = document.getElementById("studentStatsChart");
    if (!chartNode) return;

    const labels = Object.keys(student.lessonMastery);
    const values = labels.map((topic) => student.lessonMastery[topic]);

    if (!studentStatsChart) {
        studentStatsChart = new Chart(chartNode, {
            type: "bar",
            data: {
                labels,
                datasets: [
                    {
                        label: "Lesson Mastery",
                        data: values,
                        backgroundColor: "rgba(56, 189, 248, 0.72)",
                        borderColor: "#38bdf8",
                        borderWidth: 1,
                        borderRadius: 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                    },
                },
                scales: {
                    x: {
                        ticks: { color: "#64748b" },
                        grid: { display: false },
                    },
                    y: {
                        min: 0,
                        max: 100,
                        ticks: { color: "#64748b", stepSize: 20 },
                        grid: { color: "rgba(148, 163, 184, 0.2)" },
                    },
                },
            },
        });
    } else {
        studentStatsChart.data.labels = labels;
        studentStatsChart.data.datasets[0].data = values;
        studentStatsChart.update();
    }
}

function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value;
    return div.innerHTML;
}

/* FILTERS */
function onSubjectChange() {
    renderSectionOptions();
    onFilterChange();
}

function onFilterChange() {
    const { quarter, start, end } = currentFilters();

    loadDashboard();
    updateFilterBadges(
        buildBadgeText(selectedLabel("subjectSelect"), selectedLabel("sectionSelect"), quarter, start, end),
    );
}

function buildBadgeText(subjectLabel, sectionLabel, quarter, startDate, endDate) {
    const parts = [];
    // A section label already starts with its subject.
    if (sectionLabel) parts.push(sectionLabel);
    else if (subjectLabel) parts.push(subjectLabel);
    if (quarter) parts.push(QUARTER_NAMES[quarter]);
    if (startDate && endDate) parts.push(startDate + " to " + endDate);
    else if (startDate) parts.push("From " + startDate);
    else if (endDate) parts.push("Until " + endDate);
    return parts.join(" · ");
}

function updateFilterBadges(text) {
    const badgeIds = [
        "radarBadge",
        "performanceBadge",
        "masteryBadge",
        "studentMasteryBadge",
        "analysisBadge",
    ];
    badgeIds.forEach((id) => {
        const badge = document.getElementById(id);
        if (!badge) return;

        if (text) {
            badge.style.display = "inline-flex";
            const content = badge.querySelector("span");
            if (content) content.textContent = text;
        } else {
            badge.style.display = "none";
        }
    });
}

function resetFilters() {
    ["subjectSelect", "sectionSelect", "startDate", "endDate", "quarterSelect"].forEach((id) => {
        const node = document.getElementById(id);
        if (node) node.value = "";
    });
    renderSectionOptions();

    loadDashboard();
    updateFilterBadges("");
    showToast("Filters reset");
}

/* EXPORT */
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

    add("LearnQuest Professor Dashboard Export");
    add("Subject", selectedLabel("subjectSelect") || "All Subjects");
    add("Section", selectedLabel("sectionSelect") || "All Sections");
    add("Quarter", QUARTER_NAMES[quarter] || "All Quarters");
    add("Date range", start || "Any", end || "Any");
    add("Quiz responses counted", dashboardData.record_count);
    add();

    add("Class competency mastery (BKT)");
    add("Competency", "Average Mastery %", "Level", "Students", "Assessed Students", "Low Mastery Students");
    dashboardData.competencies.forEach((c) =>
        add(c.name, c.mastery, c.level, c.students, c.assessed_students, c.low_students)
    );
    add();

    add("Student mastery (BKT)");
    add("Student", "Section", "Overall Mastery %", "Level", "Average Quiz %", "Top Strength", "Priority Improvement");
    dashboardData.students.forEach((s) =>
        add(s.name, s.section, s.assessed ? s.masteryScore : "Not yet assessed", s.assessed ? s.level : "", s.quizAverage, s.strength, s.weakness)
    );
    add();

    add("Average class quiz scores (raw %)");
    add("Month", "Average %", "Attempts");
    dashboardData.quiz_scores.forEach((p) => add(p.label, p.average, p.attempts));

    const blob = new Blob([rows.join("\r\n")], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `learnquest-professor-dashboard-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    showToast("Dashboard exported as CSV");
}

/* SIDEBAR */
function wireMyClassesDropdown() {
    const toggleBtn = document.getElementById("myClassesToggleBtn");
    const container = document.getElementById("myClassesDropdownContainer");
    const chevron = document.getElementById("myClassesChevron");
    const sidebar = document.getElementById("sidebar");

    if (!toggleBtn || !container || !chevron) return;

    function openDropdown() {
        container.classList.add("open");
        chevron.classList.add("rotated");
        requestAnimationFrame(() => {
            container.style.maxHeight = container.scrollHeight + "px";
        });
    }

    function closeDropdown() {
        container.style.maxHeight = "0px";
        container.classList.remove("open");
        chevron.classList.remove("rotated");
    }

    toggleBtn.addEventListener("click", () => {
        if (sidebar && sidebar.classList.contains("collapsed")) {
            sidebar.classList.remove("collapsed");
            localStorage.setItem("sidebarState", "expanded");
        }

        if (container.classList.contains("open")) closeDropdown();
        else openDropdown();
    });

    requestAnimationFrame(openDropdown);
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
