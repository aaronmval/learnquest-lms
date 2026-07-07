/* STATE */
let professorClasses = [
    {
        id: 'chemistry',
        name: 'Chemistry',
        section: 'STEM - Amethyst',
        subject: 'Chemistry',
        room: '',
        color: '#22d3ee',
        gradient: 'linear-gradient(135deg, #06b6d4, #0891b2)',
        href: '../html/teachingChemistry.html',
        students: 42,
        pending: 8
    }
];

const CLASS_COLOR_PALETTE = [
    { color: '#22d3ee', gradient: 'linear-gradient(135deg, #06b6d4, #0891b2)' },
    { color: '#f97316', gradient: 'linear-gradient(135deg, #fb923c, #ea580c)' },
    { color: '#a855f7', gradient: 'linear-gradient(135deg, #c084fc, #9333ea)' },
    { color: '#22c55e', gradient: 'linear-gradient(135deg, #4ade80, #16a34a)' },
    { color: '#f59e0b', gradient: 'linear-gradient(135deg, #fbbf24, #d97706)' },
];

let nextPaletteIndex = 1; 

// localStorage key na ginagamit ng professorArchive.js
// para i-pasa ang na-restore na class papunta dito
const RESTORED_CLASS_STORAGE_KEY = 'lq_restoredClass';


/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    updateHeroName();
    wireMyClassesDropdown();
    wireCreateClassModal();
    checkForRestoredClass();

});


/* PAGE LOADER */
function hidePageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;

    setTimeout(() => {
        loader.classList.add('hidden');
    }, 800);
}


/*  HERO NAME */
function updateHeroName() {
    const heroName = document.getElementById('heroName');
    if (!heroName) return;

    const profileName = document.getElementById('headerProfileName');
    if (profileName) {
        // "Welcome, Ma'am Mila!" → "Ma'am Mila"
        const fullText = profileName.textContent;
        const name = fullText.replace('Welcome, ', '').replace('!', '').trim();
        heroName.textContent = name;
    }
}


/* MY CLASSES SIDEBAR DROPDOWN*/
function wireMyClassesDropdown() {
    const toggleBtn = document.getElementById('myClassesToggleBtn');
    const container = document.getElementById('myClassesDropdownContainer');
    const chevron    = document.getElementById('myClassesChevron');
    const sidebar    = document.getElementById('sidebar');

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
        // Kung collapsed ang sidebar, i-expand muna
        if (sidebar && sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarState', 'expanded');
        }

        if (container.classList.contains('open')) {
            closeDropdown();
        } else {
            openDropdown();
        }
    }

    toggleBtn.addEventListener('click', toggleDropdown);

    // I-expand by default sa simula para makita agad ang Chemistry link
    requestAnimationFrame(() => openDropdown());

    // I-expose para magamit ng addClassToUI() pagkatapos magdagdag ng bagong class
    window.__refreshMyClassesDropdownHeight = function () {
        if (container.classList.contains('open')) {
            requestAnimationFrame(() => {
                container.style.maxHeight = container.scrollHeight + 'px';
            });
        }
    };
}


/* RESTORE FROM ARCHIVE*/
function checkForRestoredClass() {
    let raw;
    try {
        raw = localStorage.getItem(RESTORED_CLASS_STORAGE_KEY);
    } catch (err) {
        return; // localStorage unavailable (private mode, etc.)
    }

    if (!raw) return;

    // Burahin agad para hindi paulit-ulit, kahit pa mag-fail ang parsing sa baba
    try {
        localStorage.removeItem(RESTORED_CLASS_STORAGE_KEY);
    } catch (err) { /* ignore */ }

    let data;
    try {
        data = JSON.parse(raw);
    } catch (err) {
        return; // corrupted data, wag na ituloy
    }

    if (!data || !data.name) return;

    // Iwasan ang duplicate kung sakaling existing na ang class na ito (same id) sa professorClasses
    const alreadyExists = professorClasses.some(c => c.id === data.id);
    if (alreadyExists) {
        showToast(`"${data.name}" is already in My Classes.`);
        return;
    }

    const restoredClass = createRestoredClassRecord(data);
    professorClasses.push(restoredClass);
    addClassToUI(restoredClass);

    showToast(`"${restoredClass.name}" has been restored to My Classes!`);
}

function createRestoredClassRecord(data) {
    const palette = CLASS_COLOR_PALETTE[nextPaletteIndex % CLASS_COLOR_PALETTE.length];
    nextPaletteIndex++;

    const id = data.id ||
        (data.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'class-' + Date.now());

    return {
        id,
        name: data.name,
        section: data.section || '',
        subject: data.subject || data.name,
        room: data.room || '',
        color: data.color || palette.color,
        gradient: data.gradient || palette.gradient,
        href: data.href || '../html/teachingChemistry.html',
        students: typeof data.students === 'number' ? data.students : 0,
        pending: typeof data.pending === 'number' ? data.pending : 0
    };
}


