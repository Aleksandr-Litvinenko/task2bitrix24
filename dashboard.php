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

// Три дашборда на одной странице: по сотрудникам, организациям и проектам.
$dashViews = [
    'employees' => ['label' => 'По сотрудникам', 'unit' => 'Сотрудников', 'empty' => 'Сотрудников за этот месяц нет.', 'mask' => 'employee'],
    'companies' => ['label' => 'По организациям', 'unit' => 'Организаций', 'empty' => 'Организаций за этот месяц нет.', 'mask' => 'company'],
    'projects' => ['label' => 'По проектам', 'unit' => 'Проектов', 'empty' => 'Проектов за этот месяц нет.', 'mask' => 'project'],
];

$view = $_GET['view'] ?? 'employees';
if (!is_string($view) || !isset($dashViews[$view])) {
    $view = 'employees';
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
$companies = is_array($snapshot['companies'] ?? null) ? $snapshot['companies'] : [];
$projects = is_array($snapshot['projects'] ?? null) ? $snapshot['projects'] : [];

// Снимки, снятые до появления вкладок, содержат только сотрудников. Показываем
// это честно, а не пустой экран: данные появятся после следующего обновления.
$legacySnapshot = $snapshot !== null && !array_key_exists('companies', $snapshot);

$viewRows = ['employees' => $employees, 'companies' => $companies, 'projects' => $projects][$view];

// Роли «Внешний» и «Гость»: названия и цифры зашифрованы, структура видна.
$maskedView = isMaskedView();
if ($maskedView) {
    foreach ($viewRows as &$maskedRow) {
        $maskedRow['name'] = maskValue($maskedRow['name'] ?? '', $dashViews[$view]['mask']);
        $maskedRow['photo'] = '';
    }
    unset($maskedRow);
}

$maxHours = 0.0;
foreach ($viewRows as $viewRow) {
    $maxHours = max($maxHours, (float)($viewRow['hours'] ?? 0));
}

$updatedLabel = '';
if (!empty($snapshot['updated_at'])) {
    $timestamp = strtotime((string)$snapshot['updated_at']);
    if ($timestamp) {
        $updatedLabel = date('d.m.Y H:i', $timestamp);
    }
}

$medals = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];

