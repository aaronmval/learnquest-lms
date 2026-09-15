/*  STATE */

// localStorage key used to hand a restored class back to the Home page.
// NOTE: restoring archived classes isn't wired to the database yet — this
// write side is currently unread (out of scope for the Create Class feature).
const RESTORED_CLASS_STORAGE_KEY = 'lq_restoredClass';


/* INIT */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    wireMyClassesDropdown();
    wireArchiveFilters();
    wireRestoreButtons();

});


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


/* ARCHIVE FILTER BAR */
function wireArchiveFilters() {
    const searchInput   = document.getElementById('archiveSearch');
    const searchClear   = document.getElementById('archiveSearchClear');
    const filterSubject = document.getElementById('filterSubject');
    const filterSection = document.getElementById('filterSection');
    const filterYear    = document.getElementById('filterYear');
    const resetBtn      = document.getElementById('archiveResetFilters');
    const resetEmptyBtn = document.getElementById('archiveEmptyReset');
    const resultCount   = document.getElementById('archiveResultCount');
    const emptyState    = document.getElementById('archiveEmpty');
    const cards         = document.querySelectorAll('#archiveList .archive-card');

    if (!searchInput) return;

    function applyFilters() {
        const query   = searchInput.value.trim().toLowerCase();
        const subject = filterSubject.value;
        const section = filterSection.value;
        const year    = filterYear.value;

        // Toggle clear button
        searchClear.classList.toggle('hidden', query === '');

        let visibleCount = 0;

        cards.forEach(card => {
            const nameMatch    = !query   || card.dataset.name.toLowerCase().includes(query);
            const subjectMatch = !subject || card.dataset.subject === subject;
            const sectionMatch = !section || card.dataset.section === section;
            const yearMatch    = !year    || card.dataset.year === year;

            const visible = nameMatch && subjectMatch && sectionMatch && yearMatch;
            card.classList.toggle('hidden', !visible);
            if (visible) visibleCount++;
        });

        // Update result count label
        if (resultCount) {
            resultCount.textContent = visibleCount === 1
                ? 'Showing 1 class'
                : `Showing ${visibleCount} class${visibleCount === 0 ? 'es' : 'es'}`;
        }

        if (emptyState) {
            emptyState.classList.toggle('hidden', visibleCount > 0);
        }
    }

    function resetFilters() {
        searchInput.value    = '';
        filterSubject.value  = '';
        filterSection.value  = '';
        filterYear.value     = '';
        applyFilters();
    }

    searchInput.addEventListener('input', applyFilters);
    searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); searchInput.focus(); });
    filterSubject.addEventListener('change', applyFilters);
    filterSection.addEventListener('change', applyFilters);
    filterYear.addEventListener('change', applyFilters);
    if (resetBtn)      resetBtn.addEventListener('click', resetFilters);
    if (resetEmptyBtn) resetEmptyBtn.addEventListener('click', resetFilters);

    applyFilters();
}


/* RESTORE BUTTONS */
function wireRestoreButtons() {
    const restoreButtons = document.querySelectorAll('#archiveList .archive-view-btn');

    restoreButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();

            const card = btn.closest('.archive-card');
            if (!card) return;

            const restoredData = buildRestoredClassData(card);

            try {
                localStorage.setItem(RESTORED_CLASS_STORAGE_KEY, JSON.stringify(restoredData));
            } catch (err) {
                // localStorage unavailable — magpatuloy pa rin ang redirect,
                // pero hindi na ma-a-auto-restore sa My Classes
            }

            // Alisin agad sa view bilang visual feedback
            card.classList.add('hidden');

            showToast(`"${restoredData.name}" restored! Redirecting to Home…`);

            setTimeout(() => {
                window.location.href = '../html/professorHome.html';
            }, 1200);
        });
    });
}

/* Kunin ang class info mula sa data attributes ng
   archive card, at i-shape into the same object format
   na ginagamit ng professorHome.js (createRestoredClassRecord) */
function buildRestoredClassData(card) {
    const subject = card.dataset.subject || 'Class';
    const section = card.dataset.section || '';
    const year    = card.dataset.year || '';

    // Galingan kunin ang students count mula sa meta row,
    // kung available — optional lang, may fallback naman
    const studentsText = card.querySelector('.archive-card-meta-item')?.textContent || '';
    const studentsMatch = studentsText.match(/(\d+)\s*Students?/i);
    const students = studentsMatch ? parseInt(studentsMatch[1], 10) : 0;

    const id = subject.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
        || 'class-' + Date.now();

    return {
        id,
        name: subject,
        section,
        subject,
        room: '',
        schoolYear: year,
        students,
        pending: 0,
        href: '../html/teachingChemistry.html'
    };
}


/* TOAST */
function showToast(text) {
    const toast        = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = text;
    toast.classList.add('visible');
    setTimeout(() => toast.classList.remove('visible'), 2500);
}
