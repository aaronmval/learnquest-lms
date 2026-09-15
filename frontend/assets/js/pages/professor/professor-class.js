/* CLASS_INFO is no longer hardcoded — it's populated from the database by
   loadClassInfo() below, based on the ?id= query param. */
let CLASS_INFO = null;
let CLASS_ID = null;

const CLASS_GRADIENT_PALETTE = [
    "linear-gradient(135deg, #06b6d4, #0891b2)",
    "linear-gradient(135deg, #fb923c, #ea580c)",
    "linear-gradient(135deg, #c084fc, #9333ea)",
    "linear-gradient(135deg, #4ade80, #16a34a)",
    "linear-gradient(135deg, #fbbf24, #d97706)",
];

/* Posts — fetched from the database (GET /professor/classes/{id}/posts),
   mapped into the shape the existing render functions expect. */
let classPosts = [];

/* Composer state — tracks whether we're creating or editing, and which post */
let composerMode = "create"; // 'create' | 'edit'
let composerEditingId = null;
let composerType = "announcement"; // 'announcement' | 'lesson'
let composerPickedFileName = null; // display label only
let composerPickedFile = null; // the real File object to upload, if any was newly chosen

/* Which post's kebab menu is currently open (for outside-click closing) */
let openKebabPostId = null;

/* Pending delete target */
let pendingDeleteId = null;

function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
}

