<?php
declare(strict_types=1);

/*
 * Расчёт зарплаты.
 *
 * Считает то же, что раньше считалось руками в «РезультатыМесяца.xlsx»:
 * условия оплаты (оклад, ставка, схема) + часы из отчёта + KPI + ручные
 * премии и штрафы = сумма к выплате.
 *
 * Что где лежит (всё в DASHBOARD_DATA_DIR):
 *   payroll-terms-YYYY-MM.json — условия за месяц (оклады, ставки, схемы);
 *   payroll-YYYY-MM.json       — ручные правки месяца и распределение KPI;
 *   payroll-log.json           — журнал: кто, когда и что поменял.
 *
 * Условия хранятся помесячно, поэтому видно, с какого месяца человеку
 * подняли оклад. Новый месяц наследует условия последнего заполненного —
 * заводить всех заново не нужно.
 */

require_once __DIR__ . '/lib.php';

// Схемы оплаты. Формулы повторяют рабочий файл «РезультатыМесяца».
const PAYROLL_SCHEMES = [
    'floor' => 'Несгораемый оклад',
    'sum' => 'Оклад + ставка',
    'hourly' => 'Только ставка',
    'fixed' => 'Фикс',
];

const PAYROLL_SCHEME_HINTS = [
    'floor' => 'Больше из двух: оклад или часы × ставка',
    'sum' => 'Оклад + часы × ставка',
    'hourly' => 'Часы × ставка, без оклада',
    'fixed' => 'Только оклад, часы не считаются',
];

// Как KPI попадает в выплату.
const PAYROLL_KPI_MODES = [
    'none' => 'Не начислять',
    'full' => 'Прибавить полностью',
    'topup' => 'Добить до KPI',
];

const PAYROLL_KPI_MODE_HINTS = [
    'none' => 'KPI считается, но в зарплату не идёт',
    'full' => 'Вся сумма KPI прибавляется к выплате',
    'topup' => 'KPI = оклад + премия: доплачивается разница (KPI 82 000 при окладе 45 000 даёт премию 37 000)',
];

function payrollDataDir(): string
{
    return rtrim(DASHBOARD_DATA_DIR, "/\\");
}

function payrollTermsPath(string $period): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Некорректный период условий оплаты.');
    }

    return payrollDataDir() . '/payroll-terms-' . $period . '.json';
}

function payrollMonthPath(string $period): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Некорректный период расчёта.');
    }

    return payrollDataDir() . '/payroll-' . $period . '.json';
}

function payrollLogPath(): string
{
    return payrollDataDir() . '/payroll-log.json';
}

function payrollReadJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function payrollWriteJson(string $path, array $data): bool
{
    ensureDashboardDataDir();

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }

    return rename($tmp, $path);
}

/**
 * Приводит запись условий к полному набору полей: файлы, записанные более
 * ранней версией, не должны ронять расчёт отсутствующим ключом.
 */
function payrollNormalizeTerm(array $term): array
{
    $scheme = (string)($term['scheme'] ?? 'hourly');
    $kpiMode = (string)($term['kpi_mode'] ?? 'none');

    return [
        'key' => (string)($term['key'] ?? ''),
        'bitrix_id' => (int)($term['bitrix_id'] ?? 0),
        'name' => trim((string)($term['name'] ?? '')),
        'source' => ($term['source'] ?? 'bitrix') === 'manual' ? 'manual' : 'bitrix',
        'scheme' => isset(PAYROLL_SCHEMES[$scheme]) ? $scheme : 'hourly',
        'salary' => round((float)($term['salary'] ?? 0), 2),
        'rate' => round((float)($term['rate'] ?? 0), 2),
        'plan_hours' => round((float)($term['plan_hours'] ?? 0), 2),
        'constant_bonus' => round((float)($term['constant_bonus'] ?? 0), 2),
        'kpi_mode' => isset(PAYROLL_KPI_MODES[$kpiMode]) ? $kpiMode : 'none',
        // Для ручных сотрудников единица измерения может быть не «час»:
        // у HR ставка платится за каждого вышедшего человека.
        'unit' => trim((string)($term['unit'] ?? '')) !== '' ? trim((string)$term['unit']) : 'ч',
        'active' => (bool)($term['active'] ?? true),
        'note' => trim((string)($term['note'] ?? '')),
    ];
}

function payrollEmployeeKey(int $bitrixId, string $name = ''): string
{
    if ($bitrixId > 0) {
        return 'b:' . $bitrixId;
    }

    $slug = mb_strtolower(trim($name), 'UTF-8');
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';

    return 'm:' . trim((string)$slug, '-');
}

