const OPTION_LETTERS = ["A", "B", "C", "D", "E", "F"];
const DEFAULT_TIME_LIMIT_SECONDS = 15 * 60;
const TIMER_CIRCUMFERENCE = 213.6;
const RESULTS_CIRCUMFERENCE = 326.7;

const params = new URLSearchParams(window.location.search);
const CLASS_ID = params.get("id");
const POST_ID = params.get("postId");

/* STATE — populated once the quiz has loaded from the server */
let QUIZ = null;
let currentIndex = 0;
let userAnswers = [];
let flagged = [];
let secondsLeft = DEFAULT_TIME_LIMIT_SECONDS;
let timerInterval = null;
let quizSubmitted = false;
let reviewMode = false;
let reviewByQuestionId = {};
let currentAttemptId = null;
let selectedRating = null;
let selectedDifficulty = null;
let feedbackSubmitted = false;

function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
}

document.addEventListener("DOMContentLoaded", () => {
    initNavigatorToggle();
    setInteractiveButtonsEnabled(false);

    document
        .getElementById("prevQBtn")
        .addEventListener("click", () => goToQuestion(currentIndex - 1));
    document
        .getElementById("nextQBtn")
        .addEventListener("click", () => goToQuestion(currentIndex + 1));

    document.getElementById("flagBtn").addEventListener("click", toggleFlag);

    document
        .getElementById("submitQuizBtn")
        .addEventListener("click", openSubmitConfirm);
    document
        .getElementById("submitCancelBtn")
        .addEventListener("click", closeSubmitConfirm);
    document
        .getElementById("submitConfirmBtn")
        .addEventListener("click", finalizeSubmit);

    document
        .getElementById("backToClassworkBtn")
        .addEventListener("click", () => {
            window.location.href = classworkUrl();
        });
    document
        .getElementById("backToClassworkResultsBtn")
        .addEventListener("click", () => {
            window.location.href = classworkUrl();
        });

    document
        .getElementById("reviewAnswersBtn")
        .addEventListener("click", () => {
            closeModal("resultsModal");
            reviewMode = true;
            goToQuestion(0);
        });

    initFeedbackControls();

    loadQuiz();
});

/*  QUIZ FEEDBACK — inline "Rate this quiz" section in the results modal  */
function initFeedbackControls() {
    document.querySelectorAll(".feedback-star").forEach((star) => {
        star.addEventListener("click", () => {
            selectedRating = Number(star.dataset.value);
            document.querySelectorAll(".feedback-star").forEach((s) => {
                s.classList.toggle("selected", Number(s.dataset.value) <= selectedRating);
            });
            updateFeedbackSubmitState();
        });
    });

    document.querySelectorAll(".feedback-pill").forEach((pill) => {
        pill.addEventListener("click", () => {
            selectedDifficulty = pill.dataset.value;
            document.querySelectorAll(".feedback-pill").forEach((p) => {
                p.classList.toggle("selected", p === pill);
            });
            updateFeedbackSubmitState();
        });
    });

    document.getElementById("feedbackSkipBtn")?.addEventListener("click", () => {
        document.getElementById("feedbackSection").hidden = true;
    });

    document.getElementById("feedbackSubmitBtn")?.addEventListener("click", submitFeedback);
}

function updateFeedbackSubmitState() {
    const btn = document.getElementById("feedbackSubmitBtn");
    if (btn) btn.disabled = !(selectedRating && selectedDifficulty);
}

function resetFeedbackUI() {
    selectedRating = null;
    selectedDifficulty = null;
    feedbackSubmitted = false;

    document.querySelectorAll(".feedback-star").forEach((s) => s.classList.remove("selected"));
    document.querySelectorAll(".feedback-pill").forEach((p) => p.classList.remove("selected"));

    const comment = document.getElementById("feedbackComment");
    if (comment) comment.value = "";

    updateFeedbackSubmitState();

    document.getElementById("feedbackSection").hidden = false;
    document.getElementById("feedbackThanks").classList.add("hidden");
}

