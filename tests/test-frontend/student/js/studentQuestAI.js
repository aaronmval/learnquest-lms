/* SUBJECT CONTEXT STATE*/
let activeSubject = 'All Subjects';

const PERSONALIZATION_BY_SUBJECT = {
    'All Subjects': {
        icon: 'fa-chart-line',
        html: 'Based on your <strong>Quiz 1 (Module 6)</strong> result, QuestAI recommends reviewing <strong>stoichiometry</strong> before your next assessment.',
    },
    'Chemistry': {
        icon: 'fa-flask',
        html: 'Your last Chemistry quiz shows lower mastery in <strong>stoichiometry</strong>. QuestAI will prioritize that topic in this session.',
    },
    'General Biology': {
        icon: 'fa-leaf',
        html: 'You haven\'t reviewed <strong>cell respiration</strong> in a while. QuestAI suggests starting there today.',
    },
    'Physics': {
        icon: 'fa-atom',
        html: 'Recent activity shows strong progress in <strong>kinematics</strong>. QuestAI will introduce slightly harder follow-up questions.',
    },
    'Earth Science': {
        icon: 'fa-globe',
        html: 'Your engagement with <strong>plate tectonics</strong> material was low this week. QuestAI recommends a quick refresher.',
    },
};

/*  SIMULATED AI RESPONSE POOL */
const QUESTAI_REPLIES = [
    "That's a great question! Based on your recent lessons, I'd suggest reviewing the related module before your next quiz.",
    "Here's a quick way to think about it: break the concept into smaller parts and connect each one to something you already know.",
    "I'd recommend checking the lesson PDF for this topic — it covers the key points you're asking about.",
    "Good thinking! Try working through a practice problem first, then I can help you check your reasoning.",
    "Let's break this down step by step. Could you tell me which part is the most confusing?",
    "That concept usually clicks better with a real-world example. Want me to give you one?",
];

const QUICK_ASK_REPLIES = {
    'Explain this topic simply': "Sure! Let's simplify it: think of it as a chain of small, related steps. Once you see how each step connects to the next, the whole topic becomes much easier to follow.",
    'Give me a practice question': "Here's a quick practice question for you: try identifying the key variables in your current topic, then explain in your own words how they relate to each other.",
    'Summarize my weak areas': "Based on your recent activity, your weaker areas seem to be in topics you haven't revisited recently. I'd suggest starting with your most recent lesson PDF.",
};

function getSimulatedReply(question) {
    if (QUICK_ASK_REPLIES[question]) return QUICK_ASK_REPLIES[question];
    const idx = Math.floor(Math.random() * QUESTAI_REPLIES.length);
    return QUESTAI_REPLIES[idx];
}

/* SUBJECT CHIPS */
function setActiveSubject(subject) {
    activeSubject = subject;

    document.querySelectorAll('.subject-chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.subject === subject);
    });

    updatePersonalizationBanner(subject);
}

function updatePersonalizationBanner(subject) {
    const banner = document.getElementById('personalizationBanner');
    const text = document.getElementById('personalizationText');
    if (!banner || !text) return;

    const data = PERSONALIZATION_BY_SUBJECT[subject] || PERSONALIZATION_BY_SUBJECT['All Subjects'];

    const icon = banner.querySelector('.personalization-icon');
    if (icon) icon.className = `fas ${data.icon} personalization-icon`;

    text.innerHTML = data.html;
}

/* CHAT RENDERING */
function appendChatBubble(text, sender) {
    const messages = document.getElementById('chatMessages');
    if (!messages) return null;

    const row = document.createElement('div');
    row.className = `chat-bubble-row ${sender === 'user' ? 'row-user' : 'row-ai'}`;

    const bubble = document.createElement('div');
    bubble.className = `chat-bubble ${sender === 'user' ? 'chat-bubble-user' : 'chat-bubble-ai'}`;
    bubble.textContent = text;
    row.appendChild(bubble);

    if (sender === 'ai') {
        row.appendChild(buildFeedbackRow());
    }

    messages.appendChild(row);

    scrollChatToBottom();
    return row;
}

function buildFeedbackRow() {
    const feedback = document.createElement('div');
    feedback.className = 'chat-feedback';
    feedback.innerHTML = `
        <span class="chat-feedback-label">Was this helpful?</span>
        <button type="button" class="chat-feedback-btn feedback-up" aria-label="Helpful">
            <i class="fas fa-thumbs-up"></i>
        </button>
        <button type="button" class="chat-feedback-btn feedback-down" aria-label="Not helpful">
            <i class="fas fa-thumbs-down"></i>
        </button>
    `;

    const upBtn = feedback.querySelector('.feedback-up');
    const downBtn = feedback.querySelector('.feedback-down');

    const handleFeedback = (selected) => {
        upBtn.disabled = true;
        downBtn.disabled = true;
        upBtn.classList.toggle('selected-up', selected === 'up');
        downBtn.classList.toggle('selected-down', selected === 'down');

        const thanks = document.createElement('span');
        thanks.className = 'chat-feedback-thanks';
        thanks.textContent = 'Thanks for the feedback!';
        feedback.appendChild(thanks);
    };

    upBtn.addEventListener('click', () => handleFeedback('up'));
    downBtn.addEventListener('click', () => handleFeedback('down'));

    return feedback;
}

