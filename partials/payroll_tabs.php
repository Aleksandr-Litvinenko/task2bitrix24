<?php
declare(strict_types=1);

/**
 * Подвкладки раздела «Расчёт ЗП».
 *
 * Ожидает заданными до подключения:
 * @var string $payrollTab Активная подвкладка: kpi|terms|salary.
 * @var string $period     Выбранный период YYYY-MM.
 */

$payrollTab = isset($payrollTab) ? (string)$payrollTab : 'kpi';
$payrollPeriod = (isset($period) && is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period))
    ? $period
    : date('Y-m');

$payrollTabs = [
    'kpi' => ['label' => 'KPI по задачам', 'href' => 'kpi.php'],
    'terms' => ['label' => 'Условия, оклады и ставка', 'href' => 'payroll_terms.php'],
    'salary' => ['label' => 'Расчёт ЗП', 'href' => 'payroll.php'],
];
?>
<div class="segmented payroll-tabs" role="tablist" aria-label="Разделы расчёта зарплаты">
    <?php foreach ($payrollTabs as $tabKey => $tab): ?>
        <a class="seg<?= $tabKey === $payrollTab ? ' is-active' : '' ?>"
           role="tab"
           aria-selected="<?= $tabKey === $payrollTab ? 'true' : 'false' ?>"
           href="<?= h($tab['href']) ?>?period=<?= h(rawurlencode($payrollPeriod)) ?>"><?= h($tab['label']) ?></a>
    <?php endforeach; ?>
</div>
