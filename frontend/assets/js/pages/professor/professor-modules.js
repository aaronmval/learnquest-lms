document.addEventListener("DOMContentLoaded", () => {
    const subjectGrid = document.getElementById("subjectGrid");
    const searchInput = document.getElementById("subjectSearchInput");
    const searchClearBtn = document.getElementById("subjectSearchClearBtn");
    const searchEmpty = document.getElementById("subjectSearchEmpty");
    const statClassesCovered = document.getElementById("statClassesCovered");

    const addSubjectBtn = document.getElementById("addSubjectBtn");
    const addSubjectModal = document.getElementById("addSubjectModal");
    const addSubjectModalCard = document.getElementById("addSubjectModalCard");
    const addSubjectCloseBtn = document.getElementById("addSubjectCloseBtn");
    const addSubjectCancelBtn = document.getElementById("addSubjectCancelBtn");
    const addSubjectConfirmBtn = document.getElementById(
        "addSubjectConfirmBtn",
    );
    const asTeacherName = document.getElementById("asTeacherName");
    const asTeacherEmail = document.getElementById("asTeacherEmail");
    const asSubjectName = document.getElementById("asSubjectName");
    const asNameError = document.getElementById("asNameError");

    const toast = document.getElementById("modulesToast");
    const toastMessage = document.getElementById("modulesToastMessage");

    let toastTimer = null;

    /* TOAST */
    function showToast(message) {
        if (!toast || !toastMessage) return;

        toastMessage.textContent = message;
        toast.classList.add("visible");

        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove("visible"), 2600);
    }

    /* STATS */
    function refreshStats() {
        if (!subjectGrid || !statClassesCovered) return;
        statClassesCovered.textContent = String(
            subjectGrid.querySelectorAll(".mat-subject-card").length,
        );
    }

    /* SEARCH */
    function applySearch() {
        if (!subjectGrid || !searchInput) return;

        const query = searchInput.value.trim().toLowerCase();
        const cards = subjectGrid.querySelectorAll(".mat-subject-card");
        let visibleCount = 0;

        cards.forEach((card) => {
            const name =
                card
                    .querySelector(".mat-subject-name")
                    ?.textContent.trim()
                    .toLowerCase() || "";
            const matches = query === "" || name.includes(query);

            card.classList.toggle("search-hidden", !matches);
            if (matches) visibleCount += 1;
        });

        searchClearBtn?.classList.toggle("hidden", query === "");
        searchEmpty?.classList.toggle("hidden", visibleCount > 0);
    }

    searchInput?.addEventListener("input", applySearch);

    searchClearBtn?.addEventListener("click", () => {
        if (!searchInput) return;
        searchInput.value = "";
        applySearch();
        searchInput.focus();
    });

    /* ADD SUBJECT MODAL */
    function openAddSubjectModal() {
        if (!addSubjectModal) return;

        addSubjectModal.classList.add("visible");
        requestAnimationFrame(() =>
            addSubjectModalCard?.classList.add("scaled"),
        );
        asSubjectName?.focus();
    }

    function closeAddSubjectModal() {
        if (!addSubjectModal) return;

        addSubjectModalCard?.classList.remove("scaled");
        addSubjectModal.classList.remove("visible");
        resetAddSubjectForm();
    }

    function resetAddSubjectForm() {
        [asTeacherName, asTeacherEmail, asSubjectName].forEach((input) => {
            if (!input) return;
            input.value = "";
            input.classList.remove("error");
        });
        asNameError?.classList.add("hidden");
    }

    function initialsFor(name) {
        const parts = name.trim().split(/\s+/).filter(Boolean);
        if (parts.length === 0) return "LQ";

        return parts
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join("");
    }

    function buildSubjectCard(subject, teacher) {
        const card = document.createElement("div");
        card.className = "mat-subject-card";
        card.dataset.subject = subject.toLowerCase().replace(/\s+/g, "-");

        const row = document.createElement("div");
        row.className = "mat-subject-row";

        const title = document.createElement("h3");
        title.className = "mat-subject-name";
        title.textContent = subject;

        const arrow = document.createElement("button");
        arrow.className = "mat-subject-arrow";
        arrow.setAttribute("aria-label", `Open ${subject} materials`);
        arrow.innerHTML = '<i class="fas fa-arrow-right"></i>';

        row.append(title, arrow);

        const uploader = document.createElement("div");
        uploader.className = "mat-subject-uploader";

        const avatar = document.createElement("div");
        avatar.className = "mat-subject-uploader-initials";
        avatar.style.display = "flex";
        avatar.textContent = initialsFor(teacher);

        const label = document.createElement("span");
        label.textContent = `Uploaded by: ${teacher}`;

        uploader.append(avatar, label);
        card.append(row, uploader);

        return card;
    }

    addSubjectBtn?.addEventListener("click", openAddSubjectModal);
    addSubjectCloseBtn?.addEventListener("click", closeAddSubjectModal);
    addSubjectCancelBtn?.addEventListener("click", closeAddSubjectModal);

    addSubjectModal?.addEventListener("click", (e) => {
        if (e.target === addSubjectModal) closeAddSubjectModal();
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && addSubjectModal?.classList.contains("visible"))
            closeAddSubjectModal();
    });

    asSubjectName?.addEventListener("input", () => {
        if (asSubjectName.value.trim() === "") return;
        asSubjectName.classList.remove("error");
        asNameError?.classList.add("hidden");
    });

    addSubjectConfirmBtn?.addEventListener("click", () => {
        const subject = asSubjectName?.value.trim() || "";

        if (subject === "") {
            asSubjectName?.classList.add("error");
            asNameError?.classList.remove("hidden");
            asSubjectName?.focus();
            return;
        }

        const teacher = asTeacherName?.value.trim() || "You";

        subjectGrid?.appendChild(buildSubjectCard(subject, teacher));
        closeAddSubjectModal();
        refreshStats();
        applySearch();
        showToast(`"${subject}" added to your classes.`);
    });

    /* SUBJECT CARD ARROWS — no module contents page in the shell yet */
    subjectGrid?.addEventListener("click", (e) => {
        const arrow = e.target.closest(".mat-subject-arrow");
        if (!arrow) return;

        const name =
            arrow
                .closest(".mat-subject-card")
                ?.querySelector(".mat-subject-name")?.textContent.trim() ||
            "This subject";

        showToast(`${name} module contents are coming soon.`);
    });

    refreshStats();
    applySearch();
});
