const CLASS_GRADIENT_PALETTE = [
    "linear-gradient(135deg, #06b6d4, #0891b2)",
    "linear-gradient(135deg, #fb923c, #ea580c)",
    "linear-gradient(135deg, #c084fc, #9333ea)",
    "linear-gradient(135deg, #4ade80, #16a34a)",
    "linear-gradient(135deg, #fbbf24, #d97706)",
];

let CLASS_ID = null;
let CLASS_TEACHER_NAME = "";
let CLASS_TEACHER_INITIALS = "";
let classPosts = [];

function initialsFor(name) {
    const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return "T";
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join("");
}

function escapeHtml(str) {
    if (str === undefined || str === null) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function formatPostDate(isoString) {
    if (!isoString) return "";
    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) return "";
    return date.toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
}

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
        CLASS_TEACHER_INITIALS = initialsFor(CLASS_TEACHER_NAME);

        renderClassBanner(data);
        await loadPosts();
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

/* FEED — read-only for students */
function mapServerPost(post) {
    return {
        id: post.id,
        type: post.type,
        quarter: post.quarter,
        author: CLASS_TEACHER_NAME,
        date: formatPostDate(post.created_at),
        title: post.title,
        body: post.body || "",
        checklist: Array.isArray(post.checklist) ? post.checklist : [],
        attachment: post.attachment_path
            ? { name: post.attachment_name || "Attachment.pdf", postId: post.id }
            : null,
        edited: !!post.edited,
    };
}

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
        classPosts = data.map(mapServerPost);
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
    feed.innerHTML = sorted.map((post) => buildPostCard(post)).join("");

    feed.querySelectorAll(".post-attachment").forEach((att) => {
        att.addEventListener("click", (e) => {
            e.stopPropagation();
            openPdfModal(att.dataset.filename, att.dataset.postId);
        });
        att.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                e.stopPropagation();
                openPdfModal(att.dataset.filename, att.dataset.postId);
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

    const authorPhotoHtml = `<div class="post-author-initials">${escapeHtml(CLASS_TEACHER_INITIALS)}</div>`;

    let bodyHtml = "";
    if (post.body)
        bodyHtml += `<p class="post-body">${escapeHtml(post.body)}</p>`;

    if (post.checklist && post.checklist.length) {
        bodyHtml += post.checklist
            .map(
                (item) => `
            <div class="post-checklist">
                <i class="fas fa-check-circle"></i>
                <span>${escapeHtml(item)}</span>
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
                 aria-label="Open ${escapeHtml(post.attachment.name)}"
                 data-filename="${escapeHtml(post.attachment.name)}"
                 data-post-id="${post.attachment.postId}">
                <span class="pdf-icon">PDF</span>
                <span class="attachment-name">${escapeHtml(post.attachment.name)}</span>
            </div>`;
    }

    const editedTag = post.edited
        ? `<span class="post-edited-tag">(edited)</span>`
        : "";

    return `
        <article class="post-card" data-post-id="${post.id}" data-type="${post.type}">
            <div class="post-header">
                <div class="post-author">
                    ${authorPhotoHtml}
                    <div class="post-author-info">
                        <p class="post-author-name">${escapeHtml(post.author)}</p>
                        <p class="post-author-date">${escapeHtml(post.date)}${editedTag}</p>
                    </div>
                </div>
                <div class="post-badges">
                    <div class="post-badge-row">
                        <span class="post-badge ${badgeClass}">${badgeLabel}</span>
                        <span class="post-badge ${quarterClass}">${escapeHtml(post.quarter)}</span>
                    </div>
                </div>
            </div>
            <h3 class="post-title">${escapeHtml(post.title)}</h3>
            ${bodyHtml}
        </article>
    `;
}

/* PDF MODAL — real attachment, served by StudentClassPostController::attachment() */
function openPdfModal(filename, postId) {
    const modal = document.getElementById("pdfModal");
    const title = document.getElementById("pdfModalTitle");
    const viewer = document.getElementById("pdfModalViewer");
    const dlBtn = document.getElementById("pdfModalDownload");
    const placeholder = document.getElementById("pdfModalPlaceholder");

    if (!modal) return;

    title.textContent = filename;

    const isReal = postId != null && CLASS_ID != null;

    if (isReal) {
        const viewUrl = `/student/classes/${CLASS_ID}/posts/${postId}/attachment`;
        viewer.src = viewUrl;
        viewer.style.display = "block";
        placeholder.style.display = "none";
        dlBtn.href = `${viewUrl}?download=1`;
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

document.getElementById("pdfModalClose")?.addEventListener("click", closePdfModal);
document.getElementById("pdfModalBackdrop")?.addEventListener("click", closePdfModal);

document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && document.getElementById("pdfModal")?.classList.contains("open"))
        closePdfModal();
});

document.addEventListener("DOMContentLoaded", () => {
    loadClassInfo();
});
