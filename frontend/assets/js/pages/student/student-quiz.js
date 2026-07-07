/* Mock quiz data — swap this out for a real fetch from the backend */
const QUIZ_DATA = {
    subject: 'Chemistry',
    section: 'STEM - Amethyst',
    title: 'Periodic Table & Atomic Structure',
    timeLimitSeconds: 15 * 60,
    attemptLabel: '1 of 2 attempts',
    questions: [
        {
            id: 'q1',
            text: 'Which subatomic particle has a negative charge?',
            options: ['Proton', 'Neutron', 'Electron', 'Positron'],
            answer: 2
        },
        {
            id: 'q2',
            text: 'Elements in the same column of the periodic table are called a...',
            options: ['Period', 'Group', 'Series', 'Block'],
            answer: 1
        },
        {
            id: 'q3',
            text: 'What is the atomic number of an element equal to?',
            options: [
                'Number of neutrons only',
                'Number of protons in the nucleus',
                'Total mass of the atom',
                'Number of electron shells'
            ],
            answer: 1
        },
        {
            id: 'q4',
            text: 'Which element is a noble gas?',
            options: ['Chlorine', 'Sodium', 'Neon', 'Oxygen'],
            answer: 2
        },
        {
            id: 'q5',
            text: 'Isotopes of an element differ in their number of...',
            options: ['Protons', 'Electrons', 'Neutrons', 'Valence shells'],
            answer: 2
        },
        {
            id: 'q6',
            text: 'Metals are generally found on which side of the periodic table?',
            options: ['Right side', 'Left side', 'Top row only', 'Bottom row only'],
            answer: 1
        },
        {
            id: 'q7',
            text: 'Which of the following best describes the "octet rule"?',
            options: [
                'Atoms react to have 8 protons',
                'Atoms tend to gain, lose, or share electrons to have 8 valence electrons',
                'Only 8 elements exist per period',
                'Atoms always form 8 bonds'
            ],
            answer: 1
        },
        {
            id: 'q8',
            text: 'Which particle is located in the nucleus along with protons?',
            options: ['Electron', 'Neutron', 'Ion', 'Photon'],
            answer: 1
        },
        {
            id: 'q9',
            text: 'As you move left to right across a period, atomic radius generally...',
            options: ['Increases', 'Decreases', 'Stays the same', 'Doubles'],
            answer: 1
        },
        {
            id: 'q10',
            text: 'Which of these is an alkali metal?',
            options: ['Calcium', 'Potassium', 'Aluminum', 'Sulfur'],
            answer: 1
        }
    ]
};

const OPTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

/* STATE */
let currentIndex   = 0;
let userAnswers    = new Array(QUIZ_DATA.questions.length).fill(null);
let flagged        = new Array(QUIZ_DATA.questions.length).fill(false);
let secondsLeft     = QUIZ_DATA.timeLimitSeconds;
let timerInterval   = null;
let quizSubmitted   = false;
let reviewMode      = false;

const TIMER_CIRCUMFERENCE  = 213.6;
const RESULTS_CIRCUMFERENCE = 326.7;


document.addEventListener('DOMContentLoaded', () => {

    // Populate banner meta
    document.getElementById('quizBannerEyebrow').textContent = `${QUIZ_DATA.subject} · ${QUIZ_DATA.section}`;
    document.getElementById('quizBannerTitle').textContent   = QUIZ_DATA.title;
    document.getElementById('quizMetaCount').textContent      = `${QUIZ_DATA.questions.length} Items`;
    document.getElementById('quizMetaTime').textContent       = `${formatTime(QUIZ_DATA.timeLimitSeconds)} Limit`;
    document.getElementById('quizMetaAttempts').textContent   = QUIZ_DATA.attemptLabel;

    buildNavigatorGrid();
    renderQuestion(0);
    startTimer();
    initNavigatorToggle();

    // Navigation buttons
    document.getElementById('prevQBtn').addEventListener('click', () => goToQuestion(currentIndex - 1));
    document.getElementById('nextQBtn').addEventListener('click', () => goToQuestion(currentIndex + 1));

    // Flag button
    document.getElementById('flagBtn').addEventListener('click', toggleFlag);

    // Submit flow
    document.getElementById('submitQuizBtn').addEventListener('click', openSubmitConfirm);
    document.getElementById('submitCancelBtn').addEventListener('click', closeSubmitConfirm);
    document.getElementById('submitConfirmBtn').addEventListener('click', finalizeSubmit);

    // Back buttons
    document.getElementById('backToClassworkBtn').addEventListener('click', () => {
        window.location.href = 'studentClassworkChemistry.html';
    });
    document.getElementById('backToClassworkResultsBtn').addEventListener('click', () => {
        window.location.href = 'studentClassworkChemistry.html';
    });

    // Review answers from results modal
    document.getElementById('reviewAnswersBtn').addEventListener('click', () => {
        closeModal('resultsModal');
        reviewMode = true;
        goToQuestion(0);
    });

    // Hide page loader
    setTimeout(() => {
        const loader = document.getElementById('pageLoader');
        if (loader) loader.classList.add('hidden');
    }, 700);
});


