const CLASS_INFO = window.CLASS_INFO || {
    subject: "CHEMISTRY",
    section: "STEM - AMETHYST",
    teacher: "Mila D. Valiente",
    subjectMastery: 85,
    teacherPhoto: "../../assets/images/teachers/chemistry.jpg",
    teacherInitials: "MV",
    gradient: "linear-gradient(135deg, #06b6d4, #0891b2)",
};

const LESSON_SUMMARIES = {
    2: {
        mastery: 78,
        overview:
            "This lesson introduces periodic trends and how atomic structure influences chemical behavior.",
        highlights: [
            "Compared atomic radius and ionization energy trends across the periodic table.",
            "Linked valence electrons to reactivity and bonding outcomes.",
            "Practiced interpreting periodic table groups for quick property prediction.",
        ],
    },
    3: {
        mastery: 82,
        overview:
            "This lesson deepens understanding of electron configuration and element classification.",
        highlights: [
            "Mapped electron arrangements to block, period, and group positions.",
            "Distinguished metals, nonmetals, and metalloids through real examples.",
            "Applied classification logic to short item analysis practice.",
        ],
    },
    default: {
        mastery: 76,
        overview:
            "The lesson focuses on foundational chemistry patterns and table-reading fluency.",
        highlights: [
            "Reviewed core atomic concepts before solving guided examples.",
            "Connected lesson terms with common quiz question patterns.",
            "Prepared a checklist of key formulas and definitions for recall.",
        ],
    },
};

let classPosts = [
    {
        id: 1,
        type: "announcement",
        quarter: "1st Quarter",
        author: CLASS_INFO.teacher,
        date: "Jan 5, 2026",
        title: "Announcement: June 11, 2026",
        body: "Please be informed that we will have the following:",
        checklist: ["Quiz 1 – Module 6 (30 Items)"],
        quiz: {
            label: "Quiz 1 – Module 6",
            quizId: "quiz-1-module-6",
            quizUrl: "student-quiz.html",
        },
    },
    {
        id: 2,
        type: "lesson",
        quarter: "1st Quarter",
        author: CLASS_INFO.teacher,
        date: "Jan 5, 2026",
        title: "Material for this Week 2",
        body: "",
        lessonMastery: 78,
        attachment: { name: "Lesson 1.pdf", url: "#" },
    },
    {
        id: 3,
        type: "lesson",
        quarter: "1st Quarter",
        author: CLASS_INFO.teacher,
        date: "Jan 12, 2026",
        title: "Material for Week 3",
        body: "",
        lessonMastery: 82,
        attachment: { name: "Lesson 2.pdf", url: "#" },
    },
];

/* URL PARAMS*/
function getParams() {
    return new URLSearchParams(window.location.search);
}

function resolveLessonPost() {
    const params = getParams();
    const postId = params.get("postId");

    const found = postId
        ? classPosts.find((p) => String(p.id) === String(postId))
        : null;
    if (found) return found;

    /* Fallback: build a minimal post from whatever the URL carried */
    if (params.get("title")) {
        return {
            id: postId || "unknown",
            type: "lesson",
            quarter: "1st Quarter",
            author: CLASS_INFO.teacher,
            date: "",
            title: params.get("title"),
            body: "",
            lessonMastery: Number(params.get("lessonMastery")) || undefined,
            attachment: params.get("file")
                ? {
                      name: params.get("file"),
                      url: params.get("fileUrl") || "#",
                  }
                : null,
        };
    }

    return null;
}

/* RENDER — class banner*/
function renderClassBanner() {
    const banner = document.getElementById("classBanner");
    if (!banner) return;

    banner.style.background = CLASS_INFO.gradient;

    document.getElementById("classBannerSubject").textContent =
        CLASS_INFO.subject;
    document.getElementById("classBannerSection").textContent =
        CLASS_INFO.section;
    document.getElementById("classBannerTeacher").textContent =
        CLASS_INFO.teacher;

    const bannerMastery = document.getElementById("classBannerMastery");
    if (bannerMastery) {
        bannerMastery.textContent = `Current Mastery Level: ${CLASS_INFO.subjectMastery}%`;
    }
}

