/**
 * Змейка в клиентском портале: модалка, общий рекорд через api/portal_snake_score.php
 */
(function () {
    'use strict';

    var cfg = window.__FIXARIVAN_PORTAL_SNAKE__;
    if (!cfg || !cfg.token) {
        return;
    }

    var GRID = 20;
    var SPEED = { slow: 178, normal: 125, fast: 82 };

    var fab = document.getElementById('portalSnakeFab');
    var modal = document.getElementById('portalSnakeModal');
    var backdrop = document.getElementById('portalSnakeBackdrop');
    var btnClose = document.getElementById('portalSnakeClose');
    var globalEl = document.getElementById('portalSnakeGlobalBest');
    var yourEl = document.getElementById('portalSnakeYourScore');
    var toast = document.getElementById('portalSnakeToast');
    var canvas = document.getElementById('portalSnakeCanvas');
    var wrap = document.getElementById('portalSnakeCanvasWrap');
    var speedSel = document.getElementById('portalSnakeSpeed');
    var btnStart = document.getElementById('portalSnakeStart');
    var btnPause = document.getElementById('portalSnakePause');
    var btnUp = document.getElementById('portalSnakeUp');
    var btnDown = document.getElementById('portalSnakeDown');
    var btnLeft = document.getElementById('portalSnakeLeft');
    var btnRight = document.getElementById('portalSnakeRight');

    if (!fab || !modal || !canvas || !wrap) {
        return;
    }

    var ctx = canvas.getContext('2d');
    var i18n = cfg.i18n || {};
    var logicalSize = 300;
    var snake = [];
    var dir = { x: 1, y: 0 };
    var nextDir = { x: 1, y: 0 };
    var food = { x: 10, y: 10, variant: 0 };
    var score = 0;
    var globalBest = 0;
    var running = false;
    var paused = false;
    var timerId = null;

    function getTickMs() {
        var k = speedSel && speedSel.value;
        return SPEED[k] || SPEED.normal;
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
        food = { x: x, y: y, variant: Math.floor(Math.random() * 3) };
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
        if (yourEl) {
            yourEl.textContent = '0';
        }
        randomFood();
    }

    function syncSpeed() {
        if (speedSel) {
            speedSel.disabled = !!(running && !paused);
        }
    }

    function syncPauseBtn() {
        if (btnPause) {
            btnPause.disabled = !running;
            btnPause.textContent = paused ? (i18n.resume || 'Continue') : (i18n.pause || 'Pause');
        }
        var dis = !running || paused;
        [btnUp, btnDown, btnLeft, btnRight].forEach(function (b) {
            if (b) {
                b.disabled = dis;
            }
        });
        syncSpeed();
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
        var pad = Math.max(cs * 0.1, 1.2);
        var x = cx * cs + pad;
        var y = cy * cs + pad;
        var w = cs - pad * 2;
        var h = w;
        var r = Math.min(w * 0.28, cs * 0.22);
        ctx.save();
        ctx.shadowColor = 'rgba(99, 102, 241, 0.5)';
        ctx.shadowBlur = Math.max(cs * 0.1, 3);
        roundRectPath(x, y, w, h, r);
        ctx.fillStyle = foodGradient(x, y, x + w, y + h, food.variant);
        ctx.fill();
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
        ctx.lineWidth = Math.max(cs * 0.03, 0.8);
        roundRectPath(x + 0.4, y + 0.4, w - 0.8, h - 0.8, r * 0.85);
        ctx.stroke();
        ctx.restore();
    }

    function drawSnakeSegment(seg, i, cs) {
        var pad = Math.max(cs * 0.06, 0.8);
        var x = seg.x * cs + pad;
        var y = seg.y * cs + pad;
        var w = cs - pad * 2;
        var r = i === 0 ? Math.min(w * 0.3, cs * 0.18) : Math.min(w * 0.2, cs * 0.12);
        ctx.save();
        if (i === 0) {
            ctx.shadowColor = 'rgba(99, 102, 241, 0.4)';
            ctx.shadowBlur = Math.max(cs * 0.08, 2);
        }
        roundRectPath(x, y, w, w, r);
        var g = ctx.createLinearGradient(x, y, x + w, y + w);
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
        gBg.addColorStop(0, 'rgba(99, 102, 241, 0.12)');
        gBg.addColorStop(1, 'transparent');
        ctx.fillStyle = gBg;
        ctx.fillRect(0, 0, logicalSize, logicalSize);
        ctx.strokeStyle = 'rgba(99, 102, 241, 0.12)';
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
        if (w < 60) {
            return;
        }
        logicalSize = w;
        var dpr = window.devicePixelRatio || 1;
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
            if (yourEl) {
                yourEl.textContent = String(score);
            }
            randomFood();
        }
        draw();
    }

    function submitScore(finalScore) {
        if (finalScore <= 0) {
            return;
        }
        fetch(cfg.api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: cfg.token, score: finalScore })
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    return;
                }
                globalBest = typeof data.best === 'number' ? data.best : globalBest;
                if (globalEl) {
                    globalEl.textContent = String(globalBest);
                }
                if (data.improved && toast) {
                    toast.textContent = i18n.newRecord || 'New record!';
                    toast.hidden = false;
                    window.setTimeout(function () {
                        toast.hidden = true;
                    }, 3200);
                }
            })
            .catch(function () {
                /* ignore */
            });
    }

    function gameOver() {
        var finalScore = score;
        running = false;
        paused = false;
        clearGameTimer();
        syncPauseBtn();
        submitScore(finalScore);
        if (btnStart) {
            btnStart.textContent = i18n.again || 'Again';
        }
    }

    function startGame() {
        resetGame();
        running = true;
        paused = false;
        if (toast) {
            toast.hidden = true;
        }
        syncPauseBtn();
        if (btnStart) {
            btnStart.textContent = i18n.start || 'Start';
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
        } else {
            startGameTimer();
            draw();
        }
        syncPauseBtn();
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

    function fetchBest() {
        if (globalEl) {
            globalEl.textContent = '…';
        }
        fetch(cfg.api, { method: 'GET', cache: 'no-store' })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (data && data.success && typeof data.best === 'number') {
                    globalBest = data.best;
                    if (globalEl) {
                        globalEl.textContent = String(globalBest);
                    }
                } else if (globalEl) {
                    globalEl.textContent = '0';
                }
            })
            .catch(function () {
                if (globalEl) {
                    globalEl.textContent = '—';
                }
                if (toast) {
                    toast.textContent = i18n.loadError || '';
                    toast.hidden = false;
                    window.setTimeout(function () {
                        toast.hidden = true;
                    }, 2500);
                }
            });
    }

    function openModal() {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        fetchBest();
        resetGame();
        running = false;
        paused = false;
        clearGameTimer();
        syncPauseBtn();
        if (btnStart) {
            btnStart.textContent = i18n.start || 'Start';
        }
        resizeCanvas();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        running = false;
        paused = false;
        clearGameTimer();
        if (toast) {
            toast.hidden = true;
        }
    }

    fab.addEventListener('click', openModal);
    if (backdrop) {
        backdrop.addEventListener('click', closeModal);
    }
    if (btnClose) {
        btnClose.addEventListener('click', closeModal);
    }
    if (btnStart) {
        btnStart.addEventListener('click', startGame);
    }
    if (btnPause) {
        btnPause.addEventListener('click', togglePause);
    }
    if (speedSel) {
        speedSel.addEventListener('change', function () {
            if (running && paused) {
                /* применится при снятии паузы */
            }
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

    document.addEventListener('keydown', function (e) {
        if (modal.hidden) {
            return;
        }
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    var ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(resizeCanvas) : null;
    if (ro && wrap) {
        ro.observe(wrap);
    }

    window.addEventListener('beforeunload', clearGameTimer);
})();
