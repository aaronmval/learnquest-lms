/* Guided tour: dims the page, spotlights one element per step and explains
   it in a small card with Back / Next / Skip.

   LQGuidedTour.start(steps, { onFinish })

   Each step is an object:
     title, body   text shown in the card
     target        CSS selector, or function returning an element or array of
                   elements, to spotlight; omit for a centred card
     fallback      text shown instead of `body`, in a centred card, when the
                   target is missing or hidden
     before        optional async function run before the step is shown
     advanceOn     optional CSS selector; clicking a matching element moves
                   to the next step (the spotlighted element stays usable)

   onFinish({ completed }) runs when the tour ends; completed is false if it
   was skipped. Styles live in base/components.css ("GUIDED TOUR"). */
(function () {
    var PADDING = 8;
    var GAP = 14;
    var NARROW_PX = 640;

    var active = null;

    function isShown(el) {
        if (!el || el.closest(".is-hidden, .hidden, [hidden]")) return false;

        var rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    /* The step's visible target elements (a selector may match several
       neighbours, which are spotlighted together), or null if none. */
    function resolveTarget(step) {
        var target = step.target;
        if (!target) return null;

        var found = typeof target === "function" ? target() : document.querySelectorAll(target);
        var elements = Array.isArray(found) || found instanceof NodeList ? Array.prototype.slice.call(found) : [found];
        elements = elements.filter(isShown);

        return elements.length ? elements : null;
    }

    /* Smallest rectangle containing every target element. */
    function targetRect(elements) {
        var box = { top: Infinity, left: Infinity, bottom: -Infinity, right: -Infinity };

        elements.forEach(function (el) {
            var rect = el.getBoundingClientRect();
            box.top = Math.min(box.top, rect.top);
            box.left = Math.min(box.left, rect.left);
            box.bottom = Math.max(box.bottom, rect.bottom);
            box.right = Math.max(box.right, rect.right);
        });

        return box;
    }

    function build() {
        var spotlight = document.createElement("div");
        spotlight.className = "lq-tour-spotlight";

        var card = document.createElement("div");
        card.className = "lq-tour-card";
        card.setAttribute("role", "dialog");
        card.setAttribute("aria-labelledby", "lqTourTitle");
        card.setAttribute("aria-describedby", "lqTourBody");
        card.innerHTML =
            '<div class="lq-tour-head">' +
            '<span class="lq-tour-count" aria-live="polite"></span>' +
            '<button type="button" class="lq-tour-close" aria-label="Skip tour"><i class="fas fa-times"></i></button>' +
            "</div>" +
            '<h3 id="lqTourTitle" class="lq-tour-title"></h3>' +
            '<p id="lqTourBody" class="lq-tour-body" aria-live="polite"></p>' +
            '<div class="lq-tour-progress"><span></span></div>' +
            '<div class="lq-tour-actions">' +
            '<button type="button" class="lq-tour-skip">Skip tour</button>' +
            '<button type="button" class="lq-tour-back">Back</button>' +
            '<button type="button" class="lq-tour-next">Next</button>' +
            "</div>";

        document.body.appendChild(spotlight);
        document.body.appendChild(card);

        return {
            spotlight: spotlight,
            card: card,
            count: card.querySelector(".lq-tour-count"),
            title: card.querySelector(".lq-tour-title"),
            body: card.querySelector(".lq-tour-body"),
            progress: card.querySelector(".lq-tour-progress span"),
            skip: card.querySelector(".lq-tour-skip"),
            close: card.querySelector(".lq-tour-close"),
            back: card.querySelector(".lq-tour-back"),
            next: card.querySelector(".lq-tour-next"),
        };
    }

    /* Size the spotlight to the target and put the card where there is room. */
    function position() {
        if (!active) return;

        var ui = active.ui;
        var target = active.target;
        var viewW = window.innerWidth;
        var viewH = window.innerHeight;
        var card = ui.card;

        card.classList.remove("lq-tour-card-docked");
        card.style.left = "";
        card.style.top = "";

        if (!target) {
            // No element to point at: dim everything, centre the card.
            ui.spotlight.style.cssText = "left:50%;top:50%;width:0;height:0;";
            card.style.left = Math.max(12, (viewW - card.offsetWidth) / 2) + "px";
            card.style.top = Math.max(12, (viewH - card.offsetHeight) / 2) + "px";
            return;
        }

        var rect = targetRect(target);
        var top = Math.max(4, rect.top - PADDING);
        var left = Math.max(4, rect.left - PADDING);
        var bottom = Math.min(viewH - 4, rect.bottom + PADDING);
        var right = Math.min(viewW - 4, rect.right + PADDING);

        ui.spotlight.style.cssText =
            "left:" + left + "px;top:" + top + "px;width:" + Math.max(0, right - left) +
            "px;height:" + Math.max(0, bottom - top) + "px;";

        var cardW = card.offsetWidth;
        var cardH = card.offsetHeight;
        var spaceBelow = viewH - bottom;
        var spaceAbove = top;

        if (viewW < NARROW_PX || (spaceBelow < cardH + GAP && spaceAbove < cardH + GAP)) {
            // Small screen or a tall target: dock to whichever edge is
            // further from the target's middle.
            card.classList.add("lq-tour-card-docked");
            var targetMiddle = (top + bottom) / 2;
            card.style.left = Math.max(12, (viewW - cardW) / 2) + "px";
            card.style.top = (targetMiddle < viewH / 2 ? viewH - cardH - 12 : 12) + "px";
            return;
        }

        var cardTop = spaceBelow >= cardH + GAP ? bottom + GAP : top - GAP - cardH;
        var cardLeft = Math.min(Math.max(12, left), viewW - cardW - 12);

        card.style.left = cardLeft + "px";
        card.style.top = cardTop + "px";
    }

    function setBusy(busy) {
        var ui = active.ui;
        ui.next.disabled = busy;
        ui.back.disabled = busy || active.index === 0;
    }

    async function show(index) {
        if (!active) return;

        var tour = active;
        var step = tour.steps[index];
        tour.index = index;

        if (typeof step.before === "function") {
            setBusy(true);
            try {
                await step.before();
            } catch (e) {
                // A failed preparation just means the step uses its fallback.
            }
            if (active !== tour || tour.index !== index) return;
        }

        var ui = tour.ui;
        var target = resolveTarget(step);
        tour.target = target;

        ui.count.textContent = "Step " + (index + 1) + " of " + tour.steps.length;
        ui.title.textContent = step.title;
        ui.body.textContent = !target && step.target && step.fallback ? step.fallback : step.body;
        ui.progress.style.width = ((index + 1) / tour.steps.length) * 100 + "%";
        ui.next.textContent = index === tour.steps.length - 1 ? "Done" : "Next";
        ui.skip.hidden = index === tour.steps.length - 1;
        setBusy(false);

        if (target) {
            var box = targetRect(target);
            var tall = box.bottom - box.top > window.innerHeight * 0.7;
            target[0].scrollIntoView({ block: tall ? "start" : "center", inline: "nearest" });
        }

        position();
        ui.next.focus({ preventScroll: true });
    }

    function go(delta) {
        if (!active) return;

        var index = active.index + delta;
        if (index < 0) return;
        if (index >= active.steps.length) {
            finish(true);
            return;
        }
        show(index);
    }

    function finish(completed) {
        if (!active) return;

        var tour = active;
        active = null;

        tour.ui.spotlight.remove();
        tour.ui.card.remove();
        window.removeEventListener("resize", position);
        window.removeEventListener("scroll", position, true);
        document.removeEventListener("keydown", onKeydown, true);
        document.removeEventListener("click", onDocumentClick, true);

        if (tour.returnFocus && document.contains(tour.returnFocus)) {
            tour.returnFocus.focus({ preventScroll: true });
        }
        if (typeof tour.onFinish === "function") tour.onFinish({ completed: completed });
    }

    function onKeydown(event) {
        if (!active) return;

        if (event.key === "Escape") {
            event.preventDefault();
            finish(false);
            return;
        }

        // Arrow keys only steer the tour from inside its card, so they keep
        // working in the page's own selects, sliders and inputs.
        if (!active.ui.card.contains(event.target)) return;
        if (event.key === "ArrowRight" && !active.ui.next.disabled) go(1);
        if (event.key === "ArrowLeft" && !active.ui.back.disabled) go(-1);
    }

    function onDocumentClick(event) {
        if (!active) return;

        var selector = active.steps[active.index].advanceOn;
        if (!selector || active.ui.card.contains(event.target)) return;
        if (!event.target.closest || !event.target.closest(selector)) return;

        // Let the page handle the click first, then move on.
        setTimeout(function () {
            go(1);
        }, 0);
    }

    function start(steps, options) {
        if (active || !steps || !steps.length) return;

        var ui = build();
        active = {
            steps: steps,
            index: 0,
            target: null,
            ui: ui,
            onFinish: options && options.onFinish,
            returnFocus: document.activeElement,
        };

        ui.next.addEventListener("click", function () {
            go(1);
        });
        ui.back.addEventListener("click", function () {
            go(-1);
        });
        ui.skip.addEventListener("click", function () {
            finish(false);
        });
        ui.close.addEventListener("click", function () {
            finish(false);
        });

        window.addEventListener("resize", position);
        // Capture phase: the page content scrolls inside #mainContent.
        window.addEventListener("scroll", position, true);
        document.addEventListener("keydown", onKeydown, true);
        document.addEventListener("click", onDocumentClick, true);

        show(0);
    }

    window.LQGuidedTour = {
        start: start,
        isActive: function () {
            return active !== null;
        },
    };
})();
