/* KUNIN LAHAT NG ELEMENTS*/
const sidebar          = document.getElementById('sidebar');
const sidebarOverlay   = document.getElementById('sidebarOverlay');
const notiDrawer       = document.getElementById('notiDrawer');
const logoutModal      = document.getElementById('logoutModal');
const logoutModalCard  = document.getElementById('logoutModalCard');
const notiBadge        = document.getElementById('notiBadge');
const notiList         = document.getElementById('notiList');
const breadcrumbParent = document.getElementById('breadcrumb-parent');
const breadcrumbChild  = document.getElementById('breadcrumb-child');
const darkModeIcon     = document.getElementById('darkModeIcon');
const toast            = document.getElementById('toast');
const toastMessage     = document.getElementById('toastMessage');
const chevronIcon      = document.getElementById('chevron-icon');
const dropdown         = document.getElementById('courses-dropdown-container');
const pageLoader       = document.getElementById('pageLoader');


/* ────────────────────────────────
   SAFE EVENT-BINDING HELPER
   Hindi lahat ng pages (e.g. professor pages) ay may
   lahat ng elements/buttons (e.g. walang labsToggleBtn,
   joinClassBtn, atbp). Kung diretsong tatawagin natin
   ang .addEventListener sa null, mag-throw ito ng error
   at titigil ang BUONG DOMContentLoaded callback — kasama
   na yung mga listener na dapat sana ay gumana
   (dark mode, notifications, atbp).
   Gamit nito, kung wala ang element, ski-skip lang siya
   nang tahimik imbes na ipatigil ang lahat.
──────────────────────────────── */
function on(id, event, handler) {
    const el = document.getElementById(id);
    if (el) el.addEventListener(event, handler);
}


/* NOTIFICATIONS DATA */
let notifications = [
    { id: 1, text: "Welcome back, Aaron! Today's lesson is Classical Mechanics in physics.", time: "Just Now", read: false },
    { id: 2, text: "Chemistry assignment 'Covalent Bonding quiz' has been posted.",           time: "2h ago",  read: false },
    { id: 3, text: "Streak achievement unlocked: 4 Days Active!",                             time: "1d ago",  read: true  }
];


