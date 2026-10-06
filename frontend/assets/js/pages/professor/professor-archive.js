/* CARD COLOR PALETTE — keyed by class id, matching the same convention used
   on the Home page/navbar/banner so a class's color stays consistent. */
const CLASS_COLOR_PALETTE = [
    { color: '#22d3ee', gradient: 'linear-gradient(135deg, #06b6d4, #0891b2)' },
    { color: '#f97316', gradient: 'linear-gradient(135deg, #fb923c, #ea580c)' },
    { color: '#a855f7', gradient: 'linear-gradient(135deg, #c084fc, #9333ea)' },
    { color: '#22c55e', gradient: 'linear-gradient(135deg, #4ade80, #16a34a)' },
    { color: '#f59e0b', gradient: 'linear-gradient(135deg, #fbbf24, #d97706)' },
];

let archivedClasses = [];
let pendingConfirmAction = null;

function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
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

function formatDate(isoString) {
    if (!isoString) return '—';
    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}


/* INIT */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    wireMyClassesDropdown();
    const loaded = loadArchivedClasses();
    wireArchiveFilters();
    wireConfirmModal();

    // The first-visit tour waits for the list so it can point at it.
    window.LQPageTour?.init({ key: 'tour_seen_archive', steps: archiveTourSteps, ready: loaded });

});


