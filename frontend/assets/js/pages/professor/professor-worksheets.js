/* QUIZ & AI SETUP (professor) — per-lesson quiz settings for students,
   optional review of AI-generated questions, and QuestAI training metrics.
   Reviews (approve / reject with reason / difficulty correction) are saved
   server-side and fed into future quiz-generation prompts for the subject. */

const DIFFICULTIES = ['easy', 'medium', 'hard'];
const OPTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

const state = {
    classes: [],
    activeClassId: null,
    activePostId: null,
    studio: null, // GET …/quiz/studio payload for the active lesson
    filter: 'all',
    mix: { easy: 30, medium: 40, hard: 30 },
    rejectingId: null, // question whose reject form is open
};

const dom = {};

/* HTTP */
function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function apiRequest(url, { method = 'GET', body = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
        headers['X-XSRF-TOKEN'] = getCsrfToken();
        if (body !== null) headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers,
        body: body !== null ? JSON.stringify(body) : null,
    });

    let data = null;
    try {
        data = await res.json();
    } catch (e) {
        /* Non-JSON error page — leave data null. */
    }

    return { ok: res.ok, status: res.status, data };
}

function errorMessageFor(status, data, fallback) {
    if (status === 419) return 'Your session expired. Please reload the page and try again.';
    if (status === 429) return 'Too many requests — please wait a moment and try again.';
    if (status === 422 && data?.errors) {
        return Object.values(data.errors)[0]?.[0] || data.message || fallback;
    }
    return data?.message || fallback;
}

