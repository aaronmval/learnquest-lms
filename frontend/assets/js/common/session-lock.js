/* Idle lock (Settings → Security), run by the app shell.

   Watches for activity in the shell and in the page inside #contentFrame.
   When the user's chosen number of idle minutes passes, it locks the
   session (POST /session/lock emails a code) and covers the window with an
   overlay asking for that code. The page underneath is left untouched, so
   nothing typed is lost.

   The server enforces the same limit (EnforceIdleLock): heartbeats keep its
   clock in step while the user is active, and a background status poll
   lets every open tab notice when one of them locks.

   LQ_HOST_CONFIG.idleLockMinutes: 0 = off. window.LQ_applySecurity(minutes)
   applies a change from the Settings page straight away. */
(function () {
    var CHECK_MS = 15000;
    var HEARTBEAT_MS = 30000;
    var STATUS_MS = 60000;
    var ACTIVITY_EVENTS = ["pointerdown", "keydown", "wheel", "touchstart", "mousemove"];

    var config = window.LQ_HOST_CONFIG || {};
    var minutes = Number(config.idleLockMinutes) || 0;
    var lastActivity = Date.now();
    var lastHeartbeat = Date.now();
    var locked = false;
    var ui = null;
    var resendTimer = null;

    function csrfToken() {
        var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

    function request(url, method, body, background) {
        var headers = { Accept: "application/json" };
        if (method !== "GET") headers["X-XSRF-TOKEN"] = csrfToken();
        if (body) headers["Content-Type"] = "application/json";
        if (background) headers["X-LQ-Background"] = "1";

        return fetch(url, {
            method: method,
            credentials: "same-origin",
            headers: headers,
            body: body ? JSON.stringify(body) : null,
        }).then(function (res) {
            return res
                .json()
                .catch(function () {
                    return null;
                })
                .then(function (data) {
                    return { ok: res.ok, status: res.status, data: data || {} };
                });
        });
    }

    /* ACTIVITY */
    function onActivity() {
        if (locked || !minutes) return;

        var now = Date.now();
        lastActivity = now;

        if (now - lastHeartbeat >= HEARTBEAT_MS) {
            lastHeartbeat = now;
            request("/session/heartbeat", "POST")
                .then(function (res) {
                    if (res.status === 423) lock();
                })
                .catch(function () {
                    /* Offline: the next heartbeat will try again. */
                });
        }
    }

    function listen(doc) {
        if (!doc || doc.__lqIdleListening) return;
        doc.__lqIdleListening = true;
        ACTIVITY_EVENTS.forEach(function (name) {
            doc.addEventListener(name, onActivity, { capture: true, passive: true });
        });
    }

    function idleTooLong() {
        return minutes > 0 && Date.now() - lastActivity >= minutes * 60000;
    }

    function check() {
        if (!locked && idleTooLong()) lock();
    }

    function pollStatus() {
        if (!minutes || locked) return;

        request("/session/status", "GET", null, true)
            .then(function (res) {
                if (res.status === 423 || res.data.locked) lock();
            })
            .catch(function () {
                /* Offline: try again next time. */
            });
    }

    /* LOCK / UNLOCK */
    function lock() {
        if (locked) return;
        locked = true;
        showOverlay();
        setError("");

        request("/session/lock", "POST")
            .then(function (res) {
                render(res.data);
                if (res.data.error) setError(res.data.error);
            })
            .catch(function () {
                setError("We could not reach LearnQuest. Check your connection, then use Resend.");
            });
    }

    function unlocked() {
        locked = false;
        lastActivity = Date.now();
        lastHeartbeat = Date.now();
        clearInterval(resendTimer);
        if (ui) {
            ui.overlay.remove();
            ui = null;
        }
    }

    function submit(event) {
        event.preventDefault();
        if (!ui) return;

        var code = ui.input.value.trim();
        if (!/^\d+$/.test(code)) {
            setError("Enter the code from your email.");
            return;
        }

        ui.submit.disabled = true;
        request("/session-locked", "POST", { code: code })
            .then(function (res) {
                if (res.ok) {
                    unlocked();
                    return;
                }
                var errors = res.data.errors || {};
                setError((errors.code && errors.code[0]) || res.data.message || "That code didn't work. Please try again.");
                render(res.data);
                ui.input.select();
            })
            .catch(function () {
                setError("We could not reach LearnQuest. Check your connection and try again.");
            })
            .then(function () {
                if (ui) ui.submit.disabled = ui.input.disabled;
            });
    }

    function resend() {
        if (!ui) return;
        ui.resend.disabled = true;

        request("/session-locked/resend", "POST")
            .then(function (res) {
                render(res.data);
                setError(res.data.error || "");
                if (res.ok && ui) {
                    // The restarted "Resend in …s" countdown confirms it was sent.
                    ui.input.value = "";
                    ui.input.focus();
                }
            })
            .catch(function () {
                setError("We could not reach LearnQuest. Check your connection and try again.");
                if (ui) ui.resend.disabled = false;
            });
    }

    function signOut() {
        var form = document.getElementById(config.logoutFormId || "shellLogoutForm");
        if (form) form.submit();
        else window.location.href = "/login";
    }

    /* OVERLAY */
    function showOverlay() {
        if (ui) return;

        var overlay = document.createElement("div");
        overlay.className = "lq-lock-overlay";
        overlay.innerHTML =
            '<div class="lq-lock-card" role="dialog" aria-modal="true" aria-labelledby="lqLockTitle" aria-describedby="lqLockText">' +
            '<span class="lq-lock-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>' +
            '<h2 id="lqLockTitle" class="lq-lock-title">Session locked</h2>' +
            '<p id="lqLockText" class="lq-lock-text">You were inactive for a while, so LearnQuest locked to keep your account safe. ' +
            '<span>Enter the code we emailed to <strong class="lq-lock-email">your address</strong>.</span></p>' +
            '<form class="lq-lock-form" novalidate>' +
            '<input class="lq-lock-input" type="text" aria-label="Unlock code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••">' +
            '<p class="lq-lock-error" role="alert"></p>' +
            '<button type="submit" class="lq-lock-submit">Unlock</button>' +
            "</form>" +
            '<div class="lq-lock-links">' +
            '<button type="button" class="lq-lock-link lq-lock-resend">Resend code</button>' +
            '<button type="button" class="lq-lock-link lq-lock-signout">Sign out instead</button>' +
            "</div>" +
            "</div>";

        document.body.appendChild(overlay);

        ui = {
            overlay: overlay,
            email: overlay.querySelector(".lq-lock-email"),
            input: overlay.querySelector(".lq-lock-input"),
            error: overlay.querySelector(".lq-lock-error"),
            submit: overlay.querySelector(".lq-lock-submit"),
            resend: overlay.querySelector(".lq-lock-resend"),
        };

        overlay.querySelector("form").addEventListener("submit", submit);
        ui.resend.addEventListener("click", resend);
        overlay.querySelector(".lq-lock-signout").addEventListener("click", signOut);
        ui.input.addEventListener("input", function () {
            ui.input.value = ui.input.value.replace(/\D/g, "");
        });

        // Keep keyboard focus inside the card; the page behind is off-limits.
        overlay.addEventListener("keydown", function (event) {
            if (event.key !== "Tab") return;
            var focusable = overlay.querySelectorAll("input:not([disabled]), button:not([disabled])");
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        ui.input.focus();
    }

    function setError(message) {
        if (ui) ui.error.textContent = message || "";
    }

    function render(state) {
        if (!ui || !state) return;

        if (state.locked === false) {
            unlocked();
            return;
        }
        if (state.email) ui.email.textContent = state.email;
        if (state.length) ui.input.maxLength = state.length;

        var outOfAttempts = state.attempts_left === 0;
        ui.input.disabled = outOfAttempts;
        ui.submit.disabled = outOfAttempts;
        if (outOfAttempts) setError("Too many failed attempts. Request a new code to continue.");

        startResendCountdown(Number(state.resend_in) || 0);
    }

    function startResendCountdown(seconds) {
        clearInterval(resendTimer);
        if (!ui) return;

        var left = seconds;
        function tick() {
            if (!ui) return;
            ui.resend.disabled = left > 0;
            ui.resend.textContent = left > 0 ? "Resend in " + left + "s" : "Resend code";
            left--;
            if (left < 0) clearInterval(resendTimer);
        }
        tick();
        if (left >= 0) resendTimer = setInterval(tick, 1000);
    }

    /* INIT */
    listen(document);

    function watchFrame() {
        var frame = document.getElementById("contentFrame");
        if (!frame) return;
        frame.addEventListener("load", function () {
            try {
                listen(frame.contentDocument);
            } catch (e) {
                // ignore same-origin framing issues
            }
        });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", watchFrame);
    else watchFrame();

    setInterval(check, CHECK_MS);
    setInterval(pollStatus, STATUS_MS);
    // Timers sleep in background tabs; check as soon as the tab is back.
    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "visible") {
            check();
            pollStatus();
        }
    });

    window.LQ_applySecurity = function (newMinutes) {
        minutes = Number(newMinutes) || 0;
        config.idleLockMinutes = minutes;
        lastActivity = Date.now();
    };
})();
