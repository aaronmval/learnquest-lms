/* SUBJECT DATA  */
const SUBJECT_DATA = {
    'chemistry': {
        name: 'CHEMISTRY',
        teacher: 'Mrs. Mila Valiente',
        icon: 'fa-flask',
        gradient: ['#1e40af', '#3b82f6'],
        accent: '#2563eb',
        accentSoft: '#dbeafe',
        instructors: [
            { initials: 'MV', color: '#2563eb', name: 'Mrs. Mila Valiente' },
            { initials: 'JD', color: '#16a34a', name: 'Mr. Jerome Dizon' },
            { initials: 'AR', color: '#9333ea', name: 'Ms. Angela Reyes' }
        ]
    },
    'physics': {
        name: 'PHYSICS',
        teacher: 'Mrs. Santos',
        icon: 'fa-atom',
        gradient: ['#6d28d9', '#a855f7'],
        accent: '#7c3aed',
        accentSoft: '#f3e8ff',
        instructors: [
            { initials: 'MS', color: '#7c3aed', name: 'Mrs. Santos' }
        ]
    },
    'general-science': {
        name: 'GENERAL SCIENCE',
        teacher: 'Mrs. Mila Valiente',
        icon: 'fa-leaf',
        gradient: ['#15803d', '#22c55e'],
        accent: '#16a34a',
        accentSoft: '#dcfce7',
        instructors: [
            { initials: 'MV', color: '#16a34a', name: 'Mrs. Mila Valiente' }
        ]
    }
};

const SUBJECT_ACCENT_PALETTE = [
    { accent: '#2563eb', accentSoft: '#dbeafe', gradient: ['#1e40af', '#3b82f6'], icon: 'fa-flask' },
    { accent: '#7c3aed', accentSoft: '#f3e8ff', gradient: ['#6d28d9', '#a855f7'], icon: 'fa-atom' },
    { accent: '#16a34a', accentSoft: '#dcfce7', gradient: ['#15803d', '#22c55e'], icon: 'fa-leaf' },
    { accent: '#d97706', accentSoft: '#fef3c7', gradient: ['#b45309', '#f59e0b'], icon: 'fa-book' },
    { accent: '#db2777', accentSoft: '#fce7f3', gradient: ['#be185d', '#ec4899'], icon: 'fa-palette' },
    { accent: '#0891b2', accentSoft: '#cffafe', gradient: ['#0e7490', '#22d3ee'], icon: 'fa-globe' }
];

let SECTION_OPTIONS = ['STEM 1', 'STEM 4', 'STEM 5'];

const MODULES_BY_SUBJECT = {
    'chemistry': [
        {
            id: 'mod-1',
            title: 'Module 1 - Chemical Reactions & Bonding',
            type: 'pdf',
            sections: ['STEM 1'],
            uploadedLabel: 'Uploaded 2 days ago',
            fileSize: '4.2 MB',
            readingLabel: '15 mins',
            uploadedBy: 'Mrs. Mila Valiente',
            description: 'Introduces the main types of chemical reactions and the difference between ionic and covalent bonding, with guided examples for each.'
        },
        {
            id: 'mod-2',
            title: 'Module 2 - Periodic Table Trends',
            type: 'pdf',
            sections: ['STEM 1', 'STEM 4'],
            uploadedLabel: 'Uploaded 5 days ago',
            fileSize: '2.8 MB',
            readingLabel: '18 mins',
            uploadedBy: 'Mr. Jerome Dizon',
            description: 'Covers periodic trends such as atomic radius, electronegativity, and ionization energy, and how they shift across periods and groups.'
        }
    ],
    'physics': [
        {
            id: 'mod-p1',
            title: 'Module 1 - Kinematics & Motion',
            type: 'pdf',
            sections: ['STEM 1'],
            uploadedLabel: 'Uploaded 3 days ago',
            fileSize: '3.1 MB',
            readingLabel: '20 mins',
            uploadedBy: 'Mrs. Santos',
            description: 'A foundational look at motion, velocity, and acceleration through real-world examples and step-by-step problem solving.'
        }
    ],
    'general-science': [
        {
            id: 'mod-g1',
            title: 'Module 1 - Ecosystems & Biodiversity',
            type: 'pdf',
            sections: ['STEM 1'],
            uploadedLabel: 'Uploaded 1 day ago',
            fileSize: '2.4 MB',
            readingLabel: '12 mins',
            uploadedBy: 'Mrs. Mila Valiente',
            description: 'Explores how organisms interact within ecosystems and why biodiversity matters for long-term environmental stability.'
        }
    ]
};

