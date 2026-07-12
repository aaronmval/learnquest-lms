const PROFESSOR_VALIDATOR_STORAGE_KEY = "lq_professor_ai_validator_v1";
const STUDENT_GENERATED_QUIZ_STORAGE_KEY = "lq_student_generated_ai_quiz_v1";

const MATERIALS = [
    {
        id: "chem-week2-atoms",
        title: "Week 2: Atomic Structure",
        section: "STEM - Amethyst",
        uploadedAt: "Uploaded Jul 10, 2026",
    },
    {
        id: "chem-week3-bonding",
        title: "Week 3: Chemical Bonding",
        section: "STEM - Amethyst",
        uploadedAt: "Uploaded Jul 11, 2026",
    },
    {
        id: "chem-week4-stoich",
        title: "Week 4: Stoichiometry",
        section: "STEM - Amethyst",
        uploadedAt: "Uploaded Jul 12, 2026",
    },
];

const QUESTION_BANK = {
    easy: [
        {
            prompt: "Which particle has a negative charge in an atom?",
            options: ["Proton", "Neutron", "Electron", "Nucleus"],
            correctIndex: 2,
        },
        {
            prompt: "What is the atomic number based on in the periodic table?",
            options: [
                "Number of neutrons",
                "Number of protons",
                "Number of shells",
                "Mass number",
            ],
            correctIndex: 1,
        },
        {
            prompt: "Which bond type forms when electrons are shared?",
            options: [
                "Ionic bond",
                "Covalent bond",
                "Metallic bond",
                "Hydrogen bond",
            ],
            correctIndex: 1,
        },
    ],
    balanced: [
        {
            prompt: "How does electronegativity difference help predict bond polarity?",
            options: [
                "Larger difference usually increases bond polarity",
                "Smaller difference always makes ionic bonds",
                "Electronegativity has no effect on polarity",
                "It only affects metallic bonds",
            ],
            correctIndex: 0,
        },
        {
            prompt: "Which periodic trend best explains increased reactivity in alkali metals?",
            options: [
                "Increasing ionization energy down the group",
                "Decreasing atomic radius down the group",
                "Decreasing ionization energy down the group",
                "Increasing electronegativity down the group",
            ],
            correctIndex: 2,
        },
        {
            prompt: "How do mole ratios guide product prediction in simple reactions?",
            options: [
                "They compare measured masses only",
                "They come from balanced equation coefficients",
                "They depend only on temperature",
                "They replace the balanced equation",
            ],
            correctIndex: 1,
        },
    ],
    hard: [
        {
            prompt: "Which evidence most strongly supports identifying a limiting reagent?",
            options: [
                "The reagent with highest molar mass",
                "The reagent fully consumed first by stoichiometric ratio",
                "The reagent with lowest concentration label",
                "The reagent added last in setup",
            ],
            correctIndex: 1,
        },
        {
            prompt: "How does orbital hybridization influence molecular geometry?",
            options: [
                "It determines spatial arrangement and typical bond angles",
                "It only changes atomic mass",
                "It removes lone pair effects",
                "It is unrelated to molecular shape",
            ],
            correctIndex: 0,
        },
        {
            prompt: "Which approach best isolates percent-yield error in a stoichiometry workflow?",
            options: [
                "Skip balancing and compare masses directly",
                "Track units and theoretical yield before comparing with actual yield",
                "Use only volume conversion factors",
                "Assume complete conversion without calculation",
            ],
            correctIndex: 1,
        },
    ],
};

const dom = {
    materialsList: document.getElementById("materialsList"),
    selectedMaterialTitle: document.getElementById("selectedMaterialTitle"),
    selectedMaterialMeta: document.getElementById("selectedMaterialMeta"),
    masterySlider: document.getElementById("masterySlider"),
    masteryValue: document.getElementById("masteryValue"),
    difficultyLabel: document.getElementById("difficultyLabel"),
    questionPreviewCard: document.getElementById("questionPreviewCard"),
    mcqQuestionText: document.getElementById("mcqQuestionText"),
    mcqOptionsList: document.getElementById("mcqOptionsList"),
    checkBtn: document.getElementById("checkBtn"),
    rejectBtn: document.getElementById("rejectBtn"),
    validatorStatus: document.getElementById("validatorStatus"),
    validatorHint: document.getElementById("validatorHint"),
};

