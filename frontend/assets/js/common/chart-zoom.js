/* Zoom + pan for dashboard charts that get cramped on small screens.

   Any <canvas data-chart-zoom> inside a .chart-canvas-wrap gets − / % / +
   controls, pinch-to-zoom on touch screens and Ctrl + wheel on desktop. The
   canvas is moved into a layer that is sized to the zoom level, so Chart.js
   (responsive) simply redraws at the new size and the wrap scrolls to pan.

   Must load before the page script creates its charts, because Chart.js
   watches the canvas's parent for size changes. */
(function () {
    // Settings → General: whether radar labels start shown, and reduced motion.
    var preferences = {};
    try {
        preferences = (window.parent.LQ_HOST_CONFIG || {}).preferences || {};
    } catch (e) {
        // not inside the shell
    }
    var labelsOnByDefault = preferences.chart_labels !== false;

    if (window.Chart && preferences.motion === "reduced") {
        window.Chart.defaults.animation = false;
    }

    var MIN_ZOOM = 0.5;
    var MAX_ZOOM = 3;
    var STEP = 0.25;

    function button(label, title) {
        var el = document.createElement("button");
        el.type = "button";
        el.className = "chart-zoom-btn";
        el.title = title;
        el.setAttribute("aria-label", title);
        el.innerHTML = label;
        return el;
    }

    /* Radar ↔ bar switch for <canvas data-chart-view-toggle>. The view is
       kept on canvas.dataset.chartView (remembered per chart); the page
       rebuilds its chart on "lq:chartviewchange", reading the view through
       window.LQChartView. Chart-key items marked data-key-view="radar|bar"
       show only in their view. */
    var VIEW_STORAGE_PREFIX = "lq_chartView_";

    function savedView(canvas) {
        try {
            return localStorage.getItem(VIEW_STORAGE_PREFIX + canvas.id) === "bar" ? "bar" : "radar";
        } catch (e) {
            return "radar";
        }
    }

    function applyView(canvas, view, viewBtn, labelsBtn) {
        canvas.dataset.chartView = view;

        var toBar = view === "radar";
        viewBtn.innerHTML = toBar ? '<i class="fas fa-chart-column"></i>' : '<i class="fas fa-circle-nodes"></i>';
        viewBtn.title = toBar ? "Show as bar graph" : "Show as radar chart";
        viewBtn.setAttribute("aria-label", viewBtn.title);
        viewBtn.setAttribute("aria-pressed", String(view === "bar"));
        viewBtn.classList.toggle("active", view === "bar");

        // Bar graphs always show their axis labels.
        labelsBtn.hidden = view === "bar";

        var card = canvas.closest(".chart-card");
        if (card) {
            card.querySelectorAll("[data-key-view]").forEach(function (item) {
                item.hidden = item.getAttribute("data-key-view") !== view;
            });
        }
    }

    function viewToggle(canvas, labelsBtn) {
        var btn = button("", "");
        btn.classList.add("chart-view-toggle");
        applyView(canvas, savedView(canvas), btn, labelsBtn);

        btn.addEventListener("click", function () {
            var view = canvas.dataset.chartView === "bar" ? "radar" : "bar";
            applyView(canvas, view, btn, labelsBtn);
            try {
                localStorage.setItem(VIEW_STORAGE_PREFIX + canvas.id, view);
            } catch (e) {
                // storage unavailable — the choice lasts for this page only
            }
            canvas.dispatchEvent(new CustomEvent("lq:chartviewchange", { detail: { view: view } }));
        });

        return btn;
    }

    window.LQChartView = {
        /** "radar" or "bar" for this canvas. */
        get: function (canvas) {
            return (canvas && canvas.dataset.chartView) === "bar" ? "bar" : "radar";
        },

        /** Chart.js scales for the view, built from the page's radar scale. */
        scales: function (view, radarScale) {
            if (view !== "bar") return { r: radarScale };

            var labelColor = (radarScale.pointLabels && radarScale.pointLabels.color) || "#e2e8f0";
            var labelFont = (radarScale.pointLabels && radarScale.pointLabels.font) || { size: 12 };

            return {
                x: {
                    ticks: { color: labelColor, font: labelFont, autoSkip: false, maxRotation: 45 },
                    grid: { display: false },
                },
                y: {
                    min: radarScale.min,
                    max: radarScale.max,
                    ticks: {
                        stepSize: radarScale.ticks && radarScale.ticks.stepSize,
                        color: radarScale.ticks && radarScale.ticks.color,
                        callback: function (value) {
                            return value + "%";
                        },
                    },
                    grid: { color: radarScale.grid && radarScale.grid.color },
                },
            };
        },
    };

    function attach(canvas) {
        var wrap = canvas.closest(".chart-canvas-wrap");
        if (!wrap || wrap.classList.contains("chart-zoomable")) return;
        wrap.classList.add("chart-zoomable");

        var scroller = document.createElement("div");
        scroller.className = "chart-zoom-scroll";
        var layer = document.createElement("div");
        layer.className = "chart-zoom-layer";
        wrap.insertBefore(scroller, canvas);
        scroller.appendChild(layer);
        layer.appendChild(canvas);

        var zoomOut = button('<i class="fas fa-minus"></i>', "Zoom out");
        var reset = button("100%", "Reset zoom");
        reset.classList.add("chart-zoom-level");
        var zoomIn = button('<i class="fas fa-plus"></i>', "Zoom in");

        var labels = button(
            '<i class="fas fa-tag"></i>',
            labelsOnByDefault ? "Hide labels" : "Show labels",
        );
        labels.classList.add("chart-labels-toggle");
        labels.classList.toggle("active", labelsOnByDefault);
        labels.setAttribute("aria-pressed", String(labelsOnByDefault));
        if (!labelsOnByDefault) canvas.dataset.chartLabels = "off";

        labels.addEventListener("click", function () {
            var hide = canvas.dataset.chartLabels !== "off";
            canvas.dataset.chartLabels = hide ? "off" : "on";
            labels.classList.toggle("active", !hide);
            labels.setAttribute("aria-pressed", String(!hide));
            labels.title = hide ? "Show labels" : "Hide labels";
            labels.setAttribute("aria-label", labels.title);

            var chart = window.Chart && window.Chart.getChart(canvas);
            if (chart) chart.update();
        });

        var controls = document.createElement("div");
        controls.className = "chart-zoom-controls";
        if (canvas.hasAttribute("data-chart-view-toggle")) {
            controls.appendChild(viewToggle(canvas, labels));
        }
        controls.appendChild(labels);
        controls.appendChild(zoomOut);
        controls.appendChild(reset);
        controls.appendChild(zoomIn);
        wrap.appendChild(controls);

        var zoom = 1;

        function setZoom(value) {
            var next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));
            if (Math.abs(next - zoom) < 0.005) return;

            // Keep whatever is at the centre of the view in the centre.
            var centerX =
                (scroller.scrollLeft + scroller.clientWidth / 2) /
                scroller.scrollWidth;
            var centerY =
                (scroller.scrollTop + scroller.clientHeight / 2) /
                scroller.scrollHeight;

            zoom = next;
            layer.style.width = zoom * 100 + "%";
            layer.style.height = zoom * 100 + "%";

            scroller.scrollLeft =
                centerX * scroller.scrollWidth - scroller.clientWidth / 2;
            scroller.scrollTop =
                centerY * scroller.scrollHeight - scroller.clientHeight / 2;

            reset.textContent = Math.round(zoom * 100) + "%";
            zoomOut.disabled = zoom <= MIN_ZOOM;
            zoomIn.disabled = zoom >= MAX_ZOOM;
        }

        zoomOut.addEventListener("click", function () {
            setZoom(zoom - STEP);
        });
        zoomIn.addEventListener("click", function () {
            setZoom(zoom + STEP);
        });
        reset.addEventListener("click", function () {
            setZoom(1);
        });

        scroller.addEventListener(
            "wheel",
            function (event) {
                if (!event.ctrlKey) return;
                event.preventDefault();
                setZoom(zoom * (event.deltaY < 0 ? 1.1 : 0.9));
            },
            { passive: false },
        );

        // Two-finger pinch. One finger is left to the browser for panning.
        var pinchDistance = 0;
        var pinchZoom = 1;

        function touchDistance(touches) {
            return Math.hypot(
                touches[0].clientX - touches[1].clientX,
                touches[0].clientY - touches[1].clientY,
            );
        }

        scroller.addEventListener(
            "touchstart",
            function (event) {
                if (event.touches.length !== 2) return;
                pinchDistance = touchDistance(event.touches);
                pinchZoom = zoom;
            },
            { passive: true },
        );
        scroller.addEventListener(
            "touchmove",
            function (event) {
                if (event.touches.length !== 2 || !pinchDistance) return;
                event.preventDefault();
                setZoom(
                    (pinchZoom * touchDistance(event.touches)) / pinchDistance,
                );
            },
            { passive: false },
        );
        scroller.addEventListener("touchend", function (event) {
            if (event.touches.length < 2) pinchDistance = 0;
        });
    }

    // The labels toggle is stored on the canvas and applied on every update,
    // so it survives the dashboards destroying and rebuilding their charts.
    if (window.Chart) {
        window.Chart.register({
            id: "lqLabelsToggle",
            beforeUpdate: function (chart) {
                var canvas = chart.canvas;
                var scale = chart.options.scales && chart.options.scales.r;
                if (!canvas || !scale) return;
                if (!canvas.hasAttribute("data-chart-zoom")) return;
                // Set it both ways: charts on a page can share one scale
                // options object, so each must state its own value.
                scale.pointLabels.display = canvas.dataset.chartLabels !== "off";
            },
        });
    }

    document.querySelectorAll("canvas[data-chart-zoom]").forEach(attach);
})();
