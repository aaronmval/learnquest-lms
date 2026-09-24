let CLASS_ID = null;
let CLASS_TEACHER_NAME = "";
let CLASS_TEACHER_INITIALS = "";
let classPosts = [];
let cardMasteryBadgesLoaded = Promise.resolve();

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
        const summarizeBtn = document.getElementById("summarizeBtn");
        if (summarizeBtn) summarizeBtn.disabled = false;
        loadClassMastery();
        await loadPosts();
        // Requested last: the AI call is slow, and the dev server handles
        // one request at a time, so it must not queue ahead of the feed.
        await cardMasteryBadgesLoaded;
        loadInsights();
    } catch (e) {
        showClassNotFound();
    }
}

/* AVERAGE MASTERY — BKT mastery averaged across every competency defined
   for this class's subject (all lessons combined). Hidden until a subject
   with at least one competency backs this class. */
async function loadClassMastery() {
    if (!CLASS_ID) return;

    try {
        const res = await fetch(`/student/classes/${CLASS_ID}/mastery`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error("failed to load mastery");

        const data = await res.json();
        const percent = data.average_mastery !== null ? data.average_mastery * 100 : null;
        ClassPostCard.renderBannerMastery(percent, "Average Mastery");
    } catch (e) {
        ClassPostCard.renderBannerMastery(null, "Average Mastery");
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
        .map((post) => ClassPostCard.buildPostCard(post, CLASS_TEACHER_INITIALS, true))
        .join("");

    loadCardMasteryBadges();

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

/* PER-CARD MASTERY BADGES — each lesson card shows its own BKT lesson
   mastery, once a quiz exists for it and the student has attempted it.
   Fetched lazily per card so a class with no quizzes yet costs nothing. */
function loadCardMasteryBadges() {
    const badges = document.querySelectorAll(".post-mastery-badge[data-post-id]");
    cardMasteryBadgesLoaded = Promise.all(
        [...badges].map((badge) => loadCardMastery(badge.dataset.postId, badge))
    );
}

async function loadCardMastery(postId, badge) {
    try {
        const res = await fetch(`/student/classes/${CLASS_ID}/posts/${postId}/mastery`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!res.ok) return;

        const data = await res.json();
        if (data.lesson_mastery === null || data.lesson_mastery === undefined) return;

        const valueEl = badge.querySelector(".post-mastery-value");
        if (valueEl) valueEl.textContent = `${Math.round(data.lesson_mastery * 100)}%`;
        badge.classList.remove("hidden");
    } catch (e) {
        /* Non-critical enhancement — leave the badge hidden on failure. */
    }
}

/* SUMMARIZE CLASS NOTE — hands off to QuestAI Coach, which summarizes
   this class's posts and lessons in a saved chat the student can follow up in. */
function summarizeClassNotes() {
    if (!CLASS_ID) return;
    window.location.href = `student-quest-ai.html?classId=${encodeURIComponent(CLASS_ID)}&action=summarize`;
}

/* AI INSIGHTS — rendering lives in common/insights-card.js */
let insightsLoading = false;

async function loadInsights(refresh = false) {
    const list = document.getElementById("insightsList");
    const btn = document.getElementById("insightsRefreshBtn");
    if (!list || !CLASS_ID || insightsLoading) return;

    insightsLoading = true;
    if (btn) btn.disabled = true;

    const ok = await InsightsCard.load(list, CLASS_ID, { refresh });
    if (ok && refresh) window.showToast?.("Insights refreshed");

    insightsLoading = false;
    if (btn) btn.disabled = false;
}

ClassPostCard.initPdfModalControls();

document.addEventListener("DOMContentLoaded", () => {
    loadClassInfo();

    document.getElementById("summarizeBtn")?.addEventListener("click", summarizeClassNotes);
    document.getElementById("insightsRefreshBtn")?.addEventListener("click", () => loadInsights(true));
});
