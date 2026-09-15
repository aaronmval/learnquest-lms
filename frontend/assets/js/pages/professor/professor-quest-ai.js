/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    wireMyClassesDropdown();
    wirePptGenerator();
    wireChatPanel();

});


/* PAGE LOADER */
function hidePageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;

    setTimeout(() => {
        loader.classList.add('hidden');
    }, 800);
}


/* MY CLASSES SIDEBAR DROPDOWN*/
function wireMyClassesDropdown() {
    const toggleBtn = document.getElementById('myClassesToggleBtn');
    const container = document.getElementById('myClassesDropdownContainer');
    const chevron    = document.getElementById('myClassesChevron');
    const sidebar    = document.getElementById('sidebar');

    if (!toggleBtn || !container || !chevron) return;

    function openDropdown() {
        container.classList.add('open');
        chevron.classList.add('rotated');
        requestAnimationFrame(() => {
            container.style.maxHeight = container.scrollHeight + 'px';
        });
    }

    function closeDropdown() {
        container.style.maxHeight = '0px';
        container.classList.remove('open');
        chevron.classList.remove('rotated');
    }

    function toggleDropdown() {
        // Kung collapsed ang sidebar, i-expand muna
        if (sidebar && sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarState', 'expanded');
        }

        if (container.classList.contains('open')) {
            closeDropdown();
        } else {
            openDropdown();
        }
    }

    toggleBtn.addEventListener('click', toggleDropdown);

    // I-expand by default sa simula para makita agad ang Chemistry link
    requestAnimationFrame(() => openDropdown());
}


/* LEFT PANEL — PDF → PPT GENERATOR (mock flow) */
function wirePptGenerator() {
    const dropzone      = document.getElementById('pptDropzone');
    const fileInput      = document.getElementById('pptFileInput');
    const browseBtn      = document.getElementById('pptBrowseBtn');

    const fileChip        = document.getElementById('pptFileChip');
    const fileNameEl       = document.getElementById('pptFileName');
    const fileMetaEl        = document.getElementById('pptFileMeta');
    const fileRemoveBtn      = document.getElementById('pptFileRemoveBtn');

    const slideCountSelect    = document.getElementById('pptSlideCount');
    const toneSelect           = document.getElementById('pptTone');

    const generateBtn           = document.getElementById('pptGenerateBtn');

    const progressWrap           = document.getElementById('pptProgress');
    const progressBar              = document.getElementById('pptProgressBar');
    const progressLabel             = document.getElementById('pptProgressLabel');

    const resultWrap                = document.getElementById('pptResult');
    const resultTitle                 = document.getElementById('pptResultTitle');
    const resultMeta                   = document.getElementById('pptResultMeta');
    const downloadBtn                   = document.getElementById('pptDownloadBtn');
    const startOverBtn                   = document.getElementById('pptStartOverBtn');

    if (!dropzone || !fileInput || !generateBtn) return;

    let selectedFile = null;
    let lastGeneratedName = '';

    const TONE_LABELS = {
        lecture: 'Lecture deck',
        visual: 'Visual / diagram-heavy',
        review: 'Review & recap'
    };

    /* File selection  */
    function setSelectedFile(file) {
        if (!file) return;

        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            showToast('Please upload a PDF file.');
            return;
        }

        selectedFile = file;
        fileNameEl.textContent = file.name;
        fileMetaEl.textContent = formatFileSize(file.size);

        fileChip.classList.remove('hidden');
        dropzone.style.display = 'none';
        generateBtn.disabled = false;

        // I-reset ang dating result/progress kung mag-iiba ng file
        resetProgressAndResult();
    }

    function clearSelectedFile() {
        selectedFile = null;
        fileInput.value = '';
        fileChip.classList.add('hidden');
        dropzone.style.display = 'flex';
        generateBtn.disabled = true;
        resetProgressAndResult();
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    browseBtn.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            setSelectedFile(fileInput.files[0]);
        }
    });

    fileRemoveBtn.addEventListener('click', e => {
        e.stopPropagation();
        clearSelectedFile();
    });

    /* Drag & drop */
    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, e => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'dragend', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, e => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', e => {
        const dropped = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (dropped) setSelectedFile(dropped);
    });

    /*Generate (mock progress sequence) */
    const PROGRESS_STEPS = [
        { pct: 18,  label: 'Reading your PDF…' },
        { pct: 42,  label: 'Pulling out key concepts…' },
        { pct: 66,  label: 'Drafting slide outline…' },
        { pct: 86,  label: 'Designing slides…' },
        { pct: 100, label: 'Finishing touches…' }
    ];

    function resetProgressAndResult() {
        progressWrap.classList.add('hidden');
        resultWrap.classList.add('hidden');
        progressBar.style.width = '0%';
        generateBtn.classList.remove('hidden');
    }

    function runGenerateSequence() {
        if (!selectedFile) return;

        generateBtn.disabled = true;
        generateBtn.classList.add('hidden');
        resultWrap.classList.add('hidden');
        progressWrap.classList.remove('hidden');
        progressBar.style.width = '0%';

        let stepIndex = 0;

        function nextStep() {
            if (stepIndex >= PROGRESS_STEPS.length) {
                onGenerateComplete();
                return;
            }
            const step = PROGRESS_STEPS[stepIndex];
            progressBar.style.width = step.pct + '%';
            progressLabel.textContent = step.label;
            stepIndex++;
            setTimeout(nextStep, 550);
        }

        nextStep();
    }

    function onGenerateComplete() {
        progressWrap.classList.add('hidden');
        resultWrap.classList.remove('hidden');

        const slideCount = slideCountSelect.value;
        const toneLabel = TONE_LABELS[toneSelect.value] || 'Lecture deck';
        const baseName = selectedFile.name.replace(/\.pdf$/i, '');
        lastGeneratedName = baseName + '-slides.pptx';

        resultTitle.textContent = 'Your deck is ready';
        resultMeta.textContent = `~${slideCount} slides · ${toneLabel}`;

        showToast('Presentation generated!');
    }

    generateBtn.addEventListener('click', runGenerateSequence);

    /* Download (mock) pdf and ppt */
    downloadBtn.addEventListener('click', () => {
        const placeholderNote =
            'This is a placeholder file generated by the QuestAI Coach demo.\n' +
            'In the full version, this would be your generated .pptx presentation.';

        const blob = new Blob([placeholderNote], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = lastGeneratedName || 'presentation.pptx';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        showToast('Download started.');
    });

    /* ── Start Over ── */
    startOverBtn.addEventListener('click', () => {
        clearSelectedFile();
    });
}