let modules = MODULES_BY_SUBJECT['chemistry'];

let currentSubjectId = 'chemistry';


/* INIT */
document.addEventListener('DOMContentLoaded', () => {
    hidePageLoader();
    loadSubjectFromQuery();
    renderInstructors();
    wireUploadModuleModal();
    renderModules();
    wireBackBtn();
    wireCreateClassModal();
    wireInviteCollabModal();
    wireModulePreviewModal();
    document.addEventListener('click', closeAllSectionDropdowns);

    forceParentNavActive();
});


function getCurrentProfessorName() {
    const el = document.getElementById('headerProfileName');
    if (!el) return 'Unknown Professor';
    return el.textContent.replace(/^Welcome,\s*/i, '').replace(/!$/, '').trim() || 'Unknown Professor';
}


/* PAGE LOADER */
function hidePageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;
    setTimeout(() => loader.classList.add('hidden'), 800);
}


function pickPaletteFor(subjectId) {
    let hash = 0;
    for (let i = 0; i < subjectId.length; i++) {
        hash = (hash * 31 + subjectId.charCodeAt(i)) >>> 0;
    }
    return SUBJECT_ACCENT_PALETTE[hash % SUBJECT_ACCENT_PALETTE.length];
}

function initialsFromName(name) {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '??';
}

function ensureSubjectData(subjectId, params) {
    if (SUBJECT_DATA[subjectId]) return;

    const displayName = (params.get('name') || subjectId.replace(/-/g, ' ')).trim();
    const teacherName = (params.get('teacher') || 'Unassigned').trim();
    const palette = pickPaletteFor(subjectId);

    SUBJECT_DATA[subjectId] = {
        name: displayName.toUpperCase(),
        teacher: teacherName,
        icon: palette.icon,
        gradient: palette.gradient,
        accent: palette.accent,
        accentSoft: palette.accentSoft,
        instructors: [
            { initials: initialsFromName(teacherName), color: palette.accent, name: teacherName }
        ]
    };
}


function loadSubjectFromQuery() {
    const params = new URLSearchParams(window.location.search);
    const subjectId = params.get('subject');

    if (subjectId) {
        ensureSubjectData(subjectId, params);
    }

    currentSubjectId = (subjectId && SUBJECT_DATA[subjectId]) ? subjectId : 'chemistry';

    if (!MODULES_BY_SUBJECT[currentSubjectId]) MODULES_BY_SUBJECT[currentSubjectId] = [];
    modules = MODULES_BY_SUBJECT[currentSubjectId];

    const data = SUBJECT_DATA[currentSubjectId];

    const breadcrumbSubject = document.getElementById('breadcrumbSubject');
    const heroSubjectName   = document.getElementById('heroSubjectName');
    const heroTeacherName   = document.getElementById('heroTeacherName');
    const heroBanner        = document.querySelector('.hero-banner');
    const heroIllustration  = document.querySelector('.hero-illustration');

    const titleCaseName = data.name.charAt(0) + data.name.slice(1).toLowerCase();

    if (breadcrumbSubject) breadcrumbSubject.textContent = titleCaseName;
    if (heroSubjectName)   heroSubjectName.textContent = data.name;
    if (heroTeacherName)   heroTeacherName.textContent = data.teacher;

    document.title = `LearnQuest | Upload Module Page - ${titleCaseName}`;

    document.documentElement.style.setProperty('--subject-accent', data.accent);
    document.documentElement.style.setProperty('--subject-accent-soft', data.accentSoft);

    if (heroBanner) {
        heroBanner.style.background = `linear-gradient(135deg, ${data.gradient[0]}, ${data.gradient[1]})`;
    }
    if (heroIllustration) {
        heroIllustration.innerHTML = `<i class="fas ${data.icon}"></i>`;
    }
}


