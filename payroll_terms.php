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
    echo '<p>Условия оплаты доступны только администратору.</p>';
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
$saveError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $terms = loadPayrollTerms($period);
    $employees = (array)$terms['employees'];

    if ($action === 'save') {
        $posted = (array)($_POST['emp'] ?? []);
        $updated = [];

        foreach ($posted as $key => $fields) {
            $key = (string)$key;
            $existing = $employees[$key] ?? [];
            $updated[$key] = payrollNormalizeTerm(array_merge($existing, [
                'key' => $key,
                'name' => (string)($fields['name'] ?? ($existing['name'] ?? '')),
                'scheme' => (string)($fields['scheme'] ?? 'hourly'),
                'salary' => str_replace([' ', ','], ['', '.'], (string)($fields['salary'] ?? '0')),
                'rate' => str_replace([' ', ','], ['', '.'], (string)($fields['rate'] ?? '0')),
                'plan_hours' => str_replace([' ', ','], ['', '.'], (string)($fields['plan_hours'] ?? '0')),
                'constant_bonus' => str_replace([' ', ','], ['', '.'], (string)($fields['constant_bonus'] ?? '0')),
                'kpi_mode' => (string)($fields['kpi_mode'] ?? 'none'),
                'unit' => (string)($fields['unit'] ?? 'ч'),
                'note' => (string)($fields['note'] ?? ''),
                'active' => !empty($fields['active']),
            ]));
        }

        savePayrollTerms($period, $updated, currentUserLogin());
        $saved = true;
    } elseif ($action === 'add_bitrix') {
        $key = (string)($_POST['candidate'] ?? '');
        $candidates = payrollCandidates($period, $terms);

        if (isset($candidates[$key])) {
            $employees[$key] = payrollNormalizeTerm([
                'key' => $key,
                'bitrix_id' => $candidates[$key]['bitrix_id'],
                'name' => $candidates[$key]['name'],
                'source' => 'bitrix',
                'scheme' => 'floor',
            ]);
            savePayrollTerms($period, $employees, currentUserLogin());
            $saved = true;
        } else {
            $saveError = 'Сотрудник не найден среди тех, у кого есть часы за месяц.';
        }
    } elseif ($action === 'add_manual') {
        $name = trim((string)($_POST['manual_name'] ?? ''));

        if ($name === '') {
            $saveError = 'Укажите фамилию сотрудника.';
        } else {
            $key = payrollEmployeeKey(0, $name);
            if (isset($employees[$key])) {
                $saveError = 'Такой сотрудник уже заведён.';
            } else {
                $employees[$key] = payrollNormalizeTerm([
                    'key' => $key,
                    'name' => $name,
                    'source' => 'manual',
                    'scheme' => 'fixed',
                    'unit' => 'шт',
                ]);
                savePayrollTerms($period, $employees, currentUserLogin());
                $saved = true;
            }
        }
    } elseif ($action === 'remove') {
        $key = (string)($_POST['key'] ?? '');
        if (isset($employees[$key])) {
            unset($employees[$key]);
            savePayrollTerms($period, $employees, currentUserLogin());
            $saved = true;
        }
    }
}

$terms = loadPayrollTerms($period);
$employees = (array)$terms['employees'];
uasort($employees, static function (array $left, array $right): int {
    return strnatcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
});

$candidates = payrollCandidates($period, $terms);
$logEntries = array_slice(loadPayrollLog($period), 0, 40);

$fieldLabels = [
    'name' => 'ФИО',
    'scheme' => 'схема',
    'salary' => 'оклад',
    'rate' => 'ставка',
    'plan_hours' => 'план часов',
    'constant_bonus' => 'постоянная премия',
    'kpi_mode' => 'режим KPI',
    'unit' => 'единица',
    'active' => 'активен',
    'note' => 'комментарий',
    'employee' => 'сотрудник',
    'period' => 'месяц',
];