/* LOAD — fetch the real class from the database, keyed by ?id= */
async function loadClassInfo() {
    const classId = new URLSearchParams(window.location.search).get("id");

    if (!classId) {
        showClassNotFound();
        return;
    }

    try {
        const res = await fetch(`/professor/classes/${encodeURIComponent(classId)}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });

        if (!res.ok) {
            showClassNotFound();
            return;
        }

        const data = await res.json();
        const teacher = data.professor?.name || "Your class";
        CLASS_ID = data.id;

        CLASS_INFO = {
            subject: (data.subject || data.name || "").toUpperCase(),
            section: data.section || "",
            teacher,
            teacherInitials: initialsFor(teacher),
            gradient: CLASS_GRADIENT_PALETTE[data.id % CLASS_GRADIENT_PALETTE.length],
            code: data.code || "",
            studentsCount: typeof data.students_count === "number" ? data.students_count : 0,
        };

        renderClassBanner();
        renderInviteCode();

        await loadPosts();
    } catch (e) {
        showClassNotFound();
    }
}

function initialsFor(name) {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "CL";
    return parts.slice(0, 2).map((w) => w[0].toUpperCase()).join("");
}

function showClassNotFound() {
    const notFound = document.getElementById("classNotFound");
    const wrap = document.getElementById("classroomWrap");
    if (notFound) notFound.classList.remove("hidden");
    if (wrap) wrap.classList.add("hidden");
}

/* RENDER — class banner */
function renderClassBanner() {
    const banner = document.getElementById("classBanner");
    if (!banner || !CLASS_INFO) return;

    banner.style.background = CLASS_INFO.gradient;

    document.getElementById("classBannerSubject").textContent =
        CLASS_INFO.subject;
    document.getElementById("classBannerSection").textContent =
        CLASS_INFO.section;
    document.getElementById("classBannerTeacher").textContent =
        CLASS_INFO.teacher;
}

/* RENDER — invite code card */
function renderInviteCode() {
    const codeEl = document.getElementById("inviteCodeValue");
    if (!codeEl || !CLASS_INFO) return;
    codeEl.textContent = CLASS_INFO.code || "—";
}

/* RENDER — class snapshot side panel */
function renderClassSnapshot() {
    const studentsEl = document.getElementById("snapshotStudents");
    const postsEl = document.getElementById("snapshotPosts");
    const lastActivityEl = document.getElementById("snapshotLastActivity");
    if (!studentsEl || !postsEl || !lastActivityEl) return;

    studentsEl.textContent = CLASS_INFO ? CLASS_INFO.studentsCount : 0;
    postsEl.textContent = classPosts.length;

    if (classPosts.length) {
        const sorted = [...classPosts].sort((a, b) => b.id - a.id);
        lastActivityEl.textContent = sorted[0].date;
    } else {
        lastActivityEl.textContent = "—";
    }
}

/* POSTS — fetch from the database and map into the feed's expected shape */
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

function mapServerPost(post) {
    return {
        id: post.id,
        type: post.type,
        quarter: post.quarter,
        author: CLASS_INFO ? CLASS_INFO.teacher : "",
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

    try {
        const res = await fetch(`/professor/classes/${CLASS_ID}/posts`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error("failed to load posts");

        const data = await res.json();
        classPosts = data.map(mapServerPost);
    } catch (e) {
        classPosts = [];
        showToast("Could not load posts. Please refresh the page.");
    }

    renderFeed();
}

/* RENDER — feed */
function renderFeed() {
    const feed = document.getElementById("feedColumn");
    if (!feed) return;

    if (!classPosts.length) {
        feed.innerHTML = `
            <div class="feed-empty">
                <i class="fas fa-inbox"></i>
                <p>No posts yet. Click "New Post" to get started.</p>
            </div>`;
        renderClassSnapshot();
        return;
    }

    const sorted = [...classPosts].sort((a, b) => b.id - a.id);
    feed.innerHTML = sorted.map((post) => buildPostCard(post)).join("");

    /* Attachment click opens the PDF modal (doesn't trigger card-level handlers) */
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

    /* Kebab toggle */
    feed.querySelectorAll(".post-kebab-btn").forEach((btn) => {
        btn.addEventListener("click", (e) => {
            e.stopPropagation();
            toggleKebabMenu(btn.dataset.postId);
        });
    });

    /* Edit */
    feed.querySelectorAll('.post-kebab-item[data-action="edit"]').forEach(
        (item) => {
            item.addEventListener("click", (e) => {
                e.stopPropagation();
                closeAllKebabMenus();
                openComposerForEdit(item.dataset.postId);
            });
        },
    );

    /* Delete */
    feed.querySelectorAll('.post-kebab-item[data-action="delete"]').forEach(
        (item) => {
            item.addEventListener("click", (e) => {
                e.stopPropagation();
                closeAllKebabMenus();
                requestDeletePost(item.dataset.postId);
            });
        },
    );

    renderClassSnapshot();
}

function buildPostCard(post) {
    const isAnnouncement = post.type === "announcement";
    const badgeClass = isAnnouncement ? "badge-announcement" : "badge-lesson";
    const badgeLabel = isAnnouncement ? "Announcement" : "Lesson";
    const quarterClass = isAnnouncement
        ? "badge-quarter-green"
        : "badge-quarter-orange";

    const authorPhotoHtml = `<div class="post-author-initials">${CLASS_INFO.teacherInitials}</div>`;

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
                    <div class="post-kebab-wrap">
                        <button class="post-kebab-btn" type="button" data-post-id="${post.id}" aria-label="Post options" aria-haspopup="true">
                            <i class="fas fa-ellipsis-vertical"></i>
                        </button>
                        <div class="post-kebab-menu" id="kebabMenu-${post.id}">
                            <button class="post-kebab-item" type="button" data-action="edit" data-post-id="${post.id}">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button class="post-kebab-item danger" type="button" data-action="delete" data-post-id="${post.id}">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <h3 class="post-title">${escapeHtml(post.title)}</h3>
            ${bodyHtml}
        </article>
    `;
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

/* KEBAB MENU handling */
function toggleKebabMenu(postId) {
    const menu = document.getElementById(`kebabMenu-${postId}`);
    if (!menu) return;

    const isOpen = menu.classList.contains("open");
    closeAllKebabMenus();

    if (!isOpen) {
        menu.classList.add("open");
        openKebabPostId = postId;
    }
}

function closeAllKebabMenus() {
    document
        .querySelectorAll(".post-kebab-menu.open")
        .forEach((m) => m.classList.remove("open"));
    openKebabPostId = null;
}

/* PDF MODAL — real attachment, served by ClassPostController::attachment() */
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
        const viewUrl = `/professor/classes/${CLASS_ID}/posts/${postId}/attachment`;
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

/* POST COMPOSER — Create & Edit*/
function openComposerForCreate() {
    composerMode = "create";
    composerEditingId = null;
    composerType = "announcement";
    composerPickedFileName = null;
    composerPickedFile = null;

    document.getElementById("pcModalTitle").textContent = "Create post";
    document.getElementById("pcSaveBtnLabel").textContent = "Post";
    document.getElementById("pcTitle").value = "";
    document.getElementById("pcBody").value = "";
    document.getElementById("pcChecklist").value = "";
    document.getElementById("pcQuarter").value = "1st Quarter";
    document.getElementById("pcFileName").textContent = "No file chosen";
    document.getElementById("pcFileInput").value = "";
    hideFieldError("pcTitleError", "pcTitle");

    setComposerType("announcement");
    setTypeToggleEnabled(true);

    openComposerModal();
}

function openComposerForEdit(postId) {
    const post = classPosts.find((p) => String(p.id) === String(postId));
    if (!post) return;

    composerMode = "edit";
    composerEditingId = post.id;
    composerType = post.type;
    composerPickedFileName = post.attachment ? post.attachment.name : null;
    composerPickedFile = null; // no new file chosen yet — keep existing attachment unless replaced

    document.getElementById("pcModalTitle").textContent = "Edit post";
    document.getElementById("pcSaveBtnLabel").textContent = "Save changes";
    document.getElementById("pcTitle").value = post.title || "";
    document.getElementById("pcBody").value = post.body || "";
    document.getElementById("pcChecklist").value = (post.checklist || []).join(
        "\n",
    );
    document.getElementById("pcQuarter").value = post.quarter || "1st Quarter";
    document.getElementById("pcFileName").textContent = post.attachment
        ? post.attachment.name
        : "No file chosen";
    document.getElementById("pcFileInput").value = "";
    hideFieldError("pcTitleError", "pcTitle");

    setComposerType(post.type);
    /* Can't change post type once created — keeps checklist/attachment data consistent */
    setTypeToggleEnabled(false);

    openComposerModal();
}

function openComposerModal() {
    const modal = document.getElementById("postComposerModal");
    if (!modal) return;
    modal.classList.add("open");
    document.body.style.overflow = "hidden";
    setTimeout(() => document.getElementById("pcTitle")?.focus(), 50);
}

function closeComposerModal() {
    const modal = document.getElementById("postComposerModal");
    if (!modal) return;
    modal.classList.remove("open");
    document.body.style.overflow = "";
}

function setComposerType(type) {
    composerType = type;

    const annBtn = document.getElementById("pcTypeAnnouncement");
    const lesBtn = document.getElementById("pcTypeLesson");
    const checklistField = document.getElementById("pcChecklistField");
    const attachmentField = document.getElementById("pcAttachmentField");

    annBtn.classList.toggle("active", type === "announcement");
    lesBtn.classList.toggle("active", type === "lesson");

    checklistField.classList.toggle("hidden", type !== "announcement");
    attachmentField.classList.toggle("hidden", type !== "lesson");
}

function setTypeToggleEnabled(enabled) {
    document.getElementById("pcTypeAnnouncement").disabled = !enabled;
    document.getElementById("pcTypeLesson").disabled = !enabled;
}

function showFieldError(errorId, inputId) {
    document.getElementById(errorId)?.classList.remove("hidden");
    document.getElementById(inputId)?.classList.add("pc-input-error");
}

function hideFieldError(errorId, inputId) {
    document.getElementById(errorId)?.classList.add("hidden");
    document.getElementById(inputId)?.classList.remove("pc-input-error");
}

async function saveComposerPost() {
    if (!CLASS_ID) return;

    const titleInput = document.getElementById("pcTitle");
    const title = titleInput.value.trim();

    if (!title) {
        showFieldError("pcTitleError", "pcTitle");
        titleInput.focus();
        return;
    }
    hideFieldError("pcTitleError", "pcTitle");

    const quarter = document.getElementById("pcQuarter").value;
    const body = document.getElementById("pcBody").value.trim();

    const checklistRaw = document.getElementById("pcChecklist").value;
    const checklist = checklistRaw
        .split("\n")
        .map((s) => s.trim())
        .filter(Boolean);

    const formData = new FormData();
    formData.append("type", composerType);
    formData.append("quarter", quarter);
    formData.append("title", title);
    formData.append("body", body);
    if (composerType === "announcement") {
        checklist.forEach((item) => formData.append("checklist[]", item));
    }
    if (composerPickedFile) {
        formData.append("attachment", composerPickedFile);
    }

    const isEdit = composerMode === "edit";
    const url = isEdit
        ? `/professor/classes/${CLASS_ID}/posts/${composerEditingId}`
        : `/professor/classes/${CLASS_ID}/posts`;
    if (isEdit) formData.append("_method", "PUT");

    const saveBtn = document.getElementById("pcSaveBtn");
    if (saveBtn) saveBtn.disabled = true;

    try {
        const res = await fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
            body: formData,
        });

        if (!res.ok) throw new Error("failed to save post");

        await loadPosts();
        showToast(isEdit ? "Post updated" : "Post published to your class");
        closeComposerModal();
    } catch (e) {
        showToast("Could not save the post. Please try again.");
    } finally {
        if (saveBtn) saveBtn.disabled = false;
    }
}

/* DELETE POST*/
function requestDeletePost(postId) {
    const post = classPosts.find((p) => String(p.id) === String(postId));
    if (!post) return;

    pendingDeleteId = post.id;
    document.getElementById("deletePostTitle").textContent = post.title;

    const modal = document.getElementById("deletePostModal");
    modal.classList.add("open");
    document.body.style.overflow = "hidden";
}

function closeDeleteModal() {
    const modal = document.getElementById("deletePostModal");
    modal.classList.remove("open");
    document.body.style.overflow = "";
    pendingDeleteId = null;
}

async function confirmDeletePost() {
    if (pendingDeleteId === null || !CLASS_ID) return;

    const postId = pendingDeleteId;
    closeDeleteModal();

    try {
        const res = await fetch(`/professor/classes/${CLASS_ID}/posts/${postId}`, {
            method: "DELETE",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
        });

        if (!res.ok) throw new Error("failed to delete post");

        await loadPosts();
        showToast("Post deleted");
    } catch (e) {
        showToast("Could not delete the post. Please try again.");
    }
}

/* INVITE CODE — copy & regenerate */
async function copyInviteCode() {
    if (!CLASS_INFO || !CLASS_INFO.code) return;

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(CLASS_INFO.code);
        } else {
            const textarea = document.createElement("textarea");
            textarea.value = CLASS_INFO.code;
            textarea.style.position = "fixed";
            textarea.style.opacity = "0";
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand("copy");
            textarea.remove();
        }
        showToast("Invite code copied!");
    } catch (e) {
        showToast("Could not copy the code — please copy it manually.");
    }
}