function renderInstructors() {
    const stack = document.getElementById('instructorsStack');
    if (!stack) return;
    const data = SUBJECT_DATA[currentSubjectId];

    stack.innerHTML = data.instructors.map(inst => `
        <div class="mod-instructor-avatar"
             style="background-color:${inst.color};"
             data-tooltip="${escapeHtml(inst.name)}"
             title="${escapeHtml(inst.name)}">${inst.initials}</div>
    `).join('');
}


function wireBackBtn() {
    const backBtn = document.getElementById('backBtn');
    if (!backBtn) return;
    backBtn.addEventListener('click', () => {
        window.location.href = '../html/professorMaterials.html';
    });
}


/*  MODULE LIST RENDERING */
function renderModules() {
    const list = document.getElementById('moduleList');
    const emptyState = document.getElementById('moduleEmptyState');
    const countBadge = document.getElementById('moduleCountBadge');
    if (!list) return;

    list.innerHTML = '';

    if (modules.length === 0) {
        if (emptyState) emptyState.classList.remove('hidden');
    } else {
        if (emptyState) emptyState.classList.add('hidden');
    }

    modules.forEach((mod, index) => {
        const card = document.createElement('div');
        card.className = 'mod-card';
        card.dataset.moduleId = mod.id;
        card.style.animationDelay = `${index * 60}ms`;

        const sectionLabel = mod.sections.length ? mod.sections.join(', ') : 'None assigned';
        const metaBits = [mod.uploadedLabel, mod.fileSize].filter(Boolean);
        const uploaderName = mod.uploadedBy || 'Unknown';

        card.innerHTML = `
            <div class="mod-card-icon"><i class="fas fa-file-${mod.type === 'pdf' ? 'pdf' : 'lines'}"></i></div>
            <div class="mod-card-info">
                <span class="mod-card-title">${escapeHtml(mod.title)}</span>
                <span class="mod-card-sections">Target Access Sections: <strong class="mod-sections-label">${escapeHtml(sectionLabel)}</strong></span>
                ${mod.description ? `<span class="mod-card-description">${escapeHtml(mod.description)}</span>` : ''}
                <span class="mod-card-meta">
                    <i class="fas fa-user-pen"></i> Uploaded by <strong>${escapeHtml(uploaderName)}</strong>
                    ${metaBits.length ? `&nbsp;&middot;&nbsp; ${metaBits.map(escapeHtml).join(' &nbsp;&middot;&nbsp; ')}` : ''}
                </span>
            </div>
            <div class="mod-card-actions">
                <button class="mod-edit-btn"><i class="fas fa-pen"></i> Edit</button>
                <button class="mod-preview-btn"><i class="fas fa-eye"></i> Preview</button>
                <button class="mod-section-btn"><i class="fas fa-list"></i> Section</button>
                <div class="mod-section-dropdown">
                    <div class="mod-section-dropdown-title">Target STEM Sections Access</div>
                    ${SECTION_OPTIONS.map(sec => `
                        <label class="mod-section-option ${mod.sections.includes(sec) ? 'checked' : ''}" data-section="${sec}">
                            <input type="checkbox" ${mod.sections.includes(sec) ? 'checked' : ''} />
                            <span>${sec}</span>
                            <i class="fas fa-check mod-check-mark"></i>
                        </label>
                    `).join('')}
                </div>
            </div>
        `;

        list.appendChild(card);

        card.addEventListener('animationend', () => {
            card.style.animation = 'none';
        }, { once: true });

        card.querySelector('.mod-preview-btn').addEventListener('click', () => {
            openModulePreview(mod);
        });

        card.querySelector('.mod-edit-btn').addEventListener('click', () => {
            if (typeof window.openModuleEditModal === 'function') {
                window.openModuleEditModal(mod);
            }
        });

        const sectionBtn = card.querySelector('.mod-section-btn');
        const dropdown = card.querySelector('.mod-section-dropdown');

        sectionBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = dropdown.classList.contains('visible');
            closeAllSectionDropdowns();
            if (!isOpen) {
                dropdown.classList.add('visible');
                sectionBtn.classList.add('open');
                card.classList.add('dropdown-open');
            }
        });

        dropdown.addEventListener('click', (e) => e.stopPropagation());

        dropdown.querySelectorAll('.mod-section-option').forEach(option => {
            option.addEventListener('click', () => {
                const sectionName = option.dataset.section;
                const checkbox = option.querySelector('input[type="checkbox"]');
                const idx = mod.sections.indexOf(sectionName);

                if (idx === -1) {
                    mod.sections.push(sectionName);
                    checkbox.checked = true;
                    option.classList.add('checked');
                } else {
                    mod.sections.splice(idx, 1);
                    checkbox.checked = false;
                    option.classList.remove('checked');
                }

                const label = card.querySelector('.mod-sections-label');
                label.textContent = mod.sections.length ? mod.sections.join(', ') : 'None assigned';
            });
        });
    });

    if (countBadge) {
        countBadge.textContent = `${modules.length} Module${modules.length === 1 ? '' : 's'} Available`;
    }
}