/*INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    // Restore dark mode kung naka-save sa browser
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark');
        if (darkModeIcon) darkModeIcon.className = 'fas fa-sun';
    }

    //Restore sidebar state (collapsed or expanded) 
    // I-disable muna ang transition para walang sliding animation on page load
    if (sidebar) {
        sidebar.style.transition = 'none';
        if (localStorage.getItem('sidebarState') === 'expanded') {
            sidebar.classList.remove('collapsed');
        } else {
            sidebar.classList.add('collapsed');
        }
        // I-re-enable ang transition after one frame  para smooth na ulit pag mag-toggle
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                sidebar.style.transition = '';
            });
        });
    }

    // I-highlight yung active nav link base sa current URL
    highlightActiveLink();

    renderNotifications();

    // Sidebar toggles
    on('mobileSidebarToggle', 'click', openMobileSidebar);
    on('desktopSidebarToggle', 'click', toggleDesktopSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeMobileSidebar);

    // I-close ang mobile sidebar pag nag-click ng link
    document.querySelectorAll('a.sidebar-link').forEach(link => {
        link.addEventListener('click', closeMobileSidebar);
    });

    // Enrolled dropdown (student pages only)
    on('labsToggleBtn', 'click', toggleEnrolledDropdown);

    //Header buttons
    on('darkModeBtn', 'click', toggleDarkMode);
    on('notiBtn', 'click', toggleNotifications);

    //Join Class (student pages only)
    on('joinClassBtn', 'click', openJoinClass);
    on('joinClassCloseBtn', 'click', closeJoinClassModal);
    on('joinClassCancelBtn', 'click', closeJoinClassModal);
    on('joinClassConfirmBtn', 'click', confirmJoinClass);
    if (joinClassModal) {
        joinClassModal.addEventListener('click', e => {
            if (e.target === joinClassModal) closeJoinClassModal();
        });
    }

    // Notifications
    on('clearNotiBtn', 'click', clearNotifications);

    //Logout
    on('signoutBtn', 'click', openLogoutModal);
    on('logoutCancelBtn', 'click', closeLogoutModal);
    on('logoutConfirmBtn', 'click', confirmLogout);

    //Page preloader for tab/link navigation
    setupPageTransitions();

    // Breadcrumb: click -> go back to a page-specific target ──
    // May explicit na data-href ang span? Gamitin agad ito (highest priority,
    // override kung kailangan ng ibang page).
    // Kung wala, gamitin ang resolveBreadcrumbDefaultHref() na nag-iiba base
    // sa text ng breadcrumb-parent ("Main" -> professorDashboard/studentDashboard,
    // "My Classes" -> professorHome). Tingnan ang function sa ibaba.
    if (breadcrumbParent) {
        breadcrumbParent.addEventListener('click', () => {
            const targetHref = breadcrumbParent.dataset.href || resolveBreadcrumbDefaultHref();

            // Skip kung target na mismo ang current page
            const currentPath = window.location.pathname;
            const targetPath  = new URL(targetHref, window.location.href).pathname;
            if (currentPath === targetPath) return;

            navigateWithPreload(targetHref);
        });
    }

});


// I-resolve ang default breadcrumb target kung walang data-href na naka-set
// sa span. Dependent sa dalawang bagay:
//   1. Ano ang kasalukuyang text ng breadcrumb-parent ("Main" vs "My Classes")
//   2. Professor page ba tayo o student page (tingnan natin kung may
//      professorHome.html link sa sidebar — ginagamit lang ito ng professor pages)
//
// "Main"        -> professorDashboard.html (professor) / studentDashboard.html (student)
// "My Classes"  -> professorHome.html (professor) / studentDashboard.html (student, walang
//                  katumbas na "home" page kaya dashboard din ang default)
function resolveBreadcrumbDefaultHref() {
    const isProfessorPage = !!document.querySelector('a[href*="professorHome.html"], a[href*="professorDashboard.html"]');
    const parentText = breadcrumbParent.textContent.trim();

    if (isProfessorPage) {
        if (parentText === 'My Classes') return '../html/professorHome.html';
        return '../html/professorDashboard.html';
    }

    return '../html/studentDashboard.html';
}


/* PAGE LOADER / PRELOADING ON NAVIGATION */

// Kapag tapos na ma-load ang page, i-fade out ang loader overlay (preloader stays visible for 3 seconds)
window.addEventListener('load', () => {
    setTimeout(() => {
        if (pageLoader) pageLoader.classList.add('hidden');
    }, 3000);
});

// Failsafe kung sakaling matagal o di-na-trigger ang 'load' event
setTimeout(() => {
    if (pageLoader && !pageLoader.classList.contains('hidden')) {
        pageLoader.classList.add('hidden');
    }
}, 3500);

// I-show ang preloader, hintayin ang 3 seconds, tapos mag-navigate 
// sa ibinigay na href. Ginagamit ng tab links at ng breadcrumb.
function navigateWithPreload(href) {
    if (pageLoader) pageLoader.classList.remove('hidden');

    setTimeout(() => {
        window.location.href = href;
    }, 3000);
}

//Kapag nag-click ng tab (sidebar link, sub-link, o logo) na may
// valid na href, ipakita muna ang preloader overlay bago mag-navigate
// sa bagong page  para may "loading" feel sa pagpalit ng tab.
function setupPageTransitions() {
    const navLinks = document.querySelectorAll('a.sidebar-link, a.logo');

    navLinks.forEach(link => attachPageTransition(link));
}

function attachPageTransition(link) {
    // Iwasan ang double-binding
    if (link.dataset.preloadBound === 'true') return;
    link.dataset.preloadBound = 'true';

    link.addEventListener('click', e => {
        const href   = link.getAttribute('href');
        const target = link.getAttribute('target');

        // Skip kung walang href, hash link, o bubukas sa bagong tab
        if (!href || href === '#' || href.startsWith('#') || target === '_blank') return;

        // Skip kung naka-active na sa current page (walang reload kailangan)
        if (link.classList.contains('nav-active')) return;

        e.preventDefault();
        navigateWithPreload(href);
    });
}


