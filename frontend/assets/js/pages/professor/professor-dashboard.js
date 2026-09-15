/* DATA */
const SECTION_NAMES = {
    "": "All Sections",
    amethyst: "STEM - Amethyst",
    sapphire: "STEM - Sapphire",
    topaz: "STEM - Topaz",
};

const QUARTER_NAMES = {
    "": "All Quarters",
    1: "1st Quarter",
    2: "2nd Quarter",
    3: "3rd Quarter",
    4: "4th Quarter",
};

const RECORD_COUNTS = {
    "all|all": "All Records (124)",
    "amethyst|all": "42 Records",
    "sapphire|all": "39 Records",
    "topaz|all": "43 Records",
    "all|1": "31 Records",
    "all|2": "30 Records",
    "all|3": "32 Records",
    "all|4": "31 Records",
};

const SECTION_DATA = {
    amethyst: {
        performance: {
            all: {
                labels: [
                    "Week 1",
                    "Week 2",
                    "Week 3",
                    "Week 4",
                    "Week 5",
                    "Week 6",
                ],
                data: [70, 73, 77, 80, 82, 84],
            },
            1: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [68, 71, 74, 76],
            },
            2: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [75, 77, 79, 81],
            },
            3: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [80, 82, 84, 86],
            },
            4: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [83, 85, 87, 88],
            },
        },
        mastery: {
            all: {
                "Atomic Structure": 90,
                "Chemical Bonding": 85,
                Stoichiometry: 80,
                "Acids & Bases": 75,
            },
            1: {
                "Atomic Structure": 82,
                "Chemical Bonding": 76,
                Stoichiometry: 70,
                "Acids & Bases": 65,
            },
            2: {
                "Atomic Structure": 86,
                "Chemical Bonding": 80,
                Stoichiometry: 74,
                "Acids & Bases": 70,
            },
            3: {
                "Atomic Structure": 91,
                "Chemical Bonding": 86,
                Stoichiometry: 82,
                "Acids & Bases": 78,
            },
            4: {
                "Atomic Structure": 94,
                "Chemical Bonding": 90,
                Stoichiometry: 86,
                "Acids & Bases": 82,
            },
        },
    },
    sapphire: {
        performance: {
            all: {
                labels: [
                    "Week 1",
                    "Week 2",
                    "Week 3",
                    "Week 4",
                    "Week 5",
                    "Week 6",
                ],
                data: [65, 68, 71, 74, 76, 78],
            },
            1: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [62, 65, 67, 69],
            },
            2: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [69, 71, 73, 75],
            },
            3: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [74, 76, 78, 80],
            },
            4: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [77, 79, 81, 83],
            },
        },
        mastery: {
            all: {
                "Atomic Structure": 84,
                "Chemical Bonding": 78,
                Stoichiometry: 70,
                "Acids & Bases": 66,
            },
            1: {
                "Atomic Structure": 76,
                "Chemical Bonding": 68,
                Stoichiometry: 60,
                "Acids & Bases": 55,
            },
            2: {
                "Atomic Structure": 80,
                "Chemical Bonding": 73,
                Stoichiometry: 64,
                "Acids & Bases": 60,
            },
            3: {
                "Atomic Structure": 86,
                "Chemical Bonding": 80,
                Stoichiometry: 73,
                "Acids & Bases": 69,
            },
            4: {
                "Atomic Structure": 90,
                "Chemical Bonding": 84,
                Stoichiometry: 78,
                "Acids & Bases": 74,
            },
        },
    },
    topaz: {
        performance: {
            all: {
                labels: [
                    "Week 1",
                    "Week 2",
                    "Week 3",
                    "Week 4",
                    "Week 5",
                    "Week 6",
                ],
                data: [78, 81, 84, 87, 89, 91],
            },
            1: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [75, 78, 80, 82],
            },
            2: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [82, 84, 86, 88],
            },
            3: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [86, 88, 89, 91],
            },
            4: {
                labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                data: [89, 90, 92, 93],
            },
        },
        mastery: {
            all: {
                "Atomic Structure": 93,
                "Chemical Bonding": 90,
                Stoichiometry: 86,
                "Acids & Bases": 84,
            },
            1: {
                "Atomic Structure": 88,
                "Chemical Bonding": 84,
                Stoichiometry: 78,
                "Acids & Bases": 75,
            },
            2: {
                "Atomic Structure": 91,
                "Chemical Bonding": 87,
                Stoichiometry: 82,
                "Acids & Bases": 79,
            },
            3: {
                "Atomic Structure": 94,
                "Chemical Bonding": 91,
                Stoichiometry: 88,
                "Acids & Bases": 86,
            },
            4: {
                "Atomic Structure": 96,
                "Chemical Bonding": 94,
                Stoichiometry: 91,
                "Acids & Bases": 89,
            },
        },
    },
};