function closeAllSectionDropdowns() {
    document.querySelectorAll('.mod-section-dropdown.visible').forEach(dd => dd.classList.remove('visible'));
    document.querySelectorAll('.mod-section-btn.open').forEach(btn => btn.classList.remove('open'));
    document.querySelectorAll('.mod-card.dropdown-open').forEach(card => card.classList.remove('dropdown-open'));
}


/* 
   UPLOAD / EDIT MODULE MODAL */
function wireUploadModuleModal() {
    const openBtn    = document.getElementById('uploadModulesBtn');
    const modal      = document.getElementById('uploadModuleModal');
    const modalCard  = document.getElementById('uploadModuleModalCard');
    const closeBtn   = document.getElementById('uploadModuleCloseBtn');
    const cancelBtn  = document.getElementById('uploadModuleCancelBtn');
    const confirmBtn = document.getElementById('uploadModuleConfirmBtn');
    const modalTitleEl = document.getElementById('umModalTitle');
    const ucModal          = document.getElementById('uploadConfirmModal');
    const ucModalCard      = document.getElementById('uploadConfirmModalCard');
    const ucCloseBtn       = document.getElementById('uploadConfirmCloseBtn');
    const ucCancelBtn      = document.getElementById('uploadConfirmCancelBtn');
    const ucYesBtn         = document.getElementById('uploadConfirmYesBtn');
    const ucTitleEl        = document.getElementById('ucConfirmTitle');
    const ucModuleTitleEl  = document.getElementById('ucConfirmModuleTitle');
    const ucSectionsEl     = document.getElementById('ucConfirmSections');

    const titleInput = document.getElementById('umModuleTitle');
    const descriptionInput = document.getElementById('umModuleDescription');
    const descriptionCounter = document.getElementById('umDescriptionCounter');
    const fileInput  = document.getElementById('umFileName');
    const dropzone   = document.getElementById('umDropzone');
    const fileNameLabel = document.getElementById('umFileNameLabel');
    const titleError = document.getElementById('umTitleError');
    const checksWrap = document.getElementById('umSectionChecks');
    const DESCRIPTION_MAX_LENGTH = 500;

    function updateDescriptionCounter() {
        if (!descriptionInput || !descriptionCounter) return;
        const len = descriptionInput.value.length;
        descriptionCounter.textContent = `${len} / ${DESCRIPTION_MAX_LENGTH}`;
        descriptionCounter.classList.toggle('near-limit', len >= DESCRIPTION_MAX_LENGTH * 0.9 && len < DESCRIPTION_MAX_LENGTH);
        descriptionCounter.classList.toggle('at-limit', len >= DESCRIPTION_MAX_LENGTH);
    }

    if (descriptionInput) {
        descriptionInput.addEventListener('input', updateDescriptionCounter);
    }

    const newSectionInput = document.getElementById('umNewSectionInput');
    const addSectionBtn   = document.getElementById('umAddSectionBtn');
    const addSectionError = document.getElementById('umAddSectionError');

    if (!openBtn || !modal) return;

    let checkedInModal = new Set([SECTION_OPTIONS[0]]);
    let editingModuleId = null;

    function buildSectionChecks(justAddedSection) {
        checksWrap.innerHTML = SECTION_OPTIONS.map(sec => `
            <label class="um-section-chip ${sec === justAddedSection ? 'um-section-chip-new' : ''}">
                <input type="checkbox" value="${sec}" ${checkedInModal.has(sec) ? 'checked' : ''} />
                ${escapeHtml(sec)}
            </label>
        `).join('');

        checksWrap.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', () => {
                if (cb.checked) checkedInModal.add(cb.value);
                else checkedInModal.delete(cb.value);
            });
        });
    }

    function resetForm() {
        editingModuleId = null;
        titleInput.value = '';
        if (descriptionInput) descriptionInput.value = '';
        updateDescriptionCounter();
        fileInput.value = '';
        if (fileNameLabel) fileNameLabel.textContent = '';
        if (dropzone) dropzone.classList.remove('dragover');
        titleError.classList.add('hidden');
        titleInput.classList.remove('error');
        addSectionError.classList.add('hidden');
        newSectionInput.value = '';
        checkedInModal = new Set([SECTION_OPTIONS[0]]);
        buildSectionChecks();
        if (modalTitleEl) modalTitleEl.textContent = 'Upload Module';
        if (confirmBtn) confirmBtn.textContent = 'Upload';

        if (ucModal) {
            ucModal.classList.remove('visible');
            ucModal.style.display = 'none';
        }
        if (ucModalCard) ucModalCard.classList.remove('scaled');
    }

    function openModal() {
        resetForm();
        modal.style.display = 'flex';
        modal.classList.add('visible');
        setTimeout(() => {
            modalCard.classList.add('scaled');
            titleInput.focus();
        }, 10);
    }

    function openModalForEdit(mod) {
        resetForm();
        editingModuleId = mod.id;

        titleInput.value = mod.title || '';
        if (descriptionInput) descriptionInput.value = mod.description || '';
        updateDescriptionCounter();

        checkedInModal = new Set(mod.sections || []);
        buildSectionChecks();

        if (fileNameLabel) {
            fileNameLabel.textContent = mod.fileName
                ? `Current file: ${mod.fileName} (choose a new file only if you want to replace it)`
                : 'No file uploaded yet — choose one if you want to add it now';
        }

        if (modalTitleEl) modalTitleEl.textContent = 'Edit Module';
        if (confirmBtn) confirmBtn.textContent = 'Save Changes';

        modal.style.display = 'flex';
        modal.classList.add('visible');
        setTimeout(() => {
            modalCard.classList.add('scaled');
            titleInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.classList.remove('visible');
        modalCard.classList.remove('scaled');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    function addNewSection() {
        const name = newSectionInput.value.trim();
        addSectionError.classList.add('hidden');

        if (!name) {
            newSectionInput.focus();
            return;
        }

        const alreadyExists = SECTION_OPTIONS.some(sec => sec.toLowerCase() === name.toLowerCase());
        if (alreadyExists) {
            addSectionError.textContent = '*That section already exists';
            addSectionError.classList.remove('hidden');
            newSectionInput.focus();
            return;
        }

        SECTION_OPTIONS.push(name);
        checkedInModal.add(name);
        buildSectionChecks(name);
        renderModules();

        newSectionInput.value = '';
        newSectionInput.focus();
        showToast(`Section "${name}" added!`);
    }

    function openConfirmModal() {
        const title = titleInput.value.trim();
        const checkedSections = Array.from(checksWrap.querySelectorAll('input[type="checkbox"]:checked'))
            .map(cb => cb.value);

        if (ucTitleEl)       ucTitleEl.textContent = editingModuleId ? 'Confirm Changes' : 'Confirm Upload';
        if (ucModuleTitleEl) ucModuleTitleEl.textContent = title;
        if (ucSectionsEl)    ucSectionsEl.textContent = checkedSections.length ? checkedSections.join(', ') : 'No section selected';
        if (ucYesBtn)        ucYesBtn.textContent = editingModuleId ? 'Yes, Save Changes' : 'Yes, Upload';

        if (!ucModal || !ucModalCard) return;
        ucModal.style.display = 'flex';
        ucModal.classList.add('visible');
        setTimeout(() => ucModalCard.classList.add('scaled'), 10);
    }

    function closeConfirmModal() {
        if (!ucModal || !ucModalCard) return;
        ucModal.classList.remove('visible');
        ucModalCard.classList.remove('scaled');
        setTimeout(() => { ucModal.style.display = 'none'; }, 300);
    }
    function requestConfirmation() {
        const title = titleInput.value.trim();

        if (!title) {
            titleError.classList.remove('hidden');
            titleInput.classList.add('error');
            titleInput.focus();
            return;
        }

        titleError.classList.add('hidden');
        titleInput.classList.remove('error');

        openConfirmModal();
    }

    function finalizeUpload() {
        const title = titleInput.value.trim();
        const description = descriptionInput ? descriptionInput.value.trim() : '';

        const checkedSections = Array.from(checksWrap.querySelectorAll('input[type="checkbox"]:checked'))
            .map(cb => cb.value);

        const file = fileInput.files && fileInput.files[0];

        if (editingModuleId) {
            // EDIT MODE
            const existing = modules.find(m => m.id === editingModuleId);
            if (!existing) { closeConfirmModal(); closeModal(); return; }

            existing.title = title;
            existing.description = description || null;
            existing.sections = checkedSections;

            if (file) {
                existing.fileURL  = URL.createObjectURL(file);
                existing.fileName = file.name;
                existing.fileMime = file.type;
                existing.fileSize = formatFileSize(file.size);
            }

            renderModules();
            closeConfirmModal();
            closeModal();
            showToast(`Module "${title}" updated!`);
            return;
        }

        // CREATE MODE
        const fileURL  = file ? URL.createObjectURL(file) : null;
        const fileName = file ? file.name : null;
        const fileMime = file ? file.type : null;

        modules.push({
            id: 'mod-' + Date.now(),
            title,
            description: description || null,
            type: 'pdf',
            sections: checkedSections,
            uploadedLabel: 'Uploaded just now',
            uploadedBy: getCurrentProfessorName(),
            fileSize: file ? formatFileSize(file.size) : null,
            readingLabel: 'Not yet estimated',
            fileURL,
            fileName,
            fileMime
        });

        renderModules();
        closeConfirmModal();
        closeModal();
        showToast(`Module "${title}" uploaded!`);
    }

    openBtn.addEventListener('click', openModal);
    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)  cancelBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', requestConfirmation);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    titleInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') requestConfirmation();
    });

    if (ucCloseBtn)  ucCloseBtn.addEventListener('click', closeConfirmModal);
    if (ucCancelBtn) ucCancelBtn.addEventListener('click', closeConfirmModal);
    if (ucYesBtn)    ucYesBtn.addEventListener('click', finalizeUpload);
    if (ucModal) {
        ucModal.addEventListener('click', e => {
            if (e.target === ucModal) closeConfirmModal();
        });
    }

    titleInput.addEventListener('input', () => {
        titleError.classList.add('hidden');
        titleInput.classList.remove('error');
    });

    if (dropzone && fileInput) {
        fileInput.addEventListener('change', () => {
            const file = fileInput.files && fileInput.files[0];
            if (fileNameLabel) fileNameLabel.textContent = file ? file.name : '';
        });

        ['dragenter', 'dragover'].forEach(evt => {
            dropzone.addEventListener(evt, e => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(evt => {
            dropzone.addEventListener(evt, e => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
            });
        });

        dropzone.addEventListener('drop', e => {
            const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) {
                fileInput.files = e.dataTransfer.files;
                if (fileNameLabel) fileNameLabel.textContent = file.name;
            }
        });
    }

    if (addSectionBtn) addSectionBtn.addEventListener('click', addNewSection);
    if (newSectionInput) {
        newSectionInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addNewSection();
            }
        });
        newSectionInput.addEventListener('input', () => {
            addSectionError.classList.add('hidden');
        });
    }
    window.openModuleEditModal = openModalForEdit;
}