function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function capitalize(text) {
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

function lessonUrl(path = '') {
    return `/professor/classes/${state.activeClassId}/posts/${state.activePostId}/quiz${path}`;
}

function setBusy(button, busy, busyHtml = null) {
    if (!button) return;
    if (busy) {
        button.dataset.idleHtml = button.innerHTML;
        button.disabled = true;
        if (busyHtml) button.innerHTML = busyHtml;
    } else {
        button.disabled = false;
        if (button.dataset.idleHtml) button.innerHTML = button.dataset.idleHtml;
    }
}

/* INIT */
document.addEventListener('DOMContentLoaded', () => {
    [
        'sectionSelect', 'materialsList', 'emptyState', 'settingsCard', 'lessonTitle', 'settingsStatus',
        'settingsForm', 'questionCount', 'timerEnabled', 'timeLimit', 'maxAttempts', 'shuffleQuestions',
        'shuffleChoices', 'showAnswers', 'adaptive', 'adaptivePreview', 'saveSettingsBtn', 'generateBtn', 'generateBtnText', 'reviewCard',
        'feedbackCard', 'feedbackBody', 'reviewMeta', 'topUpBar', 'topUpText', 'topUpBtn', 'questionList', 'trainingCard', 'trainingSubtitle',
        'trainingBody',
    ].forEach(id => {
        dom[id] = document.getElementById(id);
    });

    wireSettingsForm();
    wireReviewCard();

    dom.sectionSelect.addEventListener('change', () => selectClass(Number(dom.sectionSelect.value)));

    // The first-open tour waits for the lesson list so it can point at it.
    window.LQPageTour?.init({ key: 'quiz_setup_tour_seen', steps: setupTourSteps, ready: loadLessons() });
});

/* GUIDED TOUR — walks through the page in the order it is used. Shown once
   per account (common/page-tour.js) and replayable from the hero's "Take
   the tour" button. It only reads the page and, at the lessons step, opens
   a lesson; it never saves or generates anything. */
function waitUntil(check, timeoutMs = 5000) {
    return new Promise(resolve => {
        const started = Date.now();
        (function poll() {
            if (check() || Date.now() - started > timeoutMs) resolve();
            else setTimeout(poll, 100);
        })();
    });
}

/* Make sure a lesson is open so the settings, review and feedback panels
   exist for the steps that explain them. */
async function openALessonForTour() {
    if (!state.activePostId) {
        const first = findClass(state.activeClassId)?.lessons?.[0];
        if (!first) return;
        selectLesson(first.id);
    }
    await waitUntil(() => !dom.settingsCard.classList.contains('is-hidden'));
}

function setupTourSteps() {
    const noLesson = 'This appears once you open a lesson. Post a lesson with a PDF to one of your classes, then pick it from the Lessons list.';
    const noQuiz = 'This appears once the lesson has a generated quiz. Open a lesson and choose Generate quiz to see it.';

    return [
        {
            title: 'Welcome to Quiz & AI Setup',
            body: 'This page is where you set up the AI quiz for each lesson, then check the questions QuestAI wrote. This short tour follows the order you would work in. Nothing is saved or generated while you look around.',
        },
        {
            target: '#sectionSelect',
            title: 'Choose a section',
            body: 'Start by picking the class section you want to set up. The lesson list below changes to match.',
        },
        {
            target: '#materialsList',
            advanceOn: '.material-item',
            title: 'Pick a lesson',
            body: 'Every lesson with a PDF attachment gets an AI quiz. The chip shows where each one stands: no quiz yet, needs review, or reviewed. Click a lesson now, or press Next and the first one opens for you.',
            fallback: 'Lessons with a PDF attachment are listed here, each with a status chip. There are none in this section yet: post a lesson with a PDF to a class and it will appear.',
        },
        {
            before: openALessonForTour,
            target: '#settingsCard .panel-title-row',
            title: 'Quiz settings for this lesson',
            body: 'These settings belong to the lesson you opened. The chip on the right tells you whether your changes are saved.',
            fallback: noLesson,
        },
        {
            // The first three fields sit side by side: count, timer, attempts.
            target: () => ['questionCount', 'timerEnabled', 'maxAttempts']
                .map(id => document.getElementById(id)?.closest('.setting-field'))
                .filter(Boolean),
            title: 'Questions, timer and attempts',
            body: 'Set how many questions each student gets, an optional time limit, and how many attempts are allowed. These apply the next time a student opens the quiz.',
            fallback: noLesson,
        },
        {
            target: () => document.querySelector('#settingsForm .mix-header')?.closest('.setting-field') || null,
            title: 'Difficulty mix',
            body: 'Choose what share of the quiz is easy, medium and hard. Drag a slider and the others rebalance to 100%, or use a preset: Balanced, Easier or Harder.',
            fallback: noLesson,
        },
        {
            target: '#settingsForm .adaptive-box',
            title: 'Adapt to each student',
            body: 'With this on, each student gets a mix shifted to their mastery of the lesson\'s competencies: easier when mastery is low, harder when it is high. Mastery comes from Bayesian Knowledge Tracing on their past answers, not from the AI. The preview shows the mix each group would get.',
            fallback: noLesson,
        },
        {
            target: '#settingsCard .validator-actions',
            title: 'Save, then generate',
            body: 'Save settings keeps your choices. Generate quiz has QuestAI write the question bank from the lesson\'s PDF; once a quiz exists, the same button regenerates it.',
            fallback: noLesson,
        },
        {
            target: '#reviewCard',
            title: 'Review the AI\'s questions',
            body: 'Review is optional: students already see these questions. Approve good ones, reject wrong or unclear ones with a reason (they are hidden from students at once), correct difficulty labels, and check each question\'s competency, since it decides which skill an answer counts toward.',
            fallback: noQuiz,
        },
        {
            target: '#feedbackCard',
            title: 'Student feedback',
            body: 'After submitting, students rate the quiz and say if it felt too easy, just right or too hard. Results are grouped by mastery level so you can see whether the difficulty suits each group.',
            fallback: noQuiz,
        },
        {
            target: '#trainingCard',
            title: 'QuestAI training',
            body: 'This shows what QuestAI has learned for the subject: the questions you approved, why you rejected others, your difficulty corrections, and how students really scored. All of it is fed into the next quiz it writes.',
            fallback: 'Once you have reviewed some questions, a QuestAI training panel appears at the bottom of the page showing what it has learned from your reviews and from student results.',
        },
        {
            title: 'You\'re all set',
            body: 'That is the whole workflow: section, lesson, settings, generate, review. You can replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}

/* LESSONS */
async function loadLessons({ keepSelection = false } = {}) {
    try {
        const { ok, data } = await apiRequest('/professor/quiz-studio/lessons');
        if (!ok) throw new Error('failed');
        state.classes = data.classes || [];
    } catch (e) {
        dom.materialsList.innerHTML = '<li class="list-empty">Could not load your lessons. Please reload the page.</li>';
        return;
    }

    if (!state.classes.length) {
        dom.sectionSelect.innerHTML = '<option>No active sections</option>';
        dom.sectionSelect.disabled = true;
        dom.materialsList.innerHTML = '<li class="list-empty">Create a class and post a lesson with a PDF to set up its quiz.</li>';
        return;
    }

    dom.sectionSelect.disabled = false;
    dom.sectionSelect.innerHTML = state.classes
        .map(c => `<option value="${c.id}">${esc(c.label)}</option>`)
        .join('');

    const classId = keepSelection && findClass(state.activeClassId) ? state.activeClassId : state.classes[0].id;
    dom.sectionSelect.value = String(classId);

    if (keepSelection && classId === state.activeClassId) {
        renderLessons();
    } else {
        selectClass(classId);
    }
}

function findClass(id) {
    return state.classes.find(c => c.id === id) || null;
}

function activeLesson() {
    return findClass(state.activeClassId)?.lessons.find(l => l.id === state.activePostId) || null;
}

function selectClass(classId) {
    state.activeClassId = classId;
    state.activePostId = null;
    state.studio = null;
    showLessonPanels(false);
    renderLessons();
    loadTraining();
}

function lessonStatus(lesson) {
    if (!lesson.has_quiz) return { text: 'No quiz yet', cls: 'status-pending' };
    if (lesson.rejected > 0 && lesson.question_count < lesson.target_count) {
        return { text: `${lesson.target_count - lesson.question_count} to replace`, cls: 'status-rejected' };
    }
    const total = lesson.question_count + lesson.rejected;
    if (lesson.reviewed >= total && total > 0) return { text: 'Reviewed', cls: 'status-saved' };
    return { text: 'Needs review', cls: 'status-checked' };
}

function renderLessons() {
    const cls = findClass(state.activeClassId);
    const lessons = cls?.lessons || [];

    if (cls && !cls.has_subject) {
        dom.materialsList.innerHTML =
            '<li class="list-empty">This section isn\'t linked to a subject, so QuestAI has no competencies to write questions for.</li>';
        return;
    }

    if (!lessons.length) {
        dom.materialsList.innerHTML = '<li class="list-empty">No lessons with a PDF in this section yet.</li>';
        return;
    }

    dom.materialsList.innerHTML = lessons
        .map(lesson => {
            const status = lessonStatus(lesson);
            const meta = lesson.has_quiz
                ? `${lesson.question_count}/${lesson.target_count} questions · ${lesson.reviewed} reviewed`
                : `${lesson.target_count} questions planned`;
            return `
                <li>
                    <button type="button" class="material-item${lesson.id === state.activePostId ? ' active' : ''}" data-post-id="${lesson.id}">
                        <span class="material-title-row">
                            <span class="material-title">${esc(lesson.title)}</span>
                            <span class="status-chip ${status.cls}">${esc(status.text)}</span>
                        </span>
                        <span class="material-meta">${esc(lesson.quarter || '')}${lesson.quarter ? ' · ' : ''}${esc(meta)}</span>
                    </button>
                </li>`;
        })
        .join('');

    dom.materialsList.querySelectorAll('.material-item').forEach(btn => {
        btn.addEventListener('click', () => selectLesson(Number(btn.dataset.postId)));
    });
}

async function selectLesson(postId) {
    state.activePostId = postId;
    state.filter = 'all';
    state.rejectingId = null;
    document.querySelectorAll('.filter-chip').forEach(c => c.classList.toggle('active', c.dataset.filter === 'all'));
    renderLessons();
    await loadStudio();
}

function showLessonPanels(show) {
    dom.emptyState.classList.toggle('is-hidden', show);
    dom.settingsCard.classList.toggle('is-hidden', !show);
    dom.reviewCard.classList.toggle('is-hidden', !show || !state.studio?.quiz);
    dom.feedbackCard.classList.toggle('is-hidden', !show || !state.studio?.quiz);
}

async function loadStudio() {
    const postId = state.activePostId;

    try {
        const { ok, data } = await apiRequest(lessonUrl('/studio'));
        if (!ok) throw new Error('failed');
        if (postId !== state.activePostId) return; // a different lesson was picked meanwhile
        state.studio = data;
    } catch (e) {
        showToast('Could not load this lesson\'s quiz. Please try again.');
        return;
    }

    renderSettings();
    renderReview();
    showLessonPanels(true);
    renderFeedback(); // after the card is visible, so segment widths can be measured
}

/* SETTINGS */
function wireSettingsForm() {
    dom.settingsForm.querySelectorAll('.stepper-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            dom.questionCount.value = clampCount(Number(dom.questionCount.value) + Number(btn.dataset.step));
            renderMix();
        });
    });

    dom.questionCount.addEventListener('change', () => {
        dom.questionCount.value = clampCount(Number(dom.questionCount.value));
        renderMix();
    });

    dom.timerEnabled.addEventListener('change', () => {
        dom.timeLimit.disabled = !dom.timerEnabled.checked;
        if (dom.timerEnabled.checked && !dom.timeLimit.value) dom.timeLimit.value = 15;
    });

    document.querySelectorAll('.mix-slider').forEach(slider => {
        slider.addEventListener('input', () => rebalanceMix(slider.dataset.level, Number(slider.value)));
    });

    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const [easy, medium, hard] = btn.dataset.preset.split(',').map(Number);
            state.mix = { easy, medium, hard };
            renderMix();
        });
    });

    dom.adaptive.addEventListener('change', renderMix);

    dom.saveSettingsBtn.addEventListener('click', () => saveSettings());
    dom.generateBtn.addEventListener('click', generateQuiz);
    dom.settingsForm.addEventListener('submit', e => e.preventDefault());
}

function clampCount(value) {
    const limits = state.studio?.limits || { min_question_count: 5, max_question_count: 15 };
    if (!Number.isFinite(value)) return limits.min_question_count;
    return Math.min(limits.max_question_count, Math.max(limits.min_question_count, Math.round(value)));
}

