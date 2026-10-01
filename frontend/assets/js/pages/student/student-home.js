/* CARD COLOR PALETTE — cycled per class in the order the database returns them */
const CLASS_CARD_PALETTE = [
    { color: '#22d3ee', gradient: 'linear-gradient(135deg, #06b6d4, #0891b2)' },
    { color: '#f97316', gradient: 'linear-gradient(135deg, #fb923c, #ea580c)' },
    { color: '#a855f7', gradient: 'linear-gradient(135deg, #c084fc, #9333ea)' },
    { color: '#22c55e', gradient: 'linear-gradient(135deg, #4ade80, #16a34a)' },
    { color: '#f59e0b', gradient: 'linear-gradient(135deg, #fbbf24, #d97706)' },
];

/* NOTE: the old per-subject "Mastery" badges (MASTERY_BY_SUBJECT_KEY) only
   ever covered the 4 hardcoded canned subjects and have no data source for
   real, dynamically-joined classes — there's nothing left to wire them to
   now that #classGrid is rendered from the database, so they're not used
   here. Kept out entirely rather than left half-wired. */


/* INIT — runs when page loads*/
document.addEventListener('DOMContentLoaded', () => {
    hidePageLoader();
    updateHeroName();
    loadClasses();
});


/* PAGE LOADER: Itinatago ang loading screen pagkatapos mag-load ang lahat ng content */
function hidePageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;
    setTimeout(() => {
        loader.classList.add('hidden');
    }, 800);
}


/* HERO NAME: Kunin ang pangalan ng student at ilagay sa hero banner greeting*/
function updateHeroName() {
    const heroName = document.getElementById('heroName');
    if (!heroName) return;
    const profileName = document.getElementById('headerProfileName');
    if (profileName) {
        const fullText = profileName.textContent;
        const name = fullText.replace('Welcome, ', '').replace('!', '').trim();
        heroName.textContent = name;
    }
}


/* ENROLLED CLASSES — fetched from the database */
async function loadClasses() {
    const grid = document.getElementById('classGrid');
    if (!grid) return;

    try {
        const res = await fetch('/student/classes', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('failed to load classes');

        const classes = await res.json();
        renderClassGrid(classes);
    } catch (err) {
        grid.innerHTML = '<p class="class-grid-error">Could not load your classes. Please refresh the page.</p>';
    }
}

function renderClassGrid(classes) {
    const grid = document.getElementById('classGrid');
    if (!grid) return;

    if (!classes.length) {
        grid.innerHTML = '<p class="class-grid-empty">You haven\'t joined a class yet — use "Join a Class" above to get started.</p>';
        return;
    }

    grid.innerHTML = classes.map((cls) => buildClassCardHtml(cls)).join('');
}

function buildClassCardHtml(cls) {
    const palette = CLASS_CARD_PALETTE[cls.id % CLASS_CARD_PALETTE.length];
    const href = `enrolled-class.html?id=${cls.id}`;
    const teacherName = cls.professor?.name || 'Your teacher';

    const initials = teacherName
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('') || 'T';

    const metaParts = [];
    if (cls.subject) metaParts.push(cls.subject);
    if (cls.room) metaParts.push('Room ' + cls.room);

    return `
        <a href="${href}" class="class-card" style="--card-color: ${palette.color}">
            <div class="card-top" style="background: ${palette.gradient}">
                <div class="card-subject">${escapeHtml(cls.name.toUpperCase())}</div>
                <div class="card-section">${escapeHtml(cls.section || metaParts.join(' · '))}</div>
                <div class="card-teacher-photo">
                    ${
                        cls.professor?.avatar_url
                            ? `<img src="${escapeHtml(cls.professor.avatar_url)}" alt="">`
                            : `<div class="card-teacher-initials" style="display:flex;">${escapeHtml(initials)}</div>`
                    }
                </div>
            </div>
            <div class="card-bottom">
                <div class="card-teacher-info">
                    <p class="card-teacher-name">${escapeHtml(teacherName)}</p>
                    <p class="card-teacher-role">Teacher</p>
                </div>
                <span class="card-enter-btn">
                    Enter Classroom <i class="fas fa-arrow-right"></i>
                </span>
            </div>
        </a>
    `;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
