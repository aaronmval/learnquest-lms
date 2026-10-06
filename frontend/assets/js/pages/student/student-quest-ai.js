/* QUESTAI COACH — chat answered by Llama via Laravel, grounded in the
   student's lesson materials and adapted to their BKT mastery. */

/* STATE */
let questaiClasses = [];
let activeClassId = null; // null = All Classes
let conversationId = null;
let isSending = false;
let greetingHtml = '';

const DOT_CLASSES = ['dot-cyan', 'dot-orange', 'dot-purple', 'dot-green'];

const esc = InsightsCard.escapeHtml;

/* HTTP */
function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function apiRequest(url, { method = 'GET', body = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== null) {
        headers['Content-Type'] = 'application/json';
        headers['X-XSRF-TOKEN'] = getCsrfToken();
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

/* CLASS CONTEXT (chips, banner, insights) */
async function loadContext() {
    try {
        const { ok, data } = await apiRequest('/student/questai/context');
        if (!ok) throw new Error('failed to load context');
        questaiClasses = data.classes || [];
    } catch (e) {
        questaiClasses = [];
    }

    renderClassChips();
    updatePersonalizationBanner();
}

function classLabel(cls) {
    return cls ? cls.subject || cls.name : 'All Classes';
}

function findClass(classId) {
    return questaiClasses.find((c) => c.id === classId) || null;
}

function renderClassChips() {
    const container = document.getElementById('subjectChips');
    if (!container) return;

    container.querySelectorAll('.subject-chip[data-class-id]:not([data-class-id=""])').forEach((chip) => chip.remove());

    questaiClasses.forEach((cls, i) => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'subject-chip';
        chip.dataset.classId = String(cls.id);
        chip.title = cls.section ? `${cls.name} · ${cls.section}` : cls.name;
        chip.innerHTML = `<span class="dot ${DOT_CLASSES[i % DOT_CLASSES.length]}"></span> ${esc(classLabel(cls))}`;
        container.appendChild(chip);
    });

    container.querySelectorAll('.subject-chip').forEach((chip) => {
        chip.addEventListener('click', () => {
            const id = chip.dataset.classId ? Number(chip.dataset.classId) : null;
            if (id !== activeClassId) setActiveClass(id);
        });
    });

    highlightActiveChip();
}

function highlightActiveChip() {
    document.querySelectorAll('.subject-chip').forEach((chip) => {
        const id = chip.dataset.classId ? Number(chip.dataset.classId) : null;
        chip.classList.toggle('active', id === activeClassId);
    });
}

/* Switching class starts a fresh chat scoped to that class. */
function setActiveClass(classId, { startNew = true, refreshInsights = true } = {}) {
    activeClassId = classId;
    highlightActiveChip();
    updatePersonalizationBanner();
    if (refreshInsights) loadSideInsights();
    if (startNew) startNewChat();
}

/* Banner reflects the real weakest BKT competency in the selected scope. */
function updatePersonalizationBanner() {
    const banner = document.getElementById('personalizationBanner');
    const text = document.getElementById('personalizationText');
    if (!banner || !text) return;

    const icon = banner.querySelector('.personalization-icon');
    const setIcon = (name) => {
        if (icon) icon.className = `fas ${name} personalization-icon`;
    };

    const scope = activeClassId ? [findClass(activeClassId)].filter(Boolean) : questaiClasses;

    if (!questaiClasses.length) {
        setIcon('fa-circle-info');
        text.innerHTML = 'Join a class to get coaching based on your lessons and quiz results.';
        return;
    }

    const weaknesses = scope.flatMap((cls) => (cls.weaknesses || []).map((w) => ({ ...w, cls })));
    weaknesses.sort((a, b) => a.mastery - b.mastery);

    if (weaknesses.length) {
        const w = weaknesses[0];
        setIcon('fa-chart-line');
        text.innerHTML =
            `Your quiz results show <strong>${esc(InsightsCard.levelLabel(w.level).toLowerCase())}</strong> mastery in ` +
            `<strong>${esc(w.name)}</strong> (${Math.round(w.mastery * 100)}%, ${esc(classLabel(w.cls))}). ` +
            'QuestAI will focus on it — try <strong>Give me a practice question</strong>.';
        return;
    }

    if (scope.some((cls) => cls.overall)) {
        setIcon('fa-trophy');
        text.innerHTML =
            'You\'re at <strong>high mastery</strong> in the competencies you\'ve been quizzed on. ' +
            'Ask QuestAI for a challenge question to go further.';
        return;
    }

    setIcon('fa-circle-info');
    text.innerHTML = 'Take a lesson quiz so QuestAI can personalize your coaching to your weak areas.';
}

/* AI INSIGHTS CARD */
let insightsLoading = false;

async function loadSideInsights(refresh = false) {
    const list = document.getElementById('insightsList');
    const btn = document.getElementById('insightsRefreshBtn');
    if (!list || insightsLoading) return;

    insightsLoading = true;
    if (btn) btn.disabled = true;

    if (activeClassId) {
        const ok = await InsightsCard.load(list, activeClassId, { refresh });
        if (ok && refresh) window.showToast?.('Insights refreshed');
    } else {
        if (refresh) {
            InsightsCard.renderLoading(list);
            await loadContext();
        }
        InsightsCard.renderClassOverview(list, questaiClasses);
    }

    insightsLoading = false;
    if (btn) btn.disabled = false;
}

/* CHAT RENDERING */

/* Safe mini-formatter for AI replies: escape everything first, then allow
   **bold**, "- " bullet lists and line breaks. */
function formatAiText(text) {
    const lines = esc(text).split(/\r?\n/);
    let html = '';
    let inList = false;

    lines.forEach((raw) => {
        const line = raw.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        const bullet = line.match(/^\s*[-*•]\s+(.*)$/);

        if (bullet) {
            if (!inList) {
                html += '<ul>';
                inList = true;
            }
            html += `<li>${bullet[1]}</li>`;
            return;
        }

        if (inList) {
            html += '</ul>';
            inList = false;
        }

        html += line.trim() === '' ? '<br>' : `<p>${line}</p>`;
    });

    if (inList) html += '</ul>';

    return html.replace(/(<br>)+$/, '');
}

function appendChatBubble(text, sender, { messageId = null, feedback = null, error = false } = {}) {
    const messages = document.getElementById('chatMessages');
    if (!messages) return null;

    const row = document.createElement('div');
    row.className = `chat-bubble-row ${sender === 'user' ? 'row-user' : 'row-ai'}`;

    const bubble = document.createElement('div');
    bubble.className = `chat-bubble ${sender === 'user' ? 'chat-bubble-user' : 'chat-bubble-ai'}`;

    if (sender === 'user') {
        bubble.textContent = text;
    } else if (error) {
        bubble.classList.add('chat-bubble-error');
        bubble.innerHTML = `<i class="fas fa-triangle-exclamation"></i> ${esc(text)}`;
    } else {
        bubble.innerHTML = formatAiText(text);
    }

    row.appendChild(bubble);

    if (sender === 'ai' && messageId) {
        row.appendChild(buildFeedbackRow(messageId, feedback));
    }

    messages.appendChild(row);

    scrollChatToBottom();
    return row;
}

function buildFeedbackRow(messageId, existingFeedback) {
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

    const markSelected = (selected) => {
        upBtn.disabled = true;
        downBtn.disabled = true;
        upBtn.classList.toggle('selected-up', selected === 'up');
        downBtn.classList.toggle('selected-down', selected === 'down');
    };

    const handleFeedback = async (selected) => {
        markSelected(selected);

        try {
            const { ok } = await apiRequest(`/student/questai/messages/${messageId}/feedback`, {
                method: 'POST',
                body: { helpful: selected === 'up' },
            });
            if (!ok) throw new Error('feedback failed');

            const thanks = document.createElement('span');
            thanks.className = 'chat-feedback-thanks';
            thanks.textContent = 'Thanks for the feedback!';
            feedback.appendChild(thanks);
        } catch (e) {
            upBtn.disabled = false;
            downBtn.disabled = false;
            upBtn.classList.remove('selected-up');
            downBtn.classList.remove('selected-down');
            window.showToast?.('Could not save your feedback. Please try again.');
        }
    };

    if (existingFeedback === 1) markSelected('up');
    else if (existingFeedback === -1) markSelected('down');

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

function resetChatMessages() {
    const messages = document.getElementById('chatMessages');
    if (messages) messages.innerHTML = greetingHtml;
}

function startNewChat() {
    conversationId = null;
    resetChatMessages();
    highlightActiveConversation();
}

/* SESSION HISTORY — saved conversations from the server */
async function loadConversations() {
    const list = document.getElementById('sessionHistoryList');
    if (!list) return;

    let conversations = [];
    try {
        const { ok, data } = await apiRequest('/student/questai/conversations');
        if (!ok) throw new Error('failed to load conversations');
        conversations = data || [];
    } catch (e) {
        list.innerHTML = '<p class="session-history-empty">Could not load your past chats.</p>';
        return;
    }

    if (!conversations.length) {
        list.innerHTML = '<p class="session-history-empty">Your recent questions will appear here.</p>';
        return;
    }

    list.innerHTML = conversations
        .map((c) => {
            const cls = c.class_id ? findClass(c.class_id) : null;
            const tag = c.class_id ? (cls ? classLabel(cls) : 'Class') : 'All Classes';
            return `
                <div class="session-history-item" role="button" tabindex="0" data-conversation-id="${c.id}">
                    <i class="fas fa-comment-dots"></i>
                    <div>
                        <span class="history-subject-tag">${esc(tag)}</span>
                        ${esc(c.title)}
                    </div>
                </div>`;
        })
        .join('');

    list.querySelectorAll('.session-history-item').forEach((item) => {
        const open = () => openConversation(Number(item.dataset.conversationId));
        item.addEventListener('click', open);
        item.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    });

    highlightActiveConversation();
}

function highlightActiveConversation() {
    document.querySelectorAll('.session-history-item[data-conversation-id]').forEach((item) => {
        item.classList.toggle('active', Number(item.dataset.conversationId) === conversationId);
    });
}

async function openConversation(id) {
    if (isSending || id === conversationId) return;

    try {
        const { ok, data } = await apiRequest(`/student/questai/conversations/${id}`);
        if (!ok) throw new Error('failed to load conversation');

        const classId = data.conversation.class_id;
        if (classId !== activeClassId) setActiveClass(classId, { startNew: false });

        conversationId = data.conversation.id;
        resetChatMessages();
        data.messages.forEach((m) => {
            if (m.role === 'user') appendChatBubble(m.content, 'user');
            else appendChatBubble(m.content, 'ai', { messageId: m.id, feedback: m.feedback });
        });
        highlightActiveConversation();
    } catch (e) {
        window.showToast?.('Could not open that chat. Please try again.');
    }
}

/* SEND / ASK HANDLER */
function setInputEnabled(enabled) {
    const input = document.getElementById('chatInput');
    const askBtn = document.getElementById('chatAskBtn');
    if (input) input.disabled = !enabled;
    if (askBtn) askBtn.disabled = !enabled;
    document.querySelectorAll('.quick-ask-chip').forEach((chip) => {
        chip.disabled = !enabled;
    });
    if (enabled && input) input.focus();
}

async function sendQuestion(question) {
    const input = document.getElementById('chatInput');
    if (!question || isSending) return;

    isSending = true;
    appendChatBubble(question, 'user');
    if (input) input.value = '';
    setInputEnabled(false);
    appendTypingIndicator();

    try {
        const { ok, status, data } = await apiRequest('/student/questai/messages', {
            method: 'POST',
            body: { message: question, class_id: activeClassId, conversation_id: conversationId },
        });

        removeTypingIndicator();

        // The question is saved even when the AI fails, so keep the thread.
        if (data?.conversation?.id) conversationId = data.conversation.id;

        if (ok) {
            appendChatBubble(data.reply.content, 'ai', { messageId: data.reply.id });
        } else {
            appendChatBubble(errorMessageFor(status, data), 'ai', { error: true });
        }
    } catch (e) {
        removeTypingIndicator();
        appendChatBubble('Could not reach QuestAI. Check your connection and try again.', 'ai', { error: true });
    } finally {
        isSending = false;
        setInputEnabled(true);
        loadConversations();
    }
}

function errorMessageFor(status, data) {
    if (status === 419) return 'Your session expired. Please reload the page and try again.';
    if (status === 429) return 'You\'re asking questions quickly — please wait a moment before trying again.';
    if (status === 422) {
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        return first || data?.message || 'Please check your question and try again.';
    }
    return data?.message || 'QuestAI couldn\'t answer that right now. Please try again.';
}

function handleChatSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    if (!input) return;

    const question = input.value.trim();
    if (!question) return;

    sendQuestion(question);
}

/* INIT */
document.addEventListener('DOMContentLoaded', async () => {
    greetingHtml = document.getElementById('chatMessages')?.innerHTML || '';

    document.getElementById('chatInputBar')?.addEventListener('submit', handleChatSubmit);
    document.getElementById('insightsRefreshBtn')?.addEventListener('click', () => loadSideInsights(true));
    document.getElementById('newChatBtn')?.addEventListener('click', startNewChat);

    document.querySelectorAll('.quick-ask-chip').forEach((chip) => {
        chip.addEventListener('click', () => sendQuestion(chip.dataset.prompt));
    });

    // Sequential on purpose: the local dev server handles one request at a
    // time, and chips/banner are what the student needs first.
    const contextLoaded = loadContext();
    // The first-visit tour waits for the class chips so it can point at them.
    window.LQPageTour?.init({ key: 'tour_seen_quest_ai', steps: questAiTourSteps, ready: contextLoaded });
    await contextLoaded;

    const requested = takeRequestedAction();
    if (requested) {
        // Chat first so the summary isn't queued behind the insights request.
        await runRequestedAction(requested);
    } else {
        await loadConversations();
    }
    loadSideInsights();
});

/* GUIDED TOUR — common/page-tour.js */
function questAiTourSteps() {
    return [
        {
            title: 'Meet QuestAI',
            body: 'QuestAI is your AI study assistant. It knows your classes and lessons, and adjusts its help to how well you know each topic.',
        },
        {
            target: '#subjectChips',
            title: 'Pick a class',
            body: 'Choose which class you want help with, so QuestAI answers from that class\'s lessons.',
        },
        {
            target: '#chatInputBar',
            title: 'Ask anything',
            body: 'Type a question about your lessons, like "Why does ice float?" or "Explain mole ratios step by step".',
        },
        {
            target: '#quickAskChips',
            title: 'Quick questions',
            body: 'One click to ask for a simple explanation, a practice question and more.',
        },
        {
            target: () => document.getElementById('insightsList')?.closest('.ai-insights-card'),
            title: 'AI insights for you',
            body: 'Tips based on your mastery: what you\'re doing well and what to practise next.',
        },
        {
            target: '.session-history-card',
            title: 'Your chats',
            body: 'Your recent conversations, so you can pick up where you left off. Start a fresh one any time.',
        },
        {
            title: 'That\'s QuestAI',
            body: 'QuestAI can make mistakes, so check important answers against your lessons. Replay this tour with the "Take the tour" button at the top.',
        },
    ];
}

/* HAND-OFF FROM OTHER PAGES — e.g. the class page's "Summarize class note"
   opens student-quest-ai.html?classId=5&action=summarize. Only whitelisted
   actions run; the URL never carries free-form prompt text. */
const REQUESTED_ACTIONS = {
    summarize: (cls) => `Summarize the class notes for ${classLabel(cls)}`,
};

function takeRequestedAction() {
    const params = new URLSearchParams(window.location.search);
    const action = params.get('action');
    const classId = Number(params.get('classId'));

    if (!action && !params.has('classId')) return null;

    // Consume the parameters so reloading the page doesn't resend the request.
    try {
        window.history.replaceState(null, '', window.location.pathname);
    } catch (e) {
        /* Non-critical. */
    }

    const cls = findClass(classId);
    if (!cls || !REQUESTED_ACTIONS[action]) return null;

    return { cls, prompt: REQUESTED_ACTIONS[action](cls) };
}

async function runRequestedAction({ cls, prompt }) {
    setActiveClass(cls.id, { refreshInsights: false });
    await sendQuestion(prompt);
}
