<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_payroll.php';

requireAuth();

if (!userCan('payroll')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $styleVersion = is_file(__DIR__ . '/style.css') ? (string)filemtime(__DIR__ . '/style.css') : '1';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Нет доступа</title><link rel="stylesheet" href="style.css?v=' . h($styleVersion) . '"></head><body>';
    echo '<main class="shell"><section class="panel error"><h1>Нет доступа</h1>';
    echo '<p>Расчёт зарплаты доступен только администратору.</p>';
    echo '<a href="index.php">Вернуться</a></section></main></body></html>';
    exit;
}

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
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

$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $month = loadPayrollMonth($period);
    $rows = [];

    foreach ((array)($_POST['row'] ?? []) as $key => $fields) {
        $clean = static function ($value): string {
            return str_replace([' ', ','], ['', '.'], (string)$value);
        };

        $rows[(string)$key] = payrollNormalizeRow([
            'bonus' => $clean($fields['bonus'] ?? 0),
            'penalty' => $clean($fields['penalty'] ?? 0),
            'exam' => $clean($fields['exam'] ?? 0),
            'plus_hours' => $clean($fields['plus_hours'] ?? 0),
            'minus_hours' => $clean($fields['minus_hours'] ?? 0),
            'manual_count' => $clean($fields['manual_count'] ?? 0),
            'comment' => (string)($fields['comment'] ?? ''),
        ]);
    }

    $month['rows'] = $rows;
    savePayrollMonth($period, $month, currentUserLogin());
    $saved = true;
}

$report = buildPayrollReport($period);