/* ── NAVIGATOR COLLAPSE / EXPAND ── */
function initNavigatorToggle() {
    const toggleBtn = document.getElementById('navigatorToggleBtn');
    const card       = document.querySelector('.navigator-card');
    if (!toggleBtn || !card) return;

    // Default: collapsed on narrow screens, expanded on wider ones
    let startCollapsed;
    try {
        const saved = localStorage.getItem('lq_navigatorCollapsed');
        startCollapsed = saved !== null ? saved === 'true' : window.innerWidth <= 768;
    } catch (e) {
        startCollapsed = window.innerWidth <= 768;
    }
    setNavigatorCollapsed(startCollapsed, card, toggleBtn);

    toggleBtn.addEventListener('click', () => {
        const isCollapsed = card.classList.contains('collapsed');
        setNavigatorCollapsed(!isCollapsed, card, toggleBtn);
        try { localStorage.setItem('lq_navigatorCollapsed', String(!isCollapsed)); } catch (e) { /* ignore */ }
    });
}

function setNavigatorCollapsed(collapsed, card, toggleBtn) {
    card.classList.toggle('collapsed', collapsed);
    toggleBtn.setAttribute('aria-expanded', String(!collapsed));
}


/*  TIMER  */
function startTimer() {
    updateTimerDisplay();
    timerInterval = setInterval(() => {
        secondsLeft--;
        if (secondsLeft <= 0) {
            secondsLeft = 0;
            updateTimerDisplay();
            clearInterval(timerInterval);
            if (!quizSubmitted) {
                showToast("Time's up! Submitting your quiz…");
                finalizeSubmit();
            }
            return;
        }
        updateTimerDisplay();
    }, 1000);
}

function updateTimerDisplay() {
    const textEl   = document.getElementById('timerText');
    const circleEl = document.getElementById('timerProgressCircle');
    textEl.textContent = formatTime(secondsLeft);

    const ratio  = secondsLeft / QUIZ_DATA.timeLimitSeconds;
    const offset = TIMER_CIRCUMFERENCE * (1 - ratio);
    circleEl.style.strokeDashoffset = offset;

    if (ratio <= 0.15) {
        circleEl.classList.add('timer-low');
    } else {
        circleEl.classList.remove('timer-low');
    }
}

function formatTime(totalSeconds) {
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}


/* ── NAVIGATOR GRID ── */
function buildNavigatorGrid() {
    const grid = document.getElementById('navigatorGrid');
    grid.innerHTML = '';
    QUIZ_DATA.questions.forEach((q, i) => {
        const cell = document.createElement('div');
        cell.className = 'nav-cell';
        cell.id = `navCell-${i}`;
        cell.textContent = i + 1;
        cell.addEventListener('click', () => goToQuestion(i));
        grid.appendChild(cell);
    });
    refreshNavigatorState();
}

function refreshNavigatorState() {
    QUIZ_DATA.questions.forEach((q, i) => {
        const cell = document.getElementById(`navCell-${i}`);
        if (!cell) return;
        cell.classList.toggle('current', i === currentIndex);
        cell.classList.toggle('answered', userAnswers[i] !== null);
        cell.classList.toggle('flagged', flagged[i]);
    });
}