/*  RIGHT PANEL — ASK QUESTAI */

/* Subject/class context state */
let activeSubject = 'All Classes';

const PERSONALIZATION_BY_SUBJECT = {
    'All Classes': {
        icon: 'fa-chart-line',
        html: '<strong>8 submissions</strong> are pending review in Chemistry. QuestAI can help you draft feedback faster.'
    },
    'Chemistry': {
        icon: 'fa-flask',
        html: 'Class average on <strong>stoichiometry</strong> questions is lower than usual this week. QuestAI can suggest a quick re-teach activity.'
    }
};

/* Simulated AI response pool */
const QUESTAI_RESPONSE_BANK = [
    {
        keywords: ['warm-up', 'warm up', 'activity', 'icebreaker'],
        reply: "Try a 5-minute 'predict and explain' warm-up: show a quick reaction (like mixing baking soda and vinegar) and ask students to predict what will happen and why, before revealing the result. It gets them reasoning before you teach the concept."
    },
    {
        keywords: ['feedback', 'quiz', 'grade', 'grading'],
        reply: "Here's a quick feedback template you can adapt: \"Good work on [strength] — your explanation of [concept] was clear. To strengthen this further, revisit [specific gap] and try [concrete next step].\" Want me to tailor it to a specific question?"
    },
    {
        keywords: ['explain', 'simple', 'simpler', 'concept'],
        reply: "Sure — tell me the topic and I'll break it down into a short, plain-language explanation plus a relatable analogy your students can hold onto."
    },
    {
        keywords: ['hello', 'hi', 'kumusta'],
        reply: "Hello, Ma'am Mila! What would you like help with today — lesson ideas, feedback drafting, or something about your class?"
    }
];

const QUESTAI_FALLBACK_REPLIES = [
    "Got it — could you tell me a bit more about what you need (e.g. the topic, the student, or the activity type)?",
    "I can help with that. Could you give me a little more detail so I can tailor my suggestion?",
    "Noted! To give you something useful, can you share more context — which class or lesson is this for?"
];

function getSimulatedReply(question) {
    const lower = question.toLowerCase();
    for (const entry of QUESTAI_RESPONSE_BANK) {
        if (entry.keywords.some(k => lower.includes(k))) {
            return entry.reply;
        }
    }
    return QUESTAI_FALLBACK_REPLIES[Math.floor(Math.random() * QUESTAI_FALLBACK_REPLIES.length)];
}

/* Subject chips */
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

    const data = PERSONALIZATION_BY_SUBJECT[subject] || PERSONALIZATION_BY_SUBJECT['All Classes'];

    const icon = banner.querySelector('.personalization-icon');
    if (icon) icon.className = `fas ${data.icon} personalization-icon`;

    text.innerHTML = data.html;
}

/* Chat rendering */
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

/* Session history */
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

/* Send / ask handler */
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

/* AI Insights panel */
const INSIGHT_POOL = [
    { label: 'Tip', text: 'Three students have pending submissions older than a week — consider a gentle reminder.' },
    { label: 'Class Insight', text: 'Average quiz scores dipped slightly on stoichiometry items this cycle.' },
    { label: 'Tip', text: 'Posting a short recap announcement after each module tends to boost completion rates.' },
    { label: 'Class Insight', text: 'Engagement is highest right after you post a new activity — good time to share materials.' },
    { label: 'Tip', text: 'A few students haven\'t opened this week\'s lesson material yet.' }
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

function wireChatPanel() {
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
}


/*  TOAST*/
function showToast(text) {
    const toast        = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = text;
    toast.classList.add('visible');

    setTimeout(() => {
        toast.classList.remove('visible');
    }, 2500);
}