/*CREATE CLASS MODAL*/
function wireCreateClassModal() {
    const openBtn   = document.getElementById('createClassBtn');
    const modal     = document.getElementById('createClassModal');
    const modalCard = document.getElementById('createClassModalCard');
    const closeBtn  = document.getElementById('createClassCloseBtn');
    const cancelBtn = document.getElementById('createClassCancelBtn');
    const confirmBtn = document.getElementById('createClassConfirmBtn');

    const nameInput    = document.getElementById('ccClassName');
    const sectionInput = document.getElementById('ccSection');
    const subjectInput = document.getElementById('ccSubject');
    const roomInput    = document.getElementById('ccRoom');
    const nameError     = document.getElementById('ccNameError');

    if (!openBtn || !modal) return;

    function resetForm() {
        nameInput.value = '';
        sectionInput.value = '';
        subjectInput.value = '';
        roomInput.value = '';
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    }

    function openModal() {
        resetForm();
        modal.style.display = 'flex';
        modal.classList.add('visible');
        setTimeout(() => {
            modalCard.classList.add('scaled');
            nameInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.classList.remove('visible');
        modalCard.classList.remove('scaled');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    function confirmCreate() {
        const className = nameInput.value.trim();

        if (!className) {
            nameError.textContent = '*Required';
            nameError.classList.remove('hidden');
            nameInput.classList.add('error');
            nameInput.focus();
            return;
        }

        nameError.classList.add('hidden');
        nameInput.classList.remove('error');

        closeModal();
        showToast(`Class "${className}" created!`);
    }

    openBtn.addEventListener('click', openModal);
    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)  cancelBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', confirmCreate);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    nameInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') confirmCreate();
    });

    nameInput.addEventListener('input', () => {
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    });
}