const STUDENT_BASE = {
    amethyst: [
        {
            id: "amy-1",
            name: "Andrea Ramos",
            quizScores: [74, 78, 82, 85, 87, 89],
            lessonMastery: {
                "Atomic Structure": 92,
                "Chemical Bonding": 88,
                Stoichiometry: 81,
                "Acids & Bases": 76,
            },
        },
        {
            id: "amy-2",
            name: "Jared Cortez",
            quizScores: [69, 73, 76, 79, 82, 84],
            lessonMastery: {
                "Atomic Structure": 85,
                "Chemical Bonding": 80,
                Stoichiometry: 74,
                "Acids & Bases": 70,
            },
        },
        {
            id: "amy-3",
            name: "Nica Velasco",
            quizScores: [66, 70, 74, 77, 80, 82],
            lessonMastery: {
                "Atomic Structure": 82,
                "Chemical Bonding": 76,
                Stoichiometry: 70,
                "Acids & Bases": 66,
            },
        },
    ],
    sapphire: [
        {
            id: "sap-1",
            name: "Kyle Medina",
            quizScores: [62, 66, 69, 73, 75, 77],
            lessonMastery: {
                "Atomic Structure": 79,
                "Chemical Bonding": 72,
                Stoichiometry: 65,
                "Acids & Bases": 61,
            },
        },
        {
            id: "sap-2",
            name: "Bea Santos",
            quizScores: [64, 67, 71, 74, 76, 79],
            lessonMastery: {
                "Atomic Structure": 81,
                "Chemical Bonding": 75,
                Stoichiometry: 68,
                "Acids & Bases": 64,
            },
        },
        {
            id: "sap-3",
            name: "Miko Lim",
            quizScores: [61, 64, 67, 70, 73, 76],
            lessonMastery: {
                "Atomic Structure": 77,
                "Chemical Bonding": 70,
                Stoichiometry: 63,
                "Acids & Bases": 58,
            },
        },
    ],
    topaz: [
        {
            id: "top-1",
            name: "Isaac Mendez",
            quizScores: [80, 84, 87, 90, 92, 94],
            lessonMastery: {
                "Atomic Structure": 95,
                "Chemical Bonding": 92,
                Stoichiometry: 89,
                "Acids & Bases": 86,
            },
        },
        {
            id: "top-2",
            name: "Leah Navarro",
            quizScores: [77, 81, 85, 88, 90, 92],
            lessonMastery: {
                "Atomic Structure": 92,
                "Chemical Bonding": 88,
                Stoichiometry: 84,
                "Acids & Bases": 81,
            },
        },
        {
            id: "top-3",
            name: "Noel Cruz",
            quizScores: [75, 79, 82, 85, 88, 90],
            lessonMastery: {
                "Atomic Structure": 89,
                "Chemical Bonding": 85,
                Stoichiometry: 81,
                "Acids & Bases": 78,
            },
        },
    ],
};

const QUARTER_ADJUST = { all: 0, 1: -5, 2: -2, 3: 2, 4: 4 };

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function average(values) {
    if (!values.length) return 0;
    return Math.round(
        values.reduce((sum, value) => sum + value, 0) / values.length,
    );
}

function getSectionPerformance(sectionKey, quarterKey) {
    const section = SECTION_DATA[sectionKey] || SECTION_DATA.all;
    const q = quarterKey || "all";
    return section.performance[q] || section.performance.all;
}

function getSectionMastery(sectionKey, quarterKey) {
    const section = SECTION_DATA[sectionKey] || SECTION_DATA.all;
    const q = quarterKey || "all";
    return section.mastery[q] || section.mastery.all;
}

