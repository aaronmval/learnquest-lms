/* INIT — runs when page loads*/
document.addEventListener('DOMContentLoaded', () => {
    hidePageLoader();
    updateHeroName();

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