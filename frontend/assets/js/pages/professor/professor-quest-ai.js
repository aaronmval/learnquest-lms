/* QUESTAI COACH (professor) — chat answered by Llama via Laravel, grounded in
   the professor's lesson materials and the class-level BKT mastery; plus a
   PDF → .pptx slide-deck generator (from an upload or an existing module). */

/* INIT — runs when page loads */
document.addEventListener('DOMContentLoaded', async () => {

    hidePageLoader();
    wireMyClassesDropdown();
    wirePptGenerator();
    wireChatPanel();

    // Sequential on purpose: the local dev server handles one request at a
    // time, and the chips/banner are what the professor needs first.
    // The first-visit tour waits for the subject chips so it can point at them.
    const loaded = loadContext();
    window.LQPageTour?.init({ key: 'tour_seen_quest_ai', steps: questAiTourSteps, ready: loaded });
    await loaded;
    await loadConversations();
    loadSideInsights();

});


/* GUIDED TOUR — common/page-tour.js */
function questAiTourSteps() {
    return [
        {
            title: 'Welcome to QuestAI Coach',
            body: 'QuestAI is your AI teaching assistant. On this page you can ask it about your classes, or have it draft a slide deck from a reading.',
        },
        {
            target: '#subjectChips',
            title: 'Pick a class',
            body: 'Choose which class QuestAI should focus on. It answers using that class\'s lessons and students\' progress.',
        },
        {
            target: '#chatInputBar',
            title: 'Ask a question',
            body: 'Type a question, such as which competencies your class is struggling with or how to explain a topic another way.',
        },
        {
            target: '#quickAskChips',
            title: 'Quick questions',
            body: 'Not sure what to ask? Click one of these to send a ready-made question.',
        },
        {
            target: '#qaiGeneratorPanel',
            title: 'Generate a presentation',
            body: 'Upload a PDF or pick one of your modules, and QuestAI drafts a slide deck you can download. "Targeted focus" gives a class\'s weakest competencies extra slides.',
        },
        {
            target: '.qai-side-tools',
            title: 'Insights and history',
            body: 'AI Insights suggests where to focus based on your classes\' results; Session History keeps your recent questions so you can pick up where you left off.',
        },
        {
            title: 'That\'s QuestAI Coach',
            body: 'QuestAI can make mistakes, so check its answers against your materials. Replay this tour any time with the "Take the tour" button at the top of the page.',
        },
    ];
}


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