async function submitFeedback() {
    if (feedbackSubmitted || !currentAttemptId || !selectedRating || !selectedDifficulty) return;

    const submitBtn = document.getElementById("feedbackSubmitBtn");
    if (submitBtn) submitBtn.disabled = true;

    const url = `/student/classes/${CLASS_ID}/posts/${POST_ID}/quiz/attempts/${currentAttemptId}/feedback`;
    const comment = document.getElementById("feedbackComment")?.value.trim() || undefined;

    try {
        const res = await fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
            body: JSON.stringify({ rating: selectedRating, difficulty: selectedDifficulty, comment }),
        });

        if (!res.ok) throw new Error(`feedback submit failed (HTTP ${res.status})`);

        feedbackSubmitted = true;
        document.getElementById("feedbackSection").hidden = true;
        document.getElementById("feedbackThanks").classList.remove("hidden");
    } catch (e) {
        console.error("[AI Quiz] Feedback submit threw:", e);
        showToast("Couldn't submit feedback. Please try again.");
        if (submitBtn) submitBtn.disabled = false;
    }
}

function classworkUrl() {
    return `classwork.html?id=${CLASS_ID}&postId=${POST_ID}`;
}

/**
 * Enables/disables the buttons that only make sense once a quiz is loaded.
 * Prevents crashes from clicks (flag, submit) that land before QUIZ exists,
 * and gives clear visual feedback that the page is busy, not frozen.
 */
function setInteractiveButtonsEnabled(enabled) {
    ["prevQBtn", "nextQBtn", "flagBtn", "submitQuizBtn"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.disabled = !enabled;
    });
}

/*  LOAD QUIZ FROM SERVER  */
async function loadQuiz() {
    renderLoadingState();

    if (!CLASS_ID || !POST_ID) {
        renderLoadError("This quiz link is missing information. Please go back and try again.");
        return;
    }

    const url = `/student/classes/${CLASS_ID}/posts/${POST_ID}/quiz`;
    console.groupCollapsed(`[AI Quiz] GET ${url}`);
    console.log("post id:", POST_ID, "class id:", CLASS_ID);

    try {
        const res = await fetch(url, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });

        const data = await res.json().catch(() => null);
        console.log(`status: ${res.status}`);
        if (data?.request_id) console.log("request_id:", data.request_id);
        console.log("response body:", data);

        if (res.status === 403) {
            // The teacher's attempt limit has been reached — nothing to retry.
            console.groupEnd();
            renderLoadError(data?.message || "You've used all attempts allowed for this quiz.", { retry: false });
            return;
        }

        if (!res.ok) {
            throw new Error(data?.message || `quiz request failed (HTTP ${res.status})`);
        }

        console.groupEnd();

        if (!data.questions || data.questions.length === 0) {
            renderLoadError("This quiz doesn't have any questions yet.");
            return;
        }

        const settings = data.settings || {};

        QUIZ = {
            subject: data.subject || "Science",
            section: data.section || "",
            title: data.lesson_title || "Quiz",
            // null = the teacher set no time limit.
            timeLimitSeconds: settings.time_limit_seconds ?? null,
            maxAttempts: settings.max_attempts ?? null,
            attemptsUsed: settings.attempts_used ?? 0,
            questions: data.questions.map((q) => ({
                id: q.id,
                text: q.text,
                options: q.choices,
                // Original index of each displayed choice (choices may be
                // shuffled); answers are submitted as original indexes.
                choiceIndexes: q.choice_indexes || q.choices.map((_, i) => i),
            })),
        };

        userAnswers = new Array(QUIZ.questions.length).fill(null);
        flagged = new Array(QUIZ.questions.length).fill(false);
        // The server keeps the clock for timed quizzes, so a reload resumes
        // the remaining time instead of restarting it.
        secondsLeft = settings.remaining_seconds ?? QUIZ.timeLimitSeconds ?? 0;

        renderBanner();
        buildNavigatorGrid();
        setInteractiveButtonsEnabled(true);
        renderQuestion(0);
        if (QUIZ.timeLimitSeconds) startTimer();

        // Questions were picked for the student's BKT mastery level.
        const ADAPTED_MESSAGES = {
            high: "You've shown high mastery here, so this quiz has more challenging questions.",
            low: "This quiz focuses on the core ideas first to help you build mastery.",
        };
        if (ADAPTED_MESSAGES[settings.mastery_level]) showToast(ADAPTED_MESSAGES[settings.mastery_level]);
    } catch (e) {
        console.error("[AI Quiz] Fetch threw:", e);
        console.groupEnd();
        renderLoadError("We couldn't load this quiz right now.");
    }
}