/* RENDER — single lesson card */
function renderLessonDetail() {
    const feed = document.getElementById("feedColumn");
    if (!feed) return;

    const post = resolveLessonPost();

    if (!post) {
        feed.innerHTML = `
            <div class="feed-empty">
                <i class="fas fa-inbox"></i>
                <p>No lesson selected. Please open this page by clicking a lesson card from the class feed.</p>
            </div>`;
        return;
    }

    feed.innerHTML = buildPostCard(post);
    feed.querySelectorAll(".post-attachment").forEach((att) => {
        att.addEventListener("click", (e) => {
            e.stopPropagation();
            openPdfModal(att.dataset.filename, att.dataset.url);
        });
        att.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                e.stopPropagation();
                openPdfModal(att.dataset.filename, att.dataset.url);
            }
        });
    });
}

function buildPostCard(post) {
    const isAnnouncement = post.type === "announcement";
    const badgeClass = isAnnouncement ? "badge-announcement" : "badge-lesson";
    const badgeLabel = isAnnouncement ? "Announcement" : "Lesson";
    const quarterClass = isAnnouncement
        ? "badge-quarter-green"
        : "badge-quarter-orange";

    const authorPhotoHtml = CLASS_INFO.teacherPhoto
        ? `<img class="post-author-photo" src="${CLASS_INFO.teacherPhoto}" alt="${post.author}"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
           <div class="post-author-initials" style="display:none;">${CLASS_INFO.teacherInitials}</div>`
        : `<div class="post-author-initials">${CLASS_INFO.teacherInitials}</div>`;

    let bodyHtml = "";
    if (post.body) bodyHtml += `<p class="post-body">${post.body}</p>`;

    if (post.checklist && post.checklist.length) {
        bodyHtml += post.checklist
            .map(
                (item) => `
            <div class="post-checklist">
                <i class="fas fa-check-circle"></i>
                <span>${item}</span>
            </div>
        `,
            )
            .join("");
    }

    if (post.attachment) {
        bodyHtml += `
            <div class="post-attachment"
                 role="button"
                 tabindex="0"
                 aria-label="Open ${post.attachment.name}"
                 data-filename="${post.attachment.name}"
                 data-url="${post.attachment.url || "#"}">
                <span class="pdf-icon">PDF</span>
                <span class="attachment-name">${post.attachment.name}</span>
            </div>`;
    }

    const lessonMasteryBadge =
        typeof post.lessonMastery === "number"
            ? `<span class="post-badge badge-mastery-level">Mastery ${post.lessonMastery}%</span>`
            : "";

    return `
        <article class="post-card" data-post-id="${post.id}" data-type="${post.type}">
            <div class="post-header">
                <div class="post-author">
                    ${authorPhotoHtml}
                    <div class="post-author-info">
                        <p class="post-author-name">${post.author}</p>
                        ${post.date ? `<p class="post-author-date">${post.date}</p>` : ""}
                    </div>
                </div>
                <div class="post-badges">
                    <span class="post-badge ${badgeClass}">${badgeLabel}</span>
                    <span class="post-badge ${quarterClass}">${post.quarter}</span>
                    ${lessonMasteryBadge}
                </div>
            </div>
            <h3 class="post-title">${post.title}</h3>
            ${bodyHtml}
        </article>
    `;
}

/*PDF MODAL */

function openPdfModal(filename, url) {
    const modal = document.getElementById("pdfModal");
    const title = document.getElementById("pdfModalTitle");
    const viewer = document.getElementById("pdfModalViewer");
    const dlBtn = document.getElementById("pdfModalDownload");
    const placeholder = document.getElementById("pdfModalPlaceholder");

    if (!modal) return;

    title.textContent = filename;

    const isReal = url && url !== "#";

    if (isReal) {
        viewer.src = url;
        viewer.style.display = "block";
        placeholder.style.display = "none";
        dlBtn.href = url;
        dlBtn.download = filename;
        dlBtn.style.display = "flex";
    } else {
        viewer.src = "";
        viewer.style.display = "none";
        placeholder.style.display = "flex";
        dlBtn.href = "#";
        dlBtn.style.display = "none";
    }

    modal.classList.add("open");
    document.body.style.overflow = "hidden";
}