$pageTitle = 'Расчёт ЗП — taskCRM';
$navActive = 'payroll';
$payrollTab = 'salary';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Расчёт ЗП / Выплата</p>
                <h1 class="section-title">Расчёт зарплаты</h1>
            </div>
            <span class="month-chip"><?= h($report['month_title']) ?></span>
        </div>

        <?php require __DIR__ . '/partials/payroll_tabs.php'; ?>

        <form class="board-toolbar" method="get" action="payroll.php">
            <div class="board-period">
                <select name="periodMonth" id="payMonth" aria-label="Месяц">
                    <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                        <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>><?= h($monthName) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="periodYear" id="payYear" aria-label="Год">
                    <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                        <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>><?= h((string)$year) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <input type="hidden" name="period" id="payPeriod" value="<?= h($period) ?>">
            <button type="submit"><span>Показать месяц</span></button>
        </form>

        <?php if ($saved): ?>
            <p class="action-status" data-state="success">Расчёт сохранён.</p>
        <?php endif; ?>

        <?php if (!$report['has_hours']): ?>
            <p class="board-hint section-note">
                За этот месяц нет снимка часов — все часы будут нулевыми.
                Откройте «Дашборд» и нажмите «Обновить за этот месяц».
            </p>
        <?php endif; ?>
        <?php if (!$report['has_kpi']): ?>
            <p class="board-hint section-note">
                KPI за этот месяц не считался — на вкладке «KPI по задачам» нажмите «Рассчитать KPI».
            </p>
        <?php endif; ?>

        <?php if (empty($report['rows'])): ?>
            <div class="board-empty">
                <p>Сотрудники на этот месяц не заведены.</p>
                <p class="board-empty-detail">Заполните вкладку «Условия, оклады и ставка».</p>
            </div>
        <?php else: ?>
            <div class="dash-summary">
                <div>
                    <dt>К выплате</dt>
                    <dd class="payroll-total"><?= h(payrollMoney((float)$report['totals']['total'])) ?></dd>
                </div>
                <div>
                    <dt>Сотрудников</dt>
                    <dd><?= count($report['rows']) ?></dd>
                </div>
                <div>
                    <dt>Часов</dt>
                    <dd><?= h(number_format((float)$report['totals']['hours'], 2, '.', ' ')) ?></dd>
                </div>
                <div>
                    <dt>Из них KPI</dt>
                    <dd><?= h(payrollMoney((float)$report['totals']['kpi_pay'])) ?></dd>
                </div>
            </div>

            <form method="post" action="payroll.php?period=<?= h(rawurlencode($period)) ?>">
                <input type="hidden" name="action" value="save">
                <div class="table-scroll">
                    <table class="data-table payroll-table">
                        <thead>
                            <tr>
                                <th scope="col">Сотрудник</th>
                                <th scope="col">Схема</th>
                                <th scope="col" class="num">Часы</th>
                                <th scope="col" class="num">+ ч</th>
                                <th scope="col" class="num">− ч</th>
                                <th scope="col" class="num">План</th>
                                <th scope="col" class="num">База</th>
                                <th scope="col" class="num">KPI</th>
                                <th scope="col" class="num">Премия</th>
                                <th scope="col" class="num">Экзамен</th>
                                <th scope="col" class="num">Штраф</th>
                                <th scope="col">Комментарий</th>
                                <th scope="col" class="num">К выплате</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['rows'] as $row): ?>
                                <?php $key = (string)$row['key']; ?>
                                <tr>
                                    <th scope="row">
                                        <?= h((string)$row['name']) ?>
                                        <?php if ($row['source'] === 'manual'): ?>
                                            <span class="muted">вручную</span>
                                        <?php endif; ?>
                                    </th>
                                    <td class="muted"><?= h(PAYROLL_SCHEMES[$row['scheme']] ?? $row['scheme']) ?></td>
                                    <td class="num">
                                        <?php if ($row['source'] === 'manual'): ?>
                                            <input type="text" inputmode="decimal" class="cell-input cell-input--narrow"
                                                   name="row[<?= h($key) ?>][manual_count]"
                                                   value="<?= h((string)$row['manual_count']) ?>"
                                                   aria-label="Количество (<?= h((string)$row['unit']) ?>)">
                                        <?php else: ?>
                                            <?= h(number_format((float)$row['source_hours'], 2, '.', ' ')) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input cell-input--narrow" name="row[<?= h($key) ?>][plus_hours]" value="<?= h((string)$row['plus_hours']) ?>" aria-label="Добавить часов"></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input cell-input--narrow" name="row[<?= h($key) ?>][minus_hours]" value="<?= h((string)$row['minus_hours']) ?>" aria-label="Убрать часов"></td>
                                    <td class="num muted">
                                        <?php if ($row['plan_done'] !== null): ?>
                                            <?= h(number_format((float)$row['plan_done'], 0, '.', ' ')) ?>%
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= h(payrollMoney((float)$row['base'])) ?></td>
                                    <td class="num<?= (float)$row['kpi_pay'] > 0 ? ' total' : ' muted' ?>">
                                        <?= h(payrollMoney((float)$row['kpi_pay'])) ?>
                                    </td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="row[<?= h($key) ?>][bonus]" value="<?= h((string)$row['bonus']) ?>" aria-label="Премия"></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="row[<?= h($key) ?>][exam]" value="<?= h((string)$row['exam']) ?>" aria-label="Экзамен"></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="row[<?= h($key) ?>][penalty]" value="<?= h((string)$row['penalty']) ?>" aria-label="Штраф"></td>
                                    <td><input type="text" class="cell-input cell-input--wide" name="row[<?= h($key) ?>][comment]" value="<?= h((string)$row['comment']) ?>" aria-label="Комментарий"></td>
                                    <td class="num total"><?= h(payrollMoney((float)$row['total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row">Итого</th>
                                <td colspan="5"></td>
                                <td class="num"><?= h(payrollMoney((float)$report['totals']['base'])) ?></td>
                                <td class="num"><?= h(payrollMoney((float)$report['totals']['kpi_pay'])) ?></td>
                                <td class="num" colspan="2"><?= h(payrollMoney((float)$report['totals']['bonus'])) ?></td>
                                <td class="num"><?= h(payrollMoney((float)$report['totals']['penalty'])) ?></td>
                                <td></td>
                                <td class="num total"><?= h(payrollMoney((float)$report['totals']['total'])) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="payroll-actions">
                    <button type="submit"><span>Сохранить расчёт</span></button>
                    <p class="board-hint">
                        Часы берутся из снимка дашборда, KPI — с первой вкладки, оклад и ставка — со второй.
                        Здесь задаются только разовые премии, экзамены, штрафы и правки часов за этот месяц.
                        <?php if ($report['updated_at'] !== ''): ?>
                            Последнее сохранение: <?= h((string)$report['updated_by']) ?>,
                            <?= h(date('d.m.Y H:i', (int)(strtotime((string)$report['updated_at']) ?: 0))) ?>.
                        <?php endif; ?>
                    </p>
                </div>
            </form>
        <?php endif; ?>
    </section>
<script>
    (function () {
        var month = document.getElementById('payMonth');
        var year = document.getElementById('payYear');
        var period = document.getElementById('payPeriod');

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
