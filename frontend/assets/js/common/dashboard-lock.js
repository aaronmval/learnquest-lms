/* Privacy cover for the student and professor dashboards.

   The dashboard page calls LQDashboardLock.whenUnlocked(start) instead of
   loading its data straight away. While locked, a cover fills the page and
   nothing is fetched or drawn, so no mastery or score data sits behind it.
   Clicking "Show dashboard" unlocks it for UNLOCK_MS in this browser tab.

   This is a cover against onlookers, not access control: the signed-in user
   can always reveal it. It can be turned off in Settings → General
   ("Lock My Dashboard"). */
(function () {
    var UNLOCK_MS = 15 * 60 * 1000;
    var STORAGE_KEY = "LQ_DASHBOARD_UNLOCKED_AT";

    function lockEnabled() {
        try {
            var preferences =
                (window.parent.LQ_HOST_CONFIG || {}).preferences || {};
            return preferences.dashboard_lock !== false;
        } catch (e) {
            // Not inside the shell: stay on the safe side.
            return true;
        }
    }

    function recentlyUnlocked() {
        try {
            var unlockedAt = Number(sessionStorage.getItem(STORAGE_KEY));
            return unlockedAt > 0 && Date.now() - unlockedAt < UNLOCK_MS;
        } catch (e) {
            return false;
        }
    }

    function rememberUnlock() {
        try {
            sessionStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch (e) {
            // Storage unavailable: the cover simply shows on every visit.
        }
    }

    function lockNow() {
        try {
            sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // ignore storage issues
        }
        window.location.reload();
    }

    function showCover(onReveal) {
        var cover = document.createElement("div");
        cover.className = "dashboard-lock";
        cover.setAttribute("role", "dialog");
        cover.setAttribute("aria-modal", "true");
        cover.setAttribute("aria-labelledby", "dashboardLockTitle");
        cover.innerHTML =
            '<div class="dashboard-lock-card">' +
            '<span class="dashboard-lock-icon"><i class="fas fa-lock"></i></span>' +
            '<h2 id="dashboardLockTitle" class="dashboard-lock-title">Dashboard hidden</h2>' +
            '<p class="dashboard-lock-text">Your dashboard shows mastery and quiz results. ' +
            "It stays covered until you choose to show it.</p>" +
            '<button type="button" class="dashboard-lock-btn">' +
            '<i class="fas fa-eye"></i> Show dashboard</button>' +
            '<p class="dashboard-lock-hint">Stays visible for 15 minutes. ' +
            "You can turn this cover off in Settings → General.</p>" +
            "</div>";

        var revealButton = cover.querySelector(".dashboard-lock-btn");
        revealButton.addEventListener("click", function () {
            rememberUnlock();
            cover.remove();
            onReveal();
        });

        document.body.appendChild(cover);
        revealButton.focus();
    }

    function whenReady(fn) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", fn);
        } else {
            fn();
        }
    }

    window.LQDashboardLock = {
        /* Runs `start` once the dashboard may be shown. */
        whenUnlocked: function (start) {
            whenReady(function () {
                var enabled = lockEnabled();

                document
                    .querySelectorAll("[data-dashboard-lock-now]")
                    .forEach(function (lockButton) {
                        lockButton.classList.toggle("hidden", !enabled);
                        lockButton.addEventListener("click", lockNow);
                    });

                if (!enabled || recentlyUnlocked()) {
                    start();
                } else {
                    showCover(start);
                }
            });
        },
    };
})();