/* ACTIVE LINK — auto-highlight base sa URL*/
function setActiveSidebarLink(linkId) {
    const link = document.getElementById(linkId);
    if (!link) return;

    document.querySelectorAll('a.sidebar-link').forEach(l => l.classList.remove('nav-active'));
    link.classList.add('nav-active');

    if (dropdown && link.closest('#courses-dropdown-container')) {
        requestAnimationFrame(() => openEnrolledDropdown());
    }
}

window.setActiveSidebarLink = setActiveSidebarLink;

function highlightActiveLink() {
    const currentPath = window.location.pathname;

    document.querySelectorAll('a.sidebar-link').forEach(link => {
        const linkPath = new URL(link.href, window.location.origin).pathname;

        const isActive = currentPath === linkPath
                      || currentPath.startsWith(linkPath.replace('index.html', ''));

        if (isActive) {
            link.classList.add('nav-active');

            if (breadcrumbParent) breadcrumbParent.textContent = link.dataset.parent || 'Main';
            if (breadcrumbChild)  breadcrumbChild.textContent  = link.dataset.child  || '';

            // FIX: Defer openEnrolledDropdown so scrollHeight is read AFTER
            // the browser has painted — avoids getting 0 when sidebar is collapsed
            if (dropdown && link.closest('#courses-dropdown-container')) {
                requestAnimationFrame(() => openEnrolledDropdown());
            }
        } else {
            link.classList.remove('nav-active');
        }
    });
}


/* SIDEBAR*/
function openMobileSidebar() {
    if (sidebar) sidebar.classList.add('open');
    if (sidebarOverlay) sidebarOverlay.classList.add('visible');
}

function closeMobileSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (sidebarOverlay) sidebarOverlay.classList.remove('visible');
}

function toggleDesktopSidebar() {
    if (!sidebar) return;
    sidebar.classList.toggle('collapsed');

    // I-save ang bagong state sa localStorage
    const isCollapsed = sidebar.classList.contains('collapsed');
    localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'expanded');
}


/* ENROLLED DROPDOWN (student pages only)*/

// FIX: Read scrollHeight inside requestAnimationFrame so it reflects
// the actual rendered height, not 0 (which happens when sidebar is collapsed)
function openEnrolledDropdown() {
    if (!dropdown || !chevronIcon) return;
    dropdown.classList.add('open');
    chevronIcon.classList.add('rotated');
    requestAnimationFrame(() => {
        dropdown.style.maxHeight = dropdown.scrollHeight + 'px';
    });
}

function toggleEnrolledDropdown() {
    if (!dropdown || !sidebar) return;

    // Kung collapsed ang sidebar, i-expand muna at i-save
    if (sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarState', 'expanded');
    }

    if (dropdown.classList.contains('open')) {
        dropdown.style.maxHeight = '0px';
        dropdown.classList.remove('open');
        if (chevronIcon) chevronIcon.classList.remove('rotated');
    } else {
        openEnrolledDropdown();
    }
}


/*DARK MODE*/
function toggleDarkMode() {
    const isDark = document.body.classList.toggle('dark');

    localStorage.setItem('darkMode', isDark);

    if (darkModeIcon) darkModeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    showToast(isDark ? 'Switched to Dark mode' : 'Switched to Light mode');
}


/*  JOIN CLASS MODAL (student pages only) */

const CLASS_REGISTRY = {
    'CHEM-101':  { name: 'Chemistry',          teacher: 'Ms. Valiente',     color: '#22d3ee', href: '/enrolled/chemistry/index.html'   },
    'BIO-101':   { name: 'General Biology',        teacher: 'Dr. Smith',       color: '#f97316', href: '/enrolled/general-biology/index.html' },
    'PHY-303':   { name: 'Physics ',            teacher: 'Mr. Bautista',      color: '#a855f7', href: '/enrolled/physics/index.html'     },
    'EARTHSCI-101':   { name: 'Earth Science',          teacher: 'Ms. Garcia',     color: '#22c55e', href: '/enrolled/earth-science/index.html'     },
};

const enrolledClasses = new Set(['CHEM-101', 'BIO-101', 'PHY-303', 'EARTHSCI-101']);

const MAX_ATTEMPTS  = 3;
const COOLDOWN_SECS = 30;

