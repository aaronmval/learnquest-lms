const CLASS_INFO = window.CLASS_INFO || {
    subject: "PHYSICS",
    section: "STEM - AMETHYST",
    teacher: "John Michael C. Bautista",
    subjectMastery: 70,
    teacherPhoto: "../../assets/images/teachers/physics.jpg",
    teacherInitials: "JB",
    gradient: "linear-gradient(135deg, #7c3aed, #35063E)",
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
    },
    {
        id: 2,
        type: "lesson",
        quarter: "1st Quarter",
        author: CLASS_INFO.teacher,
        date: "Jan 5, 2026",
        title: "Material for this Week 2",
        body: "",
        lessonMastery: 68,
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
        lessonMastery: 72,
        attachment: { name: "Lesson 2.pdf", url: "#" },
    },
];

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

/* RENDER — feed */
function renderFeed() {
    const feed = document.getElementById("feedColumn");
    if (!feed) return;

    if (!classPosts.length) {
        feed.innerHTML = `
            <div class="feed-empty">
                <i class="fas fa-inbox"></i>
                <p>No posts yet for this class.</p>
            </div>`;
        return;
    }

    const sorted = [...classPosts].sort((a, b) => b.id - a.id);
    feed.innerHTML = sorted.map((post) => buildPostCard(post)).join("");
    feed.querySelectorAll('.post-card[data-type="lesson"]').forEach((card) => {
        card.addEventListener("click", () =>
            openLessonClasswork(card.dataset.postId),
        );
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

function openLessonClasswork(postId) {
    const post = classPosts.find((p) => String(p.id) === String(postId));
    if (!post) return;

    const params = new URLSearchParams({
        postId: post.id,
        subject: CLASS_INFO.subject,
        section: CLASS_INFO.section,
        title: post.title,
        ...(post.attachment
            ? { file: post.attachment.name, fileUrl: post.attachment.url || "" }
            : {}),
        ...(typeof post.lessonMastery === "number"
            ? { lessonMastery: post.lessonMastery }
            : {}),
    });
    window.location.href = `student-classwork-physics.html?${params.toString()}`;
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

    const cardAttrs =
        post.type === "lesson"
            ? `role="button" tabindex="0" aria-label="Open ${post.title}"`
            : "";

    return `
        <article class="post-card" data-post-id="${post.id}" data-type="${post.type}" ${cardAttrs}>
            <div class="post-header">
                <div class="post-author">
                    ${authorPhotoHtml}
                    <div class="post-author-info">
                        <p class="post-author-name">${post.author}</p>
                        <p class="post-author-date">${post.date}</p>
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

/* PDF MODAL */
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

/*SUMMARIZE CLASS NOTE*/
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
                    const extra = p.attachment
                        ? ` (see ${p.attachment.name})`
                        : "";
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
    {
        label: "Tip",
        text: "Based on recent activity, completing your mini-game could improve mastery.",
    },
    {
        label: "Study Insight",
        text: "Try studying in short intervals (Pomodoro) for better focus.",
    },
    {
        label: "Tip",
        text: "Review last week's lesson PDF before the next quiz — it covers similar items.",
    },
    {
        label: "Study Insight",
        text: "Spacing out review sessions over multiple days improves retention.",
    },
    {
        label: "Tip",
        text: "You haven't opened this week's lesson material yet — give it a quick read.",
    },
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
    showToast("Insights refreshed");
}

/* INIT */
document.addEventListener("DOMContentLoaded", () => {
    renderClassBanner();
    renderFeed();
    renderInsights();

    const summarizeBtn = document.getElementById("summarizeBtn");
    if (summarizeBtn)
        summarizeBtn.addEventListener("click", summarizeClassNotes);

    const refreshBtn = document.getElementById("insightsRefreshBtn");
    if (refreshBtn) refreshBtn.addEventListener("click", refreshInsights);

    /* PDF Modal close handlers */
    const closeBtn = document.getElementById("pdfModalClose");
    const backdrop = document.getElementById("pdfModalBackdrop");

    if (closeBtn) closeBtn.addEventListener("click", closePdfModal);
    if (backdrop) backdrop.addEventListener("click", closePdfModal);

    /* Keyboard: Escape closes the modal */
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") closePdfModal();
    });
});
