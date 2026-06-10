<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$availablePeriods = listDashboardPeriods();

$requestedPeriod = $_GET['period'] ?? '';
if (is_string($requestedPeriod) && preg_match('/^\d{4}-\d{2}$/', $requestedPeriod)) {
    $period = $requestedPeriod;
} elseif (!empty($availablePeriods)) {
    $period = $availablePeriods[0];
} else {
    $period = date('Y-m');
}

[$monthStart] = monthPeriod($period);
$selectedYear = (int)$monthStart->format('Y');
$selectedMonth = (int)$monthStart->format('n');
$monthSelectNames = [
    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
    5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
    9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
];
$firstYear = min((int)date('Y') - 3, $selectedYear);
$lastYear = max((int)date('Y') + 1, $selectedYear);

$snapshot = loadDashboardSnapshot($period);
$employees = is_array($snapshot['employees'] ?? null) ? $snapshot['employees'] : [];

$maxHours = 0.0;
foreach ($employees as $employee) {
    $maxHours = max($maxHours, (float)($employee['hours'] ?? 0));
}

$updatedLabel = '';
if (!empty($snapshot['updated_at'])) {
    $timestamp = strtotime((string)$snapshot['updated_at']);
    if ($timestamp) {
        $updatedLabel = date('d.m.Y H:i', $timestamp);
    }
}

$medals = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];

$pageTitle = 'Дашборд — taskCRM';
$navActive = 'dashboard';
$shellClass = 'shell--wide';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel dashboard-panel">
        <div class="panel-grid" aria-hidden="true"></div>
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / Рейтинг</p>
                <h1>Дашборд</h1>
            </div>
            <span class="month-chip"><?= h($snapshot['month_title'] ?? russianMonthTitle($monthStart)) ?></span>
        </div>

        <div class="board-toolbar">
            <div class="board-period">
                <select id="dashMonth" aria-label="Месяц">
                    <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                        <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>><?= h($monthName) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="dashYear" aria-label="Год">
                    <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                        <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>><?= h((string)$year) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button id="dashRefresh" class="secondary-action" type="button" data-period="<?= h($period) ?>">
                <span>Обновить за этот месяц</span>
            </button>
            <p id="dashStatus" class="action-status" role="status" aria-live="polite"></p>
        </div>

        <?php if (!empty($availablePeriods)): ?>
            <div class="period-chips" aria-label="Месяцы с данными">
                <?php foreach ($availablePeriods as $availablePeriod): ?>
                    <a class="period-chip<?= $availablePeriod === $period ? ' is-active' : '' ?>"
                       href="dashboard.php?period=<?= h(rawurlencode($availablePeriod)) ?>"><?= h($availablePeriod) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($employees)): ?>
            <div class="board-empty">
                <p>За этот месяц данных пока нет.</p>
                <p class="board-empty-detail">Дашборд обновляется при выгрузке Excel, создании учётов времени в УНФ, или по кнопке «Обновить за этот месяц».</p>
            </div>
        <?php else: ?>
            <div class="dash-summary">
                <div>
                    <dt>Всего часов</dt>
                    <dd><?= h(number_format((float)($snapshot['total_hours'] ?? 0), 2, '.', ' ')) ?></dd>
                </div>
                <div>
                    <dt>Сотрудников</dt>
                    <dd><?= count($employees) ?></dd>
                </div>
                <div>
                    <dt>Задач закрыто</dt>
                    <dd><?= (int)($snapshot['tasks_matched'] ?? 0) ?></dd>
                </div>
                <div>
                    <dt>Обновлено</dt>
                    <dd class="dash-updated"><?= $updatedLabel !== '' ? h($updatedLabel) : '—' ?></dd>
                </div>
            </div>

            <ol class="leaderboard">
                <?php foreach ($employees as $index => $employee): ?>
                    <?php
                    $rank = $index + 1;
                    $hours = (float)($employee['hours'] ?? 0);
                    $barWidth = $maxHours > 0 ? max(4, (int)round($hours / $maxHours * 100)) : 0;
                    $medal = $medals[$rank] ?? '';
                    ?>
                    <li class="leader-row<?= $medal !== '' ? ' leader-row--' . $medal : '' ?>">
                        <span class="leader-rank"><?= $rank ?></span>
                        <div class="leader-body">
                            <div class="leader-line">
                                <span class="leader-name"><?= h((string)($employee['name'] ?? '-')) ?></span>
                                <span class="leader-hours"><?= h(number_format($hours, 2, '.', ' ')) ?> ч</span>
                            </div>
                            <div class="leader-bar"><span style="width: <?= $barWidth ?>%"></span></div>
                            <div class="leader-sub"><?= (int)($employee['tasks_count'] ?? 0) ?> задач(и)</div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
<script>
    (function () {
        var month = document.getElementById('dashMonth');
        var year = document.getElementById('dashYear');
        var refresh = document.getElementById('dashRefresh');
        var status = document.getElementById('dashStatus');

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function currentPeriod() {
            return year.value + '-' + pad(month.value);
        }

        if (month && year) {
            function go() {
                window.location.href = 'dashboard.php?period=' + encodeURIComponent(currentPeriod());
            }
            month.addEventListener('change', go);
            year.addEventListener('change', go);
        }

        if (refresh) {
            refresh.addEventListener('click', function () {
                var period = currentPeriod();
                refresh.disabled = true;
                if (status) {
                    status.textContent = 'Считаю закрытые часы за ' + period + '...';
                    status.dataset.state = 'pending';
                }

                fetch('dashboard_refresh.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: new URLSearchParams({ period: period })
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var data = {};
                        try {
                            data = text ? JSON.parse(text) : {};
                        } catch (error) {
                            throw new Error('Сервер вернул некорректный ответ.');
                        }
                        if (!response.ok) {
                            throw new Error(data.error || ('HTTP ' + response.status));
                        }
                        return data;
                    });
                }).then(function () {
                    if (status) {
                        status.textContent = 'Готово. Обновляю...';
                        status.dataset.state = 'success';
                    }
                    window.location.href = 'dashboard.php?period=' + encodeURIComponent(period);
                }).catch(function (error) {
                    if (status) {
                        status.textContent = error.message;
                        status.dataset.state = 'error';
                    }
                    refresh.disabled = false;
                });
            });
        }
    }());
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
