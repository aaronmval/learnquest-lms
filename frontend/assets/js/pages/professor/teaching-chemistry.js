const CLASS_INFO = window.CLASS_INFO || {
    subject: "CHEMISTRY",
    section: "STEM - AMETHYST",
    teacher: "Mila D. Valiente",
    teacherPhoto: "../../assets/images/teachers/chemistry.jpg",
    teacherInitials: "MV",
    gradient: "linear-gradient(135deg, #06b6d4, #0891b2)",
};

/* Seed data — same starting posts as the student view, now editable */
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
        edited: false,
    },
    {
        id: 2,
        type: "lesson",
        quarter: "1st Quarter",
        author: CLASS_INFO.teacher,
        date: "Jan 5, 2026",
        title: "Material for this Week 2",
        body: "",
        attachment: { name: "Lesson 1.pdf", url: "#" },
        edited: false,
    },
    {
        id: 3,
        type: "lesson",
        quarter: "1st Quarter",
        author: CLASS_INFO.teacher,
        date: "Jan 12, 2026",
        title: "Material for Week 3",
        body: "",
        attachment: { name: "Lesson 2.pdf", url: "#" },
        edited: false,
    },
];

let nextPostId = 4;

/* Composer state — tracks whether we're creating or editing, and which post */
let composerMode = "create"; // 'create' | 'edit'
let composerEditingId = null;
let composerType = "announcement"; // 'announcement' | 'lesson'
let composerPickedFileName = null;

/* Which post's kebab menu is currently open (for outside-click closing) */
let openKebabPostId = null;

/* Pending delete target */
let pendingDeleteId = null;

/* RENDER — class banner */
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
}

/* RENDER — class snapshot side panel */
function renderClassSnapshot() {
    const postsEl = document.getElementById("snapshotPosts");
    const lastActivityEl = document.getElementById("snapshotLastActivity");
    if (!postsEl || !lastActivityEl) return;

    postsEl.textContent = classPosts.length;

    if (classPosts.length) {
        const sorted = [...classPosts].sort((a, b) => b.id - a.id);
        lastActivityEl.textContent = sorted[0].date;
    } else {
        lastActivityEl.textContent = "—";
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

    const authorPhotoHtml = CLASS_INFO.teacherPhoto
        ? `<img class="post-author-photo" src="${CLASS_INFO.teacherPhoto}" alt="${escapeHtml(post.author)}"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
           <div class="post-author-initials" style="display:none;">${CLASS_INFO.teacherInitials}</div>`
        : `<div class="post-author-initials">${CLASS_INFO.teacherInitials}</div>`;

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
                 data-url="${escapeHtml(post.attachment.url || "#")}">
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

/* PDF MODAL (read-only preview, same behavior as student view) */
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

/* POST COMPOSER — Create & Edit*/
function openComposerForCreate() {
    composerMode = "create";
    composerEditingId = null;
    composerType = "announcement";
    composerPickedFileName = null;

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

function todayFormatted() {
    return new Date().toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
}

function saveComposerPost() {
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

    let attachment = null;
    if (composerType === "lesson" && composerPickedFileName) {
        attachment = { name: composerPickedFileName, url: "#" };
    }

    if (composerMode === "create") {
        const newPost = {
            id: nextPostId++,
            type: composerType,
            quarter,
            author: CLASS_INFO.teacher,
            date: todayFormatted(),
            title,
            body,
            edited: false,
        };
        if (composerType === "announcement") newPost.checklist = checklist;
        if (composerType === "lesson") newPost.attachment = attachment;

        classPosts.push(newPost);
        showToast("Post published to your class");
    } else {
        const post = classPosts.find((p) => p.id === composerEditingId);
        if (!post) return;

        post.title = title;
        post.quarter = quarter;
        post.body = body;
        post.edited = true;

        if (post.type === "announcement") {
            post.checklist = checklist;
        } else if (post.type === "lesson") {
            post.attachment = attachment || post.attachment;
        }

        showToast("Post updated");
    }

    closeComposerModal();
    renderFeed();
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

function confirmDeletePost() {
    if (pendingDeleteId === null) return;

    classPosts = classPosts.filter((p) => p.id !== pendingDeleteId);
    showToast("Post deleted");

    closeDeleteModal();
    renderFeed();
}

/* CREATE CLASS MODAL */
function wireCreateClassModal() {
    const createBtn = document.getElementById("createClassBtn");
    const modal = document.getElementById("createClassModal");
    const modalCard = document.getElementById("createClassModalCard");
    const closeBtn = document.getElementById("createClassCloseBtn");
    const cancelBtn = document.getElementById("createClassCancelBtn");
    const confirmBtn = document.getElementById("createClassConfirmBtn");

    const nameInput = document.getElementById("ccClassName");
    const sectionInput = document.getElementById("ccSection");
    const subjectInput = document.getElementById("ccSubject");
    const roomInput = document.getElementById("ccRoom");
    const nameError = document.getElementById("ccNameError");

    if (!createBtn || !modal) return;

    function resetForm() {
        nameInput.value = "";
        sectionInput.value = "";
        subjectInput.value = "";
        roomInput.value = "";
        nameError.classList.add("hidden");
        nameInput.classList.remove("error");
    }

    function openModal() {
        resetForm();
        modal.style.display = "flex";
        setTimeout(() => {
            modal.style.opacity = "1";
            modalCard.classList.add("scaled");
            nameInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.style.opacity = "0";
        modalCard.classList.remove("scaled");
        setTimeout(() => {
            modal.style.display = "none";
        }, 300);
    }

    function confirmCreate() {
        const className = nameInput.value.trim();

        if (!className) {
            nameError.textContent = "*Required";
            nameError.classList.remove("hidden");
            nameInput.classList.add("error");
            nameInput.focus();
            return;
        }

        nameError.classList.add("hidden");
        nameInput.classList.remove("error");

        closeModal();
        showToast(`Class "${className}" created!`);
    }

    createBtn.addEventListener("click", openModal);
    if (closeBtn) closeBtn.addEventListener("click", closeModal);
    if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
    if (confirmBtn) confirmBtn.addEventListener("click", confirmCreate);

    modal.addEventListener("click", (e) => {
        if (e.target === modal) closeModal();
    });

    [nameInput, sectionInput, subjectInput, roomInput].forEach((field) => {
        field.addEventListener("keydown", (e) => {
            if (e.key === "Enter") confirmCreate();
        });
    });

    nameInput.addEventListener("input", () => {
        nameError.classList.add("hidden");
        nameInput.classList.remove("error");
    });
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
    renderClassBanner();
    renderFeed();
    wireCreateClassModal();

    /* Header "Create Class" button now opens the Create Class modal
       (wired above via wireCreateClassModal). The side-panel quick
       post button still opens the announcement/lesson composer. */
    const quickPostBtn = document.getElementById("quickPostBtn");
    if (quickPostBtn)
        quickPostBtn.addEventListener("click", openComposerForCreate);

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
            composerPickedFileName = file ? file.name : composerPickedFileName;
            document.getElementById("pcFileName").textContent =
                composerPickedFileName || "No file chosen";
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
