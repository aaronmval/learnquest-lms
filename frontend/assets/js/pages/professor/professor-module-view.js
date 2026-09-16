let SUBJECT_ID = null;
let subjectData = null;
let currentSectionFilter = "";

let umMode = "create"; // 'create' | 'edit'
let umEditingId = null;
let umPickedFile = null;

let pendingConfirmAction = null;

function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
}

function initialsFor(name) {
    const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return "LQ";
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join("");
}

function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value === 0) return "0 KB";
    if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function formatDate(isoString) {
    if (!isoString) return "—";
    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
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

async function extractErrorMessage(res, fallback) {
    try {
        const data = await res.json();
        const firstFieldError = data?.errors
            ? Object.values(data.errors)[0]?.[0]
            : null;
        return firstFieldError || data?.message || fallback;
    } catch (e) {
        return fallback;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const subjectNotFound = document.getElementById("subjectNotFound");
    const subjectWrap = document.getElementById("subjectWrap");

    const mvSubjectName = document.getElementById("mvSubjectName");
    const mvOwnerRow = document.getElementById("mvOwnerRow");

    const sectionsStrip = document.getElementById("sectionsStrip");
    const sectionsEmpty = document.getElementById("sectionsEmpty");

    const sectionFilter = document.getElementById("sectionFilter");
    const moduleGrid = document.getElementById("moduleGrid");
    const modulesEmpty = document.getElementById("modulesEmpty");

    const toast = document.getElementById("modulesToast");
    const toastMessage = document.getElementById("modulesToastMessage");
    let toastTimer = null;

    function showToast(message) {
        if (!toast || !toastMessage) return;
        toastMessage.textContent = message;
        toast.classList.add("visible");
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove("visible"), 2800);
    }

    /* LOAD */
    function getSubjectIdFromQuery() {
        return new URLSearchParams(window.location.search).get("subject");
    }

    async function loadSubject() {
        const id = getSubjectIdFromQuery();
        if (!id) {
            showNotFound();
            return;
        }

        try {
            const res = await fetch(`/professor/subjects/${encodeURIComponent(id)}`, {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });

            if (!res.ok) {
                showNotFound();
                return;
            }

            subjectData = await res.json();
            SUBJECT_ID = subjectData.id;
            subjectWrap?.classList.remove("hidden");
            subjectNotFound?.classList.add("hidden");
            renderAll();
        } catch (e) {
            showNotFound();
        }
    }

    function showNotFound() {
        subjectNotFound?.classList.remove("hidden");
        subjectWrap?.classList.add("hidden");
    }

    function renderAll() {
        renderHeader();
        renderSections();
        renderSectionFilterOptions();
        renderModules();
    }

    /* HEADER */
    function renderHeader() {
        if (!subjectData) return;
        if (mvSubjectName) mvSubjectName.textContent = subjectData.name;

        if (mvOwnerRow) {
            const collabCount = subjectData.collaborators?.length || 0;
            const collabLabel = collabCount === 1 ? "collaborator" : "collaborators";
            mvOwnerRow.textContent = collabCount
                ? `Owned by ${subjectData.owner?.name || "Unknown"} · ${collabCount} ${collabLabel}`
                : `Owned by ${subjectData.owner?.name || "Unknown"}`;
        }
    }

    /* SECTIONS */
    function renderSections() {
        if (!sectionsStrip || !subjectData) return;

        const sections = subjectData.sections || [];
        sectionsStrip.innerHTML = sections
            .map((section) => {
                const studentsCount = section.students_count || 0;
                const studentsLabel = studentsCount === 1 ? "student" : "students";
                return `
                    <a class="mv-section-chip" href="professor-class.html?id=${section.id}">
                        <span class="mv-section-chip-name">${escapeHtml(section.section || section.name)}</span>
                        <span class="mv-section-chip-meta">
                            <span class="mv-section-chip-code">${escapeHtml(section.code || "")}</span>
                            <span>${studentsCount} ${studentsLabel}</span>
                        </span>
                    </a>`;
            })
            .join("");

        sectionsEmpty?.classList.toggle("hidden", sections.length > 0);
    }

    function renderSectionFilterOptions() {
        if (!sectionFilter || !subjectData) return;

        const sections = subjectData.sections || [];
        const previous = sectionFilter.value;

        sectionFilter.innerHTML = '<option value="">All sections</option>' +
            sections
                .map(
                    (s) =>
                        `<option value="${s.id}">${escapeHtml(s.section || s.name)}</option>`,
                )
                .join("");

        if (sections.some((s) => String(s.id) === previous)) {
            sectionFilter.value = previous;
            currentSectionFilter = previous;
        } else {
            sectionFilter.value = "";
            currentSectionFilter = "";
        }
    }

    sectionFilter?.addEventListener("change", () => {
        currentSectionFilter = sectionFilter.value;
        renderModules();
    });

    /* MODULES */
    function sectionLabelById(id) {
        const section = (subjectData?.sections || []).find(
            (s) => String(s.id) === String(id),
        );
        return section ? section.section || section.name : "Unknown section";
    }

    function renderModules() {
        if (!moduleGrid || !subjectData) return;

        const modules = (subjectData.modules || []).filter((m) => {
            if (!currentSectionFilter) return true;
            const targetIds = (m.target_sections || []).map((t) => String(t.id));
            return targetIds.length === 0 || targetIds.includes(currentSectionFilter);
        });

        moduleGrid.innerHTML = modules.map((m) => buildModuleCard(m)).join("");
        modulesEmpty?.classList.toggle("hidden", modules.length > 0);
    }

    function buildModuleCard(module) {
        const targetIds = module.target_sections || [];
        const tags = targetIds.length
            ? targetIds.map((t) => `<span class="mv-module-tag">${escapeHtml(sectionLabelById(t.id))}</span>`).join("")
            : '<span class="mv-module-tag">All sections</span>';

        return `
            <article class="mv-module-card" data-id="${module.id}">
                <div class="mv-module-icon-row">
                    <div class="mv-module-icon"><i class="fas fa-file-pdf"></i></div>
                    <h3 class="mv-module-title">${escapeHtml(module.title)}</h3>
                </div>
                ${module.description ? `<p class="mv-module-desc">${escapeHtml(module.description)}</p>` : ""}
                <div class="mv-module-meta">
                    <span>${escapeHtml(module.uploader?.name || "Unknown")}</span>
                    <span>${formatDate(module.created_at)}</span>
                    <span>${formatBytes(module.file_size)}</span>
                </div>
                <div class="mv-module-tags">${tags}</div>
                <div class="mv-module-actions">
                    <button class="mv-module-action-btn" data-action="preview" data-id="${module.id}">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                    <button class="mv-module-action-btn" data-action="edit" data-id="${module.id}">
                        <i class="fas fa-pen"></i> Edit
                    </button>
                    <button class="mv-module-action-btn mv-module-action-danger" data-action="delete" data-id="${module.id}">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </article>`;
    }

    moduleGrid?.addEventListener("click", (e) => {
        const btn = e.target.closest("button[data-action]");
        if (!btn) return;

        const module = (subjectData?.modules || []).find(
            (m) => String(m.id) === String(btn.dataset.id),
        );
        if (!module) return;

        if (btn.dataset.action === "preview") openPdfModal(module);
        else if (btn.dataset.action === "edit") openUploadModal("edit", module);
        else if (btn.dataset.action === "delete") {
            openConfirm(
                "Delete module?",
                `This will permanently remove "${module.title}".`,
                () => deleteModule(module.id),
            );
        }
    });

    async function deleteModule(moduleId) {
        try {
            const res = await fetch(
                `/professor/subjects/${SUBJECT_ID}/modules/${moduleId}`,
                {
                    method: "DELETE",
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json",
                        "X-XSRF-TOKEN": getCsrfToken(),
                    },
                },
            );

            if (!res.ok) throw new Error("failed to delete module");

            await loadSubject();
            showToast("Module deleted");
        } catch (e) {
            showToast("Could not delete the module. Please try again.");
        }
    }

    /* PDF PREVIEW MODAL */
    function openPdfModal(module) {
        const modal = document.getElementById("pdfModal");
        const title = document.getElementById("pdfModalTitle");
        const viewer = document.getElementById("pdfModalViewer");
        const dlBtn = document.getElementById("pdfModalDownload");
        if (!modal) return;

        title.textContent = module.file_name || module.title;

        const viewUrl = `/professor/subjects/${SUBJECT_ID}/modules/${module.id}/attachment`;
        viewer.src = viewUrl;
        viewer.classList.remove("pdf-viewer-initial-hidden");
        dlBtn.href = `${viewUrl}?download=1`;
        dlBtn.download = module.file_name || `${module.title}.pdf`;

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

    /* UPLOAD / EDIT MODULE MODAL */
    const uploadModuleModal = document.getElementById("uploadModuleModal");
    const uploadModuleModalCard = document.getElementById("uploadModuleModalCard");
    const umModalTitle = document.getElementById("umModalTitle");
    const umTitle = document.getElementById("umTitle");
    const umTitleError = document.getElementById("umTitleError");
    const umDescription = document.getElementById("umDescription");
    const umDropzone = document.getElementById("umDropzone");
    const umFileInput = document.getElementById("umFileInput");
    const umFileName = document.getElementById("umFileName");
    const umFileError = document.getElementById("umFileError");
    const umSectionChecks = document.getElementById("umSectionChecks");
    const umSubmitBtn = document.getElementById("umSubmitBtn");
    const umSubmitLabel = document.getElementById("umSubmitLabel");

    function openUploadModal(mode, module) {
        umMode = mode;
        umEditingId = module ? module.id : null;
        umPickedFile = null;

        umModalTitle.textContent = mode === "edit" ? "Edit Module" : "Upload Module";
        umSubmitLabel.textContent = mode === "edit" ? "Save changes" : "Upload";
        umTitle.value = module ? module.title : "";
        umDescription.value = module ? module.description || "" : "";
        umFileName.textContent = module
            ? `Current file: ${module.file_name} (choose a new PDF to replace it)`
            : "Drag & drop a PDF here, or click to browse";
        umFileInput.value = "";
        hideFieldError(umTitleError, umTitle);
        hideFieldError(umFileError, umDropzone);

        const checkedIds = (module?.target_sections || []).map((t) => String(t.id));
        umSectionChecks.innerHTML = (subjectData?.sections || [])
            .map(
                (s) => `
                <label class="mv-section-check-item">
                    <input type="checkbox" value="${s.id}" ${checkedIds.includes(String(s.id)) ? "checked" : ""} />
                    ${escapeHtml(s.section || s.name)}
                </label>`,
            )
            .join("");

        openModal(uploadModuleModal, uploadModuleModalCard);
    }

    function closeUploadModal() {
        closeModal(uploadModuleModal, uploadModuleModalCard);
    }

    umDropzone?.addEventListener("click", () => umFileInput.click());

    umDropzone?.addEventListener("dragover", (e) => {
        e.preventDefault();
        umDropzone.classList.add("dragover");
    });

    umDropzone?.addEventListener("dragleave", () => {
        umDropzone.classList.remove("dragover");
    });

    umDropzone?.addEventListener("drop", (e) => {
        e.preventDefault();
        umDropzone.classList.remove("dragover");
        const file = e.dataTransfer.files?.[0];
        if (file) setPickedFile(file);
    });

    umFileInput?.addEventListener("change", () => {
        const file = umFileInput.files?.[0];
        if (file) setPickedFile(file);
    });

    function setPickedFile(file) {
        if (file.type !== "application/pdf" && !file.name.toLowerCase().endsWith(".pdf")) {
            umFileError.textContent = "*Only PDF files are supported";
            umFileError.classList.remove("hidden");
            return;
        }
        umPickedFile = file;
        umFileName.textContent = file.name;
        hideFieldError(umFileError, umDropzone);
    }

    function hideFieldError(errorEl, inputEl) {
        errorEl?.classList.add("hidden");
        inputEl?.classList.remove("error");
    }

    umSubmitBtn?.addEventListener("click", async () => {
        const title = umTitle.value.trim();
        if (!title) {
            umTitleError.classList.remove("hidden");
            umTitle.classList.add("error");
            umTitle.focus();
            return;
        }
        hideFieldError(umTitleError, umTitle);

        if (umMode === "create" && !umPickedFile) {
            umFileError.textContent = "*A PDF file is required";
            umFileError.classList.remove("hidden");
            return;
        }
        hideFieldError(umFileError, umDropzone);

        const sectionIds = Array.from(
            umSectionChecks.querySelectorAll("input:checked"),
        ).map((el) => el.value);

        const formData = new FormData();
        formData.append("title", title);
        formData.append("description", umDescription.value.trim());
        sectionIds.forEach((id) => formData.append("section_ids[]", id));
        if (umPickedFile) formData.append("attachment", umPickedFile);

        const isEdit = umMode === "edit";
        const url = isEdit
            ? `/professor/subjects/${SUBJECT_ID}/modules/${umEditingId}`
            : `/professor/subjects/${SUBJECT_ID}/modules`;
        if (isEdit) formData.append("_method", "PUT");

        umSubmitBtn.disabled = true;

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

            if (!res.ok) {
                const message = await extractErrorMessage(res, "Could not save the module.");
                showToast(message);
                return;
            }

            await loadSubject();
            closeUploadModal();
            showToast(isEdit ? "Module updated" : "Module uploaded");
        } catch (e) {
            showToast("Could not save the module. Please try again.");
        } finally {
            umSubmitBtn.disabled = false;
        }
    });

    document.getElementById("uploadModuleBtn")?.addEventListener("click", () => {
        openUploadModal("create", null);
    });
    document.getElementById("umCloseBtn")?.addEventListener("click", closeUploadModal);
    document.getElementById("umCancelBtn")?.addEventListener("click", closeUploadModal);
    uploadModuleModal?.addEventListener("click", (e) => {
        if (e.target === uploadModuleModal) closeUploadModal();
    });

    /* INVITE COLLABORATOR MODAL */
    const inviteCollabModal = document.getElementById("inviteCollabModal");
    const inviteCollabModalCard = document.getElementById("inviteCollabModalCard");
    const icEmail = document.getElementById("icEmail");
    const icEmailError = document.getElementById("icEmailError");
    const collabList = document.getElementById("collabList");
    const inviteCollabConfirmBtn = document.getElementById("inviteCollabConfirmBtn");

    function renderCollabList() {
        if (!collabList) return;
        const collaborators = subjectData?.collaborators || [];

        collabList.innerHTML = collaborators.length
            ? collaborators
                  .map(
                      (c) => `
                <div class="mv-collab-item" data-id="${c.id}">
                    <div>
                        <div class="mv-collab-item-name">${escapeHtml(c.name)}</div>
                        <div class="mv-collab-item-email">${escapeHtml(c.email)}</div>
                    </div>
                    <button class="mv-collab-remove-btn" data-id="${c.id}" aria-label="Remove ${escapeHtml(c.name)}">
                        <i class="fas fa-user-minus"></i>
                    </button>
                </div>`,
                  )
                  .join("")
            : '<p class="mat-field-hint">No collaborators yet.</p>';
    }

    collabList?.addEventListener("click", (e) => {
        const btn = e.target.closest(".mv-collab-remove-btn");
        if (!btn) return;

        const collaborator = (subjectData?.collaborators || []).find(
            (c) => String(c.id) === String(btn.dataset.id),
        );
        if (!collaborator) return;

        openConfirm(
            "Remove collaborator?",
            `${collaborator.name} will lose access to this subject's sections and materials.`,
            () => removeCollaborator(collaborator.id),
        );
    });

    async function removeCollaborator(userId) {
        try {
            const res = await fetch(
                `/professor/subjects/${SUBJECT_ID}/collaborators/${userId}`,
                {
                    method: "DELETE",
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json",
                        "X-XSRF-TOKEN": getCsrfToken(),
                    },
                },
            );

            if (!res.ok) throw new Error("failed to remove collaborator");

            await loadSubject();
            renderCollabList();
            showToast("Collaborator removed");
        } catch (e) {
            showToast("Could not remove that collaborator. Please try again.");
        }
    }

    function openInviteModal() {
        icEmail.value = "";
        hideFieldError(icEmailError, icEmail);
        renderCollabList();
        openModal(inviteCollabModal, inviteCollabModalCard);
    }

    function closeInviteModal() {
        closeModal(inviteCollabModal, inviteCollabModalCard);
    }

    inviteCollabConfirmBtn?.addEventListener("click", async () => {
        const email = icEmail.value.trim();
        if (!email) {
            icEmailError.textContent = "*Enter a valid email";
            icEmailError.classList.remove("hidden");
            icEmail.focus();
            return;
        }
        hideFieldError(icEmailError, icEmail);

        inviteCollabConfirmBtn.disabled = true;

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/collaborators`, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                body: JSON.stringify({ email }),
            });

            if (!res.ok) {
                const message = await extractErrorMessage(res, "Could not invite that collaborator.");
                icEmailError.textContent = `*${message}`;
                icEmailError.classList.remove("hidden");
                return;
            }

            await loadSubject();
            renderCollabList();
            icEmail.value = "";
            showToast("Collaborator added");
        } catch (e) {
            showToast("Could not invite that collaborator. Please try again.");
        } finally {
            inviteCollabConfirmBtn.disabled = false;
        }
    });

    document.getElementById("inviteCollabBtn")?.addEventListener("click", openInviteModal);
    document.getElementById("inviteCollabCloseBtn")?.addEventListener("click", closeInviteModal);
    document.getElementById("inviteCollabCancelBtn")?.addEventListener("click", closeInviteModal);
    inviteCollabModal?.addEventListener("click", (e) => {
        if (e.target === inviteCollabModal) closeInviteModal();
    });

    /* ADD SECTION MODAL */
    const addSectionModal = document.getElementById("addSectionModal");
    const addSectionModalCard = document.getElementById("addSectionModalCard");
    const asecSection = document.getElementById("asecSection");
    const asecSectionError = document.getElementById("asecSectionError");
    const asecRoom = document.getElementById("asecRoom");
    const addSectionConfirmBtn = document.getElementById("addSectionConfirmBtn");

    function openAddSectionModal() {
        asecSection.value = "";
        asecRoom.value = "";
        hideFieldError(asecSectionError, asecSection);
        openModal(addSectionModal, addSectionModalCard);
    }

    function closeAddSectionModal() {
        closeModal(addSectionModal, addSectionModalCard);
    }

    addSectionConfirmBtn?.addEventListener("click", async () => {
        const section = asecSection.value.trim();
        if (!section) {
            asecSectionError.classList.remove("hidden");
            asecSection.classList.add("error");
            asecSection.focus();
            return;
        }
        hideFieldError(asecSectionError, asecSection);

        addSectionConfirmBtn.disabled = true;

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/sections`, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                body: JSON.stringify({
                    name: `${subjectData.name} - ${section}`,
                    section,
                    room: asecRoom.value.trim() || null,
                }),
            });

            if (!res.ok) {
                const message = await extractErrorMessage(res, "Could not create the section.");
                showToast(message);
                return;
            }

            const created = await res.json();
            await loadSubject();
            closeAddSectionModal();
            showToast(`Section added. Invite code: ${created.code}`);
        } catch (e) {
            showToast("Could not create the section. Please try again.");
        } finally {
            addSectionConfirmBtn.disabled = false;
        }
    });

    document.getElementById("addSectionBtn")?.addEventListener("click", openAddSectionModal);
    document.getElementById("addSectionCloseBtn")?.addEventListener("click", closeAddSectionModal);
    document.getElementById("addSectionCancelBtn")?.addEventListener("click", closeAddSectionModal);
    addSectionModal?.addEventListener("click", (e) => {
        if (e.target === addSectionModal) closeAddSectionModal();
    });

    /* GENERIC CONFIRM MODAL */
    const confirmActionModal = document.getElementById("confirmActionModal");
    const confirmActionTitle = document.getElementById("confirmActionTitle");
    const confirmActionDesc = document.getElementById("confirmActionDesc");
    const confirmActionConfirmBtn = document.getElementById("confirmActionConfirmBtn");

    function openConfirm(title, desc, onConfirm) {
        confirmActionTitle.textContent = title;
        confirmActionDesc.textContent = desc;
        pendingConfirmAction = onConfirm;
        confirmActionModal?.classList.add("visible");
    }

    function closeConfirm() {
        confirmActionModal?.classList.remove("visible");
        pendingConfirmAction = null;
    }

    confirmActionConfirmBtn?.addEventListener("click", () => {
        const action = pendingConfirmAction;
        closeConfirm();
        action?.();
    });
    document.getElementById("confirmActionCancelBtn")?.addEventListener("click", closeConfirm);
    confirmActionModal?.addEventListener("click", (e) => {
        if (e.target === confirmActionModal) closeConfirm();
    });

    /* GENERIC MODAL HELPERS (cc-card scale-in pattern shared with professor-modules.js) */
    function openModal(overlay, card) {
        if (!overlay) return;
        overlay.classList.add("visible");
        requestAnimationFrame(() => card?.classList.add("scaled"));
    }

    function closeModal(overlay, card) {
        if (!overlay) return;
        card?.classList.remove("scaled");
        overlay.classList.remove("visible");
    }

    /* ESCAPE closes whichever modal is open */
    document.addEventListener("keydown", (e) => {
        if (e.key !== "Escape") return;
        closePdfModal();
        closeUploadModal();
        closeInviteModal();
        closeAddSectionModal();
        closeConfirm();
    });

    loadSubject();
});
