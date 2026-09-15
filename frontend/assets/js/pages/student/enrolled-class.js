const CLASS_GRADIENT_PALETTE = [
    "linear-gradient(135deg, #06b6d4, #0891b2)",
    "linear-gradient(135deg, #fb923c, #ea580c)",
    "linear-gradient(135deg, #c084fc, #9333ea)",
    "linear-gradient(135deg, #4ade80, #16a34a)",
    "linear-gradient(135deg, #fbbf24, #d97706)",
];

async function loadClassInfo() {
    const classId = new URLSearchParams(window.location.search).get("id");

    if (!classId) {
        showClassNotFound();
        return;
    }

    try {
        const res = await fetch(`/student/classes/${encodeURIComponent(classId)}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });

        if (!res.ok) {
            showClassNotFound();
            return;
        }

        const data = await res.json();
        renderClassBanner(data);
    } catch (e) {
        showClassNotFound();
    }
}

function renderClassBanner(data) {
    const banner = document.getElementById("classBanner");
    if (!banner) return;

    banner.style.background =
        CLASS_GRADIENT_PALETTE[data.id % CLASS_GRADIENT_PALETTE.length];

    document.getElementById("classBannerSubject").textContent = (
        data.subject || data.name || ""
    ).toUpperCase();
    document.getElementById("classBannerSection").textContent =
        data.section || "";
    document.getElementById("classBannerTeacher").textContent =
        data.professor?.name || "";
}

function showClassNotFound() {
    const notFound = document.getElementById("classNotFound");
    const wrap = document.getElementById("classroomWrap");
    if (notFound) notFound.classList.remove("hidden");
    if (wrap) wrap.classList.add("hidden");
}

document.addEventListener("DOMContentLoaded", () => {
    loadClassInfo();
});
