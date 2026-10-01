(function () {
    const SUBJECT_ID = new URLSearchParams(window.location.search).get("subject");

    const subjectNotFound = document.getElementById("subjectNotFound");
    const competenciesWrap = document.getElementById("competenciesWrap");
    const backToSubjectLink = document.getElementById("backToSubjectLink");
    const subjectNameEl = document.getElementById("pcSubjectName");

    const toolbarEl = document.getElementById("competencyToolbar");
    const countEl = document.getElementById("competencyCount");
    const searchInput = document.getElementById("competencySearchInput");
    const listEl = document.getElementById("competencyList");
    const loadingEl = document.getElementById("competencyLoading");
    const emptyEl = document.getElementById("competencyEmpty");
    const searchEmptyEl = document.getElementById("competencySearchEmpty");

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

    const suggestBtn = document.getElementById("suggestCompetenciesBtn");
    const suggestModal = document.getElementById("suggestModal");
    const suggestModalCard = document.getElementById("suggestModalCard");
    const suggestLoading = document.getElementById("suggestLoading");
    const suggestError = document.getElementById("suggestError");
    const suggestErrorText = document.getElementById("suggestErrorText");
    const suggestResults = document.getElementById("suggestResults");
    const suggestSummary = document.getElementById("suggestSummary");
    const suggestSelectAll = document.getElementById("suggestSelectAll");
    const suggestList = document.getElementById("suggestList");
    const suggestCloseBtn = document.getElementById("suggestCloseBtn");
    const suggestCancelBtn = document.getElementById("suggestCancelBtn");
    const suggestRetryBtn = document.getElementById("suggestRetryBtn");
    const suggestAddBtn = document.getElementById("suggestAddBtn");

    const deleteModal = document.getElementById("deleteCompetencyModal");
    const deleteModalCard = document.getElementById("deleteCompetencyModalCard");
    const deleteDesc = document.getElementById("deleteCompetencyDesc");
    const deleteCloseBtn = document.getElementById("deleteCompetencyCloseBtn");
    const deleteCancelBtn = document.getElementById("deleteCompetencyCancelBtn");
    const deleteConfirmBtn = document.getElementById("deleteCompetencyConfirmBtn");

    const toast = document.getElementById("pcToast");
    const toastMessage = document.getElementById("pcToastMessage");
    let toastTimer = null;

    let allCompetencies = [];
    let editingId = null;
    let deletingId = null;

    let suggestions = [];
    // Bumped whenever the suggestions modal opens/closes, so a reply that
    // arrives after the professor closed it is ignored.
    let suggestRequestToken = 0;
    let suggestSaving = false;

    function getCsrfToken() {
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

    function showToast(message) {
        if (!toast || !toastMessage) return;

        toastMessage.textContent = message;
        toast.classList.add("visible");

        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove("visible"), 3200);
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

    function postCompetency(payload) {
        return fetch(`/professor/subjects/${SUBJECT_ID}/competencies`, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
            body: JSON.stringify(payload),
        });
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
            subjectNameEl.textContent = subject.name;

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
        searchEmptyEl.classList.add("hidden");
        listEl.innerHTML = "";

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/competencies`, {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (!res.ok) throw new Error("failed to load competencies");

            allCompetencies = await res.json();
        } catch (e) {
            showToast("Could not load competencies. Please refresh the page.");
        } finally {
            loadingEl.classList.add("hidden");
        }

        renderList();
    }

    function renderList() {
        const total = allCompetencies.length;
        const query = (searchInput?.value || "").trim().toLowerCase();

        countEl.textContent = total;
        toolbarEl.classList.toggle("hidden", total === 0);
        emptyEl.classList.toggle("hidden", total > 0);
        listEl.innerHTML = "";

        const visible = allCompetencies.filter(
            (c) =>
                !query ||
                c.name.toLowerCase().includes(query) ||
                (c.description || "").toLowerCase().includes(query),
        );

        searchEmptyEl.classList.toggle("hidden", total === 0 || visible.length > 0);

        visible.forEach((competency) => {
            const row = document.createElement("article");
            row.className = "pc-row";
            row.dataset.id = competency.id;
            row.innerHTML = `
                <span class="pc-row-index">${allCompetencies.indexOf(competency) + 1}</span>
                <div class="pc-row-text">
                    <span class="pc-row-name">${escapeHtml(competency.name)}</span>
                    ${
                        competency.description
                            ? `<span class="pc-row-desc">${escapeHtml(competency.description)}</span>`
                            : '<span class="pc-row-desc pc-row-desc-empty">No description</span>'
                    }
                </div>
                <div class="pc-row-actions">
                    <button type="button" class="pc-icon-btn pc-edit-btn" title="Edit" aria-label="Edit ${escapeHtml(competency.name)}">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button type="button" class="pc-icon-btn pc-delete-btn" title="Delete" aria-label="Delete ${escapeHtml(competency.name)}">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            row.querySelector(".pc-edit-btn").addEventListener("click", () => openEditModal(competency));
            row.querySelector(".pc-delete-btn").addEventListener("click", () => openDeleteModal(competency));
            listEl.appendChild(row);
        });
    }

    searchInput?.addEventListener("input", renderList);

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
    document.getElementById("emptyAddBtn")?.addEventListener("click", openAddModal);
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
            const payload = { name, description: description || null };

            const res = isEdit
                ? await fetch(`/professor/subjects/${SUBJECT_ID}/competencies/${editingId}`, {
                      method: "PUT",
                      credentials: "same-origin",
                      headers: {
                          "Content-Type": "application/json",
                          Accept: "application/json",
                          "X-XSRF-TOKEN": getCsrfToken(),
                      },
                      body: JSON.stringify(payload),
                  })
                : await postCompetency(payload);

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

    /* AI SUGGESTIONS MODAL */
    function setSuggestView(view) {
        suggestLoading.classList.toggle("hidden", view !== "loading");
        suggestError.classList.toggle("hidden", view !== "error");
        suggestResults.classList.toggle("hidden", view !== "results");
        suggestRetryBtn.classList.toggle("hidden", view !== "error");
        suggestAddBtn.classList.toggle("hidden", view !== "results");
    }

    function openSuggestModal() {
        openModal(suggestModal, suggestModalCard);
        requestSuggestions();
    }

    function closeSuggestModal() {
        if (suggestSaving) return;
        suggestRequestToken++;
        closeModal(suggestModal, suggestModalCard);
    }

    async function requestSuggestions() {
        const token = ++suggestRequestToken;
        suggestions = [];
        setSuggestView("loading");

        let message = "We couldn't generate suggestions right now. Please try again shortly.";

        try {
            const res = await fetch(`/professor/subjects/${SUBJECT_ID}/competencies/suggest`, {
                method: "POST",
                credentials: "same-origin",
                headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
            });

            if (token !== suggestRequestToken) return;

            if (res.ok) {
                const data = await res.json();
                if (token !== suggestRequestToken) return;

                suggestions = (data.suggestions || []).map((s) => ({ ...s, selected: true }));

                if (suggestions.length) {
                    renderSuggestions(data);
                    setSuggestView("results");
                    return;
                }

                message = "The AI found no new competencies to suggest for these modules.";
            } else if (res.status === 429) {
                message = "You've asked for suggestions several times in a row. Please wait a minute and try again.";
            } else {
                message = await extractErrorMessage(res, message);
            }
        } catch (e) {
            if (token !== suggestRequestToken) return;
        }

        suggestErrorText.textContent = message;
        setSuggestView("error");
    }

    function renderSuggestions(data) {
        const read = data.modules_read;
        const total = data.modules_total;
        const moduleNote =
            read < total
                ? `Based on ${read} of ${total} modules (the rest had no readable text or were skipped).`
                : `Based on ${total} uploaded ${total === 1 ? "module" : "modules"}.`;

        suggestSummary.textContent = `${moduleNote} Review the list and untick anything you don't want. You can edit each one after adding it.`;

        suggestList.innerHTML = suggestions
            .map(
                (s, index) => `
                <label class="pc-suggest-item selected" data-index="${index}">
                    <input type="checkbox" checked />
                    <span class="pc-suggest-item-text">
                        <span class="pc-suggest-item-name">${escapeHtml(s.name)}</span>
                        ${s.description ? `<span class="pc-suggest-item-desc">${escapeHtml(s.description)}</span>` : ""}
                        ${
                            s.modules?.length
                                ? `<span class="pc-suggest-item-sources">${s.modules
                                      .map(
                                          (title) =>
                                              `<span class="pc-suggest-source"><i class="fas fa-file-pdf"></i>${escapeHtml(title)}</span>`,
                                      )
                                      .join("")}</span>`
                                : ""
                        }
                    </span>
                </label>`,
            )
            .join("");

        updateSuggestSelection();
    }

    function updateSuggestSelection() {
        const selected = suggestions.filter((s) => s.selected).length;
        suggestAddBtn.textContent = selected ? `Add selected (${selected})` : "Add selected";
        suggestAddBtn.disabled = selected === 0 || suggestSaving;
        suggestSelectAll.checked = selected === suggestions.length;
        suggestSelectAll.indeterminate = selected > 0 && selected < suggestions.length;
    }

    suggestList?.addEventListener("change", (e) => {
        const item = e.target.closest(".pc-suggest-item");
        if (!item) return;
        suggestions[Number(item.dataset.index)].selected = e.target.checked;
        item.classList.toggle("selected", e.target.checked);
        updateSuggestSelection();
    });

    suggestSelectAll?.addEventListener("change", () => {
        suggestions.forEach((s) => (s.selected = suggestSelectAll.checked));
        suggestList.querySelectorAll(".pc-suggest-item").forEach((item) => {
            item.classList.toggle("selected", suggestSelectAll.checked);
            item.querySelector("input").checked = suggestSelectAll.checked;
        });
        updateSuggestSelection();
    });

    suggestAddBtn?.addEventListener("click", async () => {
        const chosen = suggestions.filter((s) => s.selected);
        if (!chosen.length || suggestSaving) return;

        suggestSaving = true;
        suggestAddBtn.disabled = true;
        suggestCancelBtn.disabled = true;

        let added = 0;
        let firstError = "";

        for (let i = 0; i < chosen.length; i++) {
            suggestAddBtn.textContent = `Adding ${i + 1} of ${chosen.length}...`;

            try {
                const res = await postCompetency({
                    name: chosen[i].name,
                    description: chosen[i].description || null,
                });

                if (res.ok) added++;
                else if (!firstError) firstError = await extractErrorMessage(res, "Could not add a competency.");
            } catch (e) {
                if (!firstError) firstError = "Could not add a competency.";
            }
        }

        suggestSaving = false;
        suggestCancelBtn.disabled = false;

        const failed = chosen.length - added;
        closeSuggestModal();
        showToast(
            failed === 0
                ? `${added} ${added === 1 ? "competency" : "competencies"} added.`
                : `${added} added, ${failed} failed. ${firstError}`,
        );
        await loadCompetencies();
    });

    suggestBtn?.addEventListener("click", openSuggestModal);
    document.getElementById("emptySuggestBtn")?.addEventListener("click", openSuggestModal);
    suggestRetryBtn?.addEventListener("click", requestSuggestions);
    suggestCloseBtn?.addEventListener("click", closeSuggestModal);
    suggestCancelBtn?.addEventListener("click", closeSuggestModal);
    suggestModal?.addEventListener("click", (e) => {
        if (e.target === suggestModal) closeSuggestModal();
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

    /* ESCAPE closes whichever modal is open */
    document.addEventListener("keydown", (e) => {
        if (e.key !== "Escape") return;
        closeCompetencyModal();
        closeSuggestModal();
        closeDeleteModal();
    });

    init();
})();