function renderSettings() {
    const { settings, lesson, quiz, limits } = state.studio;

    dom.lessonTitle.textContent = `${lesson.title} · ${lesson.class_label}`;
    dom.settingsStatus.textContent = settings.saved ? 'Saved' : 'Using your last settings';
    dom.settingsStatus.className = `status-chip ${settings.saved ? 'status-saved' : 'status-pending'}`;

    dom.questionCount.min = limits.min_question_count;
    dom.questionCount.max = limits.max_question_count;
    dom.questionCount.value = settings.question_count;

    dom.timerEnabled.checked = settings.time_limit_minutes !== null;
    dom.timeLimit.max = limits.max_time_limit_minutes;
    dom.timeLimit.value = settings.time_limit_minutes ?? '';
    dom.timeLimit.disabled = !dom.timerEnabled.checked;

    dom.maxAttempts.value = settings.max_attempts === null ? '' : String(settings.max_attempts);
    if (dom.maxAttempts.value !== (settings.max_attempts === null ? '' : String(settings.max_attempts))) {
        // A saved value outside the dropdown's presets (e.g. 4).
        const option = new Option(`${settings.max_attempts} attempts`, String(settings.max_attempts));
        dom.maxAttempts.add(option, dom.maxAttempts.options.length - 1);
        dom.maxAttempts.value = String(settings.max_attempts);
    }

    dom.shuffleQuestions.checked = settings.shuffle_questions;
    dom.shuffleChoices.checked = settings.shuffle_choices;
    dom.showAnswers.checked = settings.show_answers;
    dom.adaptive.checked = settings.adaptive;

    state.mix = { ...settings.difficulty_mix };
    renderMix();

    dom.generateBtnText.textContent = quiz ? 'Regenerate with these settings' : 'Generate quiz';
}

/* Moving one slider shares the remaining percent between the other two in
   proportion to their current values, so the mix always totals 100%. */
function rebalanceMix(level, value) {
    const others = DIFFICULTIES.filter(d => d !== level);
    const remaining = 100 - value;
    const otherTotal = others.reduce((sum, d) => sum + state.mix[d], 0);

    state.mix[level] = value;

    if (otherTotal === 0) {
        state.mix[others[0]] = Math.round(remaining / 2 / 5) * 5;
        state.mix[others[1]] = remaining - state.mix[others[0]];
    } else {
        state.mix[others[0]] = Math.round((remaining * state.mix[others[0]]) / otherTotal / 5) * 5;
        state.mix[others[1]] = remaining - state.mix[others[0]];
    }

    renderMix();
}

/* Same largest-remainder rounding as the server, for the count preview. */
function allocate(count, mix) {
    const total = DIFFICULTIES.reduce((sum, d) => sum + mix[d], 0) || 1;
    const exact = DIFFICULTIES.map(d => (count * mix[d]) / total);
    const result = exact.map(Math.floor);
    const order = exact
        .map((value, i) => ({ i, remainder: value - Math.floor(value) }))
        .sort((a, b) => b.remainder - a.remainder);

    for (let k = 0; k < count - result.reduce((a, b) => a + b, 0); k++) {
        result[order[k].i]++;
    }

    return Object.fromEntries(DIFFICULTIES.map((d, i) => [d, result[i]]));
}

function sumCounts(counts) {
    return counts ? DIFFICULTIES.reduce((sum, d) => sum + (counts[d] || 0), 0) : 0;
}

/* Same rule as AdaptiveQuizService::mixFor — high BKT mastery moves points
   toward "hard" (from easy, then medium); low mastery toward "easy". */
function mixFor(mix, level) {
    const result = { ...mix };
    let shift = state.studio?.limits?.adaptive_shift ?? 20;
    const [target, sources] =
        level === 'high' ? ['hard', ['easy', 'medium']] : level === 'low' ? ['easy', ['hard', 'medium']] : [null, []];

    sources.forEach(source => {
        const moved = Math.min(shift, result[source]);
        result[source] -= moved;
        result[target] += moved;
        shift -= moved;
    });

    return result;
}

function renderAdaptivePreview(count) {
    if (!dom.adaptivePreview) return;

    const badges = counts =>
        DIFFICULTIES.map(d => `<span class="difficulty-badge diff-${d}">${counts[d]} ${d}</span>`).join('');

    if (!dom.adaptive.checked) {
        dom.adaptivePreview.innerHTML =
            `<p class="bank-note">Every student gets the same ${count} questions: ${badges(allocate(count, state.mix))}</p>`;
        return;
    }

    const levels = [
        ['high', 'High mastery', 'harder'],
        ['developing', 'Developing / new', 'your mix'],
        ['low', 'Low mastery', 'easier'],
    ];
    const pool = { easy: 0, medium: 0, hard: 0 };

    const rows = levels
        .map(([level, name, note]) => {
            const counts = allocate(count, mixFor(state.mix, level));
            DIFFICULTIES.forEach(d => {
                pool[d] = Math.max(pool[d], counts[d]);
            });
            return `
                <div class="level-row">
                    <span class="level-name">${name} <span class="material-meta">(${note})</span></span>
                    <span class="level-counts">${badges(counts)}</span>
                </div>`;
        })
        .join('');

    dom.adaptivePreview.innerHTML = `
        ${rows}
        <p class="bank-note">
            Each student gets ${count} questions picked for their BKT mastery of this lesson's
            competencies (students without quiz results yet get your mix). QuestAI keeps a bank of
            ${sumCounts(pool)} questions so every level has enough.
        </p>`;
}

function renderMix() {
    const count = clampCount(Number(dom.questionCount.value));
    const counts = allocate(count, state.mix);

    DIFFICULTIES.forEach(level => {
        const slider = document.querySelector(`.mix-slider[data-level="${level}"]`);
        if (slider) slider.value = state.mix[level];
        const valueEl = document.querySelector(`.mix-value[data-value="${level}"]`);
        if (valueEl) {
            valueEl.innerHTML = `<strong>${state.mix[level]}%</strong> · ${counts[level]} question${counts[level] === 1 ? '' : 's'}`;
        }
    });

    renderAdaptivePreview(count);

    const presetKey = `${state.mix.easy},${state.mix.medium},${state.mix.hard}`;
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.preset === presetKey);
    });
}

function readSettingsForm() {
    const timeLimit = Number(dom.timeLimit.value);

    return {
        question_count: clampCount(Number(dom.questionCount.value)),
        time_limit_minutes: dom.timerEnabled.checked && timeLimit > 0 ? Math.round(timeLimit) : null,
        difficulty_mix: { ...state.mix },
        max_attempts: dom.maxAttempts.value === '' ? null : Number(dom.maxAttempts.value),
        shuffle_questions: dom.shuffleQuestions.checked,
        shuffle_choices: dom.shuffleChoices.checked,
        show_answers: dom.showAnswers.checked,
        adaptive: dom.adaptive.checked,
    };
}

async function saveSettings({ quiet = false } = {}) {
    setBusy(dom.saveSettingsBtn, true, '<i class="fas fa-spinner fa-spin"></i> Saving…');

    try {
        const { ok, status, data } = await apiRequest(lessonUrl('/settings'), { method: 'PUT', body: readSettingsForm() });
        if (!ok) {
            showToast(errorMessageFor(status, data, 'Could not save the settings.'));
            return false;
        }

        state.studio.settings = data.settings;
        state.studio.allocation = data.allocation;
        state.studio.pool = data.pool;
        renderSettings();
        updateLessonInList({ target_count: sumCounts(data.pool) });
        if (!quiet) showToast('Quiz settings saved');
        return true;
    } catch (e) {
        showToast('Could not reach the server. Please try again.');
        return false;
    } finally {
        setBusy(dom.saveSettingsBtn, false);
    }
}

