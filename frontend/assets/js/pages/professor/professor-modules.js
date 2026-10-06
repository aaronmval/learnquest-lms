document.addEventListener("DOMContentLoaded", () => {
    const subjectGrid = document.getElementById("subjectGrid");
    const subjectLoading = document.getElementById("subjectLoading");
    const subjectEmpty = document.getElementById("subjectEmpty");
    const searchInput = document.getElementById("subjectSearchInput");
    const searchClearBtn = document.getElementById("subjectSearchClearBtn");
    const searchEmpty = document.getElementById("subjectSearchEmpty");

    const statTotalMaterials = document.getElementById("statTotalMaterials");
    const statStorageUsed = document.getElementById("statStorageUsed");
    const statClassesCovered = document.getElementById("statClassesCovered");
    const statLastUpload = document.getElementById("statLastUpload");

    const addSubjectBtn = document.getElementById("addSubjectBtn");
    const addSubjectModal = document.getElementById("addSubjectModal");
    const addSubjectModalCard = document.getElementById("addSubjectModalCard");
    const addSubjectCloseBtn = document.getElementById("addSubjectCloseBtn");
    const addSubjectCancelBtn = document.getElementById("addSubjectCancelBtn");
    const addSubjectConfirmBtn = document.getElementById(
        "addSubjectConfirmBtn",
    );
    const asTeacherEmail = document.getElementById("asTeacherEmail");
    const asSubjectName = document.getElementById("asSubjectName");
    const asNameError = document.getElementById("asNameError");

    const toast = document.getElementById("modulesToast");
    const toastMessage = document.getElementById("modulesToastMessage");

    let toastTimer = null;
    let subjects = [];

    function getCsrfToken() {
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

    /* TOAST */
    function showToast(message) {
        if (!toast || !toastMessage) return;

        toastMessage.textContent = message;
        toast.classList.add("visible");

        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove("visible"), 2600);
    }

    function initialsFor(name) {
        const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
        if (parts.length === 0) return "LQ";

        return parts
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join("");
    }

    /* Fill an avatar circle with the user's profile photo, or their initials. */
    function fillAvatar(el, user) {
        if (!user?.avatar_url) {
            el.textContent = initialsFor(user?.name);
            return;
        }

        const img = document.createElement("img");
        img.src = user.avatar_url;
        img.alt = "";
        img.style.cssText = "width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;";
        el.appendChild(img);
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

    /* STATS */
    function refreshStats() {
        const totalMaterials = subjects.reduce(
            (sum, s) => sum + (s.modules_count || 0),
            0,
        );
        const storageUsed = subjects.reduce(
            (sum, s) => sum + (Number(s.modules_sum_file_size) || 0),
            0,
        );
        const sectionsCovered = subjects.reduce(
            (sum, s) => sum + (s.sections_count || 0),
            0,
        );
        const lastUploads = subjects
            .map((s) => s.modules_max_created_at)
            .filter(Boolean)
            .sort();

        if (statTotalMaterials) statTotalMaterials.textContent = String(totalMaterials);
        if (statStorageUsed) statStorageUsed.textContent = formatBytes(storageUsed);
        if (statClassesCovered) statClassesCovered.textContent = String(sectionsCovered);
        if (statLastUpload)
            statLastUpload.textContent = lastUploads.length
                ? formatDate(lastUploads[lastUploads.length - 1])
                : "—";
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
        searchEmpty?.classList.toggle(
            "hidden",
            visibleCount > 0 || subjects.length === 0,
        );
    }

    searchInput?.addEventListener("input", applySearch);

    searchClearBtn?.addEventListener("click", () => {
        if (!searchInput) return;
        searchInput.value = "";
        applySearch();
        searchInput.focus();
    });

    /* SUBJECT CARDS */
    function buildSubjectCard(subject) {
        const card = document.createElement("div");
        card.className = "mat-subject-card";
        card.dataset.id = subject.id;

        const row = document.createElement("div");
        row.className = "mat-subject-row";

        const title = document.createElement("h3");
        title.className = "mat-subject-name";
        title.textContent = subject.name;

        const kebabWrap = document.createElement("div");
        kebabWrap.className = "post-kebab-wrap";

        const kebabBtn = document.createElement("button");
        kebabBtn.className = "post-kebab-btn mat-subject-kebab-btn";
        kebabBtn.type = "button";
        kebabBtn.setAttribute("aria-label", `${subject.name} options`);
        kebabBtn.setAttribute("aria-haspopup", "true");
        kebabBtn.innerHTML = '<i class="fas fa-ellipsis-vertical"></i>';

        const kebabMenu = document.createElement("div");
        kebabMenu.className = "post-kebab-menu";

        const renameItem = document.createElement("button");
        renameItem.type = "button";
        renameItem.className = "post-kebab-item";
        renameItem.dataset.action = "rename";
        renameItem.innerHTML = '<i class="fas fa-pen"></i> Rename';

        kebabMenu.appendChild(renameItem);
        kebabWrap.append(kebabBtn, kebabMenu);

        const arrow = document.createElement("button");
        arrow.className = "mat-subject-arrow";
        arrow.setAttribute("aria-label", `Open ${subject.name} materials`);
        arrow.innerHTML = '<i class="fas fa-arrow-right"></i>';

        const actions = document.createElement("div");
        actions.className = "mat-subject-actions";
        actions.append(kebabWrap, arrow);

        row.append(title, actions);

        const meta = document.createElement("div");
        meta.className = "mat-subject-meta";
        const sectionsLabel = subject.sections_count === 1 ? "section" : "sections";
        const modulesLabel = subject.modules_count === 1 ? "material" : "materials";
        meta.textContent = `${subject.sections_count || 0} ${sectionsLabel} · ${subject.modules_count || 0} ${modulesLabel}`;

        const uploader = document.createElement("div");
        uploader.className = "mat-subject-uploader";

        const avatar = document.createElement("div");
        avatar.className = "mat-subject-uploader-initials";
        avatar.style.display = "flex";
        fillAvatar(avatar, subject.owner);

        const label = document.createElement("span");
        label.textContent = `Owned by: ${subject.owner?.name || "Unknown"}`;

        uploader.append(avatar, label);

        if (subject.collaborators && subject.collaborators.length) {
            const collabStack = document.createElement("div");
            collabStack.className = "mat-collab-stack";
            collabStack.title = subject.collaborators
                .map((c) => c.name)
                .join(", ");

            subject.collaborators.slice(0, 3).forEach((collab) => {
                const bubble = document.createElement("div");
                bubble.className = "mat-collab-bubble";
                fillAvatar(bubble, collab);
                collabStack.appendChild(bubble);
            });

            if (subject.collaborators.length > 3) {
                const more = document.createElement("div");
                more.className = "mat-collab-bubble mat-collab-more";
                more.textContent = `+${subject.collaborators.length - 3}`;
                collabStack.appendChild(more);
            }

            uploader.appendChild(collabStack);
        }

        card.append(row, meta, uploader);

        return card;
    }

    function renderSubjects() {
        if (!subjectGrid) return;

        subjectGrid.innerHTML = "";
        subjects.forEach((subject) => {
            subjectGrid.appendChild(buildSubjectCard(subject));
        });

        subjectEmpty?.classList.toggle("hidden", subjects.length > 0);
        refreshStats();
        applySearch();
    }

    async function loadSubjects() {
        subjectLoading?.classList.remove("hidden");

        try {
            const res = await fetch("/professor/subjects", {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (!res.ok) throw new Error("failed to load subjects");

            subjects = await res.json();
        } catch (e) {
            subjects = [];
            showToast("Could not load your subjects. Please refresh the page.");
        } finally {
            subjectLoading?.classList.add("hidden");
        }

        renderSubjects();
    }

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
        [asTeacherEmail, asSubjectName].forEach((input) => {
            if (!input) return;
            input.value = "";
            input.classList.remove("error");
        });
        asNameError?.classList.add("hidden");
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

    addSubjectConfirmBtn?.addEventListener("click", async () => {
        const name = asSubjectName?.value.trim() || "";

        if (name === "") {
            asSubjectName?.classList.add("error");
            asNameError?.classList.remove("hidden");
            asSubjectName?.focus();
            return;
        }

        const email = asTeacherEmail?.value.trim() || "";

        addSubjectConfirmBtn.disabled = true;

        try {
            const res = await fetch("/professor/subjects", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                body: JSON.stringify({ name }),
            });

            if (!res.ok) throw new Error("failed to create subject");

            const created = await res.json();
            closeAddSubjectModal();

            if (email !== "") {
                try {
                    const inviteRes = await fetch(
                        `/professor/subjects/${created.id}/collaborators`,
                        {
                            method: "POST",
                            credentials: "same-origin",
                            headers: {
                                "Content-Type": "application/json",
                                Accept: "application/json",
                                "X-XSRF-TOKEN": getCsrfToken(),
                            },
                            body: JSON.stringify({ email }),
                        },
                    );

                    if (!inviteRes.ok) {
                        const message = await extractErrorMessage(
                            inviteRes,
                            "Could not invite that collaborator.",
                        );
                        showToast(`"${name}" created, but ${message.toLowerCase()}`);
                    } else {
                        showToast(`"${name}" created and collaborator invited.`);
                    }
                } catch (e) {
                    showToast(
                        `"${name}" created, but the collaborator invite failed.`,
                    );
                }
            } else {
                showToast(`"${name}" added to your subjects.`);
            }

            await loadSubjects();
        } catch (e) {
            showToast("Could not create the subject. Please try again.");
        } finally {
            addSubjectConfirmBtn.disabled = false;
        }
    });

    /* SUBJECT CARD KEBAB MENU (rename) */
    function closeAllSubjectKebabMenus() {
        subjectGrid
            ?.querySelectorAll(".post-kebab-menu.open")
            .forEach((m) => m.classList.remove("open"));
    }

    document.addEventListener("click", closeAllSubjectKebabMenus);

    /* RENAME SUBJECT MODAL */
    const renameSubjectModal = document.getElementById("renameSubjectModal");
    const renameSubjectModalCard = document.getElementById(
        "renameSubjectModalCard",
    );
    const renameSubjectCloseBtn = document.getElementById(
        "renameSubjectCloseBtn",
    );
    const renameSubjectCancelBtn = document.getElementById(
        "renameSubjectCancelBtn",
    );
    const renameSubjectConfirmBtn = document.getElementById(
        "renameSubjectConfirmBtn",
    );
    const rsSubjectName = document.getElementById("rsSubjectName");
    const rsNameError = document.getElementById("rsNameError");
    let renamingSubjectId = null;

    function openRenameModal(subject) {
        if (!renameSubjectModal) return;

        renamingSubjectId = subject.id;
        rsSubjectName.value = subject.name;
        rsSubjectName.classList.remove("error");
        rsNameError?.classList.add("hidden");

        renameSubjectModal.classList.add("visible");
        requestAnimationFrame(() =>
            renameSubjectModalCard?.classList.add("scaled"),
        );
        rsSubjectName?.focus();
    }

    function closeRenameModal() {
        if (!renameSubjectModal) return;

        renameSubjectModalCard?.classList.remove("scaled");
        renameSubjectModal.classList.remove("visible");
        renamingSubjectId = null;
    }

    renameSubjectCloseBtn?.addEventListener("click", closeRenameModal);
    renameSubjectCancelBtn?.addEventListener("click", closeRenameModal);

    renameSubjectModal?.addEventListener("click", (e) => {
        if (e.target === renameSubjectModal) closeRenameModal();
    });

    document.addEventListener("keydown", (e) => {
        if (
            e.key === "Escape" &&
            renameSubjectModal?.classList.contains("visible")
        )
            closeRenameModal();
    });

    rsSubjectName?.addEventListener("input", () => {
        if (rsSubjectName.value.trim() === "") return;
        rsSubjectName.classList.remove("error");
        rsNameError?.classList.add("hidden");
    });

    renameSubjectConfirmBtn?.addEventListener("click", async () => {
        const name = rsSubjectName?.value.trim() || "";

        if (name === "") {
            rsSubjectName?.classList.add("error");
            rsNameError?.classList.remove("hidden");
            rsSubjectName?.focus();
            return;
        }

        if (!renamingSubjectId) return;

        renameSubjectConfirmBtn.disabled = true;

        try {
            const res = await fetch(
                `/professor/subjects/${renamingSubjectId}`,
                {
                    method: "PUT",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-XSRF-TOKEN": getCsrfToken(),
                    },
                    body: JSON.stringify({ name }),
                },
            );

            if (!res.ok) {
                const message = await extractErrorMessage(
                    res,
                    "Could not rename the subject.",
                );
                showToast(message);
                return;
            }

            closeRenameModal();
            showToast("Subject renamed.");
            await loadSubjects();
        } catch (e) {
            showToast("Could not rename the subject. Please try again.");
        } finally {
            renameSubjectConfirmBtn.disabled = false;
        }
    });

    /* SUBJECT CARD CLICKS — kebab/rename, or open the subject's module-view page */
    subjectGrid?.addEventListener("click", (e) => {
        const kebabBtn = e.target.closest(".mat-subject-kebab-btn");
        if (kebabBtn) {
            e.stopPropagation();
            const menu = kebabBtn.nextElementSibling;
            const isOpen = menu?.classList.contains("open");
            closeAllSubjectKebabMenus();
            if (!isOpen) menu?.classList.add("open");
            return;
        }

        const renameItem = e.target.closest('.post-kebab-item[data-action="rename"]');
        if (renameItem) {
            e.stopPropagation();
            closeAllSubjectKebabMenus();
            const id = renameItem.closest(".mat-subject-card")?.dataset.id;
            const subject = subjects.find((s) => String(s.id) === String(id));
            if (subject) openRenameModal(subject);
            return;
        }

        const arrow = e.target.closest(".mat-subject-arrow");
        const card = e.target.closest(".mat-subject-card");
        if (!arrow && !card) return;

        const id = (arrow || card)?.closest(".mat-subject-card")?.dataset.id;
        if (!id) return;

        window.location.href = `professor-module-view.html?subject=${encodeURIComponent(id)}`;
    });

    // The first-visit tour waits for the subject cards so it can point at them.
    window.LQPageTour?.init({ key: "tour_seen_modules", steps: modulesTourSteps, ready: loadSubjects() });
});

/* GUIDED TOUR — common/page-tour.js */
function modulesTourSteps() {
    return [
        {
            title: "Welcome to Modules",
            body: "Modules is your library of learning materials. Upload a lesson file once and use it in any of your sections.",
        },
        {
            target: ".mat-stats-section",
            title: "Your library at a glance",
            body: "How many materials you have, the storage they use, how many sections they reach and when you last uploaded.",
        },
        {
            target: "#addSubjectBtn",
            title: "Add a subject",
            body: "Materials are grouped by subject, such as Chemistry. Start by adding the subjects you teach.",
        },
        {
            target: ".mat-search-wrap",
            title: "Search",
            body: "Type to find a subject quickly when your list grows.",
        },
        {
            // Just the first card, not the whole grid.
            target: () => document.querySelector(".mat-subject-card"),
            title: "Open a subject",
            body: "Click a subject to upload its lesson PDFs, post them to your classes and manage the competencies they teach. The ⋮ menu lets you rename it.",
            fallback: 'Your subjects will appear here as cards. Click "Add New Subject" to create your first one.',
        },
        {
            title: "That's Modules",
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}