async function regenerateInviteCode() {
    if (!CLASS_ID) return;

    const btn = document.getElementById("regenerateCodeBtn");
    if (btn) btn.disabled = true;

    try {
        const res = await fetch(`/professor/classes/${CLASS_ID}/regenerate-code`, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
        });

        if (!res.ok) throw new Error("failed to regenerate code");

        const data = await res.json();
        CLASS_INFO.code = data.code;
        renderInviteCode();
        showToast("New invite code generated — the old one no longer works.");
    } catch (e) {
        showToast("Could not generate a new code. Please try again.");
    } finally {
        if (btn) btn.disabled = false;
    }
}

/* TOAST helper (mirrors navigation.js showToast if present, with a safe fallback) */
function showToast(message) {
    if (
        typeof window.showToast === "function" &&
        window.showToast !== showToast
    ) {
        window.showToast(message);
        return;
    }

    const toast = document.getElementById("toast");
    const toastMessage = document.getElementById("toastMessage");
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add("show");

    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => toast.classList.remove("show"), 2600);
}

/* INIT */
document.addEventListener("DOMContentLoaded", () => {
    loadClassInfo();

    /* The header's "Create Class" button belongs to the shared shell, not
       this page. The side-panel quick post button opens the announcement/
       lesson composer below, now backed by the database. */
    const quickPostBtn = document.getElementById("quickPostBtn");
    if (quickPostBtn)
        quickPostBtn.addEventListener("click", openComposerForCreate);

    /* Invite code card */
    document
        .getElementById("copyInviteCodeBtn")
        ?.addEventListener("click", copyInviteCode);
    document
        .getElementById("regenerateCodeBtn")
        ?.addEventListener("click", regenerateInviteCode);

    /* Composer type toggle */
    document
        .getElementById("pcTypeAnnouncement")
        ?.addEventListener("click", () => setComposerType("announcement"));
    document
        .getElementById("pcTypeLesson")
        ?.addEventListener("click", () => setComposerType("lesson"));

    /* Composer close/cancel/save */
    document
        .getElementById("pcCloseBtn")
        ?.addEventListener("click", closeComposerModal);
    document
        .getElementById("pcCancelBtn")
        ?.addEventListener("click", closeComposerModal);
    document
        .getElementById("pcSaveBtn")
        ?.addEventListener("click", saveComposerPost);

    document
        .getElementById("postComposerModal")
        ?.addEventListener("click", (e) => {
            if (e.target.id === "postComposerModal") closeComposerModal();
        });

    /* File picker (lesson attachment) */
    const pcFilePickBtn = document.getElementById("pcFilePickBtn");
    const pcFileInput = document.getElementById("pcFileInput");
    if (pcFilePickBtn && pcFileInput) {
        pcFilePickBtn.addEventListener("click", () => pcFileInput.click());
        pcFileInput.addEventListener("change", () => {
            const file = pcFileInput.files && pcFileInput.files[0];
            if (file) {
                composerPickedFile = file;
                composerPickedFileName = file.name;
                document.getElementById("pcFileName").textContent = file.name;
            }
        });
    }

    /* Title field — clear error as soon as the user types */
    document.getElementById("pcTitle")?.addEventListener("input", () => {
        hideFieldError("pcTitleError", "pcTitle");
    });

    /* Delete modal */
    document
        .getElementById("deleteCancelBtn")
        ?.addEventListener("click", closeDeleteModal);
    document
        .getElementById("deleteConfirmBtn")
        ?.addEventListener("click", confirmDeletePost);
    document
        .getElementById("deletePostModal")
        ?.addEventListener("click", (e) => {
            if (e.target.id === "deletePostModal") closeDeleteModal();
        });

    /* PDF Modal close handlers */
    document
        .getElementById("pdfModalClose")
        ?.addEventListener("click", closePdfModal);
    document
        .getElementById("pdfModalBackdrop")
        ?.addEventListener("click", closePdfModal);

    /* Close kebab menus when clicking anywhere else */
    document.addEventListener("click", () => {
        if (openKebabPostId !== null) closeAllKebabMenus();
    });

    /* Keyboard: Escape closes whichever modal/menu is open */
    document.addEventListener("keydown", (e) => {
        if (e.key !== "Escape") return;
        closePdfModal();
        closeComposerModal();
        closeDeleteModal();
        closeAllKebabMenus();
    });
});
