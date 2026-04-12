/**
 * Змейка на дашборде: только клиент, без API.
 * Поле масштабируется под ширину контейнера (десктоп / мобильный).
 * Скорость настраивается; «еда» и змейка в стиле FixariVan (градиенты темы).
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'fixarivan_snake_best_v1';
    var STORAGE_SPEED = 'fixarivan_snake_speed_v1';
    var GRID = 20;

    /** мс между шагами */
    var SPEED_TICK = {
        slow: 178,
        normal: 125,
        fast: 82
    };

    var canvas = document.getElementById('snakeCanvas');
    var wrap = document.getElementById('snakeCanvasWrap');
    var overlay = document.getElementById('snakeOverlay');
    var scoreEl = document.getElementById('snakeScoreEl');
    var bestEl = document.getElementById('snakeBestEl');
    var speedSelect = document.getElementById('snakeSpeedSelect');
    var btnStart = document.getElementById('snakeBtnStart');
    var btnPause = document.getElementById('snakeBtnPause');
    var btnUp = document.getElementById('snakeBtnUp');
    var btnDown = document.getElementById('snakeBtnDown');
    var btnLeft = document.getElementById('snakeBtnLeft');
    var btnRight = document.getElementById('snakeBtnRight');

    if (!canvas || !wrap) {
        return;
    }

    var ctx = canvas.getContext('2d');
    var dpr = 1;
    var logicalSize = 400;
    var snake = [];
    var dir = { x: 1, y: 0 };
    var nextDir = { x: 1, y: 0 };
    /** @type {{ x: number, y: number, variant: number }} */
    var food = { x: 10, y: 10, variant: 0 };
    var score = 0;
    var best = 0;
    var running = false;
    var paused = false;
    var timerId = null;

    function loadBest() {
        try {
            var v = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
            return isNaN(v) ? 0 : v;
        } catch (e) {
            return 0;
        }
    }

    function saveBest(v) {
        try {
            localStorage.setItem(STORAGE_KEY, String(v));
        } catch (e) {
            /* ignore */
        }
    }

    function loadSpeedKey() {
        try {
            var s = localStorage.getItem(STORAGE_SPEED);
            if (s === 'slow' || s === 'normal' || s === 'fast') {
                return s;
            }
        } catch (e) {
            /* ignore */
        }
        return 'normal';
    }

    function saveSpeedKey(key) {
        try {
            localStorage.setItem(STORAGE_SPEED, key);
        } catch (e) {
            /* ignore */
        }
    }

    function getTickMs() {
        var key = speedSelect && speedSelect.value;
        if (key === 'slow' || key === 'fast') {
            return SPEED_TICK[key];
        }
        return SPEED_TICK.normal;
    }

    function randomFood() {
        var taken = {};
        var i;
        for (i = 0; i < snake.length; i++) {
            taken[snake[i].x + ',' + snake[i].y] = true;
        }
        var x;
        var y;
        var guard = 0;
        do {
            x = Math.floor(Math.random() * GRID);
            y = Math.floor(Math.random() * GRID);
            guard++;
        } while (taken[x + ',' + y] && guard < 800);
        food = {
            x: x,
            y: y,
            variant: Math.floor(Math.random() * 3)
        };
    }

    function resetGame() {
        snake = [
            { x: 4, y: 10 },
            { x: 3, y: 10 },
            { x: 2, y: 10 }
        ];
        dir = { x: 1, y: 0 };
        nextDir = { x: 1, y: 0 };
        score = 0;
        if (scoreEl) {
            scoreEl.textContent = '0';
        }
        randomFood();
    }

    function setOverlay(text, hidden) {
        if (!overlay) {
            return;
        }
        if (hidden) {
            overlay.classList.add('is-hidden');
            overlay.textContent = '';
        } else {
            overlay.classList.remove('is-hidden');
            overlay.textContent = text;
        }
    }

    function syncSpeedSelect() {
        if (speedSelect) {
            speedSelect.disabled = !!(running && !paused);
        }
    }

    function syncButtons() {
        if (btnPause) {
            btnPause.disabled = !running;
            btnPause.textContent = paused ? 'Продолжить' : 'Пауза';
        }
        var dis = !running || paused;
        [btnUp, btnDown, btnLeft, btnRight].forEach(function (b) {
            if (b) {
                b.disabled = dis;
            }
        });
        syncSpeedSelect();
    }

    function clearGameTimer() {
        if (timerId) {
            clearInterval(timerId);
            timerId = null;
        }
    }

    function startGameTimer() {
        clearGameTimer();
        timerId = setInterval(tick, getTickMs());
    }

    function cellSize() {
        return logicalSize / GRID;
    }

    function roundRectPath(x, y, w, h, r) {
        if (typeof ctx.roundRect === 'function') {
            ctx.beginPath();
            ctx.roundRect(x, y, w, h, r);
        } else {
            ctx.beginPath();
            ctx.rect(x, y, w, h);
        }
    }

    /**
     * Градиенты «еды» — палитра FixariVan (индиго / фиолетовый / акцент).
     */
    function foodGradient(x0, y0, x1, y1, variant) {
        var g = ctx.createLinearGradient(x0, y0, x1, y1);
        if (variant === 0) {
            g.addColorStop(0, '#a5b4fc');
            g.addColorStop(0.45, '#6366f1');
            g.addColorStop(1, '#7c3aed');
        } else if (variant === 1) {
            g.addColorStop(0, '#c4b5fd');
            g.addColorStop(0.5, '#8b5cf6');
            g.addColorStop(1, '#5b21b6');
        } else {
            g.addColorStop(0, '#6ee7b7');
            g.addColorStop(0.5, '#10b981');
            g.addColorStop(1, '#047857');
        }
        return g;
    }

    function drawFixarivanFood(cx, cy, cs) {
        var pad = Math.max(cs * 0.1, 1.5);
        var x = cx * cs + pad;
        var y = cy * cs + pad;
        var w = cs - pad * 2;
        var h = w;
        var r = Math.min(w * 0.28, cs * 0.22);

        ctx.save();
        ctx.shadowColor = 'rgba(99, 102, 241, 0.55)';
        ctx.shadowBlur = Math.max(cs * 0.12, 4);
        roundRectPath(x, y, w, h, r);
        ctx.fillStyle = foodGradient(x, y, x + w, y + h, food.variant);
        ctx.fill();
        ctx.shadowBlur = 0;

        ctx.strokeStyle = 'rgba(255, 255, 255, 0.38)';
        ctx.lineWidth = Math.max(cs * 0.035, 1);
        roundRectPath(x + 0.5, y + 0.5, w - 1, h - 1, r * 0.85);
        ctx.stroke();

        /* мини-«гайка» / сервис — абстрактная иконка в центре */
        var ix = x + w * 0.5;
        var iy = y + h * 0.52;
        var d = Math.max(cs * 0.14, 2);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.92)';
        ctx.beginPath();
        ctx.arc(ix, iy, d * 0.35, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = 'rgba(99, 102, 241, 0.9)';
        ctx.lineWidth = Math.max(1, cs * 0.04);
        ctx.beginPath();
        ctx.arc(ix, iy, d * 0.65, 0, Math.PI * 2);
        ctx.stroke();

        ctx.restore();
    }

    function drawSnakeSegment(seg, i, cs) {
        var pad = Math.max(cs * 0.06, 1);
        var x = seg.x * cs + pad;
        var y = seg.y * cs + pad;
        var w = cs - pad * 2;
        var h = w;
        var r = i === 0 ? Math.min(w * 0.32, cs * 0.2) : Math.min(w * 0.22, cs * 0.14);

        ctx.save();
        if (i === 0) {
            ctx.shadowColor = 'rgba(99, 102, 241, 0.45)';
            ctx.shadowBlur = Math.max(cs * 0.1, 3);
        }
        roundRectPath(x, y, w, h, r);
        var g = ctx.createLinearGradient(x, y, x + w, y + h);
        if (i === 0) {
            g.addColorStop(0, '#c7d2fe');
            g.addColorStop(0.5, '#818cf8');
            g.addColorStop(1, '#6366f1');
        } else {
            g.addColorStop(0, '#818cf8');
            g.addColorStop(1, '#4f46e5');
        }
        ctx.fillStyle = g;
        ctx.fill();
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.2)';
        ctx.lineWidth = Math.max(cs * 0.02, 0.8);
        roundRectPath(x + 0.4, y + 0.4, w - 0.8, h - 0.8, r * 0.9);
        ctx.stroke();
        ctx.restore();
    }

    function draw() {
        if (!ctx) {
            return;
        }
        var cs = cellSize();
        var i;

        ctx.fillStyle = '#070b14';
        ctx.fillRect(0, 0, logicalSize, logicalSize);

        var gBg = ctx.createRadialGradient(
            logicalSize * 0.35,
            logicalSize * 0.25,
            0,
            logicalSize * 0.45,
            logicalSize * 0.45,
            logicalSize * 0.75
        );
        gBg.addColorStop(0, 'rgba(99, 102, 241, 0.14)');
        gBg.addColorStop(1, 'transparent');
        ctx.fillStyle = gBg;
        ctx.fillRect(0, 0, logicalSize, logicalSize);

        ctx.strokeStyle = 'rgba(99, 102, 241, 0.14)';
        ctx.lineWidth = 1;
        for (i = 0; i <= GRID; i++) {
            var p = Math.round(i * cs) + 0.5;
            ctx.beginPath();
            ctx.moveTo(p, 0);
            ctx.lineTo(p, logicalSize);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(0, p);
            ctx.lineTo(logicalSize, p);
            ctx.stroke();
        }

        drawFixarivanFood(food.x, food.y, cs);

        for (i = snake.length - 1; i >= 0; i--) {
            drawSnakeSegment(snake[i], i, cs);
        }
    }

    function resizeCanvas() {
        if (!wrap) {
            return;
        }
        var w = wrap.clientWidth;
        if (w < 80) {
            return;
        }
        logicalSize = w;
        dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(w * dpr);
        canvas.style.width = w + 'px';
        canvas.style.height = w + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        draw();
    }

    function tick() {
        if (!running || paused) {
            return;
        }
        dir.x = nextDir.x;
        dir.y = nextDir.y;

        var head = snake[0];
        var nx = head.x + dir.x;
        var ny = head.y + dir.y;

        if (nx < 0 || nx >= GRID || ny < 0 || ny >= GRID) {
            gameOver();
            return;
        }

        var j;
        for (j = 0; j < snake.length; j++) {
            if (snake[j].x === nx && snake[j].y === ny) {
                gameOver();
                return;
            }
        }

        var ate = nx === food.x && ny === food.y;
        snake.unshift({ x: nx, y: ny });
        if (!ate) {
            snake.pop();
        } else {
            score += 1;
            if (scoreEl) {
                scoreEl.textContent = String(score);
            }
            if (score > best) {
                best = score;
                if (bestEl) {
                    bestEl.textContent = String(best);
                }
                saveBest(best);
            }
            randomFood();
        }

        draw();
    }

    function gameOver() {
        running = false;
        paused = false;
        clearGameTimer();
        syncButtons();
        setOverlay('Игра окончена. Нажмите «Старт»', false);
        if (btnStart) {
            btnStart.textContent = 'Ещё раз';
        }
    }

    function startGame() {
        resetGame();
        running = true;
        paused = false;
        setOverlay('', true);
        syncButtons();
        if (btnStart) {
            btnStart.textContent = 'Старт';
        }
        draw();
        startGameTimer();
    }

    function togglePause() {
        if (!running) {
            return;
        }
        paused = !paused;
        if (paused) {
            clearGameTimer();
            setOverlay('Пауза', false);
        } else {
            setOverlay('', true);
            startGameTimer();
            draw();
        }
        syncButtons();
    }

    function queueDir(dx, dy) {
        if (!running || paused) {
            return;
        }
        if (dir.x === -dx && dir.y === -dy) {
            return;
        }
        if (nextDir.x === -dx && nextDir.y === -dy) {
            return;
        }
        nextDir = { x: dx, y: dy };
    }

    best = loadBest();
    if (bestEl) {
        bestEl.textContent = String(best);
    }

    if (speedSelect) {
        speedSelect.value = loadSpeedKey();
        speedSelect.addEventListener('change', function () {
            saveSpeedKey(speedSelect.value);
            if (running && paused) {
                /* скорость применится при «Продолжить» */
            }
        });
    }

    resetGame();
    draw();
    setOverlay('Нажмите «Старт» или стрелку', false);
    syncButtons();

    if (btnStart) {
        btnStart.addEventListener('click', function () {
            startGame();
        });
    }
    if (btnPause) {
        btnPause.addEventListener('click', function () {
            togglePause();
        });
    }

    function bindDir(btn, dx, dy) {
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function () {
            if (!running) {
                startGame();
            }
            queueDir(dx, dy);
        });
    }
    bindDir(btnUp, 0, -1);
    bindDir(btnDown, 0, 1);
    bindDir(btnLeft, -1, 0);
    bindDir(btnRight, 1, 0);

    var ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(function () {
        resizeCanvas();
    }) : null;
    if (ro && wrap) {
        ro.observe(wrap);
    }
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    window.addEventListener('beforeunload', function () {
        clearGameTimer();
    });
})();
