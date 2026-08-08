<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

// Пересчёт ходит в Битрикс, поэтому гостю он недоступен: ему остаётся
// последний сохранённый снимок (в замаскированном виде).
$run = (isset($_GET['run']) || isset($_GET['export'])) && userCan('live');
$export = ($_GET['export'] ?? '') === 'xlsx';

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

if ($export && !userCan('excel')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Выгрузка недоступна для вашей роли.';
    exit;
}

$report = null;
$reportError = '';
$fromCache = false;

if ($export || !$run) {
    $report = loadKpiSnapshot($period);
    $fromCache = $report !== null;
}

if ($report === null && ($run || $export)) {
    try {
        $report = buildKpiReport($period);
        saveKpiSnapshot($report);
        $fromCache = false;
    } catch (Throwable $e) {
        $reportError = $e->getMessage();
    }
}

if ($export && $report !== null && $reportError === '') {
    downloadKpiXlsx($report);
    exit;
}

$maskedView = isMaskedView();
if ($maskedView && $report !== null) {
    foreach ($report['people'] as &$maskedPerson) {
        $maskedPerson['name'] = maskValue($maskedPerson['name'], 'employee');
    }
    unset($maskedPerson);
}

function kpiMoneyLabel(float $value): string
{
    return number_format($value, 0, ',', ' ') . ' ₽';
}

$pageTitle = 'KPI по созданным задачам — taskCRM';
$navActive = 'kpi';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / Показатели</p>
                <h1 class="section-title">KPI по созданным задачам</h1>
            </div>
            <span class="month-chip"><?= h($report['month_title'] ?? russianMonthTitle($monthStart)) ?></span>
        </div>

        <form class="board-toolbar" method="get" action="kpi.php">
            <input type="hidden" name="run" value="1">
            <div class="board-period">
                <select name="periodMonth" id="kpiMonth" aria-label="Месяц">
                    <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                        <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>><?= h($monthName) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="periodYear" id="kpiYear" aria-label="Год">
                    <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                        <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>><?= h((string)$year) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <input type="hidden" name="period" id="kpiPeriod" value="<?= h($period) ?>">
            <button type="submit"><span>Рассчитать KPI</span></button>
            <?php if ($report !== null && !empty($report['people']) && userCan('excel')): ?>
                <a class="button-link" href="kpi.php?export=xlsx&amp;period=<?= h(rawurlencode($period)) ?>">
                    <span>Скачать Excel</span>
                </a>
            <?php endif; ?>
        </form>

        <?php if ($report !== null && $reportError === ''): ?>
            <?php $checkedTs = !empty($report['checked_at']) ? strtotime((string)$report['checked_at']) : 0; ?>
            <p class="board-hint section-note">
                <?php if ($fromCache && $checkedTs): ?>
                    Расчёт от <?= h(date('d.m.Y H:i', $checkedTs)) ?>. Нажмите «Рассчитать KPI», чтобы обновить.
                <?php else: ?>
                    Расчёт выполнен <?= h(date('d.m.Y H:i')) ?>.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($reportError !== ''): ?>
            <div class="board-empty board-empty--error">
                <p>Не удалось рассчитать KPI.</p>
                <p class="board-empty-detail"><?= h($reportError) ?></p>
            </div>
        <?php elseif ($report === null): ?>
            <div class="board-empty">
                <p>Выберите месяц и нажмите «Рассчитать KPI».</p>
                <p class="board-empty-detail">
                    Считаются задачи, созданные и закрытые в выбранном месяце, в разрезе постановщиков.
                    Задачи без списанных часов в расчёт не идут. Расчёт обращается к Битриксу и занимает около минуты.
                </p>
            </div>
        <?php else: ?>
            <?php $rates = $report['rates']; ?>
            <p class="kpi-formula">
                KPI 1 — зачётные задачи ÷ <?= (int)$rates['tasks_base'] ?> × <?= h(number_format((float)$rates['tasks_rate'], 0, ',', ' ')) ?> ₽ ·
                KPI 2 — доля задач в срок × <?= h(number_format((float)$rates['overdue_rate'], 0, ',', ' ')) ?> ₽ ·
                KPI 3 — доля описаний по шаблону × <?= h(number_format((float)$rates['template_rate'], 0, ',', ' ')) ?> ₽
            </p>

            <div class="dash-summary">
                <div>
                    <dt>Создано задач</dt>
                    <dd><?= (int)$report['totals']['tasks_total'] ?></dd>
                </div>
                <div>
                    <dt>Зачётных</dt>
                    <dd><?= (int)$report['totals']['counted'] ?></dd>
                </div>
                <div>
                    <dt>Просрочено</dt>
                    <dd><?= (int)$report['totals']['overdue'] ?></dd>
                </div>
                <div>
                    <dt>KPI всего</dt>
                    <dd><?= h(kpiMoneyLabel((float)$report['totals']['kpi_total'])) ?></dd>
                </div>
            </div>

            <?php if (empty($report['people'])): ?>
                <div class="board-empty">
                    <p>За выбранный месяц созданных и закрытых задач не найдено.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Сотрудник</th>
                                <th scope="col" class="num">Создано</th>
                                <th scope="col" class="num">Без часов</th>
                                <th scope="col" class="num">Зачётных</th>
                                <th scope="col" class="num">Просрочено</th>
                                <th scope="col" class="num">По шаблону</th>
                                <th scope="col" class="num">KPI 1</th>
                                <th scope="col" class="num">KPI 2</th>
                                <th scope="col" class="num">KPI 3</th>
                                <th scope="col" class="num">Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['people'] as $person): ?>
                                <tr>
                                    <th scope="row"><?= h($person['name']) ?></th>
                                    <td class="num"><?= (int)$person['tasks_total'] ?></td>
                                    <td class="num muted"><?= (int)$person['no_hours'] ?></td>
                                    <td class="num"><?= (int)$person['counted'] ?></td>
                                    <td class="num<?= (int)$person['overdue'] > 0 ? ' warn' : '' ?>"><?= (int)$person['overdue'] ?></td>
                                    <td class="num"><?= (int)$person['template'] ?>
                                        <span class="muted">(<?= (int)round($person['template_share'] * 100) ?>%)</span>
                                    </td>
                                    <td class="num"><?= h(kpiMoneyLabel((float)$person['kpi1'])) ?></td>
                                    <td class="num"><?= h(kpiMoneyLabel((float)$person['kpi2'])) ?></td>
                                    <td class="num"><?= h(kpiMoneyLabel((float)$person['kpi3'])) ?></td>
                                    <td class="num total"><?= h(kpiMoneyLabel((float)$person['kpi_total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<script>
    (function () {
        var month = document.getElementById('kpiMonth');
        var year = document.getElementById('kpiYear');
        var period = document.getElementById('kpiPeriod');
        if (!month || !year || !period) {
            return;
        }

        function sync() {
            period.value = year.value + '-' + String(month.value).padStart(2, '0');
        }

        month.addEventListener('change', sync);
        year.addEventListener('change', sync);
        sync();
    }());
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
