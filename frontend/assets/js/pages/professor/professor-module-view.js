let SUBJECT_ID = null;
let subjectData = null;
let currentSectionFilter = "";

let umMode = "create"; // 'create' | 'edit'
let umEditingId = null;
let umPickedFile = null;
let umBulkItems = []; // create mode: [{ file, title, quarter, quarterTouched, error }]
let umLastAutoTitle = "";

const MAX_MODULE_BYTES = 10 * 1024 * 1024; // matches StoreModuleRequest (max:10240 KB)
const QUARTER_LABELS = ["1st Quarter", "2nd Quarter", "3rd Quarter", "4th Quarter"];
const QUARTER_WORDS = { first: 1, second: 2, third: 3, fourth: 4 };
const QUARTER_PATTERN =
    /\b(?:q(?:uarter|tr)?\s*([1-4])|([1-4])(?:st|nd|rd|th)?\s*q(?:uarter|tr)?|(first|second|third|fourth)\s+q(?:uarter|tr))\b/i;
const TITLE_SMALL_WORDS = ["a", "an", "and", "for", "in", "of", "on", "the", "to"];

// "Q2_module3-chemical_bonding.pdf" -> "Q2 module3 chemical bonding"
function normalizeFileBase(fileName) {
    return String(fileName || "")
        .replace(/\.pdf$/i, "")
        .replace(/[_\-]+/g, " ")
        .replace(/(?<!\d)\.|\.(?!\d)/g, " ")
        .replace(/\s+/g, " ")
        .trim();
}

// Quarter named in the file name ("Q2", "2nd Quarter", "Quarter 2"), or null.
function quarterFromFileName(fileName) {
    const match = normalizeFileBase(fileName).match(QUARTER_PATTERN);
    if (!match) return null;
    const number = match[1] || match[2] || QUARTER_WORDS[match[3].toLowerCase()];
    return QUARTER_LABELS[Number(number) - 1];
}

