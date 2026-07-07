// Fetches and injects shared components (navbar + pdf-modal).
(async function () {
    // Try a reasonable relative path from pages/* to components
    const navbarPath = "../../components/navbar/navbar.html";
    const pdfModalPath = "../../components/pdf-modal/pdf-modal.html";
    const navbarScriptPath = "../../components/navbar/navbar.js";
    const navbarCssPath = "../../components/navbar/navbar.css";
    const componentsCssPath = "../../assets/css/base/components.css";

    function insertCssIfMissing(href) {
        if (
            !document.querySelector(
                'link[href$="' + href.split("/").slice(-3).join("/") + '"]',
            )
        ) {
            const l = document.createElement("link");
            l.rel = "stylesheet";
            l.href = href;
            document.head.appendChild(l);
        }
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector('script[src="' + src + '"]'))
                return resolve();
            const s = document.createElement("script");
            s.src = src;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error("Failed to load " + src));
            document.body.appendChild(s);
        });
    }

    async function fetchText(path) {
        try {
            const r = await fetch(path);
            if (!r.ok) return null;
            return await r.text();
        } catch (e) {
            return null;
        }
    }

    // ensure components.css available
    insertCssIfMissing(componentsCssPath);
    // ensure navbar styles available
    insertCssIfMissing(navbarCssPath);

    // Fetch navbar HTML
    const navbarText = await fetchText(navbarPath);
    if (navbarText) {
        const bodyMatch = navbarText.match(/<body[^>]*>([\s\S]*)<\/body>/i);
        const fragment = document.createElement("div");
        fragment.innerHTML = bodyMatch ? bodyMatch[1] : navbarText;

        // Replace existing header if present
        const newHeader = fragment.querySelector("header");
        if (newHeader) {
            const oldHeader = document.querySelector("header");
            if (oldHeader) {
                oldHeader.replaceWith(newHeader);
            } else {
                document.body.insertAdjacentElement("afterbegin", newHeader);
            }
        }

        // Replace or insert sidebar (choose variant by stored role)
        (function insertRoleSidebar() {
            const storedRole =
                (window.localStorage &&
                    localStorage.getItem &&
                    localStorage.getItem("LQ_USER_ROLE")) ||
                null;
            // prefer professor variant when set and available
            let sidebarEl = null;
            if (storedRole === "professor") {
                sidebarEl = fragment.querySelector("aside#sidebar-professor");
            }
            // fallback to default student sidebar
            if (!sidebarEl)
                sidebarEl =
                    fragment.querySelector("aside#sidebar") ||
                    fragment.querySelector("aside");

            if (sidebarEl) {
                // Normalize id to `sidebar` so scripts expect the same id
                try {
                    sidebarEl.id = "sidebar";
                } catch (e) {
                    // ignore
                }

                const oldSidebar = document.getElementById("sidebar");
                if (oldSidebar) {
                    oldSidebar.replaceWith(sidebarEl);
                } else {
                    const bodyArea =
                        document.querySelector(".body-area") || document.body;
                    bodyArea.insertAdjacentElement("afterbegin", sidebarEl);
                }
            }
        })();

        // Insert other modals (logout, join class, toast) if missing
        ["#logoutModal", "#joinClassModal", "#toast", "#notiDrawer"].forEach(
            (sel) => {
                const el = fragment.querySelector(sel);
                if (el && !document.querySelector(sel))
                    document.body.insertAdjacentElement("beforeend", el);
            },
        );

        // If pdf modal not present, insert it (pdf modal CSS is in components.css)
        const newPdf = await fetchText(pdfModalPath);
        if (newPdf && !document.getElementById("pdfModal")) {
            const wrap = document.createElement("div");
            wrap.innerHTML = newPdf;
            const pdfEl = wrap.querySelector("#pdfModal");
            if (pdfEl) document.body.insertAdjacentElement("beforeend", pdfEl);
        }

        // Load navbar script AFTER elements are in DOM
        try {
            await loadScript(navbarScriptPath);
        } catch (e) {
            // best-effort; console log
            console.warn("Could not load navbar script:", e);
        }

        // Fix dashboard link to route to role-specific dashboard
        (function patchDashboardLink() {
            const navDashboard =
                document.getElementById("nav-dashboard") ||
                document.querySelector('a[href="/dashboard/index.html"]');
            if (!navDashboard) return;

            navDashboard.addEventListener("click", function (e) {
                e.preventDefault();
                // prefer explicit role in localStorage if set
                const stored =
                    (window.localStorage &&
                        localStorage.getItem &&
                        localStorage.getItem("LQ_USER_ROLE")) ||
                    null;
                let isProfessor = false;
                if (stored) {
                    isProfessor = stored === "professor";
                } else {
                    const path = window.location.pathname.toLowerCase();
                    isProfessor = path.includes("/professor/");
                }
                const base =
                    (window.location.pathname.split("/pages/")[0] ||
                        window.location.pathname) + "/pages/";
                const target = isProfessor
                    ? base + "professor/professor-dashboard.html"
                    : base + "student/student-dashboard.html";
                window.location.href = target;
            });
        })();
    }
})();
