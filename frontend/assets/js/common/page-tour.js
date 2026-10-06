/* Page tour: a page's own guided tour (common/guided-tour.js), shown once per
   account and replayable from any [data-tour-start] button on the page.

   LQPageTour.init({ key, steps, ready })

     key     General settings flag that records the tour was seen, e.g.
             "tour_seen_dashboard" (declared in User::generalPreferenceOptions)
     steps   function returning the LQGuidedTour steps
     ready   optional promise; the first-visit tour waits for it so the
             page's data is on screen before it points at anything

   The first-visit tour waits until the app tour (header and sidebar, run by
   the shell) has been seen, so the two never stack. When the app tour ends
   the shell calls LQPageTour.autoStart() to begin this page's tour. */
(function () {
    var config = null;
    var isReady = false;

    function preferences() {
        try {
            return (window.parent.LQ_HOST_CONFIG || {}).preferences || null;
        } catch (e) {
            return null;
        }
    }

    function csrfToken() {
        var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

    function markSeen() {
        var prefs = preferences();
        if (!prefs || prefs[config.key] === true) return;

        prefs[config.key] = true;
        var body = {};
        body[config.key] = true;

        fetch("/settings/general", {
            method: "PUT",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-XSRF-TOKEN": csrfToken(),
            },
            body: JSON.stringify(body),
        }).catch(function () {
            /* Not saved: the tour will simply be offered again next time. */
        });
    }

    function tourRunning() {
        if (window.LQGuidedTour && window.LQGuidedTour.isActive()) return true;
        try {
            return Boolean(window.parent.LQGuidedTour && window.parent.LQGuidedTour.isActive());
        } catch (e) {
            return false;
        }
    }

    function start() {
        if (!config || !window.LQGuidedTour || tourRunning()) return;
        window.LQGuidedTour.start(config.steps(), { onFinish: markSeen });
    }

    /* Start the tour if this account hasn't seen it. Outside the shell there
       is nowhere to remember it, so it never starts by itself there. */
    function autoStart() {
        if (!config || !isReady) return;

        var prefs = preferences();
        if (!prefs || prefs.app_tour_seen !== true || prefs[config.key] === true) return;
        start();
    }

    function init(options) {
        config = options;

        document.querySelectorAll("[data-tour-start]").forEach(function (button) {
            button.addEventListener("click", start);
        });

        Promise.resolve(options.ready)
            .catch(function () {
                /* A failed load still lets the tour run; steps use fallbacks. */
            })
            .then(function () {
                isReady = true;
                autoStart();
            });
    }

    window.LQPageTour = {
        init: init,
        start: start,
        autoStart: autoStart,
    };
})();