// Readable module title from the file name, minus any quarter marker.
function titleFromFileName(fileName) {
    const base = normalizeFileBase(fileName);
    const withoutQuarter = base.replace(QUARTER_PATTERN, " ").replace(/\s+/g, " ").trim();

    const title = (withoutQuarter || base)
        .replace(/([A-Za-z]{3,})(\d)/g, "$1 $2")
        .split(" ")
        .map((word, index) => {
            if (word !== word.toLowerCase()) return word;
            if (index > 0 && TITLE_SMALL_WORDS.includes(word)) return word;
            return word.charAt(0).toUpperCase() + word.slice(1);
        })
        .join(" ");

    return title.slice(0, 150) || "Untitled Module";
}

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

            const competenciesLink = document.getElementById("manageCompetenciesLink");
            if (competenciesLink) {
                competenciesLink.href = `professor-competencies.html?subject=${SUBJECT_ID}`;
            }

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

        // The server sends only the sections this professor owns.
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
    /* This professor's own classes the module is posted to (co-teachers'
       sections of a shared subject are theirs to see, not ours). */
    function ownTargets(module) {
        const ownIds = (subjectData?.targetable_sections || []).map((s) => String(s.id));
        return (module.target_sections || []).filter((t) => ownIds.includes(String(t.id)));
    }

    function findModule(id) {
        return (subjectData?.modules || []).find((m) => String(m.id) === String(id)) || null;
    }

    function renderModules() {
        if (!moduleGrid || !subjectData) return;

        const modules = (subjectData.modules || []).filter((m) => {
            if (!currentSectionFilter) return true;
            const targetIds = (m.target_sections || []).map((t) => String(t.id));
            return targetIds.includes(currentSectionFilter);
        });

        // Natural title order, so "SLM 2" comes before "SLM 10".
        modules.sort(
            (a, b) =>
                String(a.title).localeCompare(String(b.title), undefined, {
                    numeric: true,
                    sensitivity: "base",
                }) || String(a.created_at).localeCompare(String(b.created_at)),
        );

        moduleGrid.innerHTML = modules.map((m) => buildModuleCard(m)).join("");
        modulesEmpty?.classList.toggle("hidden", modules.length > 0);
    }

    function buildModuleCard(module) {
        const targets = ownTargets(module);
        const tags = targets.length
            ? targets.map((t) => `<span class="mv-module-tag">${escapeHtml(t.section || t.name)}</span>`).join("")
            : '<span class="mv-module-tag mv-module-tag-muted">Not posted to your classes</span>';

        return `
            <article class="mv-module-card" data-id="${module.id}" tabindex="0" title="Double-click to preview">
                <div class="mv-module-icon-row">
                    <div class="mv-module-icon"><i class="fas fa-file-pdf"></i></div>
                    <h3 class="mv-module-title">${escapeHtml(module.title)}</h3>
                    <div class="post-kebab-wrap mv-module-kebab">
                        <button class="post-kebab-btn" type="button" aria-label="${escapeHtml(module.title)} options" aria-haspopup="true">
                            <i class="fas fa-ellipsis-vertical"></i>
                        </button>
                        <div class="post-kebab-menu">
                            <button class="post-kebab-item" type="button" data-action="preview" data-id="${module.id}">
                                <i class="fas fa-eye"></i> Preview
                            </button>
                            ${
                                module.can_edit
                                    ? `<button class="post-kebab-item" type="button" data-action="edit" data-id="${module.id}">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button class="post-kebab-item danger" type="button" data-action="delete" data-id="${module.id}">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>`
                                    : `<button class="post-kebab-item" type="button" data-action="request-edit" data-id="${module.id}">
                                <i class="fas fa-pen-to-square"></i> Request edit
                            </button>
                            <button class="post-kebab-item danger" type="button" data-action="request-delete" data-id="${module.id}">
                                <i class="fas fa-trash-can"></i> Request delete
                            </button>`
                            }
                        </div>
                    </div>
                </div>
                ${module.description ? `<p class="mv-module-desc">${escapeHtml(module.description)}</p>` : ""}
                <div class="mv-module-meta">
                    <span>${escapeHtml(module.uploader?.name || "Unknown")}</span>
                    <span>${formatDate(module.created_at)}</span>
                    <span>${formatBytes(module.file_size)}</span>
                    ${module.quarter ? `<span>${escapeHtml(module.quarter)}</span>` : ""}
                </div>
                <div class="mv-module-tags">${tags}</div>
                <div class="mv-module-actions">
                    <button class="mv-module-action-btn mv-module-post-btn" type="button" data-action="post" data-id="${module.id}">
                        <i class="fas fa-paper-plane"></i> Post to classes
                    </button>
                </div>
            </article>`;
    }

    function closeModuleMenus() {
        moduleGrid?.querySelectorAll(".post-kebab-menu.open").forEach((m) => m.classList.remove("open"));
    }

    function selectModuleCard(card) {
        moduleGrid?.querySelectorAll(".mv-module-card.selected").forEach((c) => {
            if (c !== card) c.classList.remove("selected");
        });
        card?.classList.add("selected");
    }

    moduleGrid?.addEventListener("click", (e) => {
        const kebabBtn = e.target.closest(".mv-module-kebab .post-kebab-btn");
        if (kebabBtn) {
            const menu = kebabBtn.nextElementSibling;
            const wasOpen = menu.classList.contains("open");
            closeModuleMenus();
            menu.classList.toggle("open", !wasOpen);
            return;
        }

        const btn = e.target.closest("button[data-action]");
        if (!btn) {
            // A plain click on the card selects it.
            closeModuleMenus();
            selectModuleCard(e.target.closest(".mv-module-card"));
            return;
        }

        closeModuleMenus();
        const module = findModule(btn.dataset.id);
        if (!module) return;

        if (btn.dataset.action === "preview") openPdfModal(module);
        else if (btn.dataset.action === "edit") openUploadModal("edit", module);
        else if (btn.dataset.action === "post") openPostModal(module);
        else if (btn.dataset.action === "request-edit") openRequestModal(module, "edit");
        else if (btn.dataset.action === "request-delete") openRequestModal(module, "delete");
        else if (btn.dataset.action === "delete") {
            openConfirm(
                "Delete module?",
                `This will permanently remove "${module.title}".`,
                () => deleteModule(module.id),
            );
        }
    });

    /* Double-click (or Enter on a focused card) opens the preview. */
    moduleGrid?.addEventListener("dblclick", (e) => {
        if (e.target.closest("button")) return;
        const module = findModule(e.target.closest(".mv-module-card")?.dataset.id);
        if (module) openPdfModal(module);
    });

    moduleGrid?.addEventListener("keydown", (e) => {
        if (e.key !== "Enter" || !e.target.classList.contains("mv-module-card")) return;
        const module = findModule(e.target.dataset.id);
        if (module) openPdfModal(module);
    });

    /* Clicking anywhere outside the cards closes the menus and clears the selection. */
    document.addEventListener("click", (e) => {
        if (e.target.closest(".mv-module-card")) return;
        closeModuleMenus();
        selectModuleCard(null);
    });

    /* POST TO CLASSES MODAL — choose which of your classes get this module */
    const postModuleModal = document.getElementById("postModuleModal");
    const postModuleModalCard = document.getElementById("postModuleModalCard");
    const pmModuleTitle = document.getElementById("pmModuleTitle");
    const pmSectionChecks = document.getElementById("pmSectionChecks");
    const pmEmpty = document.getElementById("pmEmpty");
    const pmConfirmBtn = document.getElementById("pmConfirmBtn");
    let pmModuleId = null;

    function openPostModal(module) {
        pmModuleId = module.id;
        pmModuleTitle.textContent = module.title;

        const options = subjectData?.targetable_sections || [];
        const checkedIds = ownTargets(module).map((t) => String(t.id));
        pmSectionChecks.innerHTML = options
            .map(
                (c) => `
                <label class="mv-section-check-item">
                    <input type="checkbox" value="${c.id}" ${checkedIds.includes(String(c.id)) ? "checked" : ""} />
                    ${escapeHtml(c.section || c.name)}
                </label>`,
            )
            .join("");

        pmEmpty.classList.toggle("hidden", options.length > 0);
        pmConfirmBtn.disabled = options.length === 0;

        openModal(postModuleModal, postModuleModalCard);
    }

    function closePostModal() {
        closeModal(postModuleModal, postModuleModalCard);
        pmModuleId = null;
    }

    pmConfirmBtn?.addEventListener("click", async () => {
        const module = findModule(pmModuleId);
        if (!module) return;

        // Only the target classes are sent; the module itself is unchanged
        // (this works for collaborators who can't edit it).
        const formData = new FormData();
        formData.append("_method", "PUT");
        pmSectionChecks
            .querySelectorAll("input:checked")
            .forEach((el) => formData.append("section_ids[]", el.value));

        pmConfirmBtn.disabled = true;
        const result = await postModule(`/professor/subjects/${SUBJECT_ID}/modules/${module.id}/sections`, formData);
        pmConfirmBtn.disabled = false;

        if (result.ok) {
            await loadSubject();
            closePostModal();
            showToast("Module posted to the selected classes");
        } else {
            showToast(result.message);
        }
    });

    /* REQUEST A CHANGE MODAL — a collaborator can't edit or delete a module
       that isn't theirs, so they ask the subject's owner (a System Alert). */
    const requestChangeModal = document.getElementById("requestChangeModal");
    const requestChangeModalCard = document.getElementById("requestChangeModalCard");
    const rqModalTitle = document.getElementById("rqModalTitle");
    const rqIntro = document.getElementById("rqIntro");
    const rqModuleTitle = document.getElementById("rqModuleTitle");
    const rqNote = document.getElementById("rqNote");
    const rqNoteError = document.getElementById("rqNoteError");
    const rqConfirmBtn = document.getElementById("rqConfirmBtn");
    let rqModuleId = null;
    let rqAction = "edit"; // 'edit' | 'delete'

    function ownerName() {
        return subjectData?.owner?.name || "the subject owner";
    }

    function openRequestModal(module, action) {
        rqModuleId = module.id;
        rqAction = action;

        rqModalTitle.textContent = action === "delete" ? "Request a deletion" : "Request an edit";
        rqIntro.textContent = `Only ${ownerName()} or the uploader can ${action} this module. Your note is sent to ${ownerName()} as an alert.`;
        rqModuleTitle.textContent = module.title;
        rqNote.value = "";
        hideFieldError(rqNoteError, rqNote);

        openModal(requestChangeModal, requestChangeModalCard);
        setTimeout(() => rqNote.focus(), 50);
    }

    function closeRequestModal() {
        closeModal(requestChangeModal, requestChangeModalCard);
        rqModuleId = null;
    }

    rqConfirmBtn?.addEventListener("click", async () => {
        if (rqModuleId === null) return;

        const note = rqNote.value.trim();
        if (!note) {
            rqNoteError.classList.remove("hidden");
            rqNote.classList.add("error");
            rqNote.focus();
            return;
        }
        hideFieldError(rqNoteError, rqNote);

        rqConfirmBtn.disabled = true;

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/modules/${rqModuleId}/change-requests`, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                body: JSON.stringify({ action: rqAction, note }),
            });

            if (!res.ok) {
                showToast(await extractErrorMessage(res, "Could not send the request."));
                return;
            }

            closeRequestModal();
            showToast(`Request sent to ${ownerName()}`);
        } catch (e) {
            showToast("Could not send the request. Please try again.");
        } finally {
            rqConfirmBtn.disabled = false;
        }
    });

    document.getElementById("rqCloseBtn")?.addEventListener("click", closeRequestModal);
    document.getElementById("rqCancelBtn")?.addEventListener("click", closeRequestModal);
    requestChangeModal?.addEventListener("click", (e) => {
        if (e.target === requestChangeModal) closeRequestModal();
    });

    document.getElementById("pmCloseBtn")?.addEventListener("click", closePostModal);
    document.getElementById("pmCancelBtn")?.addEventListener("click", closePostModal);
    postModuleModal?.addEventListener("click", (e) => {
        if (e.target === postModuleModal) closePostModal();
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
    const umQuarter = document.getElementById("umQuarter");
    const umSubmitBtn = document.getElementById("umSubmitBtn");
    const umSubmitLabel = document.getElementById("umSubmitLabel");
    const umTitleField = document.getElementById("umTitleField");
    const umDescriptionField = document.getElementById("umDescriptionField");
    const umBulkField = document.getElementById("umBulkField");
    const umBulkList = document.getElementById("umBulkList");
    const umQuarterLabel = document.getElementById("umQuarterLabel");

    function openUploadModal(mode, module) {
        umMode = mode;
        umEditingId = module ? module.id : null;
        umPickedFile = null;
        umBulkItems = [];
        umLastAutoTitle = "";
        umFileInput.multiple = mode === "create";

        umModalTitle.textContent = mode === "edit" ? "Edit Module" : "Upload Module";
        umSubmitLabel.textContent = mode === "edit" ? "Save changes" : "Upload";
        umTitle.value = module ? module.title : "";
        umDescription.value = module ? module.description || "" : "";
        umFileName.textContent = module
            ? `Current file: ${module.file_name} (choose a new PDF to replace it)`
            : "Drag & drop one or more PDFs here, or click to browse";
        umFileInput.value = "";
        if (umQuarter) {
            umQuarter.value = QUARTER_LABELS.includes(module?.quarter) ? module.quarter : "1st Quarter";
        }
        renderUploadMode();
        hideFieldError(umTitleError, umTitle);
        hideFieldError(umFileError, umDropzone);

        const checkedIds = (module?.target_sections || []).map((t) => String(t.id));
        // Only this professor's own classes; the server enforces the same list.
        const options = subjectData?.targetable_sections || [];
        umSectionChecks.innerHTML = options
            .map(
                (c) => `
                <label class="mv-section-check-item">
                    <input type="checkbox" value="${c.id}" ${checkedIds.includes(String(c.id)) ? "checked" : ""} />
                    ${escapeHtml(c.section || c.name)}
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
        setPickedFiles(Array.from(e.dataTransfer.files || []));
    });

    umFileInput?.addEventListener("change", () => {
        setPickedFiles(Array.from(umFileInput.files || []));
        // Let the same file be re-picked after it was removed from the list.
        umFileInput.value = "";
    });

    function isPdf(file) {
        return file.type === "application/pdf" || file.name.toLowerCase().endsWith(".pdf");
    }

    function setPickedFiles(files) {
        if (files.length === 0) return;

        const pdfs = files.filter(isPdf);
        const accepted = pdfs.filter((file) => file.size <= MAX_MODULE_BYTES);
        const problems = [];
        if (pdfs.length < files.length) problems.push("Only PDF files are supported");
        if (accepted.length < pdfs.length) problems.push("Each PDF must be 10 MB or smaller");

        if (problems.length) {
            const skipped = files.length - accepted.length;
            umFileError.textContent =
                files.length > 1
                    ? `*${problems.join(". ")} (${skipped} skipped)`
                    : `*${problems.join(". ")}`;
            umFileError.classList.remove("hidden");
        } else {
            hideFieldError(umFileError, umDropzone);
        }

        if (accepted.length === 0) return;

        // Editing replaces the one existing file.
        if (umMode === "edit") {
            umPickedFile = accepted[0];
            umFileName.textContent = accepted[0].name;
            return;
        }

        // Keep a title the professor already typed for the first file.
        if (umBulkItems.length === 1 && umTitle.value.trim()) {
            umBulkItems[0].title = umTitle.value.trim();
        }

        accepted.forEach((file) => {
            const duplicate = umBulkItems.some(
                (item) => item.file.name === file.name && item.file.size === file.size,
            );
            if (duplicate) return;

            const detectedQuarter = quarterFromFileName(file.name);
            umBulkItems.push({
                file,
                title: titleFromFileName(file.name),
                quarter: detectedQuarter || umQuarter?.value || QUARTER_LABELS[0],
                quarterTouched: Boolean(detectedQuarter),
                error: "",
            });
        });

        renderUploadMode();
    }

    // One file keeps the regular form (title auto-filled); two or more
    // switch to the bulk list with a title and quarter per file.
    function renderUploadMode() {
        const isBulk = umMode === "create" && umBulkItems.length > 1;

        umTitleField?.classList.toggle("hidden", isBulk);
        umDescriptionField?.classList.toggle("hidden", isBulk);
        umBulkField?.classList.toggle("hidden", !isBulk);
        if (umQuarterLabel) umQuarterLabel.textContent = isBulk ? "Default quarter" : "Quarter";

        if (umMode !== "create") return;

        if (isBulk) {
            umPickedFile = null;
            umFileName.textContent = `${umBulkItems.length} PDFs selected. Drop or browse to add more.`;
            umSubmitLabel.textContent = `Upload ${umBulkItems.length} modules`;
            renderBulkList();
            return;
        }

        umSubmitLabel.textContent = "Upload";
        const only = umBulkItems[0];

        if (!only) {
            umPickedFile = null;
            umFileName.textContent = "Drag & drop one or more PDFs here, or click to browse";
            return;
        }

        umPickedFile = only.file;
        umFileName.textContent = only.file.name;
        if (!umTitle.value.trim() || umTitle.value === umLastAutoTitle) {
            umTitle.value = only.title;
            umLastAutoTitle = only.title;
            hideFieldError(umTitleError, umTitle);
        }
        if (umQuarter && only.quarterTouched) umQuarter.value = only.quarter;
    }

    function renderBulkList() {
        if (!umBulkList) return;

        umBulkList.innerHTML = umBulkItems
            .map(
                (item, index) => `
                <div class="mv-bulk-row" data-index="${index}">
                    <div class="mv-bulk-row-head">
                        <i class="fas fa-file-pdf"></i>
                        <span class="mv-bulk-file">${escapeHtml(item.file.name)} · ${formatBytes(item.file.size)}</span>
                        <button type="button" class="mv-bulk-remove" data-index="${index}" aria-label="Remove ${escapeHtml(item.file.name)}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="mv-bulk-row-fields">
                        <input class="cc-input mv-bulk-title" type="text" maxlength="150" autocomplete="off"
                            placeholder="Title" aria-label="Title for ${escapeHtml(item.file.name)}"
                            value="${escapeHtml(item.title)}" />
                        <select class="cc-input mv-bulk-quarter" aria-label="Quarter for ${escapeHtml(item.file.name)}">
                            ${QUARTER_LABELS.map(
                                (label) =>
                                    `<option value="${label}" ${label === item.quarter ? "selected" : ""}>${label}</option>`,
                            ).join("")}
                        </select>
                    </div>
                    <p class="cc-error mv-bulk-error ${item.error ? "" : "hidden"}">${escapeHtml(item.error ? `*${item.error}` : "")}</p>
                </div>`,
            )
            .join("");
    }

    function bulkItemFor(el) {
        const row = el.closest(".mv-bulk-row");
        return row ? umBulkItems[Number(row.dataset.index)] : null;
    }

    umBulkList?.addEventListener("input", (e) => {
        if (!e.target.classList.contains("mv-bulk-title")) return;
        const item = bulkItemFor(e.target);
        if (item) item.title = e.target.value;
    });

    umBulkList?.addEventListener("change", (e) => {
        if (!e.target.classList.contains("mv-bulk-quarter")) return;
        const item = bulkItemFor(e.target);
        if (!item) return;
        item.quarter = e.target.value;
        item.quarterTouched = true;
    });

    umBulkList?.addEventListener("click", (e) => {
        const btn = e.target.closest(".mv-bulk-remove");
        if (!btn) return;
        umBulkItems.splice(Number(btn.dataset.index), 1);
        if (umBulkItems.length === 1) backToSingleForm(umBulkItems[0]);
        renderUploadMode();
    });

    // Leaving bulk mode with one file left: carry its row over to the form.
    function backToSingleForm(item) {
        umTitle.value = item.title;
        umLastAutoTitle = item.title;
        item.quarterTouched = true;
    }

    // The main quarter picker sets every row whose quarter wasn't read from
    // its file name or chosen by hand.
    umQuarter?.addEventListener("change", () => {
        if (umMode !== "create" || umBulkItems.length < 2) return;
        umBulkItems.forEach((item) => {
            if (!item.quarterTouched) item.quarter = umQuarter.value;
        });
        renderBulkList();
    });

    async function postModule(url, formData) {
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

            if (res.ok) return { ok: true };
            return {
                ok: false,
                message: await extractErrorMessage(res, "Could not save the module."),
            };
        } catch (e) {
            return { ok: false, message: "Could not save the module. Please try again." };
        }
    }

    async function submitBulkUpload(sectionIds) {
        umBulkItems.forEach((item) => {
            item.title = item.title.trim();
            item.error = item.title ? "" : "Title is required";
        });

        if (umBulkItems.some((item) => item.error)) {
            renderBulkList();
            umBulkList.querySelector(".mv-bulk-error:not(.hidden)")
                ?.closest(".mv-bulk-row")
                ?.querySelector(".mv-bulk-title")
                ?.focus();
            return;
        }

        const total = umBulkItems.length;
        const failed = [];
        umSubmitBtn.disabled = true;

        // One request per PDF (sequential), so a single bad file can't sink
        // the batch and no request exceeds the server's upload size limit.
        for (let i = 0; i < total; i++) {
            const item = umBulkItems[i];
            umSubmitLabel.textContent = `Uploading ${i + 1} of ${total}...`;

            const formData = new FormData();
            formData.append("title", item.title);
            formData.append("description", "");
            formData.append("quarter", item.quarter);
            sectionIds.forEach((id) => formData.append("section_ids[]", id));
            formData.append("attachment", item.file);

            const result = await postModule(`/professor/subjects/${SUBJECT_ID}/modules`, formData);
            if (!result.ok) {
                item.error = result.message;
                failed.push(item);
            }
        }

        const uploaded = total - failed.length;
        if (uploaded > 0) await loadSubject();
        umSubmitBtn.disabled = false;

        if (failed.length === 0) {
            closeUploadModal();
            showToast(`${uploaded} modules uploaded`);
            return;
        }

        // Keep only what failed so the professor can fix and retry.
        umBulkItems = failed;
        if (failed.length === 1) backToSingleForm(failed[0]);
        renderUploadMode();
        showToast(
            failed.length === 1
                ? `${uploaded} uploaded. "${failed[0].file.name}" failed: ${failed[0].error}`
                : `${uploaded} uploaded, ${failed.length} failed. Fix the rows shown and try again.`,
        );
    }

    function hideFieldError(errorEl, inputEl) {
        errorEl?.classList.add("hidden");
        inputEl?.classList.remove("error");
    }

    umSubmitBtn?.addEventListener("click", async () => {
        if (umMode === "create" && umBulkItems.length > 1) {
            const sectionIds = Array.from(
                umSectionChecks.querySelectorAll("input:checked"),
            ).map((el) => el.value);
            await submitBulkUpload(sectionIds);
            return;
        }

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

        await submitSingleModule(title, sectionIds);
    });

    async function submitSingleModule(title, sectionIds) {
        const formData = new FormData();
        formData.append("title", title);
        formData.append("description", umDescription.value.trim());
        formData.append("quarter", umQuarter?.value || "1st Quarter");
        sectionIds.forEach((id) => formData.append("section_ids[]", id));
        if (umPickedFile) formData.append("attachment", umPickedFile);

        const isEdit = umMode === "edit";
        const url = isEdit
            ? `/professor/subjects/${SUBJECT_ID}/modules/${umEditingId}`
            : `/professor/subjects/${SUBJECT_ID}/modules`;
        if (isEdit) formData.append("_method", "PUT");

        umSubmitBtn.disabled = true;

        const result = await postModule(url, formData);
        if (result.ok) {
            await loadSubject();
            closeUploadModal();
            showToast(isEdit ? "Module updated" : "Module uploaded");
        } else {
            showToast(result.message);
        }

        umSubmitBtn.disabled = false;
    }

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
                    <div class="mv-collab-avatar">${
                        c.avatar_url
                            ? `<img src="${escapeHtml(c.avatar_url)}" alt="">`
                            : escapeHtml(initialsFor(c.name))
                    }</div>
                    <div class="mv-collab-item-info">
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
        closePostModal();
        closeRequestModal();
        closeModuleMenus();
        closeInviteModal();
        closeConfirm();
    });

    // No tour on the "Subject not found" screen; otherwise the first-visit
    // tour waits for the materials so it can point at them.
    loadSubject().then(() => {
        if (subjectWrap?.classList.contains("hidden")) return;
        window.LQPageTour?.init({ key: "tour_seen_module_view", steps: moduleViewTourSteps });
    });
});

