(function () {
    const SUBJECT_ID = new URLSearchParams(window.location.search).get("subject");

    const subjectNotFound = document.getElementById("subjectNotFound");
    const competenciesWrap = document.getElementById("competenciesWrap");
    const backToSubjectLink = document.getElementById("backToSubjectLink");
    const subjectNameEl = document.getElementById("pcSubjectName");

    const listEl = document.getElementById("competencyList");
    const loadingEl = document.getElementById("competencyLoading");
    const emptyEl = document.getElementById("competencyEmpty");

    const addBtn = document.getElementById("addCompetencyBtn");
    const modal = document.getElementById("competencyModal");
    const modalCard = document.getElementById("competencyModalCard");
    const modalTitle = document.getElementById("pcModalTitle");
    const modalCloseBtn = document.getElementById("competencyModalCloseBtn");
    const cancelBtn = document.getElementById("competencyCancelBtn");
    const confirmBtn = document.getElementById("competencyConfirmBtn");
    const nameInput = document.getElementById("pcName");
    const nameError = document.getElementById("pcNameError");
    const descriptionInput = document.getElementById("pcDescription");

    const deleteModal = document.getElementById("deleteCompetencyModal");
    const deleteModalCard = document.getElementById("deleteCompetencyModalCard");
    const deleteDesc = document.getElementById("deleteCompetencyDesc");
    const deleteCloseBtn = document.getElementById("deleteCompetencyCloseBtn");
    const deleteCancelBtn = document.getElementById("deleteCompetencyCancelBtn");
    const deleteConfirmBtn = document.getElementById("deleteCompetencyConfirmBtn");

    const toast = document.getElementById("pcToast");
    const toastMessage = document.getElementById("pcToastMessage");
    let toastTimer = null;

    let editingId = null;
    let deletingId = null;

    function getCsrfToken() {
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

    function showToast(message) {
        if (!toast || !toastMessage) return;

        toastMessage.textContent = message;
        toast.classList.add("visible");

        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove("visible"), 2600);
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

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text ?? "";
        return div.innerHTML;
    }

    async function init() {
        if (!SUBJECT_ID) {
            showNotFound();
            return;
        }

        backToSubjectLink.href = `professor-module-view.html?subject=${SUBJECT_ID}`;

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}`, {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (!res.ok) {
                showNotFound();
                return;
            }

            const subject = await res.json();
            subjectNameEl.textContent = `${subject.name} — Competencies`;

            subjectNotFound.classList.add("hidden");
            competenciesWrap.classList.remove("hidden");

            await loadCompetencies();
        } catch (e) {
            showNotFound();
        }
    }

    function showNotFound() {
        subjectNotFound.classList.remove("hidden");
        competenciesWrap.classList.add("hidden");
    }

    async function loadCompetencies() {
        loadingEl.classList.remove("hidden");
        emptyEl.classList.add("hidden");
        listEl.innerHTML = "";

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/competencies`, {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (!res.ok) throw new Error("failed to load competencies");

            const competencies = await res.json();
            renderList(competencies);
        } catch (e) {
            showToast("Could not load competencies. Please refresh the page.");
        } finally {
            loadingEl.classList.add("hidden");
        }
    }

    function renderList(competencies) {
        listEl.innerHTML = "";

        if (!competencies || competencies.length === 0) {
            emptyEl.classList.remove("hidden");
            return;
        }
        emptyEl.classList.add("hidden");

        competencies.forEach((competency) => {
            const row = document.createElement("div");
            row.className = "pc-row";
            row.dataset.id = competency.id;
            row.innerHTML = `
                <div class="pc-row-text">
                    <span class="pc-row-name">${escapeHtml(competency.name)}</span>
                    ${competency.description ? `<span class="pc-row-desc">${escapeHtml(competency.description)}</span>` : ""}
                </div>
                <div class="pc-row-actions">
                    <button type="button" class="pc-icon-btn pc-edit-btn" aria-label="Edit">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button type="button" class="pc-icon-btn pc-delete-btn" aria-label="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            row.querySelector(".pc-edit-btn").addEventListener("click", () => openEditModal(competency));
            row.querySelector(".pc-delete-btn").addEventListener("click", () => openDeleteModal(competency));
            listEl.appendChild(row);
        });
    }

    /* ADD / EDIT MODAL */
    function openAddModal() {
        editingId = null;
        modalTitle.textContent = "Add Competency";
        confirmBtn.textContent = "Save";
        nameInput.value = "";
        descriptionInput.value = "";
        nameError.classList.add("hidden");
        nameInput.classList.remove("error");
        openModal(modal, modalCard);
        nameInput?.focus();
    }

    function openEditModal(competency) {
        editingId = competency.id;
        modalTitle.textContent = "Edit Competency";
        confirmBtn.textContent = "Save Changes";
        nameInput.value = competency.name || "";
        descriptionInput.value = competency.description || "";
        nameError.classList.add("hidden");
        nameInput.classList.remove("error");
        openModal(modal, modalCard);
        nameInput?.focus();
    }

    function closeCompetencyModal() {
        closeModal(modal, modalCard);
        editingId = null;
    }

    addBtn?.addEventListener("click", openAddModal);
    modalCloseBtn?.addEventListener("click", closeCompetencyModal);
    cancelBtn?.addEventListener("click", closeCompetencyModal);
    modal?.addEventListener("click", (e) => {
        if (e.target === modal) closeCompetencyModal();
    });

    confirmBtn?.addEventListener("click", async () => {
        const name = nameInput?.value.trim() || "";
        const description = descriptionInput?.value.trim() || "";

        if (name === "") {
            nameError.classList.remove("hidden");
            nameInput.classList.add("error");
            return;
        }

        confirmBtn.disabled = true;

        try {
            const isEdit = editingId !== null;
            const url = isEdit
                ? `/professor/subjects/${SUBJECT_ID}/competencies/${editingId}`
                : `/professor/subjects/${SUBJECT_ID}/competencies`;

            const res = await fetch(url, {
                method: isEdit ? "PUT" : "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                body: JSON.stringify({ name, description: description || null }),
            });

            if (!res.ok) {
                const message = await extractErrorMessage(
                    res,
                    isEdit ? "Could not update the competency." : "Could not create the competency.",
                );
                showToast(message);
                return;
            }

            closeCompetencyModal();
            showToast(isEdit ? "Competency updated." : "Competency added.");
            await loadCompetencies();
        } catch (e) {
            showToast("Something went wrong. Please try again.");
        } finally {
            confirmBtn.disabled = false;
        }
    });

    /* DELETE MODAL */
    function openDeleteModal(competency) {
        deletingId = competency.id;
        deleteDesc.textContent = `Are you sure you want to delete "${competency.name}"? This can't be undone.`;
        openModal(deleteModal, deleteModalCard);
    }

    function closeDeleteModal() {
        closeModal(deleteModal, deleteModalCard);
        deletingId = null;
    }

    deleteCloseBtn?.addEventListener("click", closeDeleteModal);
    deleteCancelBtn?.addEventListener("click", closeDeleteModal);
    deleteModal?.addEventListener("click", (e) => {
        if (e.target === deleteModal) closeDeleteModal();
    });

    deleteConfirmBtn?.addEventListener("click", async () => {
        if (deletingId === null) return;

        deleteConfirmBtn.disabled = true;

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/competencies/${deletingId}`, {
                method: "DELETE",
                credentials: "same-origin",
                headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
            });

            if (!res.ok) {
                const message = await extractErrorMessage(res, "Could not delete the competency.");
                showToast(message);
                return;
            }

            closeDeleteModal();
            showToast("Competency deleted.");
            await loadCompetencies();
        } catch (e) {
            showToast("Something went wrong. Please try again.");
        } finally {
            deleteConfirmBtn.disabled = false;
        }
    });

    /* GENERIC MODAL HELPERS */
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

    init();
})();