/* HTTP */
function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/* `body` may be a plain object (sent as JSON) or FormData (multipart). */
async function apiRequest(url, { method = 'GET', body = null } = {}) {
    const headers = { Accept: 'application/json' };
    const isForm = body instanceof FormData;

    if (body !== null) {
        headers['X-XSRF-TOKEN'] = getCsrfToken();
        if (!isForm) headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers,
        body: body === null ? null : isForm ? body : JSON.stringify(body),
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
    if (status === 429) return 'You\'re sending requests quickly — please wait a moment and try again.';
    if (status === 413) return 'That file is too large to upload.';
    if (status === 422) {
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        return first || data?.message || fallback;
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


/* RIGHT PANEL — PDF / MODULE → PPT GENERATOR */
function wirePptGenerator() {
    const dropzone      = document.getElementById('pptDropzone');
    const fileInput      = document.getElementById('pptFileInput');
    const browseBtn      = document.getElementById('pptBrowseBtn');

    const fileChip        = document.getElementById('pptFileChip');
    const fileNameEl       = document.getElementById('pptFileName');
    const fileMetaEl        = document.getElementById('pptFileMeta');
    const fileRemoveBtn      = document.getElementById('pptFileRemoveBtn');

    const modulePicker   = document.getElementById('pptModulePicker');
    const subjectSelect  = document.getElementById('pptSubjectSelect');
    const moduleSelect   = document.getElementById('pptModuleSelect');

    const slideCountSelect    = document.getElementById('pptSlideCount');
    const themeSelect          = document.getElementById('pptTheme');
    const focusSelect          = document.getElementById('pptFocusClass');
    const focusHint            = document.getElementById('pptFocusHint');
    const focusNote            = document.getElementById('pptFocusNote');
    const focusNoteText        = document.getElementById('pptFocusNoteText');

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

    // Presentation defaults from Settings → General.
    try {
        const preferences = (window.parent.LQ_HOST_CONFIG || {}).preferences || {};
        if (preferences.deck_slide_count) slideCountSelect.value = String(preferences.deck_slide_count);
        if (preferences.deck_theme) themeSelect.value = preferences.deck_theme;
    } catch (e) {
        /* not inside the shell — keep the built-in defaults */
    }

    const MAX_BYTES = 25 * 1024 * 1024;

    let selectedFile = null;
    let selectedModule = null; // an existing module, used instead of an upload
    const modulesBySubject = new Map();
    let downloadUrl = null;
    let isGenerating = false;

    const THEME_LABELS = {
        learnquest: 'LearnQuest Blue',
        emerald: 'Emerald Green',
        charcoal: 'Classic Charcoal'
    };

    /* File selection  */
    function setSelectedFile(file) {
        if (!file || isGenerating) return;

        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            showToast('Please upload a PDF file.');
            return;
        }

        if (file.size > MAX_BYTES) {
            showToast('The PDF must be 25MB or smaller.');
            return;
        }

        selectedFile = file;
        selectedModule = null;
        showSourceChip(file.name, formatFileSize(file.size));
    }

    function setSelectedModule(module) {
        if (!module || isGenerating) return;

        selectedModule = module;
        selectedFile = null;
        fileInput.value = '';

        const size = module.file_size ? ' · ' + formatFileSize(module.file_size) : '';
        showSourceChip(module.file_name || module.title, 'Module: ' + module.title + size);
    }

    function showSourceChip(name, meta) {
        fileNameEl.textContent = name;
        fileMetaEl.textContent = meta;

        fileChip.classList.remove('hidden');
        dropzone.style.display = 'none';
        if (modulePicker) modulePicker.classList.add('hidden');
        generateBtn.disabled = false;

        // I-reset ang dating result/progress kung mag-iiba ng file
        resetProgressAndResult();
    }

    function clearSelectedFile() {
        selectedFile = null;
        selectedModule = null;
        downloadUrl = null;
        fileInput.value = '';
        if (moduleSelect) moduleSelect.value = '';
        fileChip.classList.add('hidden');
        dropzone.style.display = 'flex';
        if (modulePicker) modulePicker.classList.remove('hidden');
        generateBtn.disabled = true;
        resetProgressAndResult();
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    browseBtn.addEventListener('click', e => {
        e.stopPropagation();
        fileInput.click();
    });
    dropzone.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            setSelectedFile(fileInput.files[0]);
        }
    });

    fileRemoveBtn.addEventListener('click', e => {
        e.stopPropagation();
        if (!isGenerating) clearSelectedFile();
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

    /* Existing modules — the same subjects and modules as the Modules page */
    function setOptions(select, placeholder, items, labelOf) {
        select.innerHTML = '';
        select.appendChild(new Option(placeholder, ''));
        items.forEach(item => select.appendChild(new Option(labelOf(item), item.id)));
        select.disabled = items.length === 0;
    }

    async function loadSubjects() {
        try {
            const { ok, data } = await apiRequest('/professor/subjects');
            const subjects = ok && Array.isArray(data) ? data.filter(s => s.modules_count > 0) : [];

            setOptions(
                subjectSelect,
                !ok ? 'Could not load subjects' : subjects.length ? 'Select a subject' : 'No modules uploaded yet',
                subjects,
                s => s.name,
            );
        } catch (e) {
            setOptions(subjectSelect, 'Could not load subjects', [], null);
        }
    }

    async function loadModules(subjectId) {
        if (!subjectId) {
            setOptions(moduleSelect, 'Choose a subject first', [], null);
            return;
        }

        if (!modulesBySubject.has(subjectId)) {
            setOptions(moduleSelect, 'Loading modules…', [], null);
            try {
                const { ok, data } = await apiRequest(`/professor/subjects/${encodeURIComponent(subjectId)}/modules`);
                if (!ok || !Array.isArray(data)) throw new Error('modules request failed');
                modulesBySubject.set(subjectId, data);
            } catch (e) {
                setOptions(moduleSelect, 'Could not load modules', [], null);
                return;
            }
        }

        // The professor may have switched subject while this one loaded.
        if (subjectSelect.value !== subjectId) return;

        const modules = modulesBySubject.get(subjectId);
        setOptions(
            moduleSelect,
            modules.length ? 'Select a module' : 'No modules in this subject',
            modules,
            m => m.title,
        );
    }

    if (modulePicker && subjectSelect && moduleSelect) {
        subjectSelect.addEventListener('change', () => loadModules(subjectSelect.value));
        moduleSelect.addEventListener('change', () => {
            const modules = modulesBySubject.get(subjectSelect.value) || [];
            const module = modules.find(m => String(m.id) === moduleSelect.value);
            if (module) setSelectedModule(module);
        });
        loadSubjects();
    }

    /* Targeted focus — tells the professor up front what the chosen class's
       BKT stats would emphasise (the server decides again at generate time). */
    function updateFocusHint() {
        if (!focusSelect || !focusHint) return;

        const cls = findClass(Number(focusSelect.value));
        if (!cls) {
            focusHint.textContent = 'Pick a class to give its weakest competencies extra slides.';
        } else if (!cls.assessed) {
            focusHint.textContent = 'No quiz results for this class yet, so there is nothing to focus on.';
        } else if (!cls.weaknesses.length) {
            focusHint.textContent = 'This class shows high mastery in every assessed competency; no focus is needed.';
        } else {
            focusHint.textContent = 'Weakest: ' + cls.weaknesses
                .slice(0, 3)
                .map(w => `${w.name} (${w.mastery}%)`)
                .join(', ') + '. Slides on these get extra attention if the reading covers them.';
        }
    }

    if (focusSelect) focusSelect.addEventListener('change', updateFocusHint);

    /* Generate — the labels advance while the server works, then hold at
       the last step until the response arrives. */
    const PROGRESS_STEPS = [
        { pct: 12, label: () => selectedModule ? 'Opening your module…' : 'Uploading your PDF…' },
        { pct: 30, label: 'Reading your PDF…' },
        { pct: 52, label: 'Pulling out key concepts…' },
        { pct: 72, label: 'Drafting slide outline…' },
        { pct: 90, label: 'Designing slides…' }
    ];
    const STEP_MS = 4000;

    function resetProgressAndResult() {
        progressWrap.classList.add('hidden');
        resultWrap.classList.add('hidden');
        progressBar.style.width = '0%';
        generateBtn.classList.remove('hidden');
    }

    function startProgress() {
        let stepIndex = 0;

        const apply = () => {
            const step = PROGRESS_STEPS[stepIndex];
            progressBar.style.width = step.pct + '%';
            progressLabel.textContent = typeof step.label === 'function' ? step.label() : step.label;
        };

        apply();
        const timer = setInterval(() => {
            if (stepIndex < PROGRESS_STEPS.length - 1) {
                stepIndex++;
                apply();
            }
        }, STEP_MS);

        return () => clearInterval(timer);
    }

    async function generate() {
        if ((!selectedFile && !selectedModule) || isGenerating) return;

        isGenerating = true;
        generateBtn.disabled = true;
        generateBtn.classList.add('hidden');
        resultWrap.classList.add('hidden');
        progressWrap.classList.remove('hidden');
        slideCountSelect.disabled = true;
        themeSelect.disabled = true;
        if (focusSelect) focusSelect.disabled = true;

        const stopProgress = startProgress();

        const form = new FormData();
        if (selectedModule) {
            form.append('module_id', selectedModule.id);
        } else {
            form.append('file', selectedFile);
        }
        form.append('slide_count', slideCountSelect.value);
        form.append('theme', themeSelect.value);
        if (focusSelect && focusSelect.value) form.append('focus_class_id', focusSelect.value);

        try {
            const { ok, status, data } = await apiRequest('/professor/questai/decks', { method: 'POST', body: form });
            stopProgress();

            if (!ok) {
                resetProgressAndResult();
                generateBtn.disabled = false;
                showToast(errorMessageFor(status, data, 'Could not generate the presentation. Please try again.'));
                return;
            }

            progressBar.style.width = '100%';
            progressLabel.textContent = 'Finishing touches…';
            onGenerateComplete(data);
        } catch (e) {
            stopProgress();
            resetProgressAndResult();
            generateBtn.disabled = false;
            showToast('Could not reach QuestAI. Check your connection and try again.');
        } finally {
            isGenerating = false;
            slideCountSelect.disabled = false;
            themeSelect.disabled = false;
            if (focusSelect) focusSelect.disabled = false;
        }
    }

    function onGenerateComplete(data) {
        progressWrap.classList.add('hidden');
        resultWrap.classList.remove('hidden');

        const themeLabel = THEME_LABELS[data.theme] || 'LearnQuest Blue';
        downloadUrl = data.download_url;

        resultTitle.textContent = data.title || 'Your deck is ready';
        resultMeta.textContent = `${data.slide_count} slides + title · ${themeLabel}`;

        // Say plainly whether the targeted focus made it into the deck.
        const focus = data.focus;
        if (focusNote && focusNoteText) {
            focusNote.classList.toggle('hidden', !focus);
            focusNote.classList.toggle('qai-focus-note-applied', focus?.status === 'applied');
            focusNoteText.textContent = focus ? focus.message : '';
        }

        if (!focus) {
            showToast('Presentation generated!');
        } else if (focus.status === 'applied') {
            showToast('Presentation generated with targeted focus.');
        } else {
            showToast('Presentation generated. Targeted focus was not applied.');
        }
    }

    generateBtn.addEventListener('click', generate);

    /* Download the generated .pptx (served as an attachment) */
    downloadBtn.addEventListener('click', () => {
        if (!downloadUrl) return;

        const a = document.createElement('a');
        a.href = downloadUrl;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        showToast('Download started.');
    });

    /* ── Start Over ── */
    startOverBtn.addEventListener('click', () => {
        clearSelectedFile();
    });
}


/*  LEFT PANEL — ASK QUESTAI */

/* STATE */
let coachClasses = [];
let professorName = '';
let activeClassId = null; // null = All Classes
let conversationId = null;
let isSending = false;
let greetingHtml = '';

const DOT_CLASSES = ['dot-cyan', 'dot-orange', 'dot-purple', 'dot-green'];

function findClass(classId) {
    return coachClasses.find(c => c.id === classId) || null;
}

function classLabel(cls) {
    return cls ? cls.label : 'All Classes';
}

/* CLASS CONTEXT (greeting, chips, banner) */
async function loadContext() {
    try {
        const { ok, data } = await apiRequest('/professor/questai/context');
        if (!ok) throw new Error('failed to load context');
        coachClasses = data.classes || [];
        professorName = data.professor?.name || '';
    } catch (e) {
        coachClasses = [];
    }

    renderGreeting();
    renderClassChips();
    updatePersonalizationBanner();
    renderFocusClassOptions();
}

/* "Targeted focus" picker in the presentation generator: one option per class. */
function renderFocusClassOptions() {
    const select = document.getElementById('pptFocusClass');
    if (!select) return;

    coachClasses.forEach(cls => select.appendChild(new Option(classLabel(cls), cls.id)));
}

function renderGreeting() {
    const greeting = document.getElementById('chatGreeting');
    if (greeting && professorName) {
        greeting.textContent =
            `Hello ${professorName}! I'm your QuestAI Coach. Ask me about your lessons, ` +
            'your class\'s mastery, or teaching ideas.';
    }

    greetingHtml = document.getElementById('chatMessages')?.innerHTML || '';
}

function renderClassChips() {
    const container = document.getElementById('subjectChips');
    if (!container) return;

    container.querySelectorAll('.subject-chip:not([data-class-id=""])').forEach(chip => chip.remove());

    coachClasses.forEach((cls, i) => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'subject-chip';
        chip.dataset.classId = String(cls.id);
        chip.title = cls.label;
        chip.innerHTML = `<span class="dot ${DOT_CLASSES[i % DOT_CLASSES.length]}"></span> ${esc(cls.label)}`;
        container.appendChild(chip);
    });

    container.querySelectorAll('.subject-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            if (isSending) return;
            const id = chip.dataset.classId ? Number(chip.dataset.classId) : null;
            setActiveClass(id);
        });
    });

    highlightActiveChip();
}

