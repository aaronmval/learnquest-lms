/* PROFESSOR SETTINGS — the page itself is run by common/settings-page.js
   (shared with students); this file only adds the professor's guided tour
   (common/page-tour.js). */
document.addEventListener('DOMContentLoaded', () => {
    window.LQPageTour?.init({ key: 'tour_seen_settings', steps: settingsTourSteps });
});

function settingsTourSteps() {
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
            body: 'Theme, text size, motion, sidebar, start page, dashboard cover and slide-deck defaults. "Reset to Defaults" there also brings back the guided tours.',
        },
        {
            target: '.settings-tab[data-tab="academic-info"]',
            title: 'My Classes',
            body: 'All your classes in one list, where you can edit each one\'s details.',
        },
        {
            target: '.settings-tab[data-tab="notifications"]',
            title: 'Notifications',
            body: 'Choose which system alerts you get: new enrolments, students at risk, quiz feedback, and the alert sound.',
        },
        {
            target: '.settings-tab[data-tab="security"]',
            title: 'Security',
            body: 'Change your password.',
        },
        {
            title: 'That\'s Settings',
            body: 'Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}