/* GUIDED TOUR — common/page-tour.js */
function moduleViewTourSteps() {
    return [
        {
            title: "Inside a subject",
            body: "This is where a subject's lesson files live. Upload them here, then post them to the classes that need them.",
        },
        {
            target: "#uploadModuleBtn",
            title: "Upload a module",
            body: "Add one or more lesson PDFs. QuestAI uses each PDF to write the lesson's summary and quiz, so upload the material you actually teach from.",
        },
        {
            target: "#manageCompetenciesLink",
            title: "Competencies",
            body: "List the skills this subject's lessons teach. Every quiz question is tied to one, and each student's mastery is tracked per competency.",
        },
        {
            target: "#inviteCollabBtn",
            title: "Collaborators",
            body: "Invite other teachers to share this subject's materials. They can use the files and ask you to edit or delete one.",
        },
        {
            target: ".mv-sections-section",
            title: "Sections",
            body: "Your classes in this subject. Click one to open that class.",
        },
        {
            // Just the first card, not the whole grid.
            target: () => document.querySelector(".mv-module-card"),
            title: "A module",
            body: 'Each card shows who uploaded the file, when, and which classes it is posted to. "Post to classes" adds it as a lesson in the classes you tick; the ⋮ menu has Preview, Edit and Delete. Double-click a card to preview it.',
            fallback: 'Uploaded files appear here as cards. Click "Upload Module" to add your first PDF.',
        },
        {
            target: "#sectionFilter",
            title: "Filter by section",
            body: "Show only the materials posted to one of your sections.",
        },
        {
            title: "That's the subject page",
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}