/**
 * Shown immediately when a (re)load starts. Only touches questionText and
 * optionsList — never questionCard's innerHTML — so questionNumberBadge and
 * flagBtn stay in the DOM for renderQuestion() to use once the quiz loads.
 */
function renderLoadingState() {
    const textEl = document.getElementById("questionText");
    const optionsList = document.getElementById("optionsList");
    if (textEl) {
        textEl.textContent = "Generating your AI quiz… this can take up to a minute the first time.";
    }
    if (optionsList) optionsList.innerHTML = "";
    setInteractiveButtonsEnabled(false);
}

function renderLoadError(message, { retry = true } = {}) {
    const textEl = document.getElementById("questionText");
    const optionsList = document.getElementById("optionsList");
    if (textEl) textEl.textContent = message;
    if (optionsList) {
        optionsList.innerHTML = retry
            ? `<button type="button" id="quizRetryBtn" class="btn btn-secondary">Try again</button>`
            : "";
        document.getElementById("quizRetryBtn")?.addEventListener("click", loadQuiz);
    }
    setInteractiveButtonsEnabled(false);
}

function renderBanner() {
    document.getElementById("quizBannerEyebrow").textContent = QUIZ.section
        ? `${QUIZ.subject} · ${QUIZ.section}`
        : QUIZ.subject;
    document.getElementById("quizBannerTitle").textContent = QUIZ.title;
    document.getElementById("quizMetaCount").textContent = `${QUIZ.questions.length} Items`;
    document.getElementById("quizMetaTime").textContent = QUIZ.timeLimitSeconds
        ? `${formatTime(QUIZ.timeLimitSeconds)} Limit`
        : "No time limit";
    document.getElementById("quizMetaAttempts").textContent = QUIZ.maxAttempts
        ? `Attempt ${QUIZ.attemptsUsed + 1} of ${QUIZ.maxAttempts}`
        : "Unlimited attempts";

    // No timer set by the teacher: hide the countdown ring.
    const ring = document.querySelector(".quiz-timer-ring");
    if (ring) ring.style.display = QUIZ.timeLimitSeconds ? "" : "none";
}

/* ── NAVIGATOR COLLAPSE / EXPAND ── */
function initNavigatorToggle() {
    const toggleBtn = document.getElementById("navigatorToggleBtn");
    const card = document.querySelector(".navigator-card");
    if (!toggleBtn || !card) return;

    let startCollapsed;
    try {
        const saved = localStorage.getItem("lq_navigatorCollapsed");
        startCollapsed =
            saved !== null ? saved === "true" : window.innerWidth <= 768;
    } catch (e) {
        startCollapsed = window.innerWidth <= 768;
    }
    setNavigatorCollapsed(startCollapsed, card, toggleBtn);

    toggleBtn.addEventListener("click", () => {
        const isCollapsed = card.classList.contains("collapsed");
        setNavigatorCollapsed(!isCollapsed, card, toggleBtn);
        try {
            localStorage.setItem("lq_navigatorCollapsed", String(!isCollapsed));
        } catch (e) {
            /* ignore */
        }
    });
}