const state = loadState();

function loadState() {
    try {
        const parsed = JSON.parse(
            localStorage.getItem(PROFESSOR_VALIDATOR_STORAGE_KEY) || "{}",
        );
        return {
            selectedMaterialId: parsed.selectedMaterialId || null,
            materialStates: parsed.materialStates || {},
            questionIndexByMaterial: parsed.questionIndexByMaterial || {},
        };
    } catch (e) {
        return {
            selectedMaterialId: null,
            materialStates: {},
            questionIndexByMaterial: {},
        };
    }
}

function persistState() {
    localStorage.setItem(PROFESSOR_VALIDATOR_STORAGE_KEY, JSON.stringify(state));
}

function ensureMaterialState(materialId) {
    if (!state.materialStates[materialId]) {
        state.materialStates[materialId] = {
            mastery: 50,
            checked: false,
            rejected: false,
        };
    }

    return state.materialStates[materialId];
}

function getSelectedMaterial() {
    return MATERIALS.find((material) => material.id === state.selectedMaterialId) || null;
}

function difficultyForMastery(mastery) {
    if (mastery <= 33) {
        return "easy";
    }
    if (mastery >= 67) {
        return "hard";
    }

    return "balanced";
}

function difficultyLabelText(level) {
    if (level === "easy") {
        return "Easy Focus";
    }
    if (level === "hard") {
        return "Hard Focus";
    }

    return "Balanced";
}

function clearStudentGeneratedQuiz(materialId) {
    try {
        const parsed = JSON.parse(
            localStorage.getItem(STUDENT_GENERATED_QUIZ_STORAGE_KEY) || "{}",
        );

        if (!parsed || typeof parsed !== "object") {
            return;
        }

        if (parsed.materialId && parsed.materialId !== materialId) {
            return;
        }

        localStorage.removeItem(STUDENT_GENERATED_QUIZ_STORAGE_KEY);
    } catch (e) {
        localStorage.removeItem(STUDENT_GENERATED_QUIZ_STORAGE_KEY);
    }
}

function saveStudentGeneratedQuiz(material, materialState) {
    const payload = {
        visible: true,
        source: "ai-validator",
        subject: "CHEMISTRY",
        materialId: material.id,
        materialTitle: material.title,
        mastery: materialState.mastery,
        quizId: `ai-mcq-${material.id}`,
        quizUrl: "student-quiz.html",
        label: `AI Generated MCQ - ${material.title}`,
        updatedAt: new Date().toISOString(),
    };

    localStorage.setItem(STUDENT_GENERATED_QUIZ_STORAGE_KEY, JSON.stringify(payload));
}

function statusInfoForMaterial(materialState) {
    if (materialState.rejected) {
        return {
            text: "Rejected",
            className: "status-rejected",
            hint: "Rejected. This generated question will not appear on student side.",
        };
    }

    if (materialState.checked) {
        return {
            text: "Accepted",
            className: "status-saved",
            hint: "Accepted and published to student chemistry classwork.",
        };
    }

    return {
        text: "Not Checked",
        className: "status-pending",
        hint: "Use ✓ to accept and publish, or X to reject and hide this generated question.",
    };
}

function materialChipText(materialState) {
    if (materialState.rejected) {
        return "Rejected";
    }

    if (materialState.checked) {
        return "Checked";
    }

    return "Pending";
}

function materialChipClass(materialState) {
    if (materialState.rejected) {
        return "status-rejected";
    }
    if (materialState.checked) {
        return "status-checked";
    }

    return "status-pending";
}