$pageTitle = $dashViews[$view]['label'] . ' — Дашборд — taskCRM';
$navActive = 'dashboard';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel dashboard-panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / Рейтинг</p>
                <h1>Дашборд</h1>
            </div>
            <span class="month-chip"><?= h($snapshot['month_title'] ?? russianMonthTitle($monthStart)) ?></span>
        </div>

        <div class="board-toolbar">
            <div class="segmented" role="tablist" aria-label="Разрез дашборда">
                <?php foreach ($dashViews as $viewKey => $viewMeta): ?>
                    <a class="seg<?= $viewKey === $view ? ' is-active' : '' ?>"
                       role="tab"
                       aria-selected="<?= $viewKey === $view ? 'true' : 'false' ?>"
                       href="dashboard.php?view=<?= h($viewKey) ?>&amp;period=<?= h(rawurlencode($period)) ?>"><?= h($viewMeta['label']) ?></a>
                <?php endforeach; ?>
            </div>
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
            <?php if (userCan('refresh')): ?>
                <button id="dashRefresh" class="secondary-action" type="button" data-period="<?= h($period) ?>">
                    <span>Обновить за этот месяц</span>
                </button>
            <?php endif; ?>
            <p id="dashStatus" class="action-status" role="status" aria-live="polite"></p>
        </div>

        <?php if (!empty($availablePeriods)): ?>
            <div class="period-chips" aria-label="Месяцы с данными">
                <?php foreach ($availablePeriods as $availablePeriod): ?>
                    <?php
                    $chipMonth = DateTimeImmutable::createFromFormat('!Y-m-d', $availablePeriod . '-01');
                    $chipLabel = $chipMonth ? russianMonthTitle($chipMonth) : $availablePeriod;
                    ?>
                    <a class="period-chip<?= $availablePeriod === $period ? ' is-active' : '' ?>"
                       href="dashboard.php?view=<?= h($view) ?>&amp;period=<?= h(rawurlencode($availablePeriod)) ?>"><?= h($chipLabel) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($viewRows)): ?>
            <div class="board-empty">
                <?php if ($legacySnapshot && $view !== 'employees'): ?>
                    <p>Этот разрез появится после следующего обновления.</p>
                    <p class="board-empty-detail">Снимок за этот месяц снят до того, как дашборд научился считать организации и проекты<?= userCan('refresh') ? ' — нажмите «Обновить за этот месяц»' : '' ?>.</p>
                <?php else: ?>
                    <p><?= h($dashViews[$view]['empty']) ?></p>
                    <p class="board-empty-detail">Дашборд обновляется при выгрузке Excel, создании учётов времени в УНФ, или по кнопке «Обновить за этот месяц».</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="dash-summary">
                <div>
                    <dt>Всего часов</dt>
                    <dd><?= $maskedView ? h(maskNumber()) : h(number_format((float)($snapshot['total_hours'] ?? 0), 2, '.', ' ')) ?></dd>
                </div>
                <div>
                    <dt><?= h($dashViews[$view]['unit']) ?></dt>
                    <dd><?= count($viewRows) ?></dd>
                </div>
                <div>
                    <dt>Задач закрыто</dt>
                    <dd><?= $maskedView ? h(maskNumber()) : (int)($snapshot['tasks_matched'] ?? 0) ?></dd>
                </div>
                <div>
                    <dt>Обновлено</dt>
                    <dd class="dash-updated"><?= $updatedLabel !== '' ? h($updatedLabel) : '—' ?></dd>
                </div>
            </div>

            <ol class="leaderboard">
                <?php foreach ($viewRows as $index => $row): ?>
                    <?php
                    $rank = $index + 1;
                    $hours = (float)($row['hours'] ?? 0);
                    $barWidth = $maxHours > 0 ? max(4, (int)round($hours / $maxHours * 100)) : 0;
                    $medal = $medals[$rank] ?? '';

                    $photo = trim((string)($row['photo'] ?? ''));
                    $initialsSource = preg_split('/\s+/u', trim((string)($row['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    // Берём только слова, начинающиеся с буквы: иначе «Компания #1»
                    // превращается в «К#», а «ООО "Ромашка"» — в «О"».
                    $initialsSource = array_values(array_filter($initialsSource, static function (string $word): bool {
                        return (bool)preg_match('/^\p{L}/u', $word);
                    }));
                    $initials = '';
                    foreach (array_slice($initialsSource, 0, 2) as $word) {
                        $initials .= mb_substr($word, 0, 1, 'UTF-8');
                    }
                    ?>
                    <li class="leader-row<?= $medal !== '' ? ' leader-row--' . $medal : '' ?>">
                        <span class="leader-rank"><?= $rank ?></span>
                        <?php if ($photo !== ''): ?>
                            <img class="leader-avatar" src="<?= h($photo) ?>" alt="" loading="lazy"
                                 onerror="this.outerHTML='<span class=&quot;leader-avatar leader-avatar--initials&quot;><?= h($initials) ?></span>'">
                        <?php else: ?>
                            <span class="leader-avatar leader-avatar--initials<?= $view !== 'employees' ? ' leader-avatar--' . h($view) : '' ?>"><?= h($initials !== '' ? $initials : '·') ?></span>
                        <?php endif; ?>
                        <div class="leader-body">
                            <div class="leader-line">
                                <span class="leader-name"><?= h((string)($row['name'] ?? '-')) ?></span>
                                <?php
                                // Изменение с прошлого обновления. Гостю показываем
                                // только направление: сами часы у него скрыты.
                                $deltaHours = (float)($row['delta_hours'] ?? 0);
                                if ($deltaHours != 0.0):
                                    $isUp = $deltaHours > 0;
                                    $deltaLabel = $maskedView
                                        ? ($isUp ? '▲' : '▼')
                                        : ($isUp ? '+' : '−') . number_format(abs($deltaHours), 2, '.', ' ') . ' ч';
                                    ?>
                                    <span class="leader-delta leader-delta--<?= $isUp ? 'up' : 'down' ?>"
                                          title="Изменение с прошлого обновления"><?= h($deltaLabel) ?></span>
                                <?php endif; ?>
                                <span class="leader-hours"><?= $maskedView ? h(maskNumber()) : h(number_format($hours, 2, '.', ' ')) . ' ч' ?></span>
                            </div>
                            <div class="leader-bar"><span style="width: <?= $barWidth ?>%"></span></div>
                            <div class="leader-sub"><?= $maskedView ? h(maskNumber()) : (int)($row['tasks_count'] ?? 0) . ' задач(и)' ?></div>
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
        var view = <?= json_encode($view, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function currentPeriod() {
            return year.value + '-' + pad(month.value);
        }

        function dashboardUrl(period) {
            return 'dashboard.php?view=' + encodeURIComponent(view) + '&period=' + encodeURIComponent(period);
        }

        if (month && year) {
            function go() {
                window.location.href = dashboardUrl(currentPeriod());
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
                    window.location.href = dashboardUrl(period);
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