function setNavigatorCollapsed(collapsed, card, toggleBtn) {
    card.classList.toggle("collapsed", collapsed);
    toggleBtn.setAttribute("aria-expanded", String(!collapsed));
}

/*  TIMER — mirrors the server's deadline for timed quizzes; submissions
    after the deadline (plus a short grace period) are marked late.  */
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
    const textEl = document.getElementById("timerText");
    const circleEl = document.getElementById("timerProgressCircle");
    textEl.textContent = formatTime(secondsLeft);

    const ratio = secondsLeft / QUIZ.timeLimitSeconds;
    const offset = TIMER_CIRCUMFERENCE * (1 - ratio);
    circleEl.style.strokeDashoffset = offset;

    if (ratio <= 0.15) {
        circleEl.classList.add("timer-low");
    } else {
        circleEl.classList.remove("timer-low");
    }
}

function formatTime(totalSeconds) {
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;
    return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

/* ── NAVIGATOR GRID ── */
function buildNavigatorGrid() {
    const grid = document.getElementById("navigatorGrid");
    grid.innerHTML = "";
    QUIZ.questions.forEach((q, i) => {
        const cell = document.createElement("div");
        cell.className = "nav-cell";
        cell.id = `navCell-${i}`;
        cell.textContent = i + 1;
        cell.addEventListener("click", () => goToQuestion(i));
        grid.appendChild(cell);
    });
    refreshNavigatorState();
}

function refreshNavigatorState() {
    QUIZ.questions.forEach((q, i) => {
        const cell = document.getElementById(`navCell-${i}`);
        if (!cell) return;
        cell.classList.toggle("current", i === currentIndex);
        cell.classList.toggle("answered", userAnswers[i] !== null);
        cell.classList.toggle("flagged", flagged[i]);
    });
}

/*  RENDER QUESTION  */
function renderQuestion(index) {
    const q = QUIZ.questions[index];
    currentIndex = index;

    document.getElementById("questionNumberBadge").textContent = `Q${index + 1}`;
    document.getElementById("questionText").textContent = q.text;
    document.getElementById("progressLabel").textContent =
        `Question ${index + 1} of ${QUIZ.questions.length}`;
    document.getElementById("progressFill").style.width =
        `${((index + 1) / QUIZ.questions.length) * 100}%`;

    const flagBtn = document.getElementById("flagBtn");
    flagBtn.classList.toggle("flagged", flagged[index]);
    flagBtn.querySelector("i").className = flagged[index]
        ? "fas fa-flag"
        : "far fa-flag";

    const review = reviewByQuestionId[q.id];
    // Review data uses original choice indexes; map to the displayed order.
    // correct_index is null when the teacher hides answers.
    const correctDisplayIndex =
        review && review.correct_index !== null && review.correct_index !== undefined
            ? q.choiceIndexes.indexOf(review.correct_index)
            : null;

    const optionsList = document.getElementById("optionsList");
    optionsList.innerHTML = "";
    q.options.forEach((optionText, i) => {
        const item = document.createElement("div");
        item.className = "option-item";
        item.dataset.index = i;

        const isSelected = userAnswers[index] === i;
        if (isSelected && !reviewMode) item.classList.add("selected");

        if (reviewMode && review) {
            item.classList.add("locked");
            if (correctDisplayIndex !== null) {
                if (i === correctDisplayIndex) {
                    item.classList.add("correct");
                } else if (isSelected) {
                    item.classList.add("incorrect");
                }
            } else if (isSelected) {
                item.classList.add(review.is_correct ? "correct" : "incorrect");
            }
        } else {
            item.addEventListener("click", () => selectOption(index, i));
        }

        const letter = document.createElement("span");
        letter.className = "option-letter";
        letter.textContent = OPTION_LETTERS[i];
        const text = document.createElement("span");
        text.className = "option-text";
        text.textContent = optionText;
        item.append(letter, text);
        optionsList.appendChild(item);
    });

    document.getElementById("prevQBtn").disabled = index === 0;
    const isLast = index === QUIZ.questions.length - 1;
    document.getElementById("nextQBtn").style.display = isLast
        ? "none"
        : "inline-flex";
    document.getElementById("submitQuizBtn").style.display =
        isLast && !reviewMode ? "inline-flex" : "none";

    if (reviewMode) {
        document.getElementById("flagBtn").style.visibility = "hidden";
    } else {
        document.getElementById("flagBtn").style.visibility = "visible";
    }

    refreshNavigatorState();
}

function goToQuestion(index) {
    if (!QUIZ || index < 0 || index >= QUIZ.questions.length) return;
    renderQuestion(index);
}

function selectOption(questionIndex, optionIndex) {
    if (quizSubmitted) return;
    userAnswers[questionIndex] = optionIndex;
    renderQuestion(questionIndex);
}

function toggleFlag() {
    if (!QUIZ || reviewMode) return;
    flagged[currentIndex] = !flagged[currentIndex];
    renderQuestion(currentIndex);
    showToast(
        flagged[currentIndex] ? "Question marked for review" : "Flag removed",
    );
}

/*  SUBMIT FLOW  */
function openSubmitConfirm() {
    if (!QUIZ) return;
    const answeredCount = userAnswers.filter((a) => a !== null).length;
    document.getElementById("submitConfirmDesc").textContent =
        `You've answered ${answeredCount} of ${QUIZ.questions.length} questions. Once submitted, you won't be able to change your answers.`;
    openModal("submitConfirmModal");
}

function closeSubmitConfirm() {
    closeModal("submitConfirmModal");
}

async function finalizeSubmit() {
    closeSubmitConfirm();
    if (quizSubmitted || !QUIZ) return;
    quizSubmitted = true;
    clearInterval(timerInterval);

    const url = `/student/classes/${CLASS_ID}/posts/${POST_ID}/quiz/attempts`;
    const payload = {
        answers: QUIZ.questions.map((q, i) => ({
            question_id: q.id,
            selected_index: userAnswers[i] === null ? null : q.choiceIndexes[userAnswers[i]],
        })),
    };

    console.groupCollapsed(`[AI Quiz] POST ${url}`);
    console.log("payload:", payload);

    try {
        const res = await fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
            body: JSON.stringify(payload),
        });

        const data = await res.json().catch(() => null);
        console.log(`status: ${res.status}`);
        console.log("response body:", data);

        if (res.status === 403) {
            // Attempt limit reached (e.g. submitted from another tab).
            console.groupEnd();
            showToast(data?.message || "You've used all attempts allowed for this quiz.");
            return;
        }

        if (!res.ok) {
            throw new Error(data?.message || `submit failed (HTTP ${res.status})`);
        }

        console.groupEnd();

        reviewByQuestionId = {};
        (data.review || []).forEach((entry) => {
            reviewByQuestionId[entry.question_id] = entry;
        });

        currentAttemptId = data.attempt_id;
        showResults(data);
        if (data.status === "late") {
            showToast("Submitted after the time limit — your teacher will see it as late.");
        }
    } catch (e) {
        console.error("[AI Quiz] Submit threw:", e);
        console.groupEnd();
        quizSubmitted = false;
        showToast("We couldn't submit your quiz. Please try again.");
    }
}