let failedAttempts   = 0;
let lockedUntil      = null;
let cooldownInterval = null;
let resolvedClass    = null;

const joinClassModal      = document.getElementById('joinClassModal');
const joinClassModalCard  = document.getElementById('joinClassModalCard');
const classCodeInput      = document.getElementById('classCodeInput');
const jcError             = document.getElementById('jcError');
const jcPreview           = document.getElementById('jcPreview');
const jcPreviewDot        = document.getElementById('jcPreviewDot');
const jcPreviewName       = document.getElementById('jcPreviewName');
const jcPreviewTeacher    = document.getElementById('jcPreviewTeacher');
const joinClassConfirmBtn = document.getElementById('joinClassConfirmBtn');
const jcLockoutBanner     = document.getElementById('jcLockoutBanner');
const jcTimerSpan         = document.getElementById('jcTimerSpan');
const jcFormatHint        = document.getElementById('jcFormatHint');
const jcInputWrap         = document.getElementById('jcInputWrap');
const jcAttemptLabel      = document.getElementById('jcAttemptLabel');
const jcModalIconWrap     = document.getElementById('jcModalIconWrap');
const jcModalIcon         = document.getElementById('jcModalIcon');
const jcModalTitle        = document.getElementById('jcModalTitle');
const jcDots = [
    document.getElementById('jcDot1'),
    document.getElementById('jcDot2'),
    document.getElementById('jcDot3'),
];

// NOTE: Lahat ng functions sa ibaba (updateAttemptDots, startLockout, atbp.)
// ay para lang sa STUDENT "Join Class" modal gamit ang mga elementong
// jcPreview/jcDot1/jcLockoutBanner/etc. Sa professor pages (gaya ng
// professorHome.html) iba ang modal markup nila ("Create Class" — walang
// preview, walang lockout banner), kaya hindi gumagana ang mga function
// na ito doon — pero professorHome.js na may sariling
// wireCreateClassModal() ang humahawak ng professor flow, hindi ito.
// Ang mga function dito ay safe lang basta walang ibang code na
// tumatawag sa kanila sa ibang konteksto.

function updateAttemptDots() {
    if (!jcAttemptLabel || jcDots.some(d => !d)) return;
    jcDots.forEach((dot, i) => dot.classList.toggle('used', i < failedAttempts));

    const remaining = MAX_ATTEMPTS - failedAttempts;
    const labels = ['No attempts remaining', '1 attempt remaining', `${remaining} attempts remaining`];
    const classes = ['danger', 'warn', ''];

    const index = Math.min(remaining, 2);
    jcAttemptLabel.textContent = remaining === MAX_ATTEMPTS
        ? `${MAX_ATTEMPTS} attempts remaining`
        : labels[index];
    jcAttemptLabel.className = 'jc-attempt-label ' + (classes[index] || '');
}

function startLockout() {
    if (!classCodeInput || !joinClassConfirmBtn || !jcInputWrap || !jcModalIconWrap || !jcModalIcon || !jcModalTitle || !jcFormatHint || !jcPreview || !jcError || !jcLockoutBanner || !jcTimerSpan) return;

    lockedUntil = Date.now() + COOLDOWN_SECS * 1000;
    classCodeInput.disabled      = true;
    joinClassConfirmBtn.disabled = true;
    jcInputWrap.classList.add('dimmed');
    jcModalIconWrap.classList.add('locked');
    jcModalIcon.className    = 'fas fa-lock';
    jcModalTitle.textContent = 'Too many attempts';
    jcFormatHint.classList.add('hidden');
    jcPreview.classList.add('hidden');
    jcError.classList.add('hidden');
    jcLockoutBanner.classList.remove('hidden');
    jcTimerSpan.textContent = COOLDOWN_SECS + 's';

    cooldownInterval = setInterval(() => {
        const rem = Math.ceil((lockedUntil - Date.now()) / 1000);
        if (rem <= 0) { clearInterval(cooldownInterval); unlockInput(); }
        else jcTimerSpan.textContent = rem + 's';
    }, 500);
}

