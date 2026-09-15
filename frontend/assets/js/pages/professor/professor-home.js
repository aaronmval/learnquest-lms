/* CARD COLOR PALETTE — cycled per class in the order the database returns them */
const CLASS_COLOR_PALETTE = [
    { color: '#22d3ee', gradient: 'linear-gradient(135deg, #06b6d4, #0891b2)' },
    { color: '#f97316', gradient: 'linear-gradient(135deg, #fb923c, #ea580c)' },
    { color: '#a855f7', gradient: 'linear-gradient(135deg, #c084fc, #9333ea)' },
    { color: '#22c55e', gradient: 'linear-gradient(135deg, #4ade80, #16a34a)' },
    { color: '#f59e0b', gradient: 'linear-gradient(135deg, #fbbf24, #d97706)' },
];


/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    updateHeroName();
    loadClasses();

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

    grid.innerHTML = classes.map((cls, index) => buildClassCardHtml(cls, index)).join('');
}

function buildClassCardHtml(cls, index) {
    const palette = CLASS_COLOR_PALETTE[index % CLASS_COLOR_PALETTE.length];
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
        <a href="${href}" class="class-card" style="--card-color: ${palette.color}">
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
        </a>
    `;
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

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