/**
 * Условия за месяц. Если за этот месяц их ещё не заводили, берутся условия
 * ближайшего заполненного месяца до него — иначе каждый месяц пришлось бы
 * набивать оклады заново. Признак inherited говорит интерфейсу, что месяц
 * пока живёт на унаследованных условиях.
 */
function loadPayrollTerms(string $period): array
{
    $own = payrollReadJson(payrollTermsPath($period));
    if ($own !== null) {
        $own['inherited_from'] = '';
        $own['employees'] = array_map('payrollNormalizeTerm', (array)($own['employees'] ?? []));

        return $own;
    }

    foreach (payrollTermsPeriods() as $candidate) {
        if (strcmp($candidate, $period) >= 0) {
            continue;
        }

        $data = payrollReadJson(payrollTermsPath($candidate));
        if ($data !== null) {
            return [
                'period' => $period,
                'inherited_from' => $candidate,
                'updated_at' => (string)($data['updated_at'] ?? ''),
                'updated_by' => (string)($data['updated_by'] ?? ''),
                'employees' => array_map('payrollNormalizeTerm', (array)($data['employees'] ?? [])),
            ];
        }
    }

    return [
        'period' => $period,
        'inherited_from' => '',
        'updated_at' => '',
        'updated_by' => '',
        'employees' => [],
    ];
}

/** Месяцы с сохранёнными условиями, от новых к старым. */
function payrollTermsPeriods(): array
{
    $periods = [];
    foreach ((array)glob(payrollDataDir() . '/payroll-terms-*.json') as $path) {
        if (preg_match('/payroll-terms-(\d{4}-\d{2})\.json$/', (string)$path, $m)) {
            $periods[] = $m[1];
        }
    }

    rsort($periods);

    return $periods;
}

function payrollPeriods(): array
{
    $periods = [];
    foreach ((array)glob(payrollDataDir() . '/payroll-[0-9]*.json') as $path) {
        if (preg_match('/payroll-(\d{4}-\d{2})\.json$/', (string)$path, $m)) {
            $periods[] = $m[1];
        }
    }

    rsort($periods);

    return $periods;
}

/**
 * Сохраняет условия месяца и пишет в журнал каждое изменение по полям.
 * Журнал — единственный способ потом ответить, кто и когда поднял оклад.
 */
function savePayrollTerms(string $period, array $employees, string $login): bool
{
    $previous = loadPayrollTerms($period);
    $previousEmployees = (array)($previous['employees'] ?? []);
    $inherited = (string)($previous['inherited_from'] ?? '') !== '';

    $normalized = [];
    foreach ($employees as $key => $term) {
        $term['key'] = (string)$key;
        $normalized[(string)$key] = payrollNormalizeTerm($term);
    }

    $changes = [];
    $tracked = ['name', 'scheme', 'salary', 'rate', 'plan_hours', 'constant_bonus', 'kpi_mode', 'unit', 'active', 'note'];

    foreach ($normalized as $key => $term) {
        $before = $previousEmployees[$key] ?? null;

        if ($before === null) {
            $changes[] = [
                'employee' => $term['name'],
                'field' => 'employee',
                'from' => '',
                'to' => 'добавлен',
            ];
            continue;
        }

        foreach ($tracked as $field) {
            $from = $before[$field] ?? null;
            $to = $term[$field] ?? null;
            if ((string)$from !== (string)$to) {
                $changes[] = [
                    'employee' => $term['name'],
                    'field' => $field,
                    'from' => (string)$from,
                    'to' => (string)$to,
                ];
            }
        }
    }

    foreach ($previousEmployees as $key => $before) {
        if (!isset($normalized[$key])) {
            $changes[] = [
                'employee' => (string)($before['name'] ?? $key),
                'field' => 'employee',
                'from' => 'был',
                'to' => 'удалён',
            ];
        }
    }

    // Первое сохранение месяца поверх унаследованных условий изменением не
    // считается: человек просто зафиксировал то, что и так действовало.
    if ($inherited && $changes === []) {
        $changes[] = [
            'employee' => '',
            'field' => 'period',
            'from' => (string)$previous['inherited_from'],
            'to' => 'условия закреплены за месяцем',
        ];
    }

    $saved = payrollWriteJson(payrollTermsPath($period), [
        'period' => $period,
        'updated_at' => date('c'),
        'updated_by' => $login,
        'employees' => $normalized,
    ]);

    if ($saved && $changes !== []) {
        appendPayrollLog($period, $login, $changes);
    }

    return $saved;
}