function closePdfModal() {
    const modal = document.getElementById("pdfModal");
    const viewer = document.getElementById("pdfModalViewer");
    if (!modal) return;

    modal.classList.remove("open");
    document.body.style.overflow = "";
    setTimeout(() => {
        viewer.src = "";
    }, 300);
}

/* QUIZ CARD (right column) */
function findLatestQuizPost() {
    const sorted = [...classPosts].sort((a, b) => b.id - a.id);
    return sorted.find((p) => p.type === "announcement" && p.quiz) || null;
}

function renderQuizCard() {
    const titleEl = document.getElementById("quizCardTitle");
    const takeBtn = document.getElementById("takeQuizBtn");
    if (!titleEl || !takeBtn) return;

    const quizPost = findLatestQuizPost();

    if (quizPost) {
        titleEl.textContent = quizPost.quiz.label;
        takeBtn.textContent = "Take Quiz";
        takeBtn.disabled = false;
        takeBtn.dataset.quizUrl = quizPost.quiz.quizUrl || "student-quiz.html";
        takeBtn.dataset.quizId = quizPost.quiz.quizId || "";
    } else {
        titleEl.textContent = "No quiz available yet";
        takeBtn.textContent = "Take Quiz";
        takeBtn.disabled = true;
        delete takeBtn.dataset.quizUrl;
        delete takeBtn.dataset.quizId;
    }
}

function handleTakeQuiz() {
    const takeBtn = document.getElementById("takeQuizBtn");
    if (!takeBtn || takeBtn.disabled) return;

    const quizUrl = takeBtn.dataset.quizUrl || "student-quiz.html";
    const quizId = takeBtn.dataset.quizId || "";

    const params = new URLSearchParams({
        subject: CLASS_INFO.subject,
        ...(quizId ? { quizId } : {}),
        lessonTitle: resolveLessonPost()?.title || "",
        lessonMastery: resolveLessonPost()?.lessonMastery || "",
    });

    window.location.href = `${quizUrl}?${params.toString()}`;
}

/* SUMMARY OF LESSON (static box) */
function renderLessonSummary() {
    const result = document.getElementById("summaryResult");
    const masteryEl = document.getElementById("summaryLessonMastery");
    if (!result) return;

    const lesson = resolveLessonPost();
    const summary =
        (lesson && LESSON_SUMMARIES[String(lesson.id)]) ||
        LESSON_SUMMARIES.default;
    const lessonMastery =
        typeof lesson?.lessonMastery === "number"
            ? lesson.lessonMastery
            : summary.mastery;

    if (masteryEl) {
        masteryEl.textContent = `Lesson Mastery: ${lessonMastery}%`;
    }

    result.innerHTML = `
        <p class="summary-overview">${summary.overview}</p>
        <ul class="summary-points">
            ${summary.highlights.map((item) => `<li>${item}</li>`).join("")}
        </ul>
    `;
}

/* BACK TO CLASS BUTTON */
function handleBackToClass() {
    const cameFromSamePage =
        document.referrer &&
        document.referrer.includes(window.location.hostname);

    if (cameFromSamePage && window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = "enrolledChemistry.html";
    }
}

/*INIT*/
document.addEventListener("DOMContentLoaded", () => {
    renderClassBanner();
    renderLessonDetail();
    renderQuizCard();
    renderLessonSummary();

    if (
        window.parent &&
        typeof window.parent.setActiveSidebarLink === "function"
    ) {
        window.parent.setActiveSidebarLink("nav-chemistry");
    } else if (window.setActiveSidebarLink) {
        window.setActiveSidebarLink("nav-chemistry");
    }

    const takeQuizBtn = document.getElementById("takeQuizBtn");
    if (takeQuizBtn) takeQuizBtn.addEventListener("click", handleTakeQuiz);

    const backBtn = document.getElementById("backToClassBtn");
    if (backBtn) backBtn.addEventListener("click", handleBackToClass);

    const closeBtn = document.getElementById("pdfModalClose");
    const backdrop = document.getElementById("pdfModalBackdrop");

    if (closeBtn) closeBtn.addEventListener("click", closePdfModal);
    if (backdrop) backdrop.addEventListener("click", closePdfModal);

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") closePdfModal();
    });
});