/* CREATE CLASS MODAL */
function wireCreateClassModal() {
    const createBtn  = document.getElementById('createClassBtn');
    const modal       = document.getElementById('createClassModal');
    const modalCard    = document.getElementById('createClassModalCard');
    const closeBtn     = document.getElementById('createClassCloseBtn');
    const cancelBtn     = document.getElementById('createClassCancelBtn');
    const confirmBtn    = document.getElementById('createClassConfirmBtn');

    const nameInput     = document.getElementById('ccClassName');
    const sectionInput  = document.getElementById('ccSection');
    const subjectInput  = document.getElementById('ccSubject');
    const roomInput     = document.getElementById('ccRoom');
    const nameError     = document.getElementById('ccNameError');

    if (!createBtn || !modal) return;

    function resetForm() {
        nameInput.value    = '';
        sectionInput.value = '';
        subjectInput.value = '';
        roomInput.value    = '';
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    }

    function openModal() {
        resetForm();
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.opacity = '1';
            modalCard.classList.add('scaled');
            nameInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.style.opacity = '0';
        modalCard.classList.remove('scaled');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    function confirmCreate() {
        const className   = nameInput.value.trim();
        const sectionVal   = sectionInput.value.trim();
        const subjectVal   = subjectInput.value.trim();
        const roomVal       = roomInput.value.trim();

        if (!className) {
            nameError.textContent = '*Required';
            nameError.classList.remove('hidden');
            nameInput.classList.add('error');
            nameInput.focus();
            return;
        }

        nameError.classList.add('hidden');
        nameInput.classList.remove('error');

        const newClass = createClassRecord(className, sectionVal, subjectVal, roomVal);
        professorClasses.push(newClass);
        addClassToUI(newClass);

        closeModal();
        showToast(`Class "${newClass.name}" created!`);
    }

    createBtn.addEventListener('click', openModal);
    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)  cancelBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', confirmCreate);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    [nameInput, sectionInput, subjectInput, roomInput].forEach(field => {
        field.addEventListener('keydown', e => {
            if (e.key === 'Enter') confirmCreate();
        });
    });

    // Clear error state habang nagtatype ang professor
    nameInput.addEventListener('input', () => {
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    });
}


/* Gumawa ng bagong class object base sa form input*/
function createClassRecord(name, section, subject, room) {
    const palette = CLASS_COLOR_PALETTE[nextPaletteIndex % CLASS_COLOR_PALETTE.length];
    nextPaletteIndex++;

    const id = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'class-' + Date.now();

    return {
        id,
        name,
        section: section || '',
        subject: subject || name,
        room: room || '',
        color: palette.color,
        gradient: palette.gradient,
        href: '../html/teachingChemistry.html', // placeholder destination until a dedicated page exists
        students: 0,
        pending: 0
    };
}


/*  Idagdag ang bagong class sa "My Classes" grid card  AT sa sidebar dropdown parehong nag-uupdate
 nang sabay tuwing may na-create o na-restore.*/
function addClassToUI(cls) {
    addClassCard(cls);
    addClassSidebarLink(cls);
}

function addClassCard(cls) {
    const grid = document.getElementById('classGrid');
    if (!grid) return;

    const card = document.createElement('a');
    card.href = cls.href;
    card.className = 'class-card';
    card.style.setProperty('--card-color', cls.color);

    const initials = cls.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('') || 'CL';

    const metaParts = [];
    if (cls.subject) metaParts.push(cls.subject);
    if (cls.room) metaParts.push('Room ' + cls.room);

    card.innerHTML = `
        <div class="card-top" style="background:${cls.gradient}">
            <div class="card-subject">${escapeHtml(cls.name.toUpperCase())}</div>
            <div class="card-section">${escapeHtml(cls.section || metaParts.join(' · '))}</div>
            <div class="card-teacher-photo">
                <div class="card-teacher-initials" style="display:flex;">${escapeHtml(initials)}</div>
            </div>
        </div>
        <div class="card-bottom">
            <div class="card-teacher-info">
                <p class="card-teacher-name">${cls.students} Students</p>
                <p class="card-teacher-role">${cls.pending} Pending Submissions</p>
            </div>
            <span class="card-enter-btn">
                Manage Class <i class="fas fa-arrow-right"></i>
            </span>
        </div>
    `;

    grid.appendChild(card);
}

function addClassSidebarLink(cls) {
    const container = document.getElementById('myClassesDropdownContainer');
    if (!container) return;

    const link = document.createElement('a');
    link.href = cls.href;
    link.id = 'nav-' + cls.id;
    link.className = 'sidebar-link sub-link';
    link.dataset.parent = 'My Classes';
    link.dataset.child = cls.name;
    link.innerHTML = `
        <span class="dot" style="background-color:${cls.color}"></span>
        <span class="sidebar-text">${escapeHtml(cls.name)}</span>
    `;

    container.appendChild(link);

    // I-recalculate ang max-height ng dropdown para magkasya ang bagong link
    if (typeof window.__refreshMyClassesDropdownHeight === 'function') {
        window.__refreshMyClassesDropdownHeight();
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}


/* TOAST */
function showToast(text) {
    const toast        = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = text;
    toast.classList.add('visible');

    setTimeout(() => {
        toast.classList.remove('visible');
    }, 2500);
}