function highlightActiveChip() {
    document.querySelectorAll('.subject-chip').forEach(chip => {
        const id = chip.dataset.classId ? Number(chip.dataset.classId) : null;
        chip.classList.toggle('active', id === activeClassId);
    });
}

/* Switching class starts a fresh chat scoped to that class. */
function setActiveClass(classId, { startNew = true } = {}) {
    const changed = classId !== activeClassId;

    activeClassId = classId;
    highlightActiveChip();
    updatePersonalizationBanner();
    if (changed) loadSideInsights();
    if (startNew) startNewChat();
}

/* Banner reflects real class BKT data in the selected scope. */
function updatePersonalizationBanner() {
    const banner = document.getElementById('personalizationBanner');
    const text = document.getElementById('personalizationText');
    if (!banner || !text) return;

    const icon = banner.querySelector('.personalization-icon');
    const setIcon = name => {
        if (icon) icon.className = `fas ${name} personalization-icon`;
    };

    if (!coachClasses.length) {
        setIcon('fa-circle-info');
        text.innerHTML = 'Create a class and post a lesson so QuestAI can coach you with your own materials and class data.';
        return;
    }

    const scope = activeClassId ? [findClass(activeClassId)].filter(Boolean) : coachClasses;

    const weaknesses = scope.flatMap(cls => (cls.weaknesses || []).map(w => ({ ...w, cls })));
    weaknesses.sort((a, b) => a.mastery - b.mastery);
    const low = scope.reduce((sum, cls) => sum + (cls.low || 0), 0);
    const assessed = scope.reduce((sum, cls) => sum + (cls.assessed || 0), 0);

    if (weaknesses.length) {
        const w = weaknesses[0];
        const lowText = low > 0
            ? ` <strong>${low} student${low === 1 ? ' is' : 's are'}</strong> at low overall mastery.`
            : '';
        setIcon('fa-chart-line');
        text.innerHTML =
            `Class mastery in <strong>${esc(w.name)}</strong> is ${Math.round(w.mastery)}% ` +
            `(${esc(classLabel(w.cls))}).${lowText} QuestAI can suggest a quick re-teach activity.`;
        return;
    }

    if (assessed > 0) {
        setIcon('fa-trophy');
        text.innerHTML =
            'Your class is at <strong>high mastery</strong> in every competency assessed so far. ' +
            'Ask QuestAI for enrichment or challenge activities.';
        return;
    }

    setIcon('fa-circle-info');
    text.innerHTML = 'No quiz data yet — once students take lesson quizzes, QuestAI will point out where your class needs support.';
}


