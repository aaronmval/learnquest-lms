const STUDENT_GENERATED_QUIZ_STORAGE_KEY = "lq_student_generated_ai_quiz_v1";

const DEFAULT_LESSON_SUMMARY = {
    mastery: 76,
    overview:
        "Here's a quick recap of this lesson based on the material your teacher shared.",
    highlights: [
        "Review the attached material for the full lesson content.",
        "Revisit your notes and any checklist items above before your next quiz.",
        "Reach out to your teacher if anything is unclear.",
    ],
};

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
   server-side after the first generation. Mastery chip stays a static
   placeholder until BKT mastery tracking is implemented. */
async function renderLessonSummary(lesson) {
    const result = document.getElementById("summaryResult");
    const masteryEl = document.getElementById("summaryLessonMastery");
    if (!result) return;

    if (masteryEl) {
        masteryEl.textContent = `Lesson Mastery: ${DEFAULT_LESSON_SUMMARY.mastery}%`;
    }

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

/* QUIZ CARD */
function getGeneratedValidatorQuiz() {
    try {
        const payload = JSON.parse(
            localStorage.getItem(STUDENT_GENERATED_QUIZ_STORAGE_KEY) || "{}",
        );

        if (!payload || typeof payload !== "object") {
            return null;
        }

        if (!payload.visible || payload.subject !== CLASS_SUBJECT) {
            return null;
        }

        return {
            label: payload.label || "AI Generated MCQ",
            quizId: payload.quizId || "ai-generated-mcq",
            quizUrl: payload.quizUrl || "student-quiz.html",
            lessonTitle: payload.materialTitle || "AI Generated Worksheet",
            lessonMastery:
                typeof payload.mastery === "number" ? payload.mastery : "",
        };
    } catch (e) {
        return null;
    }
}

function renderQuizCard() {
    const titleEl = document.getElementById("quizCardTitle");
    const takeBtn = document.getElementById("takeQuizBtn");
    if (!titleEl || !takeBtn) return;

    const generatedQuiz = getGeneratedValidatorQuiz();

    if (generatedQuiz) {
        titleEl.textContent = generatedQuiz.label;
        takeBtn.textContent = "Take AI Quiz";
        takeBtn.disabled = false;
        takeBtn.dataset.quizUrl = generatedQuiz.quizUrl;
        takeBtn.dataset.quizId = generatedQuiz.quizId;
        takeBtn.dataset.lessonTitle = generatedQuiz.lessonTitle;
        takeBtn.dataset.lessonMastery = String(generatedQuiz.lessonMastery);
        return;
    }

    titleEl.textContent = "No quiz available yet";
    takeBtn.textContent = "Take Quiz";
    takeBtn.disabled = true;
    delete takeBtn.dataset.quizUrl;
    delete takeBtn.dataset.quizId;
    delete takeBtn.dataset.lessonTitle;
    delete takeBtn.dataset.lessonMastery;
}

function handleTakeQuiz() {
    const takeBtn = document.getElementById("takeQuizBtn");
    if (!takeBtn || takeBtn.disabled) return;

    const quizUrl = takeBtn.dataset.quizUrl || "student-quiz.html";
    const quizId = takeBtn.dataset.quizId || "";
    const lessonTitle = takeBtn.dataset.lessonTitle || CURRENT_LESSON?.title || "";
    const lessonMastery = takeBtn.dataset.lessonMastery || "";

    const params = new URLSearchParams({
        subject: CLASS_SUBJECT,
        ...(quizId ? { quizId } : {}),
        lessonTitle,
        lessonMastery,
    });

    window.location.href = `${quizUrl}?${params.toString()}`;
}

function handleBackToClass() {
    window.location.href = `enrolled-class.html?id=${CLASS_ID}`;
}

ClassPostCard.initPdfModalControls();

document.addEventListener("DOMContentLoaded", () => {
    loadLesson();
    document.getElementById("backToClassBtn")?.addEventListener("click", handleBackToClass);
    document.getElementById("takeQuizBtn")?.addEventListener("click", handleTakeQuiz);
});
