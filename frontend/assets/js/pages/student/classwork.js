let CLASS_ID = null;
let CLASS_SUBJECT = "";
let CLASS_TEACHER_INITIALS = "";
let POST_ID = null;
let CURRENT_LESSON = null;

function showNotFound(title, message, linkHref, linkLabel) {
    const notFound = document.getElementById("classNotFound");
    const wrap = document.getElementById("classroomWrap");
    if (!notFound || !wrap) return;

    document.getElementById("classNotFoundTitle").textContent = title;
    document.getElementById("classNotFoundMessage").textContent = message;

    const link = document.getElementById("classNotFoundLink");
    link.href = linkHref;
    link.innerHTML = `<i class="fas fa-arrow-left"></i> ${ClassPostCard.escapeHtml(linkLabel)}`;

    notFound.classList.remove("hidden");
    wrap.classList.add("hidden");
}

async function loadLesson() {
    const params = new URLSearchParams(window.location.search);
    const classId = params.get("id");
    POST_ID = params.get("postId");

    if (!classId || !POST_ID) {
        showNotFound(
            "Class not found",
            "This class doesn't exist, or you're not enrolled in it.",
            "student-home.html",
            "Back to Home",
        );
        return;
    }

    try {
        const classRes = await fetch(`/student/classes/${encodeURIComponent(classId)}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });

        if (!classRes.ok) {
            showNotFound(
                "Class not found",
                "This class doesn't exist, or you're not enrolled in it.",
                "student-home.html",
                "Back to Home",
            );
            return;
        }

        const classData = await classRes.json();
        CLASS_ID = classData.id;
        CLASS_SUBJECT = (classData.subject || classData.name || "").toUpperCase();
        const teacherName = classData.professor?.name || "Your teacher";
        CLASS_TEACHER_INITIALS = ClassPostCard.initialsFor(teacherName);

        ClassPostCard.renderClassBanner(classData);

        const postsRes = await fetch(`/student/classes/${CLASS_ID}/posts`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!postsRes.ok) throw new Error("failed to load posts");

        const posts = await postsRes.json();
        const mapped = posts.map((post) => ClassPostCard.mapServerPost(post, teacherName));
        const lesson = mapped.find((post) => String(post.id) === String(POST_ID));

        if (!lesson) {
            showNotFound(
                "Lesson not found",
                "This lesson doesn't exist, or the link is invalid.",
                `enrolled-class.html?id=${CLASS_ID}`,
                "Back to Class",
            );
            return;
        }

        CURRENT_LESSON = lesson;
        renderLesson(lesson);
        renderLessonSummary(lesson);
        renderQuizCard();
        loadLessonMastery();
    } catch (e) {
        showNotFound(
            "Lesson not found",
            "This lesson doesn't exist, or the link is invalid.",
            `enrolled-class.html?id=${CLASS_ID || ""}`,
            "Back to Class",
        );
    }
}

function renderLesson(lesson) {
    const feed = document.getElementById("feedColumn");
    if (!feed) return;

    feed.innerHTML = ClassPostCard.buildPostCard(lesson, CLASS_TEACHER_INITIALS);

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

/* SUMMARY OF LESSON — real AI summary of the lesson's attached PDF, cached
   server-side after the first generation. Real lesson mastery is shown on
   the banner above (see loadLessonMastery()), not here. */
async function renderLessonSummary(lesson) {
    const result = document.getElementById("summaryResult");
    if (!result) return;

    if (!lesson.attachment) {
        result.innerHTML = `<p class="summary-overview">No material attached to summarize for this lesson.</p>`;
        return;
    }

    result.innerHTML = `<p class="summary-overview">Generating summary…</p>`;

    const url = `/student/classes/${CLASS_ID}/posts/${POST_ID}/summary`;
    const startedAt = performance.now();
    console.groupCollapsed(`[AI Summary] GET ${url}`);
    console.log("post id:", POST_ID, "class id:", CLASS_ID);

    try {
        const res = await fetch(url, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });

        const durationMs = Math.round(performance.now() - startedAt);
        const data = await res.json().catch(() => null);

        console.log(`status: ${res.status} (${durationMs}ms)`);
        if (data?.request_id) console.log("request_id:", data.request_id);
        console.log("response body:", data);

        if (!res.ok) {
            throw new Error(data?.message || `summary request failed (HTTP ${res.status})`);
        }

        console.groupEnd();

        result.innerHTML = `
            <p class="summary-overview">${ClassPostCard.escapeHtml(data.overview)}</p>
            <ul class="summary-points">
                ${(data.key_points || [])
                    .map((item) => `<li>${ClassPostCard.escapeHtml(item)}</li>`)
                    .join("")}
            </ul>
        `;
    } catch (e) {
        console.error("[AI Summary] Fetch threw:", e);
        console.groupEnd();

        result.innerHTML = `
            <p class="summary-overview">We couldn't generate a summary right now.</p>
            <button type="button" id="summaryRetryBtn" class="summary-retry-btn">Try again</button>
        `;
        document
            .getElementById("summaryRetryBtn")
            ?.addEventListener("click", () => renderLessonSummary(lesson));
    }
}

/* LESSON MASTERY — BKT mastery averaged across just this lesson's tested
   competencies. Hidden until an AI quiz has been generated for the lesson
   and the student has at least attempted it. */
async function loadLessonMastery() {
    if (!CLASS_ID || !POST_ID) return;

    try {
        const res = await fetch(`/student/classes/${CLASS_ID}/posts/${POST_ID}/mastery`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error("failed to load mastery");

        const data = await res.json();
        const percent = data.lesson_mastery !== null ? data.lesson_mastery * 100 : null;
        ClassPostCard.renderBannerMastery(percent, "Lesson Mastery");
    } catch (e) {
        ClassPostCard.renderBannerMastery(null, "Lesson Mastery");
    }
}

/* QUIZ CARD — real AI-generated quiz, lazily generated server-side on first
   "Take Quiz" click and cached after that (same pattern as the AI summary). */
function renderQuizCard() {
    const titleEl = document.getElementById("quizCardTitle");
    const takeBtn = document.getElementById("takeQuizBtn");
    if (!titleEl || !takeBtn) return;

    if (CURRENT_LESSON?.attachment) {
        titleEl.textContent = "AI Generated Quiz";
        takeBtn.textContent = "Take AI Quiz";
        takeBtn.disabled = false;
        return;
    }

    titleEl.textContent = "No quiz available yet";
    takeBtn.textContent = "Take Quiz";
    takeBtn.disabled = true;
}

function handleTakeQuiz() {
    const takeBtn = document.getElementById("takeQuizBtn");
    if (!takeBtn || takeBtn.disabled) return;

    window.location.href = `student-quiz.html?id=${CLASS_ID}&postId=${POST_ID}`;
}

function handleBackToClass() {
    window.location.href = `enrolled-class.html?id=${CLASS_ID}`;
}

ClassPostCard.initPdfModalControls();

/* GUIDED TOUR — common/page-tour.js. This is where students learn how quizzes
   work, before opening one: a timed quiz's clock starts with the quiz page. */
function lessonTourSteps() {
    return [
        {
            title: "Inside a lesson",
            body: "This is one lesson from your teacher: the lesson itself, an AI summary of it, and its quiz.",
        },
        {
            target: () => document.querySelector("#feedColumn .post-card"),
            title: "The lesson",
            body: "Your teacher's notes and the lesson file. Click the file to read it here or download it.",
        },
        {
            target: ".summary-card",
            title: "Summary of the lesson",
            body: "QuestAI reads the lesson file and writes a short summary of the key points, for quick review before a quiz.",
        },
        {
            target: ".quiz-card",
            title: "The AI quiz",
            body: "QuestAI writes this quiz from the lesson. Some quizzes have a time limit that starts as soon as you open them, so get ready first. Your answers update your mastery of each topic, and your next quiz can adjust to be easier or harder to match it.",
        },
        {
            target: "#backToClassBtn",
            title: "Back to the class",
            body: "Return to the class's list of lessons and announcements.",
        },
        {
            title: "That's a lesson",
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}

document.addEventListener("DOMContentLoaded", () => {
    // No tour when the class or lesson isn't found.
    loadLesson().then(() => {
        if (!CURRENT_LESSON) return;
        window.LQPageTour?.init({ key: "tour_seen_lesson", steps: lessonTourSteps });
    });
    document.getElementById("backToClassBtn")?.addEventListener("click", handleBackToClass);
    document.getElementById("takeQuizBtn")?.addEventListener("click", handleTakeQuiz);
});
