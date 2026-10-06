/* SETTINGS TOUR — the Settings page itself is run by common/settings-page.js
   (shared by students and professors); this file adds its guided tour
   (common/page-tour.js). The tabs are the same for both roles; only the
   wording about classes and alerts changes. */
document.addEventListener('DOMContentLoaded', () => {
    window.LQPageTour?.init({ key: 'tour_seen_settings', steps: settingsTourSteps });
});

function settingsTourRole() {
    try {
        return (window.parent.LQ_HOST_CONFIG || {}).role || 'student';
    } catch (e) {
        return 'student';
    }
}

/* Open a tab the way a click would, so its panel is on screen. */
function openSettingsTab(name) {
    document.querySelector(`.settings-tab[data-tab="${name}"]`)?.click();
}

function settingsTourSteps() {
    const isProfessor = settingsTourRole() === 'professor';

    return [
        {
            title: 'Welcome to Settings',
            body: 'Manage your profile, how LearnQuest looks and behaves, your classes, alerts and password.',
        },
        {
            target: '.profile-side-card',
            title: 'Your profile',
            body: 'Your profile photo and name. Upload a new photo here.',
        },
        {
            target: '.settings-tab[data-tab="profile-info"]',
            title: 'Profile Info',
            body: 'Edit your name. Your email address is shown here but can\'t be changed.',
        },
        {
            target: '.settings-tab[data-tab="general"]',
            title: 'General',
            body: isProfessor
                ? 'Theme, text size, motion, sidebar, start page, dashboard cover and slide-deck defaults. "Reset to Defaults" there also brings back the guided tours.'
                : 'Theme, text size, motion, sidebar, start page and the dashboard cover. "Reset to Defaults" there also brings back the guided tours.',
        },
        {
            target: '.settings-tab[data-tab="academic-info"]',
            title: 'My Classes',
            body: isProfessor
                ? 'All your classes in one list, where you can edit each one\'s details.'
                : 'The classes you\'re enrolled in, all in one list.',
        },
        {
            target: '.settings-tab[data-tab="notifications"]',
            title: 'Notifications',
            body: isProfessor
                ? 'Choose which system alerts you get: new enrolments, students at risk, quiz feedback, and the alert sound.'
                : 'Choose which alerts you get: announcements, new lessons, changes in your mastery, and the alert sound.',
        },
        {
            before: () => openSettingsTab('security'),
            target: () => document.getElementById('securityIdleLock')?.closest('.settings-card'),
            title: 'Security',
            body: 'Change your password, and protect your account on shared computers: ask for an emailed code every time you sign in, and lock LearnQuest after you\'ve been away for a while.',
            fallback: 'The Security tab is where you change your password and set up extra sign-in protection.',
        },
        {
            before: () => openSettingsTab('profile-info'),
            title: 'That\'s Settings',
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}