$pageTitle = 'Условия оплаты — Расчёт ЗП — taskCRM';
$navActive = 'payroll';
$payrollTab = 'terms';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Расчёт ЗП / Условия</p>
                <h1 class="section-title">Условия, оклады и ставка</h1>
            </div>
            <span class="month-chip"><?= h(russianMonthTitle($monthStart)) ?></span>
        </div>

        <?php require __DIR__ . '/partials/payroll_tabs.php'; ?>

        <form class="board-toolbar" method="get" action="payroll_terms.php">
            <div class="board-period">
                <select name="periodMonth" id="termsMonth" aria-label="Месяц">
                    <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                        <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>><?= h($monthName) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="periodYear" id="termsYear" aria-label="Год">
                    <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                        <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>><?= h((string)$year) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <input type="hidden" name="period" id="termsPeriod" value="<?= h($period) ?>">
            <button type="submit"><span>Показать месяц</span></button>
        </form>

        <?php if ($saved): ?>
            <p class="action-status" data-state="success">Сохранено. Изменения записаны в журнал.</p>
        <?php endif; ?>
        <?php if ($saveError !== ''): ?>
            <p class="action-status" data-state="error"><?= h($saveError) ?></p>
        <?php endif; ?>

        <?php if ($terms['inherited_from'] !== ''): ?>
            <p class="board-hint section-note">
                Условия унаследованы от <?= h($terms['inherited_from']) ?>: за этот месяц их ещё не меняли.
                Любое сохранение закрепит их за <?= h(russianMonthTitle($monthStart)) ?> — прошлые месяцы не изменятся.
            </p>
        <?php elseif ($terms['updated_at'] !== ''): ?>
            <p class="board-hint section-note">
                Условия этого месяца сохранил <?= h($terms['updated_by'] !== '' ? $terms['updated_by'] : 'неизвестно кто') ?>
                <?= h(date('d.m.Y H:i', (int)(strtotime($terms['updated_at']) ?: 0))) ?>.
            </p>
        <?php endif; ?>

        <?php if (empty($employees)): ?>
            <div class="board-empty">
                <p>За этот месяц условия ещё не заведены.</p>
                <p class="board-empty-detail">Добавьте сотрудников ниже: из Битрикса подтянутся те, у кого есть закрытые часы.</p>
            </div>
        <?php else: ?>
            <form method="post" action="payroll_terms.php?period=<?= h(rawurlencode($period)) ?>">
                <input type="hidden" name="action" value="save">
                <div class="table-scroll">
                    <table class="data-table terms-table">
                        <thead>
                            <tr>
                                <th scope="col">Сотрудник</th>
                                <th scope="col">Схема</th>
                                <th scope="col" class="num">Оклад</th>
                                <th scope="col" class="num">Ставка</th>
                                <th scope="col" class="num">План</th>
                                <th scope="col" class="num">Пост. премия</th>
                                <th scope="col">KPI</th>
                                <th scope="col">Комментарий</th>
                                <th scope="col">Активен</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $key => $term): ?>
                                <tr>
                                    <th scope="row">
                                        <?= h((string)$term['name']) ?>
                                        <?php if ($term['source'] === 'manual'): ?>
                                            <span class="muted" title="Заведён вручную, часов из Битрикса нет">вручную</span>
                                        <?php endif; ?>
                                        <input type="hidden" name="emp[<?= h((string)$key) ?>][name]" value="<?= h((string)$term['name']) ?>">
                                        <input type="hidden" name="emp[<?= h((string)$key) ?>][unit]" value="<?= h((string)$term['unit']) ?>">
                                    </th>
                                    <td>
                                        <select name="emp[<?= h((string)$key) ?>][scheme]" aria-label="Схема оплаты">
                                            <?php foreach (PAYROLL_SCHEMES as $schemeKey => $schemeLabel): ?>
                                                <option value="<?= h($schemeKey) ?>"
                                                        title="<?= h(PAYROLL_SCHEME_HINTS[$schemeKey]) ?>"
                                                        <?= $schemeKey === $term['scheme'] ? ' selected' : '' ?>><?= h($schemeLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="emp[<?= h((string)$key) ?>][salary]" value="<?= h((string)$term['salary']) ?>" aria-label="Оклад"></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="emp[<?= h((string)$key) ?>][rate]" value="<?= h((string)$term['rate']) ?>" aria-label="Ставка за <?= h((string)$term['unit']) ?>"></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="emp[<?= h((string)$key) ?>][plan_hours]" value="<?= h((string)$term['plan_hours']) ?>" aria-label="План часов"></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="cell-input" name="emp[<?= h((string)$key) ?>][constant_bonus]" value="<?= h((string)$term['constant_bonus']) ?>" aria-label="Постоянная премия"></td>
                                    <td>
                                        <select name="emp[<?= h((string)$key) ?>][kpi_mode]" aria-label="Как начисляется KPI">
                                            <?php foreach (PAYROLL_KPI_MODES as $modeKey => $modeLabel): ?>
                                                <option value="<?= h($modeKey) ?>"
                                                        title="<?= h(PAYROLL_KPI_MODE_HINTS[$modeKey]) ?>"
                                                        <?= $modeKey === $term['kpi_mode'] ? ' selected' : '' ?>><?= h($modeLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" class="cell-input cell-input--wide" name="emp[<?= h((string)$key) ?>][note]" value="<?= h((string)$term['note']) ?>" aria-label="Комментарий"></td>
                                    <td class="kpi-assign-cell">
                                        <input type="checkbox" name="emp[<?= h((string)$key) ?>][active]" value="1" <?= $term['active'] ? 'checked' : '' ?> aria-label="Активен">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="payroll-actions">
                    <button type="submit"><span>Сохранить условия</span></button>
                    <p class="board-hint">
                        <?php foreach (PAYROLL_SCHEMES as $schemeKey => $schemeLabel): ?>
                            <strong><?= h($schemeLabel) ?></strong> — <?= h(PAYROLL_SCHEME_HINTS[$schemeKey]) ?>.
                        <?php endforeach; ?>
                        Премия, экзамен и штраф задаются помесячно на вкладке «Расчёт ЗП».
                    </p>
                </div>
            </form>
        <?php endif; ?>

        <div class="payroll-add">
            <?php if (!empty($candidates)): ?>
                <form method="post" action="payroll_terms.php?period=<?= h(rawurlencode($period)) ?>" class="payroll-add-form">
                    <input type="hidden" name="action" value="add_bitrix">
                    <label for="candidate">Добавить из Битрикса</label>
                    <select name="candidate" id="candidate">
                        <?php foreach ($candidates as $key => $candidate): ?>
                            <option value="<?= h((string)$key) ?>"><?= h($candidate['name']) ?> — <?= h(number_format($candidate['hours'], 2, '.', ' ')) ?> ч</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="secondary-action"><span>Добавить</span></button>
                </form>
            <?php endif; ?>

            <form method="post" action="payroll_terms.php?period=<?= h(rawurlencode($period)) ?>" class="payroll-add-form">
                <input type="hidden" name="action" value="add_manual">
                <label for="manual_name">Добавить вручную</label>
                <input type="text" name="manual_name" id="manual_name" placeholder="Фамилия" class="cell-input">
                <button type="submit" class="secondary-action"><span>Добавить</span></button>
                <span class="board-hint">Для тех, кого нет в Битриксе: например, HR со ставкой за каждого вышедшего человека.</span>
            </form>
        </div>
    </section>

    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">История</p>
                <h1 class="section-title">Что меняли в этом месяце</h1>
            </div>
        </div>

        <?php if (empty($logEntries)): ?>
            <div class="board-empty">
                <p>За этот месяц условия не меняли.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Когда</th>
                            <th scope="col">Кто</th>
                            <th scope="col">Сотрудник</th>
                            <th scope="col">Что</th>
                            <th scope="col">Было</th>
                            <th scope="col">Стало</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logEntries as $entry): ?>
                            <tr>
                                <td><?= h(date('d.m.Y H:i', (int)(strtotime((string)$entry['at']) ?: 0))) ?></td>
                                <td><?= h((string)$entry['by'] !== '' ? (string)$entry['by'] : '—') ?></td>
                                <td><?= h((string)$entry['employee'] !== '' ? (string)$entry['employee'] : '—') ?></td>
                                <td><?= h($fieldLabels[(string)$entry['field']] ?? (string)$entry['field']) ?></td>
                                <td class="muted"><?= h((string)$entry['from'] !== '' ? (string)$entry['from'] : '—') ?></td>
                                <td><?= h((string)$entry['to']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<script>
    (function () {
        var month = document.getElementById('termsMonth');
        var year = document.getElementById('termsYear');
        var period = document.getElementById('termsPeriod');

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
