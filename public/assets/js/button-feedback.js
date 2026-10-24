/* BUTTON FEEDBACK
   Gives every button visible feedback without each page wiring it up:

   - Pressing a button darkens it briefly.
   - A button whose click starts a request (fetch) is disabled and shows a
     spinner until the server responds, so it can't be double-submitted.
   - A native form POST (login, register, reset password...) shows the
     spinner on its submit button and ignores repeat submits while the
     browser navigates.

   Buttons that a page already manages itself (it disables the button or
   shows its own spinner before the request) are left alone. Add
   data-no-busy to a button to opt it out entirely.

   Attribution: a request belongs to the button clicked just before it. If
   that button has since been removed (e.g. the "Confirm" button of a dialog
   that closed), the button that opened the dialog gets the spinner. */
(function () {
    if (window.__lqButtonFeedback) return;
    window.__lqButtonFeedback = true;

    var BUTTON_SELECTOR =
        'button, input[type="submit"], input[type="button"], [role="button"]';
    // A request must start within this long after the click to count as its.
    var CLICK_WINDOW_MS = 1000;
    // How far back to look for the trigger when the clicked button is gone.
    var FALLBACK_WINDOW_MS = 60000;
    // Fast responses skip the spinner (the button is still disabled) so it
    // doesn't flash.
    var SPINNER_DELAY_MS = 120;

    var CSS =
        "button:not(:disabled):active, input[type=submit]:not(:disabled):active, input[type=button]:not(:disabled):active, [role=button]:active {" +
        "  filter: brightness(0.9);" +
        "}" +
        ".lq-btn-busy { cursor: progress !important; pointer-events: none; }" +
        ".lq-btn-busy:disabled { opacity: 0.85; }" +
        ".lq-btn-spinning > *:not(.lq-btn-spinner) { visibility: hidden; }" +
        ".lq-btn-spinning { color: transparent !important; }" +
        ".lq-btn-spinner {" +
        "  position: absolute; top: 50%; left: 50%;" +
        "  width: 1em; height: 1em; margin: -0.5em 0 0 -0.5em;" +
        "  border: 2px solid var(--lq-busy-color, currentColor);" +
        "  border-right-color: transparent; border-radius: 50%;" +
        "  animation: lqBtnSpin 0.7s linear infinite;" +
        "  pointer-events: none; box-sizing: border-box;" +
        "}" +
        "@keyframes lqBtnSpin { to { transform: rotate(360deg); } }" +
        "@media (prefers-reduced-motion: reduce) { .lq-btn-spinner { animation-duration: 1.6s; } }";

    function injectStyles() {
        if (document.getElementById("lqButtonFeedbackStyles")) return;
        var style = document.createElement("style");
        style.id = "lqButtonFeedbackStyles";
        style.textContent = CSS;
        (document.head || document.documentElement).appendChild(style);
    }

    injectStyles();

    /* ---------- busy state ---------- */

    // el -> { count, setDisabled, timer, position }
    var busy = new Map();

    function managedByPage(el) {
        return (
            el.disabled ||
            el.getAttribute("aria-busy") === "true" ||
            el.querySelector(".fa-spin")
        );
    }

    function showSpinner(el) {
        if (!el.isConnected || el.querySelector(".lq-btn-spinner")) return;

        var style = window.getComputedStyle(el);
        el.style.setProperty("--lq-busy-color", style.color);

        var state = busy.get(el);
        if (state && style.position === "static") {
            state.position = el.style.position;
            el.style.position = "relative";
        }

        var spinner = document.createElement("span");
        spinner.className = "lq-btn-spinner";
        spinner.setAttribute("aria-hidden", "true");
        el.appendChild(spinner);
        el.classList.add("lq-btn-spinning");
    }

    function markBusy(el, options) {
        var state = busy.get(el);
        if (state) {
            state.count++;
            return true;
        }

        if (managedByPage(el)) return false;

        state = { count: 1, setDisabled: false, timer: null, position: null };
        busy.set(el, state);

        if (!options.keepEnabled && "disabled" in el) {
            el.disabled = true;
            state.setDisabled = true;
        }
        el.classList.add("lq-btn-busy");
        el.setAttribute("aria-busy", "true");
        state.timer = setTimeout(function () {
            showSpinner(el);
        }, options.immediate ? 0 : SPINNER_DELAY_MS);

        return true;
    }

    function clearBusy(el) {
        var state = busy.get(el);
        if (!state || --state.count > 0) return;

        busy.delete(el);
        clearTimeout(state.timer);

        var spinner = el.querySelector(":scope > .lq-btn-spinner");
        if (spinner) spinner.remove();
        if (state.position !== null) el.style.position = state.position;
        el.style.removeProperty("--lq-busy-color");
        el.classList.remove("lq-btn-busy", "lq-btn-spinning");
        el.removeAttribute("aria-busy");
        if (state.setDisabled) el.disabled = false;
    }

    /* ---------- which button triggered a request ---------- */

    var lastClick = null; // { el, at }
    var previousClick = null;

    document.addEventListener(
        "click",
        function (event) {
            var el = event.target.closest && event.target.closest(BUTTON_SELECTOR);
            if (!el || el.hasAttribute("data-no-busy")) return;

            if (lastClick && lastClick.el !== el) previousClick = lastClick;
            lastClick = { el: el, at: Date.now() };
        },
        true
    );

    // Still on the page and rendered (works for position: fixed too).
    function isVisible(el) {
        return el.isConnected && el.getClientRects().length > 0;
    }

    function triggerButton() {
        var now = Date.now();
        if (!lastClick || now - lastClick.at > CLICK_WINDOW_MS) return null;

        if (isVisible(lastClick.el)) {
            return lastClick.el;
        }

        // The clicked control went away (a dialog closed): credit the
        // button that opened it, if it's still on the page.
        if (
            previousClick &&
            now - previousClick.at <= FALLBACK_WINDOW_MS &&
            isVisible(previousClick.el)
        ) {
            return previousClick.el;
        }

        return null;
    }

    /* ---------- fetch ---------- */

    if (typeof window.fetch === "function") {
        var originalFetch = window.fetch;

        window.fetch = function () {
            var el = triggerButton();
            var request = originalFetch.apply(this, arguments);

            if (el && markBusy(el, {})) {
                var done = function () {
                    clearBusy(el);
                };
                request.then(done, done);
            }

            return request;
        };
    }

    /* ---------- native form submits ---------- */

    // Runs after the page's own submit handlers (bubbling to document), so a
    // submit they cancel (validation, fetch instead) is left alone.
    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        if (form.dataset.lqSubmitting) {
            event.preventDefault(); // already on its way — ignore repeats
            return;
        }
        if (event.defaultPrevented) return;

        form.dataset.lqSubmitting = "1";

        var submitter =
            event.submitter ||
            form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');

        // Not disabled: a disabled submitter would drop its name/value from
        // the request. pointer-events + the guard above block repeats.
        if (submitter && !submitter.hasAttribute("data-no-busy")) {
            markBusy(submitter, { keepEnabled: true, immediate: true });
        }
    });

    // Coming back with the Back button restores the page from cache with
    // the spinner still showing — reset it.
    window.addEventListener("pageshow", function (event) {
        if (!event.persisted) return;

        busy.forEach(function (state, el) {
            state.count = 1;
            clearBusy(el);
        });
        document.querySelectorAll("form[data-lq-submitting]").forEach(function (form) {
            delete form.dataset.lqSubmitting;
        });
    });
})();
