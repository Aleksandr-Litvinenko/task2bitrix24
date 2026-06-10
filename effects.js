(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var canvas = document.getElementById('cursorTrail');
    var context = canvas && canvas.getContext ? canvas.getContext('2d') : null;

    if (!context || reduceMotion) {
        return;
    }

    var points = [];
    var pointer = {
        x: window.innerWidth / 2,
        y: window.innerHeight / 2
    };
    var dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 2));

    function resizeCanvas() {
        dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 2));
        canvas.width = Math.floor(window.innerWidth * dpr);
        canvas.height = Math.floor(window.innerHeight * dpr);
        canvas.style.width = window.innerWidth + 'px';
        canvas.style.height = window.innerHeight + 'px';
        context.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function addPoint(x, y, scale) {
        scale = scale || 1;
        pointer.x = x;
        pointer.y = y;
        points.push({
            x: x,
            y: y,
            createdAt: performance.now(),
            scale: scale,
            life: scale > 1 ? 920 : 760,
            vx: (Math.random() - 0.5) * 0.35 * scale,
            vy: (Math.random() - 0.5) * 0.35 * scale
        });

        if (points.length > 72) {
            points.shift();
        }
    }

    function draw() {
        var now = performance.now();
        context.clearRect(0, 0, window.innerWidth, window.innerHeight);
        points = points.filter(function (point) {
            return now - point.createdAt < point.life;
        });

        for (var i = 0; i < points.length; i++) {
            var point = points[i];
            var age = (now - point.createdAt) / point.life;
            var opacity = Math.max(0, 1 - age);
            var scale = point.scale || 1;
            point.x += point.vx;
            point.y += point.vy;

            if (i > 0) {
                var previous = points[i - 1];
                context.beginPath();
                context.moveTo(previous.x, previous.y);
                context.lineTo(point.x, point.y);
                context.strokeStyle = 'rgba(96, 239, 255, ' + (opacity * 0.26) + ')';
                context.lineWidth = Math.max(1, scale * 0.45);
                context.stroke();
            }

            context.beginPath();
            context.arc(point.x, point.y, (2.2 + opacity * 3.4) * scale, 0, Math.PI * 2);
            context.fillStyle = 'rgba(75, 255, 196, ' + (opacity * (scale > 1 ? 0.2 : 0.32)) + ')';
            context.fill();
        }

        context.beginPath();
        context.arc(pointer.x, pointer.y, 12, 0, Math.PI * 2);
        context.strokeStyle = 'rgba(249, 199, 79, 0.24)';
        context.lineWidth = 1;
        context.stroke();

        requestAnimationFrame(draw);
    }

    /* Мини-игра «терпеливый клик»: пауза между кликами от 5 секунд
       растит серию (+1 к счёту) и размер вспышки; ранний клик сбрасывает серию.
       После 5 кликов в правом верхнем углу появляется таблица лидеров. */
    var GAME_GAP_MS = 5000;
    var totalClicks = 0;
    var streak = 0;
    var lastClickAt = 0;
    var highScore = 0;
    var leaderEl = null;

    try {
        highScore = parseInt(localStorage.getItem('ccHighScore') || '0', 10) || 0;
    } catch (error) {
        highScore = 0;
    }

    function loadLeaders() {
        try {
            var parsed = JSON.parse(localStorage.getItem('ccLeaders') || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function saveLeaders(leaders) {
        try {
            localStorage.setItem('ccLeaders', JSON.stringify(leaders));
        } catch (error) {
            /* localStorage недоступен — игра работает без сохранения */
        }
    }

    function todayLabel() {
        var now = new Date();
        return ('0' + now.getDate()).slice(-2) + '.' + ('0' + (now.getMonth() + 1)).slice(-2) + '.' + now.getFullYear();
    }

    function recordScore(score) {
        if (score <= 0) {
            return;
        }

        var leaders = loadLeaders();
        var label = todayLabel();
        var entry = null;

        for (var i = 0; i < leaders.length; i++) {
            if (leaders[i] && leaders[i].date === label) {
                entry = leaders[i];
                break;
            }
        }

        if (entry) {
            if (score > entry.score) {
                entry.score = score;
            }
        } else {
            leaders.push({ date: label, score: score });
        }

        leaders.sort(function (left, right) {
            return right.score - left.score;
        });
        saveLeaders(leaders.slice(0, 5));
    }

    function renderLeaderboard() {
        if (!leaderEl) {
            leaderEl = document.createElement('div');
            leaderEl.className = 'cc-leader';
            leaderEl.setAttribute('aria-hidden', 'true');
            document.body.appendChild(leaderEl);
        }

        var leaders = loadLeaders();
        var rows = '';

        for (var i = 0; i < leaders.length; i++) {
            rows += '<div class="cc-leader-row"><span>' + (i + 1) + '. ' + leaders[i].date + '</span><strong>' + leaders[i].score + '</strong></div>';
        }

        if (rows === '') {
            rows = '<div class="cc-leader-row"><span>Выдержите 5 секунд между кликами</span></div>';
        }

        leaderEl.innerHTML =
            '<div class="cc-leader-title">Таблица лидеров</div>' +
            '<div class="cc-leader-row cc-leader-current"><span>Текущая серия</span><strong>' + streak + '</strong></div>' +
            '<div class="cc-leader-row"><span>Рекорд</span><strong>' + highScore + '</strong></div>' +
            rows;
    }

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
    window.addEventListener('pointermove', function (event) {
        addPoint(event.clientX, event.clientY);
    });
    window.addEventListener('pointerdown', function (event) {
        var now = performance.now();
        var qualifies = lastClickAt === 0 || (now - lastClickAt) >= GAME_GAP_MS;

        if (qualifies) {
            streak += 1;
        } else {
            streak = 0;
        }

        if (streak > highScore) {
            highScore = streak;
            try {
                localStorage.setItem('ccHighScore', String(highScore));
            } catch (error) {
                /* без сохранения */
            }
        }

        recordScore(streak);
        lastClickAt = now;
        totalClicks += 1;

        var burstScale = Math.min(4 + streak * 1.5, 16);
        var burstSpread = 72 + streak * 18;
        var burstCount = Math.min(18 + streak * 6, 60);

        for (var i = 0; i < burstCount; i++) {
            addPoint(event.clientX + (Math.random() - 0.5) * burstSpread, event.clientY + (Math.random() - 0.5) * burstSpread, burstScale);
        }

        if (totalClicks >= 5 || leaderEl) {
            renderLeaderboard();
        }
    });
    requestAnimationFrame(draw);
}());