/* GUIDED TOUR — common/page-tour.js */
function archiveTourSteps() {
    return [
        {
            title: 'Welcome to Archived Classes',
            body: 'Classes you have archived, such as sections from previous school years, are kept here with their records.',
        },
        {
            target: '.prof-stats-section',
            title: 'Archive summary',
            body: 'How many classes you have archived, how many students they had, and the subjects and sections they cover.',
        },
        {
            target: '.archive-filter-bar',
            title: 'Find a class',
            body: 'Search by class name or section, or filter by subject and section. Reset clears the filters.',
        },
        {
            // Just the first card's buttons, not every card's.
            target: () => document.querySelector('.archive-card-actions'),
            title: 'Restore or delete',
            body: 'Restore puts a class back on your Home page. Delete removes it for good, so you are asked to confirm first.',
            fallback: 'Each archived class appears here as a card with Restore and Delete buttons. Archive a class from its card on Home to see it here.',
        },
        {
            title: 'That\'s the archive',
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}


/* PAGE LOADER */
function hidePageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;
    setTimeout(() => loader.classList.add('hidden'), 800);
}


/* MY CLASSES SIDEBAR DROPDOWN*/
function wireMyClassesDropdown() {
    const toggleBtn = document.getElementById('myClassesToggleBtn');
    const container = document.getElementById('myClassesDropdownContainer');
    const chevron   = document.getElementById('myClassesChevron');
    const sidebar   = document.getElementById('sidebar');

    if (!toggleBtn || !container || !chevron) return;

    function openDropdown() {
        container.classList.add('open');
        chevron.classList.add('rotated');
        requestAnimationFrame(() => {
            container.style.maxHeight = container.scrollHeight + 'px';
        });
    }

    function closeDropdown() {
        container.style.maxHeight = '0px';
        container.classList.remove('open');
        chevron.classList.remove('rotated');
    }

    function toggleDropdown() {
        if (sidebar && sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarState', 'expanded');
        }
        container.classList.contains('open') ? closeDropdown() : openDropdown();
    }

    toggleBtn.addEventListener('click', toggleDropdown);
    requestAnimationFrame(() => openDropdown());

    window.__refreshMyClassesDropdownHeight = function () {
        if (container.classList.contains('open')) {
            requestAnimationFrame(() => {
                container.style.maxHeight = container.scrollHeight + 'px';
            });
        }
    };
}


/* LOAD — fetch the professor's archived classes from the database */
async function loadArchivedClasses() {
    try {
        const res = await fetch('/professor/classes/archived', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('failed to load archived classes');

        archivedClasses = await res.json();
    } catch (e) {
        archivedClasses = [];
        showToast('Could not load your archived classes. Please refresh the page.');
    }

    renderStats();
    populateFilterOptions();
    renderArchiveList();
}

function renderStats() {
    const statTotalArchived = document.getElementById('statTotalArchived');
    const statTotalStudents = document.getElementById('statTotalStudents');
    const statSubjectsRepresented = document.getElementById('statSubjectsRepresented');
    const statSectionsRepresented = document.getElementById('statSectionsRepresented');

    const totalStudents = archivedClasses.reduce((sum, c) => sum + (c.students_count || 0), 0);
    const subjects = new Set(archivedClasses.map((c) => c.subject).filter(Boolean));
    const sections = new Set(archivedClasses.map((c) => c.section).filter(Boolean));

    if (statTotalArchived) statTotalArchived.textContent = String(archivedClasses.length);
    if (statTotalStudents) statTotalStudents.textContent = String(totalStudents);
    if (statSubjectsRepresented) statSubjectsRepresented.textContent = String(subjects.size);
    if (statSectionsRepresented) statSectionsRepresented.textContent = String(sections.size);
}

function populateFilterOptions() {
    const filterSubject = document.getElementById('filterSubject');
    const filterSection = document.getElementById('filterSection');
    if (!filterSubject || !filterSection) return;

    const subjects = [...new Set(archivedClasses.map((c) => c.subject).filter(Boolean))].sort();
    const sections = [...new Set(archivedClasses.map((c) => c.section).filter(Boolean))].sort();

    const previousSubject = filterSubject.value;
    const previousSection = filterSection.value;

    filterSubject.innerHTML = '<option value="">All Subjects</option>' +
        subjects.map((s) => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('');
    filterSection.innerHTML = '<option value="">All Sections</option>' +
        sections.map((s) => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('');

    if (subjects.includes(previousSubject)) filterSubject.value = previousSubject;
    if (sections.includes(previousSection)) filterSection.value = previousSection;
}

function renderArchiveList() {
    const list = document.getElementById('archiveList');
    if (!list) return;

    list.innerHTML = archivedClasses.map((cls) => buildArchiveCardHtml(cls)).join('');

    applyFilters();
}

function buildArchiveCardHtml(cls) {
    const palette = CLASS_COLOR_PALETTE[cls.id % CLASS_COLOR_PALETTE.length];
    const studentsCount = cls.students_count || 0;
    const studentsLabel = studentsCount === 1 ? 'Student' : 'Students';
    const title = cls.name || 'Class';
    const section = cls.section || '';
    const displayName = [title, section].filter(Boolean).join(' · ');

    return `
        <div
            class="archive-card"
            data-id="${cls.id}"
            data-subject="${escapeHtml(cls.subject || '')}"
            data-section="${escapeHtml(section)}"
            data-name="${escapeHtml(displayName)}"
        >
            <div class="archive-card-color-bar" style="background: ${palette.gradient};"></div>
            <div class="archive-card-icon">
                <i class="fas fa-flask"></i>
            </div>
            <div class="archive-card-body">
                <div class="archive-card-top-row">
                    <div>
                        <p class="archive-card-title">${escapeHtml(title)}</p>
                        <p class="archive-card-section">${escapeHtml(section)}</p>
                    </div>
                </div>
                <div class="archive-card-meta-row">
                    <span class="archive-card-meta-item">
                        <i class="fas fa-users"></i> ${studentsCount} ${studentsLabel}
                    </span>
                    <span class="archive-card-meta-item">
                        <i class="fas fa-calendar-xmark"></i>
                        Archived ${escapeHtml(formatDate(cls.archived_at))}
                    </span>
                </div>
            </div>
            <div class="archive-card-actions">
                <button type="button" class="archive-view-btn" data-action="restore" data-id="${cls.id}">
                    <i class="fas fa-arrow-rotate-left"></i>
                    Restore
                </button>
                <button type="button" class="archive-delete-btn" data-action="delete" data-id="${cls.id}">
                    <i class="fas fa-trash-alt"></i>
                    Delete
                </button>
            </div>
        </div>`;
}

document.getElementById('archiveList')?.addEventListener('click', async (e) => {
    const restoreBtn = e.target.closest('[data-action="restore"]');
    if (restoreBtn) {
        await restoreClass(restoreBtn.dataset.id);
        return;
    }

    const deleteBtn = e.target.closest('[data-action="delete"]');
    if (deleteBtn) {
        const card = deleteBtn.closest('.archive-card');
        const name = card?.dataset.name || 'this class';
        openConfirm(
            'Delete class permanently?',
            `This will permanently delete "${name}" and all of its posts, enrollments, and materials. This cannot be undone.`,
            () => deleteClass(deleteBtn.dataset.id),
        );
    }
});

async function restoreClass(id) {
    try {
        const res = await fetch(`/professor/classes/${id}/restore`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });
        if (!res.ok) throw new Error('failed to restore class');

        showToast('Class restored — find it back on your Home page.');
        archivedClasses = archivedClasses.filter((c) => String(c.id) !== String(id));
        renderStats();
        populateFilterOptions();
        renderArchiveList();
    } catch (e) {
        showToast('Could not restore the class. Please try again.');
    }
}

async function deleteClass(id) {
    try {
        const res = await fetch(`/professor/classes/${id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });
        if (!res.ok) throw new Error('failed to delete class');

        showToast('Class deleted permanently.');
        archivedClasses = archivedClasses.filter((c) => String(c.id) !== String(id));
        renderStats();
        populateFilterOptions();
        renderArchiveList();
    } catch (e) {
        showToast('Could not delete the class. Please try again.');
    }
}


/* ARCHIVE FILTER BAR */
function wireArchiveFilters() {
    const searchInput   = document.getElementById('archiveSearch');
    const searchClear   = document.getElementById('archiveSearchClear');
    const filterSubject = document.getElementById('filterSubject');
    const filterSection = document.getElementById('filterSection');
    const resetBtn      = document.getElementById('archiveResetFilters');
    const resetEmptyBtn = document.getElementById('archiveEmptyReset');

    if (!searchInput) return;

    searchInput.addEventListener('input', applyFilters);
    searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); searchInput.focus(); });
    filterSubject.addEventListener('change', applyFilters);
    filterSection.addEventListener('change', applyFilters);
    if (resetBtn)      resetBtn.addEventListener('click', resetFilters);
    if (resetEmptyBtn) resetEmptyBtn.addEventListener('click', resetFilters);
}

function resetFilters() {
    const searchInput   = document.getElementById('archiveSearch');
    const filterSubject = document.getElementById('filterSubject');
    const filterSection = document.getElementById('filterSection');

    if (searchInput) searchInput.value = '';
    if (filterSubject) filterSubject.value = '';
    if (filterSection) filterSection.value = '';
    applyFilters();
}

function applyFilters() {
    const searchInput   = document.getElementById('archiveSearch');
    const searchClear   = document.getElementById('archiveSearchClear');
    const filterSubject = document.getElementById('filterSubject');
    const filterSection = document.getElementById('filterSection');
    const resultCount   = document.getElementById('archiveResultCount');
    const emptyState    = document.getElementById('archiveEmpty');
    const emptyTitle    = document.getElementById('archiveEmptyTitle');
    const cards         = document.querySelectorAll('#archiveList .archive-card');

    if (!searchInput) return;

    const query   = searchInput.value.trim().toLowerCase();
    const subject = filterSubject.value;
    const section = filterSection.value;

    searchClear.classList.toggle('hidden', query === '');

    let visibleCount = 0;

    cards.forEach(card => {
        const nameMatch    = !query   || card.dataset.name.toLowerCase().includes(query);
        const subjectMatch = !subject || card.dataset.subject === subject;
        const sectionMatch = !section || card.dataset.section === section;

        const visible = nameMatch && subjectMatch && sectionMatch;
        card.classList.toggle('hidden', !visible);
        if (visible) visibleCount++;
    });

    if (resultCount) {
        resultCount.textContent = visibleCount === 1
            ? 'Showing 1 class'
            : `Showing ${visibleCount} classes`;
    }

    if (emptyState) {
        emptyState.classList.toggle('hidden', visibleCount > 0);
    }
    if (emptyTitle) {
        emptyTitle.textContent = archivedClasses.length
            ? 'No classes found'
            : 'No archived classes yet';
    }
}


/* CONFIRM MODAL (delete class) */
function wireConfirmModal() {
    const modal = document.getElementById('confirmActionModal');
    const cancelBtn = document.getElementById('confirmActionCancelBtn');
    const confirmBtn = document.getElementById('confirmActionConfirmBtn');

    cancelBtn?.addEventListener('click', closeConfirm);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeConfirm();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal?.classList.contains('visible')) closeConfirm();
    });
    confirmBtn?.addEventListener('click', () => {
        const action = pendingConfirmAction;
        closeConfirm();
        action?.();
    });
}

function openConfirm(title, desc, onConfirm) {
    const modal = document.getElementById('confirmActionModal');
    const titleEl = document.getElementById('confirmActionTitle');
    const descEl = document.getElementById('confirmActionDesc');

    if (titleEl) titleEl.textContent = title;
    if (descEl) descEl.textContent = desc;
    pendingConfirmAction = onConfirm;
    modal?.classList.add('visible');
}

function closeConfirm() {
    document.getElementById('confirmActionModal')?.classList.remove('visible');
    pendingConfirmAction = null;
}


/* TOAST — id must not be "toast": the host shell hides #toast inside the content iframe */
let archiveToastTimer = null;
function showToast(text) {
    const toast        = document.getElementById('archiveToast');
    const toastMessage = document.getElementById('archiveToastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = text;
    toast.classList.add('visible');
    clearTimeout(archiveToastTimer);
    archiveToastTimer = setTimeout(() => toast.classList.remove('visible'), 2500);
}
