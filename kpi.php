<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_payroll.php';

requireAuth();

// Раздел «Расчёт ЗП» целиком закрыт: тут видно, из чего складывается выплата.
if (!userCan('payroll')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $styleVersion = is_file(__DIR__ . '/style.css') ? (string)filemtime(__DIR__ . '/style.css') : '1';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Нет доступа</title><link rel="stylesheet" href="style.css?v=' . h($styleVersion) . '"></head><body>';
    echo '<main class="shell"><section class="panel error"><h1>Нет доступа</h1>';
    echo '<p>Расчёт зарплаты доступен только администратору.</p>';
    echo '<a href="index.php">Вернуться</a>';
    echo '</section></main></body></html>';
    exit;
}

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

// Раздел админский, маскировать некого — но проверку оставляем на случай,
// если права раздела когда-нибудь расширят.
$maskedView = isMaskedView();
if ($maskedView && $report !== null) {
    foreach ($report['people'] as &$maskedPerson) {
        $maskedPerson['name'] = maskValue($maskedPerson['name'], 'employee');
    }
    unset($maskedPerson);
}

// Кому начисляем KPI: галочка и получатель. Хранится в расчёте месяца,
// потому что это решение по конкретному месяцу, а не постоянное условие.
$payrollMonth = loadPayrollMonth($period);
$payrollTerms = loadPayrollTerms($period);
$assignSaved = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'kpi_assign') {
    $assign = [];
    foreach ((array)($_POST['apply'] ?? []) as $sourceKey => $_) {
        $sourceKey = (string)$sourceKey;
        $recipient = (string)(($_POST['recipient'][$sourceKey] ?? '') ?: $sourceKey);
        $assign[$sourceKey] = ['apply' => true, 'recipient' => $recipient];
    }

    $payrollMonth['kpi_assign'] = $assign;
    savePayrollMonth($period, $payrollMonth, currentUserLogin());
    $assignSaved = true;
}

$kpiAssign = (array)($payrollMonth['kpi_assign'] ?? []);

// Список получателей: все, кто заведён в условиях оплаты.
$recipients = [];
foreach ((array)$payrollTerms['employees'] as $termKey => $term) {
    $recipients[(string)$termKey] = (string)($term['name'] ?? $termKey);
}
asort($recipients);

function kpiMoneyLabel(float $value): string
{
    return number_format($value, 0, ',', ' ') . ' ₽';
}

$pageTitle = 'KPI по задачам — Расчёт ЗП — taskCRM';
$navActive = 'payroll';
$payrollTab = 'kpi';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / Расчёт ЗП</p>
                <h1 class="section-title">KPI по созданным задачам</h1>
            </div>
            <span class="month-chip"><?= h($report['month_title'] ?? russianMonthTitle($monthStart)) ?></span>
        </div>

        <?php require __DIR__ . '/partials/payroll_tabs.php'; ?>

        <?php if ($assignSaved): ?>
            <p class="action-status" data-state="success">Начисление KPI сохранено — суммы уже в расчёте ЗП.</p>
        <?php endif; ?>

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
                <form method="post" action="kpi.php?period=<?= h(rawurlencode($period)) ?>">
                <input type="hidden" name="action" value="kpi_assign">
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Сотрудник</th>
                                <th scope="col">Начислять</th>
                                <th scope="col">Кому</th>
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
                                <?php
                                $sourceKey = payrollEmployeeKey((int)($person['id'] ?? 0));
                                $assigned = $kpiAssign[$sourceKey] ?? null;
                                $isApplied = is_array($assigned) && !empty($assigned['apply']);
                                $recipientKey = $isApplied ? (string)($assigned['recipient'] ?? $sourceKey) : $sourceKey;
                                ?>
                                <tr>
                                    <th scope="row"><?= h($person['name']) ?></th>
                                    <td class="kpi-assign-cell">
                                        <input type="checkbox"
                                               name="apply[<?= h($sourceKey) ?>]"
                                               value="1"
                                               aria-label="Начислять KPI: <?= h($person['name']) ?>"
                                               <?= $isApplied ? 'checked' : '' ?>>
                                    </td>
                                    <td class="kpi-assign-cell">
                                        <?php if (empty($recipients)): ?>
                                            <span class="muted">заведите сотрудника в условиях</span>
                                        <?php else: ?>
                                            <select name="recipient[<?= h($sourceKey) ?>]"
                                                    aria-label="Кому начислить KPI: <?= h($person['name']) ?>">
                                                <?php foreach ($recipients as $key => $name): ?>
                                                    <option value="<?= h((string)$key) ?>"<?= (string)$key === $recipientKey ? ' selected' : '' ?>><?= h($name) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
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
                <div class="payroll-actions">
                    <button type="submit"><span>Сохранить начисление</span></button>
                    <p class="board-hint">
                        Отмеченный KPI прибавляется к зарплате выбранного сотрудника на вкладке «Расчёт ЗП».
                        Как именно — задаётся режимом KPI в условиях: полностью или добивкой до суммы KPI.
                    </p>
                </div>
                </form>
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