async function generateQuiz() {
    const hasQuiz = Boolean(state.studio?.quiz);

    if (hasQuiz) {
        const confirmed = await LQDialog.confirm({
            title: 'Generate a new version?',
            message:
                'This replaces the quiz with new questions using these settings.\n\n' +
                'Students will get the new questions. Past attempts, scores and mastery are kept.',
            confirmLabel: 'Generate',
        });
        if (!confirmed) return;
    }

    if (!(await saveSettings({ quiet: true }))) return;

    setBusy(dom.generateBtn, true, '<i class="fas fa-spinner fa-spin"></i> QuestAI is writing questions…');

    try {
        const { ok, status, data } = await apiRequest(lessonUrl('/generate'), { method: 'POST', body: {} });
        if (!ok) {
            showToast(errorMessageFor(status, data, 'Could not generate the quiz.'));
            return;
        }

        showToast(`Quiz ready — ${data.question_count} questions`);
        await refreshAfterChange();
    } catch (e) {
        showToast('Could not reach the server. Please try again.');
    } finally {
        setBusy(dom.generateBtn, false);
    }
}

/* REVIEW */
function wireReviewCard() {
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            state.filter = chip.dataset.filter;
            document.querySelectorAll('.filter-chip').forEach(c => c.classList.toggle('active', c === chip));
            renderQuestions();
        });
    });

    dom.topUpBtn.addEventListener('click', topUp);

    // One delegated handler for every question card's controls.
    dom.questionList.addEventListener('click', event => {
        const button = event.target.closest('[data-action]');
        if (!button || button.tagName === 'SELECT') return;

        const card = button.closest('.question-card');
        const id = Number(card?.dataset.questionId);
        if (!id) return;

        const action = button.dataset.action;
        if (action === 'approve') submitReview(id, 'approved', card);
        else if (action === 'reject') toggleRejectForm(id);
        else if (action === 'confirm-reject') submitReview(id, 'rejected', card);
        else if (action === 'cancel-reject') toggleRejectForm(null);
        else if (action === 'undo') clearReview(id, card);
        else if (action === 'pick-competency') toggleCompetencyPicker(button, id);
        else if (action === 'use-suggestion') {
            const select = card.querySelector('select[data-action="difficulty"]');
            if (select) select.value = button.dataset.value;
        }
    });

    // Changing the label of an already-reviewed question saves right away.
    dom.questionList.addEventListener('change', event => {
        const select = event.target.closest('select[data-action="difficulty"]');
        if (!select) return;
        const card = select.closest('.question-card');
        const question = findQuestion(Number(card.dataset.questionId));
        if (question?.review) submitReview(question.id, question.review.verdict, card, { keepReason: true });
    });
}

function findQuestion(id) {
    return state.studio?.questions.find(q => q.id === id) || null;
}

function renderReview() {
    const { quiz, questions } = state.studio;
    dom.reviewCard.classList.toggle('is-hidden', !quiz);
    if (!quiz) return;

    const rejected = questions.filter(q => q.review?.verdict === 'rejected').length;
    const active = questions.length - rejected;
    const reviewed = questions.filter(q => q.review).length;
    // The bank the quiz should hold (larger than each student's count when adaptive).
    const target = sumCounts(state.studio.pool);
    const feedback = quiz.feedback;

    const parts = [
        `${active} active question${active === 1 ? '' : 's'}`,
        `${reviewed} reviewed`,
        `${quiz.attempts} student attempt${quiz.attempts === 1 ? '' : 's'}`,
    ];
    if (feedback?.count) parts.push(`student rating ${feedback.average_rating}/5`);
    dom.reviewMeta.textContent = parts.join(' · ');

    const missing = target - active;
    dom.topUpBar.classList.toggle('is-hidden', missing <= 0);
    dom.topUpText.textContent =
        missing > 0
            ? `The question bank has ${active} of the ${target} questions your settings need` +
              `${rejected ? ` (${rejected} rejected)` : ''}. QuestAI can write ${missing} more using your reviews.`
            : '';

    renderQuestions();
}

function questionMatchesFilter(q) {
    switch (state.filter) {
        case 'unreviewed':
            return !q.review;
        case 'rejected':
            return q.review?.verdict === 'rejected';
        case 'flagged':
            return Boolean(q.stats?.flagged);
        case 'retagged':
            return Boolean(q.ai_competency);
        default:
            return true;
    }
}

function renderQuestions() {
    closeCompetencyPicker();

    const { questions, limits } = state.studio;
    const visible = questions.filter(questionMatchesFilter);

    if (!visible.length) {
        const empty = {
            all: 'This quiz has no questions yet.',
            unreviewed: 'Every question has been reviewed. 🎉',
            rejected: 'No rejected questions.',
            retagged: 'You have not changed any competency tags.',
            flagged: `No questions flagged yet — a question needs at least ${limits.min_responses} student answers before its results are compared with its difficulty label.`,
        }[state.filter];
        dom.questionList.innerHTML = `<p class="list-empty">${esc(empty)}</p>`;
        return;
    }

    dom.questionList.innerHTML = visible
        .map(q => renderQuestionCard(q, questions.indexOf(q) + 1, limits))
        .join('');
}

