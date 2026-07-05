/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', () => {

    hidePageLoader();
    wireMyClassesDropdown();
    wirePptGenerator();
    wireChatPanel();
    wireCreateClassModal(); 

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


/* CREATE CLASS MODAL */
function wireCreateClassModal() {
    const createBtn  = document.getElementById('createClassBtn');
    const modal       = document.getElementById('createClassModal');
    const modalCard    = document.getElementById('createClassModalCard');
    const closeBtn     = document.getElementById('createClassCloseBtn');
    const cancelBtn     = document.getElementById('createClassCancelBtn');
    const confirmBtn    = document.getElementById('createClassConfirmBtn');

    const nameInput     = document.getElementById('ccClassName');
    const sectionInput  = document.getElementById('ccSection');
    const subjectInput  = document.getElementById('ccSubject');
    const roomInput     = document.getElementById('ccRoom');
    const nameError     = document.getElementById('ccNameError');

    if (!createBtn || !modal) return;

    function resetForm() {
        nameInput.value    = '';
        sectionInput.value = '';
        subjectInput.value = '';
        roomInput.value    = '';
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    }

    function openModal() {
        resetForm();
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.opacity = '1';
            modalCard.classList.add('scaled');
            nameInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.style.opacity = '0';
        modalCard.classList.remove('scaled');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    function confirmCreate() {
        const className   = nameInput.value.trim();
        const sectionVal   = sectionInput.value.trim();
        const subjectVal   = subjectInput.value.trim();
        const roomVal       = roomInput.value.trim();

        if (!className) {
            nameError.textContent = '*Required';
            nameError.classList.remove('hidden');
            nameInput.classList.add('error');
            nameInput.focus();
            return;
        }

        nameError.classList.add('hidden');
        nameInput.classList.remove('error');

        const newClass = createClassRecord(className, sectionVal, subjectVal, roomVal);
        professorClasses.push(newClass);
        addClassToUI(newClass);

        closeModal();
        showToast(`Class "${newClass.name}" created!`);
    }

    createBtn.addEventListener('click', openModal);
    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)  cancelBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', confirmCreate);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    [nameInput, sectionInput, subjectInput, roomInput].forEach(field => {
        field.addEventListener('keydown', e => {
            if (e.key === 'Enter') confirmCreate();
        });
    });

    // Clear error state habang nagtatype ang professor
    nameInput.addEventListener('input', () => {
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    });
}


/* Gumawa ng bagong class object base sa form input*/
function createClassRecord(name, section, subject, room) {
    const palette = CLASS_COLOR_PALETTE[nextPaletteIndex % CLASS_COLOR_PALETTE.length];
    nextPaletteIndex++;

    const id = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'class-' + Date.now();

    return {
        id,
        name,
        section: section || '',
        subject: subject || name,
        room: room || '',
        color: palette.color,
        gradient: palette.gradient,
        href: '../html/teachingChemistry.html', 
        students: 0,
        pending: 0
    };
}


/*  Idagdag ang bagong class sa "My Classes" grid card
   AT sa sidebar dropdown — parehong nag-uupdate
   nang sabay tuwing may na-create. */
function addClassToUI(cls) {
    addClassCard(cls);
    addClassSidebarLink(cls);
}

function addClassCard(cls) {
    const grid = document.getElementById('classGrid');
    if (!grid) return;

    const card = document.createElement('a');
    card.href = cls.href;
    card.className = 'class-card';
    card.style.setProperty('--card-color', cls.color);

    const initials = cls.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('') || 'CL';

    const metaParts = [];
    if (cls.subject) metaParts.push(cls.subject);
    if (cls.room) metaParts.push('Room ' + cls.room);

    card.innerHTML = `
        <div class="card-top" style="background:${cls.gradient}">
            <div class="card-subject">${escapeHtml(cls.name.toUpperCase())}</div>
            <div class="card-section">${escapeHtml(cls.section || metaParts.join(' · '))}</div>
            <div class="card-teacher-photo">
                <div class="card-teacher-initials" style="display:flex;">${escapeHtml(initials)}</div>
            </div>
        </div>
        <div class="card-bottom">
            <div class="card-teacher-info">
                <p class="card-teacher-name">${cls.students} Students</p>
                <p class="card-teacher-role">${cls.pending} Pending Submissions</p>
            </div>
            <span class="card-enter-btn">
                Manage Class <i class="fas fa-arrow-right"></i>
            </span>
        </div>
    `;

    grid.appendChild(card);
}

function addClassSidebarLink(cls) {
    const container = document.getElementById('myClassesDropdownContainer');
    if (!container) return;

    const link = document.createElement('a');
    link.href = cls.href;
    link.id = 'nav-' + cls.id;
    link.className = 'sidebar-link sub-link';
    link.dataset.parent = 'My Classes';
    link.dataset.child = cls.name;
    link.innerHTML = `
        <span class="dot" style="background-color:${cls.color}"></span>
        <span class="sidebar-text">${escapeHtml(cls.name)}</span>
    `;

    container.appendChild(link);

    // I-recalculate ang max-height ng dropdown para magkasya ang bagong link
    if (typeof window.__refreshMyClassesDropdownHeight === 'function') {
        window.__refreshMyClassesDropdownHeight();
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
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