function appendPayrollLog(string $period, string $login, array $changes): void
{
    $log = payrollReadJson(payrollLogPath()) ?? [];
    $entries = is_array($log['entries'] ?? null) ? $log['entries'] : [];

    foreach ($changes as $change) {
        $entries[] = [
            'at' => date('c'),
            'by' => $login,
            'period' => $period,
            'employee' => (string)($change['employee'] ?? ''),
            'field' => (string)($change['field'] ?? ''),
            'from' => (string)($change['from'] ?? ''),
            'to' => (string)($change['to'] ?? ''),
        ];
    }

    // Журнал не должен расти бесконечно: держим последние 2000 записей.
    if (count($entries) > 2000) {
        $entries = array_slice($entries, -2000);
    }

    payrollWriteJson(payrollLogPath(), ['entries' => $entries]);
}

function loadPayrollLog(string $period = ''): array
{
    $log = payrollReadJson(payrollLogPath()) ?? [];
    $entries = is_array($log['entries'] ?? null) ? $log['entries'] : [];

    if ($period !== '') {
        $entries = array_values(array_filter($entries, static function (array $entry) use ($period): bool {
            return (string)($entry['period'] ?? '') === $period;
        }));
    }

    return array_reverse($entries);
}

function loadPayrollMonth(string $period): array
{
    $data = payrollReadJson(payrollMonthPath($period)) ?? [];

    return [
        'period' => $period,
        'updated_at' => (string)($data['updated_at'] ?? ''),
        'updated_by' => (string)($data['updated_by'] ?? ''),
        'paid_at' => (string)($data['paid_at'] ?? ''),
        'rows' => is_array($data['rows'] ?? null) ? $data['rows'] : [],
        'kpi_assign' => is_array($data['kpi_assign'] ?? null) ? $data['kpi_assign'] : [],
    ];
}

function savePayrollMonth(string $period, array $month, string $login): bool
{
    $month['period'] = $period;
    $month['updated_at'] = date('c');
    $month['updated_by'] = $login;

    return payrollWriteJson(payrollMonthPath($period), $month);
}

/** Ручная правка месяца по сотруднику, приведённая к числам. */
function payrollNormalizeRow(array $row): array
{
    return [
        'bonus' => round((float)($row['bonus'] ?? 0), 2),
        'penalty' => round((float)($row['penalty'] ?? 0), 2),
        'exam' => round((float)($row['exam'] ?? 0), 2),
        'plus_hours' => round((float)($row['plus_hours'] ?? 0), 2),
        'minus_hours' => round((float)($row['minus_hours'] ?? 0), 2),
        'manual_count' => round((float)($row['manual_count'] ?? 0), 2),
        'comment' => trim((string)($row['comment'] ?? '')),
    ];
}

/**
 * Считает выплату одному человеку.
 *
 * @param array $term  условия (схема, оклад, ставка)
 * @param float $hours итоговые часы: из отчёта плюс/минус ручные правки
 * @param array $row   ручные премия, штраф, экзамен
 * @param float $kpi   сумма KPI, начисленная этому человеку
 */
function payrollCalculateOne(array $term, float $hours, array $row, float $kpi): array
{
    $rate = (float)$term['rate'];
    $salary = (float)$term['salary'];
    $byRate = round($hours * $rate, 2);

    switch ($term['scheme']) {
        case 'floor':
            // Оклад не сгорает: если наработал больше — платим по ставке.
            $base = max($salary, $byRate);
            break;
        case 'sum':
            $base = $salary + $byRate;
            break;
        case 'fixed':
            $base = $salary;
            break;
        case 'hourly':
        default:
            $base = $byRate;
            break;
    }

    switch ($term['kpi_mode']) {
        case 'full':
            $kpiPay = $kpi;
            break;
        case 'topup':
            // KPI = оклад + премия, поэтому доплачивается только разница.
            $kpiPay = max(0.0, $kpi - $salary);
            break;
        case 'none':
        default:
            $kpiPay = 0.0;
            break;
    }

    $total = $base
        + (float)$term['constant_bonus']
        + (float)$row['bonus']
        + (float)$row['exam']
        + $kpiPay
        - (float)$row['penalty'];

    return [
        'hours' => round($hours, 2),
        'by_rate' => $byRate,
        'base' => round($base, 2),
        'kpi' => round($kpi, 2),
        'kpi_pay' => round($kpiPay, 2),
        'total' => round($total, 2),
    ];
}

/**
 * Собирает расчёт за месяц: условия + часы из снимка дашборда + KPI из
 * снимка KPI + ручные правки. В Битрикс не ходит — только сохранённые данные,
 * поэтому страница открывается мгновенно и не жжёт лимиты вебхука.
 */