function renderQuestionCard(q, number, limits) {
    const review = q.review;
    const cardClass = review ? (review.verdict === 'approved' ? 'is-approved' : 'is-rejected') : '';

    let reviewChip = '';
    if (review?.verdict === 'approved') {
        reviewChip = '<span class="status-chip status-saved review-chip"><i class="fas fa-check"></i> Approved</span>';
    } else if (review?.verdict === 'rejected') {
        const reason = limits.reasons[review.reason] || 'Rejected';
        reviewChip = `<span class="status-chip status-rejected review-chip"><i class="fas fa-xmark"></i> Rejected · ${esc(reason)}</span>`;
    }

    const relabel =
        review?.teacher_difficulty && review.teacher_difficulty !== q.ai_difficulty
            ? ` <span class="material-meta">(AI said ${esc(q.ai_difficulty)})</span>`
            : '';

    const choices = (q.choices || [])
        .map(
            (choice, i) =>
                `<li class="mcq-option-item${i === q.correct_index ? ' correct-answer' : ''}">` +
                `<span class="option-letter">${OPTION_LETTERS[i] || i + 1}</span><span>${esc(choice)}</span></li>`,
        )
        .join('');

    const options = DIFFICULTIES.map(
        d => `<option value="${d}"${d === q.difficulty ? ' selected' : ''}>${capitalize(d)}</option>`,
    ).join('');

    const competencies = state.studio.competencies || [];
    const retagged = q.ai_competency
        ? ` <span class="material-meta">(AI tagged ${esc(q.ai_competency)})</span>`
        : '';

    const isRejecting = state.rejectingId === q.id;
    const reasonOptions = Object.entries(limits.reasons)
        .map(([value, label]) => `<option value="${value}"${review?.reason === value ? ' selected' : ''}>${esc(capitalize(label))}</option>`)
        .join('');

    return `
        <div class="question-card ${cardClass}" data-question-id="${q.id}">
            <div class="question-card-head">
                <span class="q-number">Q${number}</span>
                <span class="difficulty-badge diff-${esc(q.difficulty)}">${esc(q.difficulty)}</span>${relabel}
                ${q.competency ? `<span class="competency-tag${q.ai_competency ? ' is-retagged' : ''}"><i class="fas fa-tag"></i> ${esc(q.competency)}</span>${retagged}` : ''}
                ${reviewChip}
            </div>
            <p class="mcq-question-text">${esc(q.text)}</p>
            <ul class="mcq-options-list">${choices}</ul>
            <details class="explanation">
                <summary>Explanation</summary>
                <p>${esc(q.explanation)}</p>
            </details>
            ${renderStats(q)}
            ${review?.comment ? `<p class="review-note"><i class="fas fa-comment"></i> ${esc(review.comment)}</p>` : ''}
            <div class="question-actions">
                <label>Difficulty
                    <select class="field-select" data-action="difficulty" aria-label="Difficulty for question ${number}">${options}</select>
                </label>
                ${competencies.length ? `<span class="competency-field">Competency
                    <button type="button" class="field-select competency-picker-btn" data-action="pick-competency"
                        aria-haspopup="listbox" aria-expanded="false"
                        aria-label="Competency for question ${number}: ${esc(q.competency || 'none')}. Change">
                        <span class="competency-picker-value">${esc(q.competency || 'Choose…')}</span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                </span>` : ''}
                <span class="actions-spacer"></span>
                ${review ? '<button type="button" class="link-btn" data-action="undo">Undo review</button>' : ''}
                <button type="button" class="action-btn check-btn" data-action="approve"${review?.verdict === 'approved' ? ' disabled' : ''}>
                    <i class="fas fa-check"></i> Approve
                </button>
                <button type="button" class="action-btn reject-btn" data-action="reject">
                    <i class="fas fa-xmark"></i> ${review?.verdict === 'rejected' ? 'Edit reason' : 'Reject'}
                </button>
            </div>
            <div class="reject-form${isRejecting ? '' : ' is-hidden'}">
                <select class="field-select" data-field="reason" aria-label="Rejection reason">${reasonOptions}</select>
                <input type="text" class="field-input" data-field="comment" maxlength="500"
                    placeholder="Optional note for QuestAI (what should it do differently?)"
                    value="${esc(review?.comment || '')}" />
                <button type="button" class="action-btn reject-btn" data-action="confirm-reject">Reject</button>
                <button type="button" class="link-btn" data-action="cancel-reject">Cancel</button>
            </div>
        </div>`;
}

function renderStats(q) {
    const stats = q.stats;
    if (!stats || !stats.responses) {
        return '<div class="question-stats"><i class="fas fa-chart-simple"></i> No student answers yet</div>';
    }

    let suggestion = '';
    if (stats.suggested_difficulty && stats.suggested_difficulty !== q.difficulty) {
        suggestion =
            `<span class="${stats.flagged ? 'flag' : ''}"><i class="fas fa-triangle-exclamation"></i> ` +
            `Results suggest <strong>${esc(stats.suggested_difficulty)}</strong></span>` +
            `<button type="button" class="link-btn" data-action="use-suggestion" data-value="${esc(stats.suggested_difficulty)}">Use suggestion</button>`;
    } else if (stats.suggested_difficulty) {
        suggestion = '<span><i class="fas fa-circle-check"></i> Results match the label</span>';
    }

    return `
        <div class="question-stats">
            <span><i class="fas fa-chart-simple"></i> ${stats.responses} answer${stats.responses === 1 ? '' : 's'} · ${stats.percent_correct}% correct</span>
            ${suggestion}
        </div>`;
}

function toggleRejectForm(id) {
    state.rejectingId = state.rejectingId === id ? null : id;
    renderQuestions();
    if (state.rejectingId) {
        document.querySelector(`.question-card[data-question-id="${id}"] [data-field="comment"]`)?.focus();
    }
}

async function submitReview(id, verdict, card, { keepReason = false } = {}) {
    const question = findQuestion(id);
    const body = {
        verdict,
        teacher_difficulty: card.querySelector('select[data-action="difficulty"]')?.value || null,
    };

    if (verdict === 'rejected') {
        const reasonEl = card.querySelector('[data-field="reason"]');
        const commentEl = card.querySelector('[data-field="comment"]');
        body.reason = keepReason ? question?.review?.reason : reasonEl?.value || 'other';
        body.comment = keepReason ? question?.review?.comment : commentEl?.value.trim() || null;
    } else if (keepReason) {
        body.comment = question?.review?.comment || null;
    }

    card.classList.add('is-busy');

    try {
        const { ok, status, data } = await apiRequest(lessonUrl(`/questions/${id}/review`), { method: 'PUT', body });
        if (!ok) {
            showToast(errorMessageFor(status, data, 'Could not save the review.'));
            card.classList.remove('is-busy');
            return;
        }

        replaceQuestion(data.question);
        state.rejectingId = null;
        renderReview();
        syncLessonCounts();
        loadTraining();
    } catch (e) {
        card.classList.remove('is-busy');
        showToast('Could not reach the server. Please try again.');
    }
}

/* COMPETENCY PICKER — one shared searchable dropdown, opened from a
   question card's Competency button. Type to filter, arrows + Enter or a
   click to choose, Escape to close. */
const competencyPicker = { el: null, input: null, list: null, anchor: null, questionId: null, options: [], active: 0 };

function buildCompetencyPicker() {
    const el = document.createElement('div');
    el.className = 'competency-popover is-hidden';
    el.innerHTML = `
        <div class="competency-search">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="text" class="field-input" placeholder="Search competencies…" autocomplete="off"
                spellcheck="false" role="combobox" aria-expanded="true" aria-controls="competencyOptions"
                aria-label="Search competencies" />
        </div>
        <ul id="competencyOptions" class="competency-options" role="listbox"></ul>`;
    document.body.appendChild(el);

    competencyPicker.el = el;
    competencyPicker.input = el.querySelector('input');
    competencyPicker.list = el.querySelector('ul');

    competencyPicker.input.addEventListener('input', () => {
        competencyPicker.active = 0;
        renderCompetencyOptions();
    });

    competencyPicker.input.addEventListener('keydown', event => {
        const count = competencyPicker.options.length;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!count) return;
            competencyPicker.active = (competencyPicker.active + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
            renderCompetencyOptions();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const choice = competencyPicker.options[competencyPicker.active];
            if (choice) chooseCompetency(choice.id);
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            closeCompetencyPicker({ refocus: true });
        } else if (event.key === 'Tab') {
            closeCompetencyPicker();
        }
    });

    // mousedown keeps focus in the search box until the choice is made.
    competencyPicker.list.addEventListener('mousedown', event => {
        event.preventDefault();
        const option = event.target.closest('[data-id]');
        if (option) chooseCompetency(Number(option.dataset.id));
    });

    document.addEventListener('mousedown', event => {
        if (!competencyPicker.anchor) return;
        if (competencyPicker.el.contains(event.target) || competencyPicker.anchor.contains(event.target)) return;
        closeCompetencyPicker();
    });

    // The popover is fixed-position, so close it rather than let it drift.
    window.addEventListener('resize', () => closeCompetencyPicker());
    document.addEventListener('scroll', event => {
        if (competencyPicker.anchor && !competencyPicker.el.contains(event.target)) closeCompetencyPicker();
    }, true);
}