function buildAllSectionsData() {
    const sectionKeys = ["amethyst", "sapphire", "topaz"];
    const quarterKeys = ["all", "1", "2", "3", "4"];

    const performance = {};
    const mastery = {};

    quarterKeys.forEach((q) => {
        const performanceSets = sectionKeys.map(
            (key) => SECTION_DATA[key].performance[q],
        );
        const labels = performanceSets[0].labels;
        const data = performanceSets[0].data.map((_, index) => {
            return average(performanceSets.map((set) => set.data[index]));
        });
        performance[q] = { labels, data };

        const masterySets = sectionKeys.map(
            (key) => SECTION_DATA[key].mastery[q],
        );
        const topics = Object.keys(masterySets[0]);
        const masteryAverage = {};
        topics.forEach((topic) => {
            masteryAverage[topic] = average(
                masterySets.map((set) => set[topic]),
            );
        });
        mastery[q] = masteryAverage;
    });

    return { performance, mastery };
}

SECTION_DATA.all = buildAllSectionsData();

function deriveRadarData(masteryObj) {
    const labels = Object.keys(masteryObj || {});
    const data = labels.map((topic) => masteryObj[topic]);
    return { labels, data };
}

function deriveStrengthWeakness(masteryObj) {
    const labels = Object.keys(masteryObj || {});
    const strengths = labels.map((topic) =>
        masteryObj[topic] >= 80 ? masteryObj[topic] : 0,
    );
    const weaknesses = labels.map((topic) =>
        masteryObj[topic] < 80 ? 100 - masteryObj[topic] : 0,
    );

    const sorted = labels
        .map((topic) => ({ topic, value: masteryObj[topic] }))
        .sort((a, b) => b.value - a.value);

    return {
        labels,
        strengths,
        weaknesses,
        top: sorted.slice(0, 2),
        bottom: sorted.slice(-2).reverse(),
    };
}

function getFilteredStudents(sectionKey, quarterKey) {
    const keys =
        sectionKey === "all" ? ["amethyst", "sapphire", "topaz"] : [sectionKey];
    const delta = QUARTER_ADJUST[quarterKey || "all"] || 0;

    const students = keys.flatMap((key) => {
        const sectionName = SECTION_NAMES[key];
        return (STUDENT_BASE[key] || []).map((student) => {
            const lessonMastery = {};
            Object.keys(student.lessonMastery).forEach((topic) => {
                lessonMastery[topic] = clamp(
                    student.lessonMastery[topic] + delta,
                    45,
                    99,
                );
            });

            const quizScores = (
                quarterKey && quarterKey !== "all"
                    ? student.quizScores.slice(0, 4)
                    : student.quizScores
            ).map((score) => clamp(score + delta, 45, 100));

            const masteryScore = average(Object.values(lessonMastery));
            const strength = Object.entries(lessonMastery).sort(
                (a, b) => b[1] - a[1],
            )[0][0];
            const weakness = Object.entries(lessonMastery).sort(
                (a, b) => a[1] - b[1],
            )[0][0];

            return {
                id: student.id + "-" + (quarterKey || "all") + "-" + key,
                name: student.name,
                section: sectionName,
                masteryScore,
                lessonMastery,
                quizScores,
                strength,
                weakness,
            };
        });
    });

    return students.sort((a, b) => b.masteryScore - a.masteryScore);
}

function getFilteredData(sectionKey, quarterKey) {
    const key = sectionKey || "all";
    const q = quarterKey || "all";
    const mastery = getSectionMastery(key, q);

    return {
        performance: getSectionPerformance(key, q),
        mastery,
        radar: deriveRadarData(mastery),
        students: getFilteredStudents(key, q),
        analysis: deriveStrengthWeakness(mastery),
    };
}

let radarChart = null;
let performanceChart = null;
let strengthWeaknessChart = null;
let studentStatsChart = null;

let currentStudents = [];

const axisColor = "rgba(241, 245, 249, 0.65)";
const gridColor = "rgba(148, 163, 184, 0.15)";

Chart.defaults.color = axisColor;
Chart.defaults.font.family = "'Inter', sans-serif";

