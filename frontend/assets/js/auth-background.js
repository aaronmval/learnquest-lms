// Animated "screensaver" background shared by the auth pages (login, forgot
// password, OTP, reset password): glowing orbs bounce slowly around the
// viewport behind a drifting network of linked points. Purely decorative.
(function () {
    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1;pointer-events:none;';

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Reduced by the device, or by Settings → General on this device.
    let reducedBySetting = false;
    try {
        reducedBySetting = localStorage.getItem('lqMotion') === 'reduced';
    } catch (e) {
        // storage unavailable
    }
    const reduceMotion = reducedBySetting || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const ORB_COLORS = ['94,200,255', '59,130,246', '168,85,247', '34,211,238'];
    const LINK_DISTANCE = 140;

    let width = 0;
    let height = 0;
    let orbs = [];
    let points = [];
    let lastTime = 0;

    function random(min, max) {
        return min + Math.random() * (max - min);
    }

    function velocity(speed) {
        const angle = random(0, Math.PI * 2);
        return { vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed };
    }

    function resize() {
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function createScene() {
        const size = Math.max(width, height);

        orbs = ORB_COLORS.map((color) => Object.assign({
            x: random(0, width),
            y: random(0, height),
            radius: random(size * 0.16, size * 0.28),
            color: color,
        }, velocity(random(18, 34))));

        const count = Math.min(80, Math.round(width * height / 16000));
        points = Array.from({ length: count }, () => Object.assign({
            x: random(0, width),
            y: random(0, height),
            radius: random(1, 2.4),
        }, velocity(random(8, 22))));
    }

    // Moves an item and bounces it off the viewport edges.
    function move(item, seconds) {
        item.x += item.vx * seconds;
        item.y += item.vy * seconds;
        if (item.x < 0 || item.x > width) {
            item.vx = -item.vx;
            item.x = Math.min(Math.max(item.x, 0), width);
        }
        if (item.y < 0 || item.y > height) {
            item.vy = -item.vy;
            item.y = Math.min(Math.max(item.y, 0), height);
        }
    }

    function draw() {
        ctx.clearRect(0, 0, width, height);

        orbs.forEach((orb) => {
            const glow = ctx.createRadialGradient(orb.x, orb.y, 0, orb.x, orb.y, orb.radius);
            glow.addColorStop(0, 'rgba(' + orb.color + ',0.30)');
            glow.addColorStop(1, 'rgba(' + orb.color + ',0)');
            ctx.fillStyle = glow;
            ctx.beginPath();
            ctx.arc(orb.x, orb.y, orb.radius, 0, Math.PI * 2);
            ctx.fill();
        });

        ctx.lineWidth = 1;
        for (let i = 0; i < points.length; i++) {
            for (let j = i + 1; j < points.length; j++) {
                const dx = points[i].x - points[j].x;
                const dy = points[i].y - points[j].y;
                const distance = Math.sqrt(dx * dx + dy * dy);
                if (distance < LINK_DISTANCE) {
                    ctx.strokeStyle = 'rgba(255,255,255,' + (0.22 * (1 - distance / LINK_DISTANCE)).toFixed(3) + ')';
                    ctx.beginPath();
                    ctx.moveTo(points[i].x, points[i].y);
                    ctx.lineTo(points[j].x, points[j].y);
                    ctx.stroke();
                }
            }
        }

        ctx.fillStyle = 'rgba(255,255,255,0.75)';
        points.forEach((point) => {
            ctx.beginPath();
            ctx.arc(point.x, point.y, point.radius, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    function frame(now) {
        // Clamp the step so returning to a background tab doesn't jump.
        const seconds = Math.min((now - lastTime) / 1000, 0.05);
        lastTime = now;
        orbs.forEach((orb) => move(orb, seconds));
        points.forEach((point) => move(point, seconds));
        draw();
        requestAnimationFrame(frame);
    }

    function start() {
        document.body.appendChild(canvas);
        resize();
        createScene();
        draw();

        window.addEventListener('resize', () => {
            resize();
            if (reduceMotion) {
                createScene();
                draw();
            }
        });

        if (!reduceMotion) {
            lastTime = performance.now();
            requestAnimationFrame(frame);
        }
    }

    if (document.body) {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
    }
})();