function toggleCompetencyPicker(button, questionId) {
    if (!competencyPicker.el) buildCompetencyPicker();

    if (competencyPicker.anchor === button) {
        closeCompetencyPicker();
        return;
    }

    closeCompetencyPicker();
    competencyPicker.anchor = button;
    competencyPicker.questionId = questionId;
    competencyPicker.input.value = '';
    button.setAttribute('aria-expanded', 'true');

    // Start on the question's current competency.
    const current = findQuestion(questionId)?.competency_id;
    competencyPicker.active = Math.max(0, (state.studio.competencies || []).findIndex(c => c.id === current));

    competencyPicker.el.classList.remove('is-hidden');
    renderCompetencyOptions();
    positionCompetencyPicker();
    competencyPicker.input.focus();
}

function closeCompetencyPicker({ refocus = false } = {}) {
    if (!competencyPicker.anchor) return;

    const anchor = competencyPicker.anchor;
    anchor.setAttribute('aria-expanded', 'false');
    competencyPicker.el.classList.add('is-hidden');
    competencyPicker.anchor = null;
    competencyPicker.questionId = null;
    if (refocus && document.body.contains(anchor)) anchor.focus();
}

function renderCompetencyOptions() {
    const query = competencyPicker.input.value.trim().toLowerCase();
    const current = findQuestion(competencyPicker.questionId)?.competency_id;

    competencyPicker.options = (state.studio?.competencies || []).filter(c => c.name.toLowerCase().includes(query));
    competencyPicker.active = Math.min(competencyPicker.active, Math.max(0, competencyPicker.options.length - 1));

    competencyPicker.list.innerHTML = competencyPicker.options.length
        ? competencyPicker.options
              .map(
                  (c, i) => `
                <li id="competencyOption${c.id}" role="option" data-id="${c.id}" aria-selected="${c.id === current}"
                    class="competency-option${i === competencyPicker.active ? ' is-active' : ''}${c.id === current ? ' is-current' : ''}">
                    <span>${esc(c.name)}</span>
                    ${c.id === current ? '<i class="fas fa-check" aria-hidden="true"></i>' : ''}
                </li>`,
              )
              .join('')
        : '<li class="competency-option-empty">No competencies match your search.</li>';

    const active = competencyPicker.options[competencyPicker.active];
    if (active) {
        competencyPicker.input.setAttribute('aria-activedescendant', `competencyOption${active.id}`);
        competencyPicker.list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
    } else {
        competencyPicker.input.removeAttribute('aria-activedescendant');
    }
}

/* Below the button when there is room, otherwise above it. */
function positionCompetencyPicker() {
    const rect = competencyPicker.anchor.getBoundingClientRect();
    const el = competencyPicker.el;
    const width = Math.min(window.innerWidth - 16, Math.max(rect.width, 320));

    el.style.width = `${width}px`;
    el.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;

    const height = el.offsetHeight;
    const below = window.innerHeight - rect.bottom;
    el.style.top = below >= height + 12 || below >= rect.top
        ? `${rect.bottom + 6}px`
        : `${Math.max(8, rect.top - height - 6)}px`;
}

function chooseCompetency(competencyId) {
    const questionId = competencyPicker.questionId;
    const card = competencyPicker.anchor?.closest('.question-card');
    const unchanged = findQuestion(questionId)?.competency_id === competencyId;

    closeCompetencyPicker({ refocus: unchanged });
    if (!unchanged && card) saveCompetency(questionId, competencyId, card);
}

async function saveCompetency(id, competencyId, card) {
    card.classList.add('is-busy');

    try {
        const { ok, status, data } = await apiRequest(lessonUrl(`/questions/${id}/competency`), {
            method: 'PUT',
            body: { competency_id: competencyId },
        });

        if (!ok) {
            showToast(errorMessageFor(status, data, 'Could not change the competency.'));
            renderQuestions(); // put the dropdown back on the saved value
            return;
        }

        replaceQuestion(data.question);
        renderQuestions();
        showToast(`Tagged as "${data.question.competency}"`);
    } catch (e) {
        renderQuestions();
        showToast('Could not reach the server. Please try again.');
    }
}

async function clearReview(id, card) {
    card.classList.add('is-busy');

    try {
        const { ok, data } = await apiRequest(lessonUrl(`/questions/${id}/review`), { method: 'DELETE' });
        if (!ok) throw new Error('failed');

        replaceQuestion(data.question);
        renderReview();
        syncLessonCounts();
        loadTraining();
    } catch (e) {
        card.classList.remove('is-busy');
        showToast('Could not undo the review. Please try again.');
    }
}

function replaceQuestion(updated) {
    const index = state.studio.questions.findIndex(q => q.id === updated.id);
    if (index >= 0) state.studio.questions[index] = updated;
}

async function topUp() {
    setBusy(dom.topUpBtn, true, '<i class="fas fa-spinner fa-spin"></i> Writing replacements…');

    try {
        const { ok, status, data } = await apiRequest(lessonUrl('/top-up'), { method: 'POST', body: {} });
        if (!ok) {
            showToast(errorMessageFor(status, data, 'Could not generate replacement questions.'));
            return;
        }

        showToast(`${data.added} new question${data.added === 1 ? '' : 's'} added`);
        await refreshAfterChange();
    } catch (e) {
        showToast('Could not reach the server. Please try again.');
    } finally {
        setBusy(dom.topUpBtn, false);
    }
}

async function refreshAfterChange() {
    await loadStudio();
    await loadLessons({ keepSelection: true });
    loadTraining();
}

/* Keep the lesson list's counts in step with local review changes. */
function syncLessonCounts() {
    const questions = state.studio.questions;
    const rejected = questions.filter(q => q.review?.verdict === 'rejected').length;

    updateLessonInList({
        has_quiz: true,
        question_count: questions.length - rejected,
        reviewed: questions.filter(q => q.review).length,
        rejected,
    });
}

function updateLessonInList(changes) {
    const lesson = activeLesson();
    if (!lesson) return;
    Object.assign(lesson, changes);
    renderLessons();
}

/* STUDENT FEEDBACK — perceived difficulty as a diverging stacked bar per
   mastery group (too easy ← just right → too hard, centered on "just right"),
   rating distribution, comments, and a table view with every value. */
const FEEDBACK_KINDS = [
    { key: 'too_easy', label: 'Too easy' },
    { key: 'just_right', label: 'Just right' },
    { key: 'too_hard', label: 'Too hard' },
];

const FEEDBACK_GROUPS = {
    all: { label: 'All students', note: '' },
    high: { label: 'High mastery', note: 'got a harder mix' },
    developing: { label: 'Developing', note: 'got your mix' },
    low: { label: 'Low mastery', note: 'got an easier mix' },
    unassessed: { label: 'Not adapted', note: 'new students or adaptivity off' },
};

function percent(part, whole) {
    return whole ? Math.round((part / whole) * 100) : 0;
}

function stars(rating) {
    return '★'.repeat(rating) + '☆'.repeat(5 - rating);
}