function showResults(data) {
    const { correct, incorrect, skipped, total, percent } = data.score;

    document.getElementById("resultsScoreText").textContent = `${correct}/${total}`;
    document.getElementById("resultsPercentText").textContent = `${percent}%`;
    document.getElementById("resultsCorrectCount").textContent = correct;
    document.getElementById("resultsIncorrectCount").textContent = incorrect;
    document.getElementById("resultsSkippedCount").textContent = skipped;

    // Lesson mastery: average mastery across just this lesson's tested
    // competencies. Subject mastery: average across every competency
    // defined for the subject (all lessons combined), not only this one.
    const lessonMastery = data.lesson_mastery;
    if (lessonMastery && lessonMastery.before !== null && lessonMastery.after !== null) {
        renderMasteryComparison(
            "lessonMasteryBefore",
            "lessonMasteryAfter",
            "lessonMasteryDelta",
            lessonMastery.before * 100,
            lessonMastery.after * 100,
        );
    } else {
        hideMasteryRow("lessonMasteryBefore", "lessonMasteryAfter", "lessonMasteryDelta");
    }

    const subjectMastery = data.subject_mastery;
    if (subjectMastery && subjectMastery.before !== null && subjectMastery.after !== null) {
        renderMasteryComparison(
            "subjectMasteryBefore",
            "subjectMasteryAfter",
            "subjectMasteryDelta",
            subjectMastery.before * 100,
            subjectMastery.after * 100,
        );
    } else {
        hideMasteryRow("subjectMasteryBefore", "subjectMasteryAfter", "subjectMasteryDelta");
    }

    let title, desc, ringColor;
    if (percent >= 80) {
        title = "Excellent Work!";
        desc = "You've mastered this topic. Keep it up!";
        ringColor = "#4ade80";
    } else if (percent >= 60) {
        title = "Good Job!";
        desc = "Solid effort — review the missed items to level up.";
        ringColor = "#60a5fa";
    } else {
        title = "Quiz Complete";
        desc = "Take a look at the review to see where to focus next.";
        ringColor = "#f87171";
    }
    document.getElementById("resultsTitle").textContent = title;
    document.getElementById("resultsDesc").textContent =
        `${desc} ${QUIZ.title ? `Lesson: ${QUIZ.title}.` : ""}`;

    const circle = document.getElementById("resultsProgressCircle");
    circle.style.stroke = ringColor;

    resetFeedbackUI();
    openModal("resultsModal");

    requestAnimationFrame(() => {
        const offset = RESULTS_CIRCUMFERENCE * (1 - correct / total);
        setTimeout(() => {
            circle.style.strokeDashoffset = offset;
        }, 50);
    });
}