function unlockInput() {
    if (!classCodeInput || !joinClassConfirmBtn || !jcInputWrap || !jcModalIconWrap || !jcModalIcon || !jcModalTitle || !jcFormatHint || !jcLockoutBanner) return;

    failedAttempts = 0;
    lockedUntil    = null;
    classCodeInput.disabled      = false;
    classCodeInput.value         = '';
    classCodeInput.classList.remove('error');
    joinClassConfirmBtn.disabled = true;
    jcInputWrap.classList.remove('dimmed');
    jcModalIconWrap.classList.remove('locked');
    jcModalIcon.className    = 'fas fa-door-open';
    jcModalTitle.textContent = 'Join a Class';
    jcFormatHint.classList.remove('hidden');
    jcLockoutBanner.classList.add('hidden');
    updateAttemptDots();
    classCodeInput.focus();
}

function openJoinClass() {
    if (!classCodeInput || !joinClassConfirmBtn || !joinClassModal || !joinClassModalCard) return;

    resolvedClass = null;
    classCodeInput.value = '';
    classCodeInput.classList.remove('error');
    if (jcError) jcError.classList.add('hidden');
    if (jcPreview) jcPreview.classList.add('hidden');
    joinClassConfirmBtn.disabled = true;

    if (!lockedUntil || Date.now() >= lockedUntil) {
        failedAttempts = 0;
        lockedUntil    = null;
        classCodeInput.disabled = false;
        if (jcInputWrap) jcInputWrap.classList.remove('dimmed');
        if (jcModalIconWrap) jcModalIconWrap.classList.remove('locked');
        if (jcModalIcon) jcModalIcon.className    = 'fas fa-door-open';
        if (jcModalTitle) jcModalTitle.textContent = 'Join a Class';
        if (jcFormatHint) jcFormatHint.classList.remove('hidden');
        if (jcLockoutBanner) jcLockoutBanner.classList.add('hidden');
        updateAttemptDots();
    }

    joinClassModal.style.display = 'flex';
    setTimeout(() => {
        joinClassModal.style.opacity = '1';
        joinClassModalCard.classList.add('scaled');
        if (!classCodeInput.disabled) classCodeInput.focus();
    }, 10);
}

function closeJoinClassModal() {
    if (!joinClassModal || !joinClassModalCard) return;
    joinClassModal.style.opacity = '0';
    joinClassModalCard.classList.remove('scaled');
    setTimeout(() => { joinClassModal.style.display = 'none'; }, 300);
}

if (classCodeInput) {
    classCodeInput.addEventListener('input', () => {
        if (lockedUntil && Date.now() < lockedUntil) return;

        const raw  = classCodeInput.value.trim().toUpperCase();
        const data = CLASS_REGISTRY[raw];

        classCodeInput.classList.remove('error');
        if (jcError) jcError.classList.add('hidden');
        if (jcPreview) jcPreview.classList.add('hidden');
        resolvedClass = null;
        if (joinClassConfirmBtn) joinClassConfirmBtn.disabled = true;

        if (!raw) return;

        if (data) {
            if (enrolledClasses.has(raw)) { showJcError('You are already enrolled in this class.'); return; }

            resolvedClass = { code: raw, ...data };
            if (jcPreviewDot)     jcPreviewDot.style.backgroundColor  = data.color;
            if (jcPreviewName)    jcPreviewName.textContent           = data.name;
            if (jcPreviewTeacher) jcPreviewTeacher.textContent        = 'Teacher: ' + data.teacher;
            if (jcPreview) jcPreview.classList.remove('hidden');
            if (joinClassConfirmBtn) joinClassConfirmBtn.disabled = false;
        }
    });

    classCodeInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && joinClassConfirmBtn && !joinClassConfirmBtn.disabled) confirmJoinClass();
    });
}

function showJcError(msg) {
    if (!classCodeInput || !jcError || !joinClassConfirmBtn) return;
    classCodeInput.classList.add('error');
    jcError.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + msg;
    jcError.classList.remove('hidden');
    joinClassConfirmBtn.disabled = true;
    resolvedClass = null;
}

