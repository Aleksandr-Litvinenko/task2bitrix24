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

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
    window.addEventListener('pointermove', function (event) {
        addPoint(event.clientX, event.clientY);
    });
    window.addEventListener('pointerdown', function (event) {
        for (var i = 0; i < 18; i++) {
            addPoint(event.clientX + (Math.random() - 0.5) * 72, event.clientY + (Math.random() - 0.5) * 72, 4);
        }
    });
    requestAnimationFrame(draw);
}());