function bindById(id, event, handler) {
    const node = document.getElementById(id);
    if (node) node.addEventListener(event, handler);
}

function initDashboard() {
    refreshDashboard();

    bindById("sectionSelect", "change", onFilterChange);
    bindById("quarterSelect", "change", onFilterChange);
    bindById("startDate", "change", onFilterChange);
    bindById("endDate", "change", onFilterChange);

    bindById("resetBtn", "click", resetFilters);
    bindById("exportBtn", "click", openExportModal);
    bindById("exportCloseBtn", "click", closeExportModal);

    const exportModalNode = document.getElementById("exportModal");
    if (exportModalNode) {
        exportModalNode.addEventListener("click", (e) => {
            if (e.target === exportModalNode) closeExportModal();
        });
    }

    document.querySelectorAll(".export-format-btn").forEach((btn) => {
        btn.addEventListener("click", () => selectExportFormat(btn));
    });

    bindById("exportConfirmBtn", "click", confirmExport);

    wireStudentStatsModal();
    wireMyClassesDropdown();

    setTimeout(() => {
        const loader = document.getElementById("pageLoader");
        if (loader) loader.classList.add("hidden");
    }, 700);
}

document.addEventListener("DOMContentLoaded", initDashboard);

function refreshDashboard() {
    const sectionKey = document.getElementById("sectionSelect")?.value || "all";
    const quarterKey = document.getElementById("quarterSelect")?.value || "all";

    const dashboard = getFilteredData(sectionKey, quarterKey);
    currentStudents = dashboard.students;

    buildOrUpdateRadarChart(dashboard.radar);
    buildOrUpdatePerformanceChart(dashboard.performance);
    buildOrUpdateStrengthWeaknessChart(dashboard.analysis);

    renderMasteryBoxes(dashboard.mastery);
    renderStudentMasteryList(dashboard.students);
    renderAnalysisNotes(dashboard.analysis);
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

function renderMasteryBoxes(masteryObj) {
    const container = document.getElementById("masteryGrid");
    if (!container) return;

    const topics = Object.keys(masteryObj || {});
    if (!topics.length) {
        container.innerHTML =
            '<div class="insights-empty">No mastery data available.</div>';
        return;
    }

    container.innerHTML = topics
        .map((topic) => {
            return `
                <div class="mastery-box">
                    <span class="mastery-box-label">${escapeHtml(topic)}</span>
                    <span class="mastery-box-value">${masteryObj[topic]}%</span>
                </div>
            `;
        })
        .join("");
}

function renderStudentMasteryList(students) {
    const container = document.getElementById("studentMasteryList");
    if (!container) return;

    if (!students.length) {
        container.innerHTML =
            '<div class="insights-empty">No students found for this filter.</div>';
        return;
    }

    container.innerHTML = students
        .map((student, index) => {
            return `
                <button class="student-row" type="button" data-student-id="${escapeHtml(student.id)}">
                    <span class="student-rank">#${index + 1}</span>
                    <span class="student-name-wrap">
                        <span class="student-name">${escapeHtml(student.name)}</span>
                        <span class="student-section">${escapeHtml(student.section)}</span>
                    </span>
                    <span class="student-mastery-value">${student.masteryScore}%</span>
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

function renderAnalysisNotes(analysis) {
    const container = document.getElementById("analysisNotes");
    if (!container) return;

    const strengths = analysis.top
        .map((item) => `${item.topic} (${item.value}%)`)
        .join(", ");
    const weaknesses = analysis.bottom
        .map((item) => `${item.topic} (${item.value}%)`)
        .join(", ");

    container.innerHTML = `
        <div class="analysis-note positive">
            <i class="fas fa-circle-check"></i>
            <span><strong>Strongest lessons:</strong> ${escapeHtml(strengths)}</span>
        </div>
        <div class="analysis-note warning">
            <i class="fas fa-triangle-exclamation"></i>
            <span><strong>Focus lessons:</strong> ${escapeHtml(weaknesses)}</span>
        </div>
    `;
}

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
    document.getElementById("studentStatsSection").textContent =
        student.section;
    document.getElementById("studentStatsMastery").textContent =
        student.masteryScore + "%";
    document.getElementById("studentStatsQuizAvg").textContent =
        average(student.quizScores) + "%";
    document.getElementById("studentStatsStrength").textContent =
        student.strength;
    document.getElementById("studentStatsWeakness").textContent =
        student.weakness;

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

function onFilterChange() {
    const section = document.getElementById("sectionSelect")?.value;
    const quarter = document.getElementById("quarterSelect")?.value;
    const startDate = document.getElementById("startDate")?.value;
    const endDate = document.getElementById("endDate")?.value;

    refreshDashboard();

    const badgeText = buildBadgeText(section, quarter, startDate, endDate);
    updateFilterBadges(badgeText);
}

function buildBadgeText(section, quarter, startDate, endDate) {
    const parts = [];
    if (section) parts.push(SECTION_NAMES[section]);
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
    const section = document.getElementById("sectionSelect");
    const startDate = document.getElementById("startDate");
    const endDate = document.getElementById("endDate");
    const quarter = document.getElementById("quarterSelect");

    if (section) section.value = "";
    if (startDate) startDate.value = "";
    if (endDate) endDate.value = "";
    if (quarter) quarter.value = "";

    refreshDashboard();
    updateFilterBadges("");
    showToast("Filters reset");
}

const exportModal = document.getElementById("exportModal");
const exportModalCard = document.getElementById("exportModalCard");
let selectedFormat = "csv";

function openExportModal() {
    if (!exportModal || !exportModalCard) {
        showToast("Export modal is not available in this page view yet.");
        return;
    }

    const section = document.getElementById("sectionSelect")?.value;
    const quarter = document.getElementById("quarterSelect")?.value;
    const startDate = document.getElementById("startDate")?.value;
    const endDate = document.getElementById("endDate")?.value;

    const dateSummary = document.getElementById("exportSummaryDate");
    const quarterSummary = document.getElementById("exportSummaryQuarter");
    const countSummary = document.getElementById("exportSummaryCount");

    if (dateSummary) {
        if (startDate && endDate)
            dateSummary.textContent = startDate + " to " + endDate;
        else if (startDate) dateSummary.textContent = "From " + startDate;
        else if (endDate) dateSummary.textContent = "Until " + endDate;
        else dateSummary.textContent = "All dates";
    }

    if (quarterSummary) {
        quarterSummary.textContent =
            (section ? SECTION_NAMES[section] + " · " : "") +
            (QUARTER_NAMES[quarter] || "All Quarters");
    }

    if (countSummary) {
        const countKey = (section || "all") + "|" + (quarter || "all");
        countSummary.textContent =
            RECORD_COUNTS[countKey] || RECORD_COUNTS["all|all"];
    }

    exportModal.style.display = "flex";
    setTimeout(() => {
        exportModal.style.opacity = "1";
        exportModalCard.classList.add("scaled");
    }, 10);
}

function closeExportModal() {
    if (!exportModal || !exportModalCard) return;
    exportModal.style.opacity = "0";
    exportModalCard.classList.remove("scaled");
    setTimeout(() => {
        exportModal.style.display = "none";
    }, 300);
}

function selectExportFormat(btn) {
    document
        .querySelectorAll(".export-format-btn")
        .forEach((b) => b.classList.remove("selected"));
    btn.classList.add("selected");
    selectedFormat = btn.dataset.format;
}

function confirmExport() {
    const section = document.getElementById("sectionSelect")?.value;
    const quarter = document.getElementById("quarterSelect")?.value;

    const label =
        (section ? SECTION_NAMES[section] + " · " : "") +
        (QUARTER_NAMES[quarter] || "All Quarters");
    const format = selectedFormat.toUpperCase();

    const confirmBtn = document.getElementById("exportConfirmBtn");
    if (!confirmBtn) {
        showToast("Exported " + label + " as " + format + " successfully!");
        return;
    }

    confirmBtn.disabled = true;
    confirmBtn.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Exporting...';

    setTimeout(() => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-download"></i> Download';
        closeExportModal();
        showToast("Exported " + label + " as " + format + " successfully!");
    }, 1300);
}

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

function showToast(message) {
    const toast = document.getElementById("toast");
    const toastMessage = document.getElementById("toastMessage");
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add("visible");
    setTimeout(() => toast.classList.remove("visible"), 2500);
}