function renderMasteryComparison(beforeId, afterId, deltaId, before, after) {
    const beforeEl = document.getElementById(beforeId);
    const afterEl = document.getElementById(afterId);
    const deltaEl = document.getElementById(deltaId);
    if (!beforeEl || !afterEl || !deltaEl) {
        return;
    }

    const beforeRounded = Math.round(before);
    const afterRounded = Math.round(after);
    const delta = afterRounded - beforeRounded;
    const sign = delta >= 0 ? "+" : "";

    beforeEl.textContent = `${beforeRounded}%`;
    afterEl.textContent = `${afterRounded}%`;
    deltaEl.textContent = `${sign}${delta}%`;
    deltaEl.classList.remove("delta-positive", "delta-negative", "delta-neutral");
    deltaEl.classList.add(
        delta > 0 ? "delta-positive" : delta < 0 ? "delta-negative" : "delta-neutral",
    );
}

function hideMasteryRow(beforeId, afterId, deltaId) {
    const beforeEl = document.getElementById(beforeId);
    const afterEl = document.getElementById(afterId);
    const deltaEl = document.getElementById(deltaId);
    if (beforeEl) beforeEl.textContent = "—";
    if (afterEl) afterEl.textContent = "—";
    if (deltaEl) deltaEl.textContent = "";
}

/*  MODAL HELPERS  */
function openModal(id) {
    document.getElementById(id).classList.add("open");
}

function closeModal(id) {
    document.getElementById(id).classList.remove("open");
}

/* TOAST */
function showToast(message) {
    const toast = document.getElementById("toast");
    const toastMessage = document.getElementById("toastMessage");
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.add("visible");
    setTimeout(() => toast.classList.remove("visible"), 2500);
}
