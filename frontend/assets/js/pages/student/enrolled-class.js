let CLASS_ID = null;
let CLASS_TEACHER_NAME = "";
let CLASS_TEACHER_INITIALS = "";
let classPosts = [];

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
        CLASS_ID = data.id;
        CLASS_TEACHER_NAME = data.professor?.name || "Your teacher";
        CLASS_TEACHER_INITIALS = ClassPostCard.initialsFor(CLASS_TEACHER_NAME);

        ClassPostCard.renderClassBanner(data);
        await loadPosts();
    } catch (e) {
        showClassNotFound();
    }
}

function showClassNotFound() {
    const notFound = document.getElementById("classNotFound");
    const wrap = document.getElementById("classroomWrap");
    if (notFound) notFound.classList.remove("hidden");
    if (wrap) wrap.classList.add("hidden");
}

/* FEED — read-only for students */
async function loadPosts() {
    if (!CLASS_ID) return;

    const feed = document.getElementById("feedColumn");

    try {
        const res = await fetch(`/student/classes/${CLASS_ID}/posts`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error("failed to load posts");

        const data = await res.json();
        classPosts = data.map((post) => ClassPostCard.mapServerPost(post, CLASS_TEACHER_NAME));
    } catch (e) {
        classPosts = [];
        if (feed)
            feed.innerHTML =
                '<div class="feed-empty"><i class="fas fa-triangle-exclamation"></i><p>Could not load posts. Please refresh the page.</p></div>';
        return;
    }

    renderFeed();
}

function renderFeed() {
    const feed = document.getElementById("feedColumn");
    if (!feed) return;

    if (!classPosts.length) {
        feed.innerHTML = `
            <div class="feed-empty">
                <i class="fas fa-inbox"></i>
                <p>No posts yet. Your professor's announcements and lesson materials will show up here.</p>
            </div>`;
        return;
    }

    const sorted = [...classPosts].sort((a, b) => b.id - a.id);
    feed.innerHTML = sorted
        .map((post) => ClassPostCard.buildPostCard(post, CLASS_TEACHER_INITIALS))
        .join("");

    feed.querySelectorAll('.post-card[data-type="lesson"]').forEach((card) => {
        card.addEventListener("click", () => openLessonClasswork(card.dataset.postId));
        card.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                openLessonClasswork(card.dataset.postId);
            }
        });
    });

    feed.querySelectorAll(".post-attachment").forEach((att) => {
        att.addEventListener("click", (e) => {
            e.stopPropagation();
            ClassPostCard.openPdfModal(CLASS_ID, att.dataset.postId, att.dataset.filename);
        });
        att.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                e.stopPropagation();
                ClassPostCard.openPdfModal(CLASS_ID, att.dataset.postId, att.dataset.filename);
            }
        });
    });
}

function openLessonClasswork(postId) {
    window.location.href = `classwork.html?id=${CLASS_ID}&postId=${postId}`;
}

/* SUMMARIZE CLASS NOTE */
function summarizeClassNotes() {
    const btn = document.getElementById("summarizeBtn");
    const result = document.getElementById("summaryResult");
    if (!btn || !result) return;

    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner"></i> Summarizing...';
    result.classList.add("hidden");

    setTimeout(() => {
        if (!classPosts.length) {
            result.innerHTML = "<p>No class notes to summarize yet.</p>";
        } else {
            const sorted = [...classPosts].sort((a, b) => b.id - a.id);
            const points = sorted
                .slice(0, 3)
                .map((p) => {
                    if (p.type === "announcement") {
                        const extra =
                            p.checklist && p.checklist.length
                                ? ": " + p.checklist.join(", ")
                                : "";
                        return `<li><strong>${p.date}</strong> — ${p.title}${extra}</li>`;
                    }
                    const extra = p.attachment ? ` (see ${p.attachment.name})` : "";
                    return `<li><strong>${p.date}</strong> — ${p.title}${extra}</li>`;
                })
                .join("");

            result.innerHTML = `
                <p><strong>Summary of recent class notes:</strong></p>
                <ul>${points}</ul>
            `;
        }

        result.classList.remove("hidden");
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }, 900);
}

/* AI INSIGHTS */
const INSIGHT_POOL = [
    { label: "Tip", text: "Based on recent activity, completing your mini-game could improve mastery." },
    { label: "Study Insight", text: "Try studying in short intervals (Pomodoro) for better focus." },
    { label: "Tip", text: "Review last week's lesson PDF before the next quiz — it covers similar items." },
    { label: "Study Insight", text: "Spacing out review sessions over multiple days improves retention." },
    { label: "Tip", text: "You haven't opened this week's lesson material yet — give it a quick read." },
];

let insightIndexes = [0, 1];

function renderInsights() {
    const list = document.getElementById("insightsList");
    if (!list) return;

    list.innerHTML = insightIndexes
        .map((i) => {
            const insight = INSIGHT_POOL[i];
            return `<div class="insight-item"><strong>${insight.label}:</strong> ${insight.text}</div>`;
        })
        .join("");
}

function refreshInsights() {
    const a = Math.floor(Math.random() * INSIGHT_POOL.length);
    let b = Math.floor(Math.random() * INSIGHT_POOL.length);
    if (b === a) b = (b + 1) % INSIGHT_POOL.length;
    insightIndexes = [a, b];
    renderInsights();
    window.showToast?.("Insights refreshed");
}

ClassPostCard.initPdfModalControls();

document.addEventListener("DOMContentLoaded", () => {
    loadClassInfo();
    renderInsights();

    document.getElementById("summarizeBtn")?.addEventListener("click", summarizeClassNotes);
    document.getElementById("insightsRefreshBtn")?.addEventListener("click", refreshInsights);
});