function buildPayrollReport(string $period): array
{
    $terms = loadPayrollTerms($period);
    $month = loadPayrollMonth($period);

    $snapshot = loadDashboardSnapshot($period);
    $hoursByKey = [];
    foreach ((array)($snapshot['employees'] ?? []) as $employee) {
        $key = payrollEmployeeKey((int)($employee['id'] ?? 0));
        $hoursByKey[$key] = (float)($employee['hours'] ?? 0);
    }

    $kpiReport = loadKpiSnapshot($period);
    $kpiByKey = [];
    foreach ((array)($kpiReport['people'] ?? []) as $person) {
        $key = payrollEmployeeKey((int)($person['id'] ?? 0));
        $kpiByKey[$key] = (float)($person['kpi_total'] ?? 0);
    }

    // Галочки с вкладки KPI: кому и сколько ушло.
    $kpiCredited = [];
    foreach ($kpiByKey as $sourceKey => $amount) {
        $assign = $month['kpi_assign'][$sourceKey] ?? null;
        if (!is_array($assign) || empty($assign['apply'])) {
            continue;
        }

        $recipient = (string)($assign['recipient'] ?? '') !== '' ? (string)$assign['recipient'] : $sourceKey;
        $kpiCredited[$recipient] = ($kpiCredited[$recipient] ?? 0) + $amount;
    }

    $rows = [];
    $totals = ['hours' => 0.0, 'base' => 0.0, 'kpi_pay' => 0.0, 'bonus' => 0.0, 'penalty' => 0.0, 'total' => 0.0];

    foreach ((array)$terms['employees'] as $key => $term) {
        $term = payrollNormalizeTerm($term);
        if (!$term['active']) {
            continue;
        }

        $manual = payrollNormalizeRow($month['rows'][$key] ?? []);

        // У сотрудников Битрикса часы приходят из отчёта, у ручных — вбиваются.
        $sourceHours = $term['source'] === 'manual'
            ? $manual['manual_count']
            : (float)($hoursByKey[$key] ?? 0);

        $hours = $sourceHours + $manual['plus_hours'] - $manual['minus_hours'];
        $calculated = payrollCalculateOne($term, $hours, $manual, (float)($kpiCredited[$key] ?? 0));

        $planHours = (float)$term['plan_hours'];

        $rows[] = array_merge($term, $manual, $calculated, [
            'key' => (string)$key,
            'source_hours' => round($sourceHours, 2),
            'plan_done' => $planHours > 0 ? round($hours / $planHours * 100, 1) : null,
        ]);

        // В сумму часов идут только те, у кого единица — час: у HR это люди,
        // и складывать их с часами нельзя.
        if ($term['unit'] === 'ч') {
            $totals['hours'] += $calculated['hours'];
        }
        $totals['base'] += $calculated['base'];
        $totals['kpi_pay'] += $calculated['kpi_pay'];
        $totals['bonus'] += $manual['bonus'] + $manual['exam'] + $term['constant_bonus'];
        $totals['penalty'] += $manual['penalty'];
        $totals['total'] += $calculated['total'];
    }

    usort($rows, static function (array $left, array $right): int {
        if ($left['total'] !== $right['total']) {
            return $right['total'] <=> $left['total'];
        }

        return strnatcasecmp((string)$left['name'], (string)$right['name']);
    });

    foreach ($totals as $key => $value) {
        $totals[$key] = round($value, 2);
    }

    return [
        'period' => $period,
        'month_title' => russianMonthTitle(monthPeriod($period)[0]),
        'terms_inherited_from' => (string)($terms['inherited_from'] ?? ''),
        'terms_updated_at' => (string)($terms['updated_at'] ?? ''),
        'terms_updated_by' => (string)($terms['updated_by'] ?? ''),
        'updated_at' => (string)($month['updated_at'] ?? ''),
        'updated_by' => (string)($month['updated_by'] ?? ''),
        'has_hours' => $snapshot !== null,
        'has_kpi' => $kpiReport !== null,
        'rows' => $rows,
        'totals' => $totals,
        'kpi_credited' => $kpiCredited,
    ];
}

/**
 * Кандидаты в сотрудники: те, кто есть в снимке часов, но ещё не заведён
 * в условиях. Чтобы не вбивать ФИО руками и не разъезжаться с Битриксом.
 */
function payrollCandidates(string $period, array $terms): array
{
    $snapshot = loadDashboardSnapshot($period);
    $candidates = [];

    foreach ((array)($snapshot['employees'] ?? []) as $employee) {
        $bitrixId = (int)($employee['id'] ?? 0);
        if ($bitrixId <= 0) {
            continue;
        }

        $key = payrollEmployeeKey($bitrixId);
        if (isset($terms['employees'][$key])) {
            continue;
        }

        $candidates[$key] = [
            'key' => $key,
            'bitrix_id' => $bitrixId,
            'name' => (string)($employee['name'] ?? ''),
            'hours' => (float)($employee['hours'] ?? 0),
        ];
    }

    return $candidates;
}

function payrollMoney(float $value): string
{
    return number_format($value, 0, ',', ' ') . ' ₽';
}