function renderMaterials() {
    if (!dom.materialsList) {
        return;
    }

    dom.materialsList.innerHTML = MATERIALS.map((material) => {
        const materialState = ensureMaterialState(material.id);
        const chipText = materialChipText(materialState);
        const chipClass = materialChipClass(materialState);
        const isActive = material.id === state.selectedMaterialId;

        return `
            <li>
                <button type="button" class="material-item${isActive ? " active" : ""}" data-material-id="${material.id}">
                    <div class="material-title-row">
                        <span class="material-title">${material.title}</span>
                        <span class="status-chip ${chipClass}">${chipText}</span>
                    </div>
                    <span class="material-meta">${material.section}</span>
                    <span class="material-meta">${material.uploadedAt}</span>
                </button>
            </li>
        `;
    }).join("");

    dom.materialsList.querySelectorAll(".material-item").forEach((button) => {
        button.addEventListener("click", () => {
            state.selectedMaterialId = button.dataset.materialId;
            ensureMaterialState(state.selectedMaterialId);
            persistState();
            renderAll();
        });
    });
}

function updateSliderColor(masteryValue) {
    if (!dom.masterySlider) {
        return;
    }

    const hue = 120 - (120 * masteryValue) / 100;
    const sliderColor = `hsl(${hue}, 72%, 44%)`;
    dom.masterySlider.style.setProperty("--slider-track-color", sliderColor);
    dom.masterySlider.style.setProperty("--slider-stop", `${masteryValue}%`);
}

function getCurrentQuestion(materialId, level) {
    const bank = QUESTION_BANK[level] || [];
    if (!bank.length) {
        return null;
    }

    const index = Number(state.questionIndexByMaterial[materialId] || 0) % bank.length;
    return bank[index];
}

function renderQuestionPreview(materialId, mastery) {
    const level = difficultyForMastery(mastery);
    const currentQuestion = getCurrentQuestion(materialId, level);

    if (!currentQuestion) {
        dom.mcqQuestionText.textContent = "No generated question available.";
        dom.mcqOptionsList.innerHTML = "<li class=\"mcq-option-item\">No options available.</li>";
        return;
    }

    dom.difficultyLabel.textContent = difficultyLabelText(level);
    dom.mcqQuestionText.textContent = currentQuestion.prompt;
    dom.mcqOptionsList.innerHTML = currentQuestion.options
        .map((option, index) => {
            const classes =
                index === currentQuestion.correctIndex
                    ? "mcq-option-item correct-answer"
                    : "mcq-option-item";
            return `<li class=\"${classes}\">${String.fromCharCode(65 + index)}. ${option}</li>`;
        })
        .join("");
}

function showQuestionIntro() {
    if (!dom.questionPreviewCard) {
        return;
    }

    dom.questionPreviewCard.classList.remove("is-fading-out", "is-visible");
    dom.questionPreviewCard.classList.add("is-fading-in");

    requestAnimationFrame(() => {
        dom.questionPreviewCard.classList.add("is-visible");
    });
}

function renderEmptyPreview() {
    dom.difficultyLabel.textContent = "Balanced";
    dom.mcqQuestionText.textContent =
        "Select a material to preview a generated question.";
    dom.mcqOptionsList.innerHTML =
        "<li class=\"mcq-option-item\">Option preview will appear here.</li>";
}

function cycleToNextQuestion(materialId, mastery) {
    if (!dom.questionPreviewCard) {
        return;
    }

    const level = difficultyForMastery(mastery);
    const bankSize = (QUESTION_BANK[level] || []).length;

    if (!bankSize) {
        renderQuestionPreview(materialId, mastery);
        return;
    }

    dom.questionPreviewCard.classList.remove("is-fading-in", "is-visible");
    dom.questionPreviewCard.classList.add("is-fading-out");

    setTimeout(() => {
        const currentIndex = Number(state.questionIndexByMaterial[materialId] || 0);
        state.questionIndexByMaterial[materialId] = (currentIndex + 1) % bankSize;

        renderQuestionPreview(materialId, mastery);
        persistState();

        dom.questionPreviewCard.classList.remove("is-fading-out");
        showQuestionIntro();
    }, 300);
}

