/* Shared page transitions for every in-app page (the pages shown inside the
   shell's content iframe).

   The page-open entrance of each top-level section is pure CSS (see the
   "PAGE TRANSITIONS" block in base/components.css). This script covers what
   changes afterwards: cards or rows rendered after a fetch, panels that get
   un-hidden, and text that is updated in place. It only adds/removes the
   .lq-enter / .lq-fade classes; the motion itself lives in the stylesheet. */
(function () {
    if (!("MutationObserver" in window)) return;
    // Animations set to "Reduced" in Settings → General.
    if (document.documentElement.classList.contains("lq-reduced-motion")) return;
    if (
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
        return;
    }

    var STAGGER_MS = 45;
    var MAX_STAGGER_STEPS = 10;
    var TYPING_GUARD_MS = 500;
    var TEXT_REPEAT_MS = 2000;

    var SKIP_TAGS = /^(SCRIPT|STYLE|LINK|CANVAS|OPTION|BR)$/;
    // Elements that already bring their own open/close motion.
    var OWN_MOTION = /modal|overlay|toast|drawer|menu|dropdown|tooltip|loader|spinner/i;
    var TEXT_INPUT_SKIP = /^(checkbox|radio|range|file|color|button|submit)$/;

    var root = null;
    var lastTyped = 0;
    var lastTextChange = new WeakMap();

    function hasOwnMotion(el) {
        for (var node = el; node && node !== root; node = node.parentElement) {
            var names = (node.getAttribute("class") || "") + " " + node.id;
            if (OWN_MOTION.test(names)) return true;
        }
        return false;
    }

    function isAnimating(el) {
        var name = window.getComputedStyle(el).animationName;
        return !!name && name !== "none";
    }

    function canAnimate(el) {
        if (!el || el.nodeType !== 1 || el === root) return false;
        if (SKIP_TAGS.test(el.tagName)) return false;
        if (el.parentElement && el.parentElement.closest(".lq-enter")) {
            return false;
        }
        if (hasOwnMotion(el)) return false;
        // Covers sections still running their page-open entrance and anything
        // with its own CSS animation (spinners, modal cards).
        return !isAnimating(el);
    }

    function play(el, className, delay) {
        var timer = null;

        function done(event) {
            if (event && event.target !== el) return;
            el.removeEventListener("animationend", done);
            clearTimeout(timer);
            el.classList.remove(className);
            el.style.removeProperty("--lq-delay");
        }

        if (delay) el.style.setProperty("--lq-delay", delay + "ms");
        el.classList.add(className);
        el.addEventListener("animationend", done);
        // Fallback for elements that never render (inserted while hidden).
        timer = setTimeout(done, delay + 700);
    }

    function wasShown(mutation) {
        var el = mutation.target;
        var old = mutation.oldValue;

        if (mutation.attributeName === "hidden") {
            return old !== null && !el.hasAttribute("hidden");
        }
        if (mutation.attributeName === "class") {
            return (
                /(^|\s)hidden(\s|$)/.test(old || "") &&
                !el.classList.contains("hidden")
            );
        }
        return (
            /display\s*:\s*none/.test(old || "") && el.style.display !== "none"
        );
    }

    function fadeText(el, now) {
        if (!el || el.nodeType !== 1 || el === root) return;
        if (el.isContentEditable || SKIP_TAGS.test(el.tagName)) return;

        // Timers and counters update constantly; fade them once, not on
        // every tick.
        var last = lastTextChange.get(el) || 0;
        lastTextChange.set(el, now);
        if (now - last < TEXT_REPEAT_MS) return;

        if (el.closest(".lq-enter") || hasOwnMotion(el) || isAnimating(el)) {
            return;
        }
        play(el, "lq-fade", 0);
    }

    function handleMutations(mutations) {
        var now = Date.now();

        // Search boxes and filter fields re-render per keystroke; animating
        // each of those would flash.
        if (now - lastTyped < TYPING_GUARD_MS) return;

        var staggerByParent = new Map();
        var textTargets = new Set();

        mutations.forEach(function (mutation) {
            if (mutation.type === "characterData") {
                textTargets.add(mutation.target.parentElement);
                return;
            }

            if (mutation.type === "attributes") {
                if (wasShown(mutation) && canAnimate(mutation.target)) {
                    play(mutation.target, "lq-enter", 0);
                }
                return;
            }

            var addedElement = false;
            var addedText = false;

            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 3) {
                    if (node.nodeValue.trim()) addedText = true;
                    return;
                }
                if (!node.isConnected || !canAnimate(node)) return;

                addedElement = true;
                var step = staggerByParent.get(mutation.target) || 0;
                staggerByParent.set(mutation.target, step + 1);
                play(
                    node,
                    "lq-enter",
                    Math.min(step, MAX_STAGGER_STEPS) * STAGGER_MS,
                );
            });

            if (addedText && !addedElement) textTargets.add(mutation.target);
        });

        textTargets.forEach(function (el) {
            fadeText(el, now);
        });
    }

    function start() {
        root = document.getElementById("mainContent") || document.body;
        if (!root) return;

        document.addEventListener(
            "input",
            function (event) {
                var el = event.target;
                var isText =
                    el.tagName === "TEXTAREA" ||
                    el.isContentEditable ||
                    (el.tagName === "INPUT" && !TEXT_INPUT_SKIP.test(el.type));
                if (isText) lastTyped = Date.now();
            },
            true,
        );
        // Enter submits (chat message, search) — let the result animate.
        document.addEventListener(
            "keydown",
            function (event) {
                if (event.key === "Enter") lastTyped = 0;
            },
            true,
        );

        new MutationObserver(handleMutations).observe(root, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ["class", "style", "hidden"],
            attributeOldValue: true,
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