function appendTypingIndicator() {
    const messages = document.getElementById('chatMessages');
    if (!messages) return null;

    const row = document.createElement('div');
    row.className = 'chat-bubble-row row-ai';
    row.id = 'questaiTypingRow';

    const typing = document.createElement('div');
    typing.className = 'chat-bubble chat-bubble-typing';
    typing.innerHTML = `
        <span class="chat-typing-dot"></span>
        <span class="chat-typing-dot"></span>
        <span class="chat-typing-dot"></span>
    `;
    row.appendChild(typing);
    messages.appendChild(row);

    scrollChatToBottom();
    return row;
}

function removeTypingIndicator() {
    const row = document.getElementById('questaiTypingRow');
    if (row) row.remove();
}

function scrollChatToBottom() {
    const chatWindow = document.getElementById('chatWindow');
    if (chatWindow) chatWindow.scrollTop = chatWindow.scrollHeight;
}

/* SESSION HISTORY*/
function logSessionHistory(question) {
    const list = document.getElementById('sessionHistoryList');
    if (!list) return;

    const empty = list.querySelector('.session-history-empty');
    if (empty) empty.remove();

    const item = document.createElement('div');
    item.className = 'session-history-item';
    item.innerHTML = `
        <i class="fas fa-comment-dots"></i>
        <div>
            <span class="history-subject-tag">${activeSubject}</span>
            ${question}
        </div>
    `;

    list.insertBefore(item, list.firstChild);

    /* Keep only the 5 most recent entries */
    const items = list.querySelectorAll('.session-history-item');
    if (items.length > 5) items[items.length - 1].remove();
}

/* SEND / ASK HANDLER */
function sendQuestion(question) {
    const input = document.getElementById('chatInput');
    const askBtn = document.getElementById('chatAskBtn');
    if (!question) return;

    appendChatBubble(question, 'user');
    logSessionHistory(question);

    if (input) input.value = '';
    if (input) input.disabled = true;
    if (askBtn) askBtn.disabled = true;

    appendTypingIndicator();

    setTimeout(() => {
        removeTypingIndicator();
        appendChatBubble(getSimulatedReply(question), 'ai');
        if (input) { input.disabled = false; input.focus(); }
        if (askBtn) askBtn.disabled = false;
    }, 900);
}

function handleChatSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    if (!input) return;

    const question = input.value.trim();
    if (!question) return;

    sendQuestion(question);
}

/* AI INSIGHTS (unchanged behavior)*/
const INSIGHT_POOL = [
    { label: 'Tip', text: 'Based on recent activity, completing your mini-game could improve mastery.' },
    { label: 'Study Insight', text: 'Try studying in short intervals (Pomodoro) for better focus.' },
    { label: 'Tip', text: 'Review last week\'s lesson PDF before the next quiz — it covers similar items.' },
    { label: 'Study Insight', text: 'Spacing out review sessions over multiple days improves retention.' },
    { label: 'Tip', text: 'You haven\'t opened this week\'s lesson material yet — give it a quick read.' },
];

let insightIndexes = [0, 1];

function renderInsights() {
    const list = document.getElementById('insightsList');
    if (!list) return;

    list.innerHTML = insightIndexes.map(i => {
        const insight = INSIGHT_POOL[i];
        return `<div class="insight-item"><strong>${insight.label}:</strong> ${insight.text}</div>`;
    }).join('');
}

function refreshInsights() {
    const a = Math.floor(Math.random() * INSIGHT_POOL.length);
    let b = Math.floor(Math.random() * INSIGHT_POOL.length);
    if (b === a) b = (b + 1) % INSIGHT_POOL.length;
    insightIndexes = [a, b];
    renderInsights();
    showToast('Insights refreshed');
}

/* INIT */
document.addEventListener('DOMContentLoaded', () => {
    renderInsights();
    updatePersonalizationBanner(activeSubject);

    const chatForm = document.getElementById('chatInputBar');
    if (chatForm) chatForm.addEventListener('submit', handleChatSubmit);

    const refreshBtn = document.getElementById('insightsRefreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', refreshInsights);

    document.querySelectorAll('.subject-chip').forEach(chip => {
        chip.addEventListener('click', () => setActiveSubject(chip.dataset.subject));
    });

    document.querySelectorAll('.quick-ask-chip').forEach(chip => {
        chip.addEventListener('click', () => sendQuestion(chip.dataset.prompt));
    });
});