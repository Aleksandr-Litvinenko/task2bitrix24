<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

// Проверка ходит в Битрикс, поэтому гостю она недоступна: ему остаётся
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

// Выгрузка Excel — только тем, кому доступен отчёт.
if ($export && !userCan('excel')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Выгрузка недоступна для вашей роли.';
    exit;
}

$report = null;
$reportError = '';
$fromCache = false;

// Экспорт и повторное открытие страницы берут последний снимок проверки,
// чтобы не гонять Битрикс ещё раз; кнопка «Проверить задачи» пересчитывает.
if ($export || !$run) {
    $report = loadQualitySnapshot($period);
    $fromCache = $report !== null;
}

if ($report === null && ($run || $export)) {
    try {
        $report = buildTaskQualityReport($period);
        saveQualitySnapshot($report);
        $fromCache = false;
    } catch (Throwable $e) {
        $reportError = $e->getMessage();
    }
}

if ($export && $report !== null && $reportError === '') {
    downloadQualityXlsx($report);
    exit;
}

$maskedView = isMaskedView();
if ($maskedView && $report !== null) {
    foreach ($report['rows'] as &$maskedRow) {
        $maskedRow['title'] = maskValue($maskedRow['title'], 'task');
        $maskedRow['company'] = $maskedRow['company'] !== '-' ? maskValue($maskedRow['company'], 'company') : '-';
        $maskedRow['project'] = $maskedRow['project'] !== '' ? maskValue($maskedRow['project'], 'project') : '';
        $maskedRow['responsible'] = maskValue($maskedRow['responsible'], 'employee');
        $maskedRow['result_text'] = '';
        $maskedRow['url'] = '';
    }
    unset($maskedRow);
}

$issueTitles = qualityIssueTitles();
$issueSeverities = qualityIssueSeverities();

$pageTitle = 'Проверка задач — taskCRM';
$navActive = 'quality';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / Контроль</p>
                <h1 class="section-title">Проверка корректности заполнения задач</h1>
            </div>
            <span class="month-chip"><?= h($report['month_title'] ?? russianMonthTitle($monthStart)) ?></span>
        </div>

        <form class="board-toolbar" method="get" action="quality.php">
            <input type="hidden" name="run" value="1">
            <div class="board-period">
                <select name="periodMonth" id="qualityMonth" aria-label="Месяц">
                    <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                        <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>><?= h($monthName) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="periodYear" id="qualityYear" aria-label="Год">
                    <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                        <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>><?= h((string)$year) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <input type="hidden" name="period" id="qualityPeriod" value="<?= h($period) ?>">
            <button type="submit"><span>Проверить задачи</span></button>
            <?php if ($report !== null && !empty($report['rows']) && userCan('excel')): ?>
                <a class="button-link" href="quality.php?export=xlsx&amp;period=<?= h(rawurlencode($period)) ?>">
                    <span>Скачать Excel</span>
                </a>
            <?php endif; ?>
        </form>

        <?php if ($report !== null && $reportError === ''): ?>
            <?php
            $checkedTs = !empty($report['checked_at']) ? strtotime((string)$report['checked_at']) : 0;
            ?>
            <p class="board-hint section-note">
                <?php if ($fromCache && $checkedTs): ?>
                    Результат проверки от <?= h(date('d.m.Y H:i', $checkedTs)) ?>. Нажмите «Проверить задачи», чтобы обновить.
                <?php else: ?>
                    Проверка выполнена <?= h(date('d.m.Y H:i')) ?>.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($reportError !== ''): ?>
            <div class="board-empty board-empty--error">
                <p>Не удалось выполнить проверку.</p>
                <p class="board-empty-detail"><?= h($reportError) ?></p>
            </div>
        <?php elseif ($report === null): ?>
            <div class="board-empty">
                <p>Выберите месяц и нажмите «Проверить задачи».</p>
                <p class="board-empty-detail">
                    Проверяются задачи, закрытые в выбранном месяце: списанные часы, результат и дата выполнения работ,
                    элемент CRM и проект. Проверка обращается к Битриксу и занимает около минуты.
                </p>
            </div>
        <?php else: ?>
            <div class="dash-summary">
                <div>
                    <dt>Закрыто задач</dt>
                    <dd><?= (int)$report['tasks_total'] ?></dd>
                </div>
                <div>
                    <dt>С замечаниями</dt>
                    <dd><?= (int)$report['tasks_with_issues'] ?></dd>
                </div>
                <div>
                    <dt>Красных</dt>
                    <dd class="quality-red-value"><?= (int)$report['red_tasks'] ?></dd>
                </div>
                <div>
                    <dt>Жёлтых</dt>
                    <dd class="quality-yellow-value"><?= (int)$report['yellow_tasks'] ?></dd>
                </div>
            </div>

            <?php if (!empty($report['issue_counts'])): ?>
                <?php arsort($report['issue_counts']); ?>
                <div class="quality-issue-stats">
                    <?php foreach ($report['issue_counts'] as $code => $count): ?>
                        <span class="quality-stat quality-stat--<?= h($issueSeverities[$code] ?? 'yellow') ?>">
                            <?= h($issueTitles[$code] ?? $code) ?>
                            <strong><?= (int)$count ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($report['rows'])): ?>
                <div class="board-empty">
                    <p>Замечаний нет — все задачи заполнены корректно.</p>
                </div>
            <?php else: ?>
                <ol class="quality-list">
                    <?php foreach ($report['rows'] as $row): ?>
                        <li class="quality-row quality-row--<?= h($row['severity']) ?>">
                            <div class="quality-row-head">
                                <span class="quality-badge quality-badge--<?= h($row['severity']) ?>">
                                    <?= $row['severity'] === 'red' ? 'Красный' : 'Жёлтый' ?>
                                </span>
                                <span class="quality-id">#<?= (int)$row['task_id'] ?></span>
                                <h2 class="quality-name">
                                    <?php if (!empty($row['url'])): ?>
                                        <a href="<?= h($row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($row['title']) ?></a>
                                    <?php else: ?>
                                        <?= h($row['title']) ?>
                                    <?php endif; ?>
                                </h2>
                                <span class="quality-hours"><?= h(number_format((float)$row['hours'], 2, '.', ' ')) ?> ч</span>
                            </div>
                            <div class="quality-meta">
                                <span><?= h($row['company']) ?></span>
                                <span><?= $row['project'] !== '' ? h($row['project']) : 'без проекта' ?></span>
                                <span><?= h($row['responsible']) ?></span>
                                <span>закрыта <?= h($row['closed_date']) ?></span>
                            </div>
                            <ul class="quality-issues">
                                <?php foreach ($row['issues'] as $issue): ?>
                                    <li class="quality-issue quality-issue--<?= h($issue['severity']) ?>"><?= h($issue['text']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<script>
    (function () {
        var month = document.getElementById('qualityMonth');
        var year = document.getElementById('qualityYear');
        var period = document.getElementById('qualityPeriod');
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
