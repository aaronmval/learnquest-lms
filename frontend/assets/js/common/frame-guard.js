/* FRAME GUARD
   Content pages under /pages/professor/ and /pages/student/ carry no header
   or sidebar of their own — the navbar only exists in the shell
   (components/navbar/navbar.html), which loads these pages into
   <iframe id="contentFrame">. If one of these pages is ever reached as a
   top-level document instead — a bookmark, a shared link, "open in new tab",
   middle-click, browser autocomplete — it renders with no navbar at all.

   This script detects that case and bounces the browser into the proper
   shell, remembering which page was requested so the shell opens straight
   to it instead of its default Home page. Must run as early as possible
   (synchronous, no defer/async, placed right after <meta charset>) so it
   fires before the page has a chance to paint. */
(function () {
    try {
        if (window.top !== window.self) {
            // Already inside the shell's iframe. Apply the account's text
            // size and motion settings (Settings → General) before first
            // paint, so the page doesn't flash at the default size.
            var preferences =
                (window.parent.LQ_HOST_CONFIG || {}).preferences || {};
            var root = document.documentElement;

            if (preferences.text_size === "small") root.classList.add("lq-text-small");
            if (preferences.text_size === "large") root.classList.add("lq-text-large");
            if (preferences.motion === "reduced") root.classList.add("lq-reduced-motion");
            return;
        }

        var match = window.location.pathname.match(/\/pages\/(professor|student)\//);
        if (!match) return;

        var role = match[1];
        var pendingPage =
            window.location.pathname + window.location.search + window.location.hash;

        try {
            sessionStorage.setItem("LQ_PENDING_PAGE", pendingPage);
        } catch (e) {
            // sessionStorage unavailable (private mode, etc.) — the shell will
            // just fall back to its default page for the role, which is still
            // far better than no navbar at all.
        }

        window.location.replace("/" + role + "/dashboard");
    } catch (e) {
        // Never let the guard itself break the page.
    }
})();