function renderValidator() {
    const selectedMaterial = getSelectedMaterial();

    if (!selectedMaterial) {
        dom.selectedMaterialTitle.textContent = "Select a material";
        dom.selectedMaterialMeta.textContent =
            "Pick from the left panel to start validating generated quiz items.";
        dom.masteryValue.textContent = "50%";
        dom.masterySlider.value = "50";
        updateSliderColor(50);
        dom.masterySlider.disabled = true;
        dom.checkBtn.disabled = true;
        dom.rejectBtn.disabled = true;
        renderEmptyPreview();
        dom.validatorStatus.textContent = "Not Checked";
        dom.validatorStatus.className = "status-chip status-pending";
        dom.validatorHint.textContent =
            "Use ✓ to accept and publish to student chemistry classwork, or X to reject and hide it.";
        return;
    }

    const materialState = ensureMaterialState(selectedMaterial.id);
    const mastery = Number(materialState.mastery) || 50;
    const statusInfo = statusInfoForMaterial(materialState);

    dom.selectedMaterialTitle.textContent = selectedMaterial.title;
    dom.selectedMaterialMeta.textContent = `${selectedMaterial.section} | ${selectedMaterial.uploadedAt}`;

    dom.masterySlider.disabled = false;
    dom.masterySlider.value = String(mastery);
    dom.masteryValue.textContent = `${mastery}%`;
    updateSliderColor(mastery);

    renderQuestionPreview(selectedMaterial.id, mastery);

    dom.checkBtn.disabled = false;
    dom.rejectBtn.disabled = false;

    dom.validatorStatus.textContent = statusInfo.text;
    dom.validatorStatus.className = `status-chip ${statusInfo.className}`;
    dom.validatorHint.textContent = statusInfo.hint;
}

function handleMasteryInput(event) {
    const selectedMaterial = getSelectedMaterial();
    if (!selectedMaterial) {
        return;
    }

    const materialState = ensureMaterialState(selectedMaterial.id);
    materialState.mastery = Number(event.target.value);
    materialState.checked = false;
    materialState.rejected = false;

    clearStudentGeneratedQuiz(selectedMaterial.id);
    persistState();
    renderValidator();
    renderMaterials();
}

function handleCheck() {
    const selectedMaterial = getSelectedMaterial();
    if (!selectedMaterial) {
        return;
    }

    const materialState = ensureMaterialState(selectedMaterial.id);
    materialState.checked = true;
    materialState.rejected = false;

    saveStudentGeneratedQuiz(selectedMaterial, materialState);
    persistState();
    renderMaterials();
    renderValidator();
    cycleToNextQuestion(selectedMaterial.id, materialState.mastery);
}

function handleReject() {
    const selectedMaterial = getSelectedMaterial();
    if (!selectedMaterial) {
        return;
    }

    const materialState = ensureMaterialState(selectedMaterial.id);
    materialState.checked = false;
    materialState.rejected = true;

    clearStudentGeneratedQuiz(selectedMaterial.id);
    persistState();
    renderMaterials();
    renderValidator();
    cycleToNextQuestion(selectedMaterial.id, materialState.mastery);
}

function bindEvents() {
    if (dom.masterySlider) {
        dom.masterySlider.addEventListener("input", handleMasteryInput);
    }

    if (dom.checkBtn) {
        dom.checkBtn.addEventListener("click", handleCheck);
    }

    if (dom.rejectBtn) {
        dom.rejectBtn.addEventListener("click", handleReject);
    }
}

function renderAll() {
    renderMaterials();
    renderValidator();
    showQuestionIntro();
}

document.addEventListener("DOMContentLoaded", () => {
    bindEvents();
    renderAll();
});