/* AI INSIGHTS CARD — class analysis from BKT mastery (same source as the dashboard) */
const INSIGHT_LABELS = {
    strength: 'Strength',
    weakness: 'Needs attention',
    intervention: 'Intervention'
};

let insightsController = null;

async function loadSideInsights(refresh = false) {
    const list = document.getElementById('insightsList');
    const btn = document.getElementById('insightsRefreshBtn');
    if (!list) return;

    insightsController?.abort();
    insightsController = new AbortController();
    const { signal } = insightsController;

    if (btn) btn.disabled = true;
    list.innerHTML = '<div class="insight-item">Analyzing your class data…</div>';

    const params = new URLSearchParams();
    if (activeClassId) params.set('class_id', String(activeClassId));
    if (refresh) params.set('refresh', '1');

    try {
        const res = await fetch(`/professor/analytics/insights?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal,
        });
        const data = await res.json().catch(() => null);
        if (!res.ok) throw new Error(data?.message || 'failed');

        if (!data.insights?.length) {
            list.innerHTML = '<div class="insight-item">No quiz data yet. Insights will appear once students take lesson quizzes.</div>';
        } else {
            list.innerHTML = data.insights
                .map(insight => {
                    const label = INSIGHT_LABELS[insight.type] || 'Insight';
                    return `<div class="insight-item"><strong>${esc(label)}:</strong> ${esc(insight.text)}</div>`;
                })
                .join('');
        }

        if (refresh) showToast('Insights refreshed');
    } catch (e) {
        if (e.name === 'AbortError') return;
        list.innerHTML = '<div class="insight-item">Could not load insights right now. Try refreshing in a moment.</div>';
    } finally {
        if (!signal.aborted && btn) btn.disabled = false;
    }
}


/* CHAT RENDERING */

/* Safe mini-formatter for AI replies: escape everything first, then allow
   **bold**, "- " bullet lists and line breaks. */
function formatAiText(text) {
    const lines = esc(text).split(/\r?\n/);
    let html = '';
    let inList = false;

    lines.forEach(raw => {
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

    const markSelected = selected => {
        upBtn.disabled = true;
        downBtn.disabled = true;
        upBtn.classList.toggle('selected-up', selected === 'up');
        downBtn.classList.toggle('selected-down', selected === 'down');
    };

    const handleFeedback = async selected => {
        markSelected(selected);

        try {
            const { ok } = await apiRequest(`/professor/questai/messages/${messageId}/feedback`, {
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
            showToast('Could not save your feedback. Please try again.');
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
        const { ok, data } = await apiRequest('/professor/questai/conversations');
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
        .map(c => {
            const cls = c.class_id ? findClass(c.class_id) : null;
            const tag = c.class_id ? (cls ? cls.label : 'Class') : 'All Classes';
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

    list.querySelectorAll('.session-history-item').forEach(item => {
        const open = () => openConversation(Number(item.dataset.conversationId));
        item.addEventListener('click', open);
        item.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    });

    highlightActiveConversation();
}

function highlightActiveConversation() {
    document.querySelectorAll('.session-history-item[data-conversation-id]').forEach(item => {
        item.classList.toggle('active', Number(item.dataset.conversationId) === conversationId);
    });
}

async function openConversation(id) {
    if (isSending || id === conversationId) return;

    try {
        const { ok, data } = await apiRequest(`/professor/questai/conversations/${id}`);
        if (!ok) throw new Error('failed to load conversation');

        setActiveClass(data.conversation.class_id, { startNew: false });

        conversationId = data.conversation.id;
        resetChatMessages();
        data.messages.forEach(m => {
            if (m.role === 'user') appendChatBubble(m.content, 'user');
            else appendChatBubble(m.content, 'ai', { messageId: m.id, feedback: m.feedback });
        });
        highlightActiveConversation();
    } catch (e) {
        showToast('Could not open that chat. Please try again.');
    }
}


/* SEND / ASK HANDLER */
function setInputEnabled(enabled) {
    const input = document.getElementById('chatInput');
    const askBtn = document.getElementById('chatAskBtn');
    if (input) input.disabled = !enabled;
    if (askBtn) askBtn.disabled = !enabled;
    document.querySelectorAll('.quick-ask-chip').forEach(chip => {
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
        const { ok, status, data } = await apiRequest('/professor/questai/messages', {
            method: 'POST',
            body: { message: question, class_id: activeClassId, conversation_id: conversationId },
        });

        removeTypingIndicator();

        // The question is saved even when the AI fails, so keep the thread.
        if (data?.conversation?.id) conversationId = data.conversation.id;

        if (ok) {
            appendChatBubble(data.reply.content, 'ai', { messageId: data.reply.id });
        } else {
            appendChatBubble(
                errorMessageFor(status, data, 'QuestAI couldn\'t answer that right now. Please try again.'),
                'ai',
                { error: true }
            );
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

function handleChatSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    if (!input) return;

    const question = input.value.trim();
    if (!question) return;

    sendQuestion(question);
}

function wireChatPanel() {
    greetingHtml = document.getElementById('chatMessages')?.innerHTML || '';

    const chatForm = document.getElementById('chatInputBar');
    if (chatForm) chatForm.addEventListener('submit', handleChatSubmit);

    const refreshBtn = document.getElementById('insightsRefreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', () => loadSideInsights(true));

    document.querySelectorAll('.quick-ask-chip').forEach(chip => {
        chip.addEventListener('click', () => sendQuestion(chip.dataset.prompt));
    });
}


/*  TOAST — uses the shell's toast (this page runs inside its iframe),
    falling back to a local #toast element if one exists. */
function showToast(text) {
    try {
        if (window.parent !== window && typeof window.parent.showToast === 'function') {
            window.parent.showToast(text);
            return;
        }
    } catch (e) {
        /* Cross-origin parent — fall through to the local toast. */
    }

    const toast        = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    if (!toast || !toastMessage) return;

    toastMessage.textContent = text;
    toast.classList.add('visible');

    setTimeout(() => {
        toast.classList.remove('visible');
    }, 2500);
}
