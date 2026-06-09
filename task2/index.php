<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

[$monthStart, $periodStart, $periodEnd] = monthPeriod($period);
$styleVersion = is_file(__DIR__ . '/style.css') ? (string)filemtime(__DIR__ . '/style.css') : '1';
$selectedYear = (int)$monthStart->format('Y');
$selectedMonth = (int)$monthStart->format('n');
$monthSelectNames = [
    1 => 'Январь',
    2 => 'Февраль',
    3 => 'Март',
    4 => 'Апрель',
    5 => 'Май',
    6 => 'Июнь',
    7 => 'Июль',
    8 => 'Август',
    9 => 'Сентябрь',
    10 => 'Октябрь',
    11 => 'Ноябрь',
    12 => 'Декабрь',
];
$firstYear = min((int)date('Y') - 3, $selectedYear);
$lastYear = max((int)date('Y') + 1, $selectedYear);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Закрытые часы</title>
    <link rel="stylesheet" href="style.css?v=<?= h($styleVersion) ?>">
</head>
<body>
<div class="ambient-glow" aria-hidden="true"></div>
<canvas id="cursorTrail" aria-hidden="true"></canvas>
<main class="shell">
    <section class="panel">
        <div class="panel-grid" aria-hidden="true"></div>
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / XLSX</p>
                <h1>Закрытые часы</h1>
            </div>
            <span id="monthTitle" class="month-chip"><?= h(russianMonthTitle($monthStart)) ?></span>
        </div>

        <form class="report-form" action="report.php" method="get">
            <label for="periodMonth">Месяц отчёта</label>
            <div class="controls">
                <input id="period" name="period" type="hidden" value="<?= h($period) ?>">
                <div class="period-picker">
                    <select id="periodMonth" aria-label="Месяц отчёта">
                        <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                            <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>>
                                <?= h($monthName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="periodYear" aria-label="Год отчёта">
                        <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                            <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>>
                                <?= h((string)$year) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit">
                    <span>Скачать Excel</span>
                </button>
            </div>
        </form>

        <dl class="meta">
            <div>
                <dt>Месяц результата</dt>
                <dd id="resultMonthValue"><?= h($monthStart->format('m.Y')) ?></dd>
            </div>
            <div>
                <dt>Закрыты с</dt>
                <dd id="closedFromValue"><?= h($periodStart->format('d.m.Y')) ?></dd>
            </div>
            <div>
                <dt>Закрыты по</dt>
                <dd id="closedToValue"><?= h($periodEnd->format('d.m.Y')) ?></dd>
            </div>
        </dl>

    </section>

    <section class="panel quick-panel" aria-labelledby="quickLinksTitle">
        <div class="panel-grid" aria-hidden="true"></div>
        <div class="quick-links">
            <h2 id="quickLinksTitle">Полезные кнопки</h2>
            <div class="quick-link-grid">
                <a href="https://task.kodar-msk.ru/" target="_blank" rel="noopener noreferrer">Наш таск</a>
                <a href="https://nalog1c.bitrix24.ru/" target="_blank" rel="noopener noreferrer">Наш битрикс</a>
                <a href="https://1cfresh.com/a/sbm/767684" target="_blank" rel="noopener noreferrer">Наша УНФ</a>
            </div>
        </div>
    </section>
</main>
<script>
    (function () {
        var periodInput = document.getElementById('period');
        var periodMonth = document.getElementById('periodMonth');
        var periodYear = document.getElementById('periodYear');
        var monthTitle = document.getElementById('monthTitle');
        var resultMonthValue = document.getElementById('resultMonthValue');
        var closedFromValue = document.getElementById('closedFromValue');
        var closedToValue = document.getElementById('closedToValue');
        var monthNames = [
            'Январь',
            'Февраль',
            'Март',
            'Апрель',
            'Май',
            'Июнь',
            'Июль',
            'Август',
            'Сентябрь',
            'Октябрь',
            'Ноябрь',
            'Декабрь'
        ];

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function formatDate(date) {
            return pad(date.getUTCDate()) + '.' + pad(date.getUTCMonth() + 1) + '.' + date.getUTCFullYear();
        }

        function syncPeriodFromSelects() {
            periodInput.value = periodYear.value + '-' + pad(periodMonth.value);
            updateMeta();
        }

        function updateMeta() {
            var value = periodInput.value;
            if (!/^\d{4}-\d{2}$/.test(value)) {
                return;
            }

            var parts = value.split('-');
            var year = Number(parts[0]);
            var monthIndex = Number(parts[1]) - 1;
            var monthStart = new Date(Date.UTC(year, monthIndex, 1));
            var monthEnd = new Date(Date.UTC(year, monthIndex + 1, 0));
            var closedFrom = new Date(monthStart.getTime());
            var closedTo = new Date(monthEnd.getTime());

            closedFrom.setUTCDate(closedFrom.getUTCDate() - 7);
            closedTo.setUTCDate(closedTo.getUTCDate() + 7);

            monthTitle.textContent = monthNames[monthIndex] + ' ' + year;
            resultMonthValue.textContent = pad(monthIndex + 1) + '.' + year;
            closedFromValue.textContent = formatDate(closedFrom);
            closedToValue.textContent = formatDate(closedTo);

            if (periodMonth.value !== String(monthIndex + 1)) {
                periodMonth.value = String(monthIndex + 1);
            }

            if (periodYear.value !== String(year)) {
                periodYear.value = String(year);
            }
        }

        periodMonth.addEventListener('change', syncPeriodFromSelects);
        periodYear.addEventListener('change', syncPeriodFromSelects);

        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var canvas = document.getElementById('cursorTrail');
        var context = canvas && canvas.getContext ? canvas.getContext('2d') : null;

        if (context && !reduceMotion) {
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
        }
    }());
</script>
</body>
</html>
