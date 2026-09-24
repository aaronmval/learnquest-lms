/* AI INSIGHTS CARD — shared by the class page and QuestAI.
   Mastery level and weaknesses come from BKT; the advice text is written
   by Llama (or rule-based if the AI is unavailable). */
const InsightsCard = (() => {
    const INSIGHT_LABELS = {
        weakness: "Weakness",
        strength: "Strength",
        next_step: "Next Step",
    };

    const LEVEL_LABELS = {
        low: "Low",
        developing: "Developing",
        high: "High",
    };

    function escapeHtml(str) {
        if (str === undefined || str === null) return "";
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function levelLabel(level) {
        return LEVEL_LABELS[level] || level;
    }

    function percent(mastery) {
        return Math.round(mastery * 100);
    }

    function status(el, icon, text) {
        el.innerHTML = `<div class="insight-item insight-status"><i class="fas ${icon}"></i> ${escapeHtml(text)}</div>`;
    }

    function renderLoading(el) {
        status(el, "fa-spinner fa-spin", "Analyzing your mastery...");
    }

    function renderError(el) {
        status(el, "fa-triangle-exclamation", "Could not load your insights. Please try again.");
    }

    function renderEmpty(el) {
        status(el, "fa-circle-info", "Take a lesson quiz to unlock insights about your mastery and weak areas.");
    }

    function levelPill(level, text) {
        return `<span class="insight-level-pill level-${escapeHtml(level)}">${escapeHtml(text)}</span>`;
    }

    /* Response of GET /student/classes/{id}/insights */
    function render(el, data) {
        if (!el) return;

        if (data.source === "empty" || !data.overall) {
            renderEmpty(el);
            return;
        }

        const level = data.overall.level;
        let html = `
            <div class="insight-level">
                <span class="insight-level-label">Mastery level</span>
                ${levelPill(level, `${levelLabel(level)} · ${percent(data.overall.mastery)}%`)}
            </div>`;

        const focus = (data.weaknesses || []).slice(0, 3);
        if (focus.length) {
            html += `
                <div class="insight-focus">
                    <p class="insight-focus-title">Focus areas</p>
                    <ul class="insight-focus-list">
                        ${focus
                            .map(
                                (c) => `
                            <li>
                                <span class="insight-focus-name">${escapeHtml(c.name)}</span>
                                ${levelPill(c.level, `${percent(c.mastery)}%`)}
                            </li>`
                            )
                            .join("")}
                    </ul>
                </div>`;
        }

        html += (data.insights || [])
            .map(
                (insight) =>
                    `<div class="insight-item"><strong>${escapeHtml(INSIGHT_LABELS[insight.type] || "Insight")}:</strong> ${escapeHtml(insight.text)}</div>`
            )
            .join("");

        el.innerHTML = html;
    }

    /* Per-class mastery overview, e.g. QuestAI's "All Classes" scope.
       classes: [{ name, subject, overall: {mastery, level} | null }] */
    function renderClassOverview(el, classes) {
        if (!el) return;

        const assessed = (classes || []).filter((c) => c.overall);
        if (!assessed.length) {
            renderEmpty(el);
            return;
        }

        el.innerHTML = `
            <div class="insight-focus">
                <p class="insight-focus-title">Mastery by class</p>
                <ul class="insight-focus-list">
                    ${assessed
                        .map(
                            (c) => `
                        <li>
                            <span class="insight-focus-name">${escapeHtml(c.subject || c.name)}</span>
                            ${levelPill(c.overall.level, `${levelLabel(c.overall.level)} · ${percent(c.overall.mastery)}%`)}
                        </li>`
                        )
                        .join("")}
                </ul>
            </div>
            <div class="insight-item insight-status">
                <i class="fas fa-circle-info"></i> Select a class above for detailed AI insights.
            </div>`;
    }

    /* Fetch and render insights for one class. Resolves true on success. */
    async function load(el, classId, { refresh = false } = {}) {
        if (!el || !classId) return false;

        renderLoading(el);

        try {
            const url = `/student/classes/${encodeURIComponent(classId)}/insights${refresh ? "?refresh=1" : ""}`;
            const res = await fetch(url, {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (!res.ok) throw new Error("failed to load insights");

            render(el, await res.json());
            return true;
        } catch (e) {
            renderError(el);
            return false;
        }
    }

    return {
        escapeHtml,
        levelLabel,
        render,
        renderClassOverview,
        renderLoading,
        renderError,
        load,
    };
})();