function confirmJoinClass() {
    if (!classCodeInput) return;
    const raw = classCodeInput.value.trim().toUpperCase();

    if (!raw) { showJcError('Please enter a class code.'); return; }

    if (!CLASS_REGISTRY[raw]) {
        failedAttempts++;
        updateAttemptDots();
        if (failedAttempts >= MAX_ATTEMPTS) { startLockout(); return; }
        const rem = MAX_ATTEMPTS - failedAttempts;
        showJcError('Class code not found. ' + rem + ' attempt' + (rem === 1 ? '' : 's') + ' remaining.');
        return;
    }

    if (enrolledClasses.has(raw)) { showJcError('You are already enrolled in this class.'); return; }
    if (!resolvedClass) return;

    enrolledClasses.add(raw);
    addClassToSidebar(resolvedClass);
    closeJoinClassModal();
    showToast('Successfully joined ' + resolvedClass.name + '!');
}

function addClassToSidebar(cls) {
    if (!dropdown || !sidebar) return;

    const newLink = document.createElement('a');
    newLink.href           = cls.href;
    newLink.id             = 'nav-' + cls.code.toLowerCase().replace(/[^a-z0-9]/g, '-');
    newLink.className      = 'sidebar-link sub-link';
    newLink.dataset.parent = 'Enrolled';
    newLink.dataset.child  = cls.name;
    newLink.innerHTML = `
        <span class="dot" style="background-color:${cls.color}"></span>
        <span class="sidebar-text">${cls.name}</span>
    `;
    newLink.addEventListener('click', closeMobileSidebar);

    // I-enable din ang preloading transition para sa bagong tab na idinagdag
    attachPageTransition(newLink);

    dropdown.appendChild(newLink);

    // I-expand ang sidebar at i-save ang state
    sidebar.classList.remove('collapsed');
    localStorage.setItem('sidebarState', 'expanded');

    if (!dropdown.classList.contains('open')) {
        openEnrolledDropdown();
    } else {
        // FIX: also defer the height recalc here for consistency
        requestAnimationFrame(() => {
            dropdown.style.maxHeight = dropdown.scrollHeight + 'px';
        });
    }
}


/* NOTIFICATION DRAWER */
function toggleNotifications() {
    if (notiDrawer) notiDrawer.classList.toggle('open');
}

function renderNotifications() {
    if (!notiList) return;

    const unreadCount = notifications.filter(n => !n.read).length;
    if (notiBadge) notiBadge.classList.toggle('hidden', unreadCount === 0);

    if (!notifications.length) {
        notiList.innerHTML = '<div class="noti-empty">No new alerts.</div>';
        return;
    }

    notiList.innerHTML = notifications.map(n => `
        <div class="noti-item ${n.read ? 'read' : 'unread'}">
            <p class="noti-text">${n.text}</p>
            <span class="noti-time">${n.time}</span>
            ${!n.read ? `<button class="noti-read-btn" data-id="${n.id}">Read</button>` : ''}
        </div>
    `).join('');

    notiList.querySelectorAll('.noti-read-btn').forEach(btn => {
        btn.addEventListener('click', () => markRead(Number(btn.dataset.id)));
    });
}

function markRead(id) {
    notifications = notifications.map(n => n.id === id ? { ...n, read: true } : n);
    renderNotifications();
}

function clearNotifications() {
    notifications = [];
    renderNotifications();
    showToast('Cleared all notifications');
}


/* LOGOUT MODAL */
function openLogoutModal() {
    if (!logoutModal || !logoutModalCard) return;
    logoutModal.style.display = 'flex';
    setTimeout(() => {
        logoutModal.style.opacity = '1';
        logoutModalCard.classList.add('scaled');
    }, 10);
}

function closeLogoutModal() {
    if (!logoutModal || !logoutModalCard) return;
    logoutModal.style.opacity = '0';
    logoutModalCard.classList.remove('scaled');
    setTimeout(() => { logoutModal.style.display = 'none'; }, 300);
}

function confirmLogout() {
    closeLogoutModal();
    showToast('Session cleared. Entering Guest Mode.');
}


/* TOAST — maliit na notification sa baba*/
function showToast(text) {
    if (!toast || !toastMessage) return;
    toastMessage.textContent = text;
    toast.classList.add('visible');
    setTimeout(() => { toast.classList.remove('visible'); }, 2500);
}