function renderFeedback() {
    const data = state.studio?.quiz?.feedback_breakdown;
    if (!dom.feedbackBody || !data) return;

    if (!data.count) {
        dom.feedbackBody.innerHTML =
            '<p class="list-empty">No student feedback yet. Students can rate the quiz and say how difficult it felt after they submit it.</p>';
        return;
    }

    const all = data.groups.find(g => g.key === 'all');
    const levelGroups = data.groups.filter(g => g.key !== 'all');

    dom.feedbackBody.innerHTML = `
        <div class="fb-kpis">
            <div class="fb-kpi">
                <div class="fb-kpi-label">Responses</div>
                <div class="fb-kpi-value">${data.count}</div>
            </div>
            <div class="fb-kpi">
                <div class="fb-kpi-label">Average rating</div>
                <div class="fb-kpi-value">${data.average_rating ?? '—'} <small>/ 5</small></div>
            </div>
            <div class="fb-kpi">
                <div class="fb-kpi-label">Felt "just right"</div>
                <div class="fb-kpi-value">${percent(all.just_right, all.count)}%</div>
            </div>
        </div>

        <section class="fb-section" aria-labelledby="fbDifficultyTitle">
            <h3 id="fbDifficultyTitle" class="fb-section-title">How difficult did the quiz feel?</h3>
            <p class="fb-section-note">
                Grouped by the BKT mastery level each student's quiz was adapted to.
                Bars are centered on "just right": blue leans too easy, red leans too hard.
            </p>
            <div class="fb-legend" aria-hidden="true">
                ${FEEDBACK_KINDS.map(k => `<span><i class="fb-swatch" style="background:var(--fb-${k.key === 'too_easy' ? 'easy' : k.key === 'too_hard' ? 'hard' : 'right'})"></i>${k.label}</span>`).join('')}
            </div>
            ${renderDivergingBars([all, ...levelGroups])}
        </section>

        <div class="fb-two-col">
            <section class="fb-section" aria-labelledby="fbRatingTitle">
                <h3 id="fbRatingTitle" class="fb-section-title">Ratings</h3>
                <p class="fb-section-note">How many students gave each star rating.</p>
                ${renderRatingBars(data.ratings, data.count)}
            </section>
            <section class="fb-section" aria-labelledby="fbCommentsTitle">
                <h3 id="fbCommentsTitle" class="fb-section-title">Recent comments</h3>
                <p class="fb-section-note">Anonymous — shown with the student's rating and mastery group.</p>
                ${renderFeedbackComments(data.comments)}
            </section>
        </div>

        ${renderFeedbackTable(data)}`;

    fitSegmentLabels();
    wireChartTooltips(dom.feedbackBody);
}

function renderDivergingBars(groups) {
    const rows = groups.filter(g => g.count > 0);

    // Shared scale so every row's "just right" midpoint sits on one center line.
    const extent = rows.map(g => {
        const easy = g.too_easy / g.count;
        const right = g.just_right / g.count;
        const hard = g.too_hard / g.count;
        return { g, easy, right, hard, left: easy + right / 2, rightSide: hard + right / 2 };
    });
    const maxLeft = Math.max(...extent.map(e => e.left), 0.01);
    const maxRight = Math.max(...extent.map(e => e.rightSide), 0.01);
    const span = maxLeft + maxRight;
    const center = (maxLeft / span) * 100;

    const bars = extent
        .map(({ g, easy, right, hard, left }) => {
            const info = FEEDBACK_GROUPS[g.key] || { label: g.key, note: '' };
            const segments = [
                ['too_easy', easy, g.too_easy],
                ['just_right', right, g.just_right],
                ['too_hard', hard, g.too_hard],
            ]
                .filter(([, share]) => share > 0)
                .map(([kind, share, count]) => {
                    const label = FEEDBACK_KINDS.find(k => k.key === kind).label;
                    const pct = percent(count, g.count);
                    const tip = `${info.label}: ${label} — ${count} of ${g.count} (${pct}%)`;
                    return `<span class="fb-seg" data-kind="${kind}" data-label="${pct}%" data-tip="${esc(tip)}"
                        tabindex="0" role="img" aria-label="${esc(tip)}" style="flex:${share} 1 0"></span>`;
                })
                .join('');

            return `
                <div class="fb-row${g.key === 'all' ? ' is-all' : ''}">
                    <div class="fb-row-label">
                        <strong>${esc(info.label)}</strong> <span class="fb-n">(${g.count})</span>
                        ${info.note ? `<br><span class="fb-n">${esc(info.note)}</span>` : ''}
                    </div>
                    <div class="fb-track">
                        <span class="fb-center" style="left:${center}%" aria-hidden="true"></span>
                        <div class="fb-stack" style="left:${((maxLeft - left) / span) * 100}%; width:${(1 / span) * 100}%">
                            ${segments}
                        </div>
                    </div>
                </div>`;
        })
        .join('');

    return `
        <div class="fb-diverging">${bars}</div>
        <div class="fb-axis-labels" aria-hidden="true">
            <span></span>
            <span class="fb-axis-track"><span>← Too easy</span><span>Too hard →</span></span>
        </div>`;
}

/* Percent labels go inside a segment only when they fit; otherwise the
   legend, tooltip and table view carry the value. */
function fitSegmentLabels() {
    dom.feedbackBody.querySelectorAll('.fb-seg').forEach(seg => {
        seg.textContent = seg.offsetWidth >= 34 ? seg.dataset.label : '';
    });
}

function renderRatingBars(ratings, total) {
    const max = Math.max(...Object.values(ratings), 1);

    return `<div class="fb-ratings">${[5, 4, 3, 2, 1]
        .map(r => {
            const count = ratings[r] || 0;
            const tip = `${r} star${r === 1 ? '' : 's'}: ${count} of ${total} (${percent(count, total)}%)`;
            return `
                <div class="fb-rating-row">
                    <span class="fb-stars" aria-hidden="true">${r} ★</span>
                    <span class="fb-rating-track">
                        ${count ? `<span class="fb-rating-bar" style="width:${(count / max) * 100}%" data-tip="${esc(tip)}" tabindex="0" role="img" aria-label="${esc(tip)}"></span>` : ''}
                    </span>
                    <span class="fb-rating-count">${count}</span>
                </div>`;
        })
        .join('')}</div>`;
}

function renderFeedbackComments(comments) {
    if (!comments.length) return '<p class="list-empty">No written comments yet.</p>';

    const swatchVar = { too_easy: 'easy', just_right: 'right', too_hard: 'hard' };

    return `<ul class="fb-comments">${comments
        .map(c => {
            const kind = FEEDBACK_KINDS.find(k => k.key === c.difficulty)?.label || c.difficulty;
            const group = FEEDBACK_GROUPS[c.level]?.label || c.level;
            return `
                <li class="fb-comment">
                    <div class="fb-comment-meta">
                        <span aria-label="${c.rating} out of 5 stars">${stars(c.rating)}</span>
                        <span><i class="fb-swatch" style="background:var(--fb-${swatchVar[c.difficulty] || 'right'})"></i> ${esc(kind)}</span>
                        <span>· ${esc(group)}</span>
                        <span>· ${esc(new Date(c.submitted_at).toLocaleDateString())}</span>
                    </div>
                    ${esc(c.comment)}
                </li>`;
        })
        .join('')}</ul>`;
}

