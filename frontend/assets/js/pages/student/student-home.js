/* MASTERY DATA — aligned with dashboard "all" mastery values */
const MASTERY_BY_SUBJECT_KEY = {
    chemistry: 85,
    general_biology: 88,
    earth_science: 75,
    physics: 70,
};


/* INIT — runs when page loads*/
document.addEventListener('DOMContentLoaded', () => {
    hidePageLoader();
    updateHeroName();
    populateClassMasteryBadges();

});


/* PAGE LOADER:Itinatago ang loading screen pagkatapos mag-load ang lahat ng content */
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


/* CLASS CARD MASTERY: Ipakita ang mastery indicator bawat enrolled class */
function populateClassMasteryBadges() {
    const cards = document.querySelectorAll('.class-card[data-subject-key]');
    if (!cards.length) return;

    cards.forEach(card => {
        const subjectKey = card.getAttribute('data-subject-key');
        const masteryBadge = card.querySelector('[data-mastery-badge]');
        if (!masteryBadge || !subjectKey) return;

        const masteryValue = MASTERY_BY_SUBJECT_KEY[subjectKey];

        if (typeof masteryValue === 'number') {
            masteryBadge.textContent = `Mastery: ${masteryValue}%`;
            return;
        }

        masteryBadge.textContent = 'Mastery: N/A';
    });
}