/* INVITE COLLABORATOR MODAL */
function wireInviteCollabModal() {
    const openBtn   = document.getElementById('collabBtn');
    const modal     = document.getElementById('inviteCollabModal');
    const modalCard = document.getElementById('inviteCollabModalCard');
    const closeBtn  = document.getElementById('inviteCollabCloseBtn');
    const cancelBtn = document.getElementById('inviteCollabCancelBtn');
    const confirmBtn = document.getElementById('inviteCollabConfirmBtn');

    const nameInput  = document.getElementById('icName');
    const emailInput = document.getElementById('icEmail');
    const nameError  = document.getElementById('icNameError');
    const emailError = document.getElementById('icEmailError');

    if (!openBtn || !modal) return;

    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function resetForm() {
        nameInput.value = '';
        emailInput.value = '';
        nameError.classList.add('hidden');
        emailError.classList.add('hidden');
        nameInput.classList.remove('error');
        emailInput.classList.remove('error');
    }

    function openModal() {
        resetForm();
        modal.style.display = 'flex';
        modal.classList.add('visible');
        setTimeout(() => {
            modalCard.classList.add('scaled');
            nameInput.focus();
        }, 10);
    }

    function closeModal() {
        modal.classList.remove('visible');
        modalCard.classList.remove('scaled');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    function confirmInvite() {
        const name = nameInput.value.trim();
        const email = emailInput.value.trim();
        let hasError = false;

        if (!name) {
            nameError.classList.remove('hidden');
            nameInput.classList.add('error');
            hasError = true;
        } else {
            nameError.classList.add('hidden');
            nameInput.classList.remove('error');
        }

        if (!email || !EMAIL_RE.test(email)) {
            emailError.classList.remove('hidden');
            emailInput.classList.add('error');
            hasError = true;
        } else {
            emailError.classList.add('hidden');
            emailInput.classList.remove('error');
        }

        if (hasError) {
            (nameError.classList.contains('hidden') ? emailInput : nameInput).focus();
            return;
        }

        closeModal();
        showToast(`Invite sent to ${name} (${email})!`);
    }

    openBtn.addEventListener('click', openModal);
    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)  cancelBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', confirmInvite);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    [nameInput, emailInput].forEach(input => {
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') confirmInvite();
        });
    });

    nameInput.addEventListener('input', () => {
        nameError.classList.add('hidden');
        nameInput.classList.remove('error');
    });

    emailInput.addEventListener('input', () => {
        emailError.classList.add('hidden');
        emailInput.classList.remove('error');
    });
}


