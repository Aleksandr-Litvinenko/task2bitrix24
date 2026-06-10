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

$pageTitle = 'Закрытые часы';
$navActive = 'main';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel page-panel--with-extra">
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
                <?php if (userCan('excel')): ?>
                    <button type="submit">
                        <span>Скачать Excel</span>
                    </button>
                <?php else: ?>
                    <button type="button" disabled title="Недоступно для вашей роли">
                        <span>Скачать Excel</span>
                    </button>
                <?php endif; ?>
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

        <?php if (userCan('unf')): ?>
            <div class="unf-action">
                <button id="createUnfTimeButton" class="secondary-action" type="button">
                    <span>создать "Учеты времени" в БОЕВОЙ УНФ</span>
                </button>
                <p id="unfActionStatus" class="action-status" role="status" aria-live="polite"></p>
            </div>

            <div class="unf-action">
                <button id="createUnfWorkOrderButton" class="secondary-action" type="button">
                    <span>создать "Задания на работу" в БОЕВОЙ УНФ</span>
                </button>
                <p id="workOrderActionStatus" class="action-status" role="status" aria-live="polite"></p>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel quick-panel" aria-labelledby="quickLinksTitle">
        <div class="panel-grid" aria-hidden="true"></div>
        <div class="quick-links">
            <h2 id="quickLinksTitle">Полезные кнопки</h2>
            <div class="quick-link-grid">
                <a href="https://task.kodar-msk.ru/" target="_blank" rel="noopener noreferrer">Наш таск</a>
                <a href="https://nalog1c.bitrix24.ru/" target="_blank" rel="noopener noreferrer">Наш битрикс</a>
                <a href="https://1cfresh.com/a/sbm/767684" target="_blank" rel="noopener noreferrer">Наша УНФ</a>
                <?php if (userCan('admin')): ?>
                    <a href="admin.php" class="quick-link-admin">Администрирование</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<script>
    (function () {
        var periodInput = document.getElementById('period');
        var periodMonth = document.getElementById('periodMonth');
        var periodYear = document.getElementById('periodYear');
        var monthTitle = document.getElementById('monthTitle');
        var resultMonthValue = document.getElementById('resultMonthValue');
        var closedFromValue = document.getElementById('closedFromValue');
        var closedToValue = document.getElementById('closedToValue');
        var createUnfTimeButton = document.getElementById('createUnfTimeButton');
        var unfActionStatus = document.getElementById('unfActionStatus');
        var createUnfWorkOrderButton = document.getElementById('createUnfWorkOrderButton');
        var workOrderActionStatus = document.getElementById('workOrderActionStatus');
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

        function setActionStatus(statusElement, message, state) {
            if (!statusElement) {
                return;
            }

            statusElement.textContent = message;
            statusElement.dataset.state = state || '';
        }

        function summarizeUnfResult(result) {
            var created = Array.isArray(result.created) ? result.created : [];
            var skipped = Array.isArray(result.skipped) ? result.skipped : [];
            var errors = Array.isArray(result.errors) ? result.errors : [];
            var lines = [
                'Создано: ' + created.length + '. Пропущено: ' + skipped.length + '. Ошибок: ' + errors.length + '.'
            ];

            if (created.length > 0) {
                lines.push(created.slice(0, 3).map(function (item) {
                    return item.name + (item.number ? ' - ' + item.number : '');
                }).join('\n'));
            }

            if (errors.length > 0) {
                lines.push(errors.slice(0, 3).map(function (item) {
                    return item.name + ': ' + item.error;
                }).join('\n'));
            } else if (created.length === 0 && skipped.length > 0) {
                lines.push(skipped.slice(0, 3).map(function (item) {
                    return item.name + ': ' + item.reason;
                }).join('\n'));
            }

            return lines.join('\n');
        }

        function bindUnfAction(button, statusElement, endpoint, pendingMessage) {
            if (!button) {
                return;
            }

            button.addEventListener('click', function () {
                button.disabled = true;
                setActionStatus(statusElement, pendingMessage, 'pending');

                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        period: periodInput.value
                    })
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var data = {};
                        try {
                            data = text ? JSON.parse(text) : {};
                        } catch (error) {
                            throw new Error('УНФ вернула некорректный ответ.');
                        }

                        if (!response.ok && data.error) {
                            throw new Error(data.error);
                        }

                        if (!response.ok) {
                            throw new Error('Запрос завершился с HTTP ' + response.status + '.');
                        }

                        return data;
                    });
                }).then(function (data) {
                    var errors = Array.isArray(data.errors) ? data.errors : [];
                    setActionStatus(statusElement, summarizeUnfResult(data), errors.length > 0 ? 'error' : 'success');
                }).catch(function (error) {
                    setActionStatus(statusElement, error.message, 'error');
                }).finally(function () {
                    button.disabled = false;
                });
            });
        }

        periodMonth.addEventListener('change', syncPeriodFromSelects);
        periodYear.addEventListener('change', syncPeriodFromSelects);
        bindUnfAction(createUnfTimeButton, unfActionStatus, 'unf_time.php', 'Создаю учёты времени в УНФ...');
        bindUnfAction(createUnfWorkOrderButton, workOrderActionStatus, 'unf_workorder.php', 'Создаю задания на работу в УНФ...');
    }());
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