function renderFeedbackTable(data) {
    const groupRows = data.groups
        .map(g => {
            const cell = n => `<td class="num">${n} (${percent(n, g.count)}%)</td>`;
            return `<tr><th scope="row">${esc(FEEDBACK_GROUPS[g.key]?.label || g.key)}</th>
                <td class="num">${g.count}</td>${cell(g.too_easy)}${cell(g.just_right)}${cell(g.too_hard)}
                <td class="num">${g.average_rating ?? '—'}</td></tr>`;
        })
        .join('');

    const ratingRows = [5, 4, 3, 2, 1]
        .map(r => `<tr><th scope="row">${r} ★</th><td class="num">${data.ratings[r] || 0}</td><td class="num">${percent(data.ratings[r] || 0, data.count)}%</td></tr>`)
        .join('');

    return `
        <details class="fb-table-toggle">
            <summary>View as table</summary>
            <table class="fb-table">
                <caption class="fb-section-note">Perceived difficulty by mastery group</caption>
                <thead><tr><th>Group</th><th class="num">Responses</th><th class="num">Too easy</th>
                    <th class="num">Just right</th><th class="num">Too hard</th><th class="num">Avg rating</th></tr></thead>
                <tbody>${groupRows}</tbody>
            </table>
            <table class="fb-table">
                <caption class="fb-section-note">Ratings</caption>
                <thead><tr><th>Rating</th><th class="num">Students</th><th class="num">Share</th></tr></thead>
                <tbody>${ratingRows}</tbody>
            </table>
        </details>`;
}

/* One shared tooltip for chart marks: follows hover and keyboard focus. */
let chartTooltip = null;

function wireChartTooltips(root) {
    if (!chartTooltip) {
        chartTooltip = document.createElement('div');
        chartTooltip.className = 'fb-tooltip is-hidden';
        chartTooltip.setAttribute('role', 'presentation');
        document.body.appendChild(chartTooltip);
    }

    const show = (el, x, y) => {
        chartTooltip.textContent = el.dataset.tip;
        chartTooltip.classList.remove('is-hidden');
        const rect = chartTooltip.getBoundingClientRect();
        const left = Math.min(window.innerWidth - rect.width - 8, Math.max(8, x - rect.width / 2));
        chartTooltip.style.left = `${left}px`;
        chartTooltip.style.top = `${Math.max(8, y - rect.height - 12)}px`;
    };
    const hide = () => chartTooltip.classList.add('is-hidden');

    root.querySelectorAll('[data-tip]').forEach(el => {
        el.addEventListener('mousemove', e => show(el, e.clientX, e.clientY));
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', () => {
            const r = el.getBoundingClientRect();
            show(el, r.left + r.width / 2, r.top);
        });
        el.addEventListener('blur', hide);
    });
}

window.addEventListener('resize', () => {
    if (state.studio?.quiz?.feedback_breakdown?.count) fitSegmentLabels();
});

/* TRAINING METRICS */
let trainingRequest = 0;

async function loadTraining() {
    const classId = state.activeClassId;
    const requestId = ++trainingRequest;
    if (!classId) return;

    try {
        const { ok, data } = await apiRequest(`/professor/quiz-studio/training?class_id=${classId}`);
        if (!ok) throw new Error('failed');
        if (requestId !== trainingRequest) return;
        renderTraining(data);
    } catch (e) {
        if (requestId === trainingRequest) dom.trainingCard.classList.add('is-hidden');
    }
}

function renderTraining(data) {
    if (!data.subject || !data.metrics) {
        dom.trainingCard.classList.add('is-hidden');
        return;
    }

    const m = data.metrics;
    dom.trainingCard.classList.remove('is-hidden');
    dom.trainingSubtitle.textContent = `${data.subject.name} · all lessons in this subject`;

    const pct = value => (value === null || value === undefined ? '—' : `${value}%`);

    const calibration = m.by_difficulty
        .map(row => {
            const target = Math.round(row.target * 100);
            const actual = row.percent_correct;
            const label =
                row.responses >= m.min_responses && actual !== null
                    ? `${actual}% correct · target ${target}%`
                    : `${row.responses} answer${row.responses === 1 ? '' : 's'} so far`;
            return `
                <div class="calibration-row">
                    <span class="difficulty-badge diff-${row.difficulty}">${row.difficulty}</span>
                    <div class="calibration-bar" title="Target ${target}%">
                        <div class="calibration-fill" style="width:${actual ?? 0}%"></div>
                        <span class="calibration-target" style="left:calc(${target}% - 1px)"></span>
                    </div>
                    <span>${esc(label)}</span>
                </div>`;
        })
        .join('');

    const reasons = m.rejection_reasons.length
        ? `<ul class="plain-list">${m.rejection_reasons
              .map(r => `<li><span>${esc(capitalize(r.label))}</span><strong>${r.count}</strong></li>`)
              .join('')}</ul>`
        : '<p class="metric-note">No rejections yet.</p>';

    const relabels = m.relabels.length
        ? `<ul class="plain-list">${m.relabels
              .map(r => `<li><span>AI "${esc(r.from)}" → you "${esc(r.to)}"</span><strong>${r.count}</strong></li>`)
              .join('')}</ul>`
        : '<p class="metric-note">No difficulty corrections yet.</p>';

    const flagged = m.flagged.length
        ? `<ul class="plain-list">${m.flagged
              .map(
                  f =>
                      `<li><span>${esc(f.question)} <span class="muted">— ${esc(f.lesson || '')}</span></span>` +
                      `<strong>${esc(f.difficulty)} → ${esc(f.suggested_difficulty)}</strong></li>`,
              )
              .join('')}</ul>`
        : `<p class="metric-note">No mislabeled questions detected (needs ${m.min_responses}+ student answers per question).</p>`;

    dom.trainingBody.innerHTML = `
        <div class="metric-tile metric-half">
            <div class="metric-label">Questions reviewed</div>
            <div class="metric-value">${m.reviewed}</div>
            <p class="metric-note">${m.approved} approved · ${m.rejected} rejected</p>
        </div>
        <div class="metric-tile metric-half">
            <div class="metric-label">Approval rate</div>
            <div class="metric-value">${pct(m.approval_rate)}</div>
            <p class="metric-note">Share of reviewed AI questions you kept.</p>
        </div>
        <div class="metric-tile metric-half">
            <div class="metric-label">Difficulty agreement</div>
            <div class="metric-value">${pct(m.difficulty_agreement)}</div>
            <p class="metric-note">Reviewed questions where you kept the AI's difficulty label.</p>
        </div>
        <div class="metric-tile metric-wide">
            <div class="metric-label">Student results by difficulty label</div>
            <p class="metric-note">Bar = share of answers correct; line = working target (configurable).</p>
            ${calibration}
        </div>
        <div class="metric-tile metric-half">
            <div class="metric-label">Why questions were rejected</div>
            ${reasons}
        </div>
        <div class="metric-tile metric-half">
            <div class="metric-label">Difficulty corrections</div>
            ${relabels}
        </div>
        <div class="metric-tile metric-half">
            <div class="metric-label">Possibly mislabeled (by student results)</div>
            ${flagged}
        </div>`;
}

/* TOAST — uses the shell's toast (this page runs inside its iframe). */
function showToast(text) {
    try {
        if (window.parent !== window && typeof window.parent.showToast === 'function') {
            window.parent.showToast(text);
            return;
        }
    } catch (e) {
        /* Cross-origin parent — fall through. */
    }
    console.info(text);
}