/*  MODULE PREVIEW MODAL */
let closeModulePreview = () => {};
let currentPreviewMod = null;

function wireModulePreviewModal() {
    const modal      = document.getElementById('modulePreviewModal');
    const card       = document.getElementById('modulePreviewCard');
    const closeBtn   = document.getElementById('previewCloseBtn');
    const downloadBtn = document.getElementById('previewDownloadBtn');
    const pdfFrame   = document.getElementById('previewPdfFrame');
    if (!modal || !card) return;

    closeModulePreview = () => {
        modal.classList.remove('visible');
        card.classList.remove('scaled');
        setTimeout(() => {
            modal.style.display = 'none';
            if (pdfFrame) pdfFrame.src = ''; 
        }, 300);
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModulePreview);

    if (downloadBtn) {
        downloadBtn.addEventListener('click', () => {
            if (currentPreviewMod && currentPreviewMod.fileURL) {
                const a = document.createElement('a');
                a.href = currentPreviewMod.fileURL;
                a.download = currentPreviewMod.fileName || `${currentPreviewMod.title}.pdf`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            } else {
                showToast(`No uploaded file yet for "${currentPreviewMod ? currentPreviewMod.title : 'this module'}".`);
            }
        });
    }

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModulePreview();
    });
}

function openModulePreview(mod) {
    const modal      = document.getElementById('modulePreviewModal');
    const card       = document.getElementById('modulePreviewCard');
    const pdfWrap    = document.getElementById('previewPdfWrap');
    const pdfFrame   = document.getElementById('previewPdfFrame');
    const noFileNote = document.getElementById('previewNoFileNote');
    const noFileText = document.getElementById('previewNoFileText');
    const sampleContent = document.getElementById('previewSampleContent');
    if (!modal || !card) return;

    currentPreviewMod = mod;

    const sectionLabel = mod.sections.length ? mod.sections.join(', ') : 'None assigned';

    document.getElementById('previewTypeBadge').textContent = (mod.type || 'pdf').toUpperCase();
    document.getElementById('previewModuleTitle').textContent = mod.title;
    document.getElementById('previewModuleMeta').textContent =
        `Reading time: ${mod.readingLabel || 'Not yet estimated'} \u2022 Accessible by: ${sectionLabel} \u2022 Uploaded by: ${mod.uploadedBy || 'Unknown'}`;
    document.getElementById('previewHeading').textContent = mod.title;
    document.getElementById('previewStatSections').textContent =
        `${mod.sections.length} STEM`;

    const introEl = document.getElementById('previewIntroText');
    if (introEl) {
        introEl.textContent = mod.description
            ? mod.description
            : 'Welcome to this subject module! This document contains essential reading materials, core theoretical foundations, worked examples, and review problems curated specifically for your STEM coursework.';
    }
    const isPdf = !!mod.fileURL && (
        mod.fileMime === 'application/pdf' ||
        (mod.fileName && mod.fileName.toLowerCase().endsWith('.pdf'))
    );

    if (isPdf) {
        pdfFrame.src = mod.fileURL + '#toolbar=1&navpanes=0&view=FitH';
        pdfWrap.classList.remove('hidden');
        noFileNote.classList.add('hidden');
        sampleContent.classList.add('hidden');
    } else if (mod.fileURL) {
        pdfFrame.src = '';
        pdfWrap.classList.add('hidden');
        noFileText.textContent = `Inline preview isn't available for "${mod.fileName}" — use Download to open it.`;
        noFileNote.classList.remove('hidden');
        sampleContent.classList.remove('hidden');
    } else {
        pdfFrame.src = '';
        pdfWrap.classList.add('hidden');
        noFileText.textContent = 'No uploaded file yet for this module — showing a sample preview layout instead.';
        noFileNote.classList.remove('hidden');
        sampleContent.classList.remove('hidden');
    }

    modal.style.display = 'flex';
    modal.classList.add('visible');
    card.scrollTop = 0;
    setTimeout(() => card.classList.add('scaled'), 10);
}


function formatFileSize(bytes) {
    if (!bytes) return null;
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return `${size.toFixed(1)} ${units[unitIndex]}`;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}


/* TOAST */
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


/* FORCE PARENT SIDEBAR LINK HIGHLIGHT (dynamic) */
function forceParentNavActive() {
    document.querySelectorAll('.sidebar-link.nav-active').forEach(link => {
        link.classList.remove('nav-active');
    });

    const breadcrumbLink = document.querySelector('.breadcrumb a[href]');
    if (!breadcrumbLink) return;

    const breadcrumbPath = new URL(breadcrumbLink.getAttribute('href'), window.location.href).pathname;

    const matchingSidebarLink = Array.from(document.querySelectorAll('a.sidebar-link'))
        .find(link => {
            const linkPath = new URL(link.getAttribute('href'), window.location.href).pathname;
            return linkPath === breadcrumbPath;
        });

    if (matchingSidebarLink) matchingSidebarLink.classList.add('nav-active');
}