/*  RENDER QUESTION  */
function renderQuestion(index) {
    const q = QUIZ_DATA.questions[index];
    currentIndex = index;

    document.getElementById('questionNumberBadge').textContent = `Q${index + 1}`;
    document.getElementById('questionText').textContent = q.text;
    document.getElementById('progressLabel').textContent = `Question ${index + 1} of ${QUIZ_DATA.questions.length}`;
    document.getElementById('progressFill').style.width =
        `${((index + 1) / QUIZ_DATA.questions.length) * 100}%`;

    // Flag button state
    const flagBtn = document.getElementById('flagBtn');
    flagBtn.classList.toggle('flagged', flagged[index]);
    flagBtn.querySelector('i').className = flagged[index] ? 'fas fa-flag' : 'far fa-flag';

    // Options
    const optionsList = document.getElementById('optionsList');
    optionsList.innerHTML = '';
    q.options.forEach((optionText, i) => {
        const item = document.createElement('div');
        item.className = 'option-item';
        item.dataset.index = i;

        const isSelected = userAnswers[index] === i;
        if (isSelected && !reviewMode) item.classList.add('selected');

        if (reviewMode) {
            item.classList.add('locked');
            if (i === q.answer) {
                item.classList.add('correct');
            } else if (isSelected && i !== q.answer) {
                item.classList.add('incorrect');
            }
        } else {
            item.addEventListener('click', () => selectOption(index, i));
        }

        item.innerHTML = `
            <span class="option-letter">${OPTION_LETTERS[i]}</span>
            <span class="option-text">${optionText}</span>
        `;
        optionsList.appendChild(item);
    });

    // Prev/Next/Submit button states
    document.getElementById('prevQBtn').disabled = index === 0;
    const isLast = index === QUIZ_DATA.questions.length - 1;
    document.getElementById('nextQBtn').style.display   = isLast ? 'none' : 'inline-flex';
    document.getElementById('submitQuizBtn').style.display = (isLast && !reviewMode) ? 'inline-flex' : 'none';

    if (reviewMode) {
        document.getElementById('nextQBtn').style.display = isLast ? 'none' : 'inline-flex';
        document.getElementById('submitQuizBtn').style.display = 'none';
        document.getElementById('flagBtn').style.visibility = 'hidden';
    } else {
        document.getElementById('flagBtn').style.visibility = 'visible';
    }

    refreshNavigatorState();
}

function goToQuestion(index) {
    if (index < 0 || index >= QUIZ_DATA.questions.length) return;
    renderQuestion(index);
}

function selectOption(questionIndex, optionIndex) {
    if (quizSubmitted) return;
    userAnswers[questionIndex] = optionIndex;
    renderQuestion(questionIndex);
}

function toggleFlag() {
    if (reviewMode) return;
    flagged[currentIndex] = !flagged[currentIndex];
    renderQuestion(currentIndex);
    showToast(flagged[currentIndex] ? 'Question marked for review' : 'Flag removed');
}


/*  SUBMIT FLOW  */
function openSubmitConfirm() {
    const answeredCount = userAnswers.filter(a => a !== null).length;
    document.getElementById('submitConfirmDesc').textContent =
        `You've answered ${answeredCount} of ${QUIZ_DATA.questions.length} questions. Once submitted, you won't be able to change your answers.`;
    openModal('submitConfirmModal');
}

function closeSubmitConfirm() {
    closeModal('submitConfirmModal');
}

function finalizeSubmit() {
    closeSubmitConfirm();
    if (quizSubmitted) return;
    quizSubmitted = true;
    clearInterval(timerInterval);

    let correct = 0, incorrect = 0, skipped = 0;
    QUIZ_DATA.questions.forEach((q, i) => {
        if (userAnswers[i] === null) skipped++;
        else if (userAnswers[i] === q.answer) correct++;
        else incorrect++;
    });

    showResults(correct, incorrect, skipped);
}

function showResults(correct, incorrect, skipped) {
    const total   = QUIZ_DATA.questions.length;
    const percent = Math.round((correct / total) * 100);

    document.getElementById('resultsScoreText').textContent   = `${correct}/${total}`;
    document.getElementById('resultsPercentText').textContent = `${percent}%`;
    document.getElementById('resultsCorrectCount').textContent   = correct;
    document.getElementById('resultsIncorrectCount').textContent = incorrect;
    document.getElementById('resultsSkippedCount').textContent   = skipped;

    let title, desc, ringColor;
    if (percent >= 80) {
        title = 'Excellent Work!'; desc = "You've mastered this topic. Keep it up!"; ringColor = '#4ade80';
    } else if (percent >= 60) {
        title = 'Good Job!'; desc = 'Solid effort — review the missed items to level up.'; ringColor = '#60a5fa';
    } else {
        title = 'Quiz Complete'; desc = 'Take a look at the review to see where to focus next.'; ringColor = '#f87171';
    }
    document.getElementById('resultsTitle').textContent = title;
    document.getElementById('resultsDesc').textContent   = desc;

    const circle = document.getElementById('resultsProgressCircle');
    circle.style.stroke = ringColor;

    openModal('resultsModal');

    // Animate the ring in after the modal is visible
    requestAnimationFrame(() => {
        const offset = RESULTS_CIRCUMFERENCE * (1 - correct / total);
        setTimeout(() => { circle.style.strokeDashoffset = offset; }, 50);
    });
}


/*  MODAL HELPERS  */
function openModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}


/* TOAST */
function showToast(message) {
    const toast        = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add('visible');
    setTimeout(() => toast.classList.remove('visible'), 2500);
}
