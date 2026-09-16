/* CARD COLOR PALETTE — keyed by class id, so a class's color stays the same
   everywhere (home page, navbar sidebar, banner) regardless of list order. */
const CLASS_COLOR_PALETTE = [
    { color: '#22d3ee', gradient: 'linear-gradient(135deg, #06b6d4, #0891b2)' },
    { color: '#f97316', gradient: 'linear-gradient(135deg, #fb923c, #ea580c)' },
    { color: '#a855f7', gradient: 'linear-gradient(135deg, #c084fc, #9333ea)' },
    { color: '#22c55e', gradient: 'linear-gradient(135deg, #4ade80, #16a34a)' },
    { color: '#f59e0b', gradient: 'linear-gradient(135deg, #fbbf24, #d97706)' },
];

function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
}

let homeToastTimer = null;
function showToast(text) {
    const toast = document.getElementById('homeToast');
    const toastMessage = document.getElementById('homeToastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = text;
    toast.classList.add('visible');
    clearTimeout(homeToastTimer);
    homeToastTimer = setTimeout(() => toast.classList.remove('visible'), 2500);
}


/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    updateHeroName();
    loadClasses();
    loadStats();
    loadArchivedTeaser();

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


/* MY CLASSES — fetched from the database */
async function loadClasses() {
    const grid = document.getElementById('classGrid');
    if (!grid) return;

    try {
        const res = await fetch('/professor/classes', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('failed to load classes');

        const classes = await res.json();
        renderClassGrid(classes);
        updateHeroAnnouncementLink(classes);
    } catch (err) {
        grid.innerHTML = '<p class="class-grid-error">Could not load your classes. Please refresh the page.</p>';
    }
}

function renderClassGrid(classes) {
    const grid = document.getElementById('classGrid');
    if (!grid) return;

    if (!classes.length) {
        grid.innerHTML = '<p class="class-grid-empty">You haven\'t created a class yet — use "Create Class" above to get started.</p>';
        return;
    }

    grid.innerHTML = classes.map((cls) => buildClassCardHtml(cls)).join('');
}

function buildClassCardHtml(cls) {
    const palette = CLASS_COLOR_PALETTE[cls.id % CLASS_COLOR_PALETTE.length];
    const href = `professor-class.html?id=${cls.id}`;

    const initials = cls.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('') || 'CL';

    const metaParts = [];
    if (cls.subject) metaParts.push(cls.subject);
    if (cls.room) metaParts.push('Room ' + cls.room);

    return `
        <div class="class-card" data-id="${cls.id}" style="--card-color: ${palette.color}">
            <a href="${href}" class="class-card-link" aria-label="Open ${escapeHtml(cls.name)}"></a>
            <button class="class-card-archive-btn" type="button" data-action="archive" title="Archive" aria-label="Archive class">
                <i class="fas fa-box-archive"></i>
            </button>
            <div class="card-top" style="background: ${palette.gradient}">
                <div class="card-subject">${escapeHtml(cls.name.toUpperCase())}</div>
                <div class="card-section">${escapeHtml(cls.section || metaParts.join(' · '))}</div>
                <div class="card-teacher-photo">
                    <div class="card-teacher-initials" style="display:flex;">${escapeHtml(initials)}</div>
                </div>
            </div>
            <div class="card-bottom">
                <div class="card-teacher-info">
                    <p class="card-teacher-name">Class details</p>
                    <p class="card-teacher-role">${escapeHtml(metaParts.join(' · ') || 'No subject/room set')}</p>
                </div>
                <span class="card-enter-btn">
                    Manage Class <i class="fas fa-arrow-right"></i>
                </span>
            </div>
        </div>
    `;
}

document.getElementById('classGrid')?.addEventListener('click', async (e) => {
    const archiveBtn = e.target.closest('[data-action="archive"]');
    if (!archiveBtn) return;

    e.preventDefault();
    e.stopPropagation();
    const card = archiveBtn.closest('.class-card');
    const id = card?.dataset.id;
    if (id) await archiveClass(id);
});

async function archiveClass(id) {
    try {
        const res = await fetch(`/professor/classes/${id}/archive`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });

        if (!res.ok) throw new Error('failed to archive class');

        showToast('Class archived.');
        await loadClasses();
        await loadArchivedTeaser();
    } catch (e) {
        showToast('Could not archive the class. Please try again.');
    }
}

function updateHeroAnnouncementLink(classes) {
    const btn = document.getElementById('heroAnnouncementBtn');
    if (!btn) return;

    if (!classes.length) {
        btn.classList.add('hidden');
        return;
    }

    btn.href = `professor-class.html?id=${classes[0].id}`;
    btn.classList.remove('hidden');
}

/* QUICK STATS — real numbers from /professor/classes and /professor/subjects */
async function loadStats() {
    const statTotalStudents = document.getElementById('statTotalStudents');
    const statTotalMaterials = document.getElementById('statTotalMaterials');
    const statActiveSections = document.getElementById('statActiveSections');
    const statSubjects = document.getElementById('statSubjects');

    try {
        const [classesRes, subjectsRes] = await Promise.all([
            fetch('/professor/classes', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
            fetch('/professor/subjects', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
        ]);

        const classes = classesRes.ok ? await classesRes.json() : [];
        const subjects = subjectsRes.ok ? await subjectsRes.json() : [];

        const totalStudents = classes.reduce((sum, c) => sum + (c.students_count || 0), 0);
        const totalMaterials = subjects.reduce((sum, s) => sum + (s.modules_count || 0), 0);

        if (statTotalStudents) statTotalStudents.textContent = String(totalStudents);
        if (statTotalMaterials) statTotalMaterials.textContent = String(totalMaterials);
        if (statActiveSections) statActiveSections.textContent = String(classes.length);
        if (statSubjects) statSubjects.textContent = String(subjects.length);
    } catch (e) {
        // Leave the stat cards at their default markup on failure — the
        // class grid's own error state already tells the professor
        // something's wrong with the connection.
    }
}

/* ARCHIVED CLASSES TEASER — up to 2 most recently archived */
async function loadArchivedTeaser() {
    const list = document.getElementById('archiveList');
    if (!list) return;

    try {
        const res = await fetch('/professor/classes/archived', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('failed to load archived classes');

        const archived = await res.json();
        renderArchivedTeaser(archived.slice(0, 2));
    } catch (e) {
        renderArchivedTeaser([]);
    }
}

function renderArchivedTeaser(classes) {
    const list = document.getElementById('archiveList');
    if (!list) return;

    const viewAllLink = `
        <div class="prof-archive-empty-link">
            <a href="professor-archive.html">
                View all archived classes
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>`;

    if (!classes.length) {
        list.innerHTML = `
            <p class="class-grid-empty">No archived classes yet.</p>
            ${viewAllLink}`;
        return;
    }

    const rows = classes.map((cls) => {
        const studentsCount = cls.students_count || 0;
        const studentsLabel = studentsCount === 1 ? 'Student' : 'Students';
        const archivedDate = formatDate(cls.archived_at);
        const title = [cls.name, cls.section].filter(Boolean).join(' · ');

        return `
            <div class="prof-archive-row">
                <div class="prof-archive-icon">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="prof-archive-text">
                    <p class="prof-archive-title">${escapeHtml(title)}</p>
                    <p class="prof-archive-meta">
                        ${studentsCount} ${studentsLabel} &middot; Archived ${escapeHtml(archivedDate)}
                    </p>
                </div>
                <a href="professor-archive.html" class="prof-archive-action">
                    <i class="fas fa-box-open"></i> View
                </a>
            </div>`;
    }).join('');

    list.innerHTML = rows + viewAllLink;
}

function formatDate(isoString) {
    if (!isoString) return '—';
    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
