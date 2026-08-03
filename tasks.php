<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

$view = $_GET['view'] ?? 'active';
if (!is_string($view) || !in_array($view, ['active', 'closed'], true)) {
    $view = 'active';
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

$board = null;
$boardError = '';
try {
    $board = buildProjectBoard($view, $period);
} catch (Throwable $e) {
    $boardError = $e->getMessage();
}

// Роль "Внешний" видит структуру доски, но данные зашифрованы.
$maskedView = isMaskedView();
if ($maskedView && is_array($board)) {
    foreach ($board['projects'] as &$maskedProject) {
        $maskedProject['name'] = maskValue($maskedProject['name']);
        $maskedProject['url'] = '';
        foreach ($maskedProject['tasks'] as &$maskedTask) {
            $maskedTask['title'] = maskValue($maskedTask['title']);
            $maskedTask['responsible'] = maskValue($maskedTask['responsible']);
            $maskedTask['company'] = $maskedTask['company'] !== '-' ? maskValue($maskedTask['company']) : '-';
            $maskedTask['url'] = '';
        }
        unset($maskedTask);
    }
    unset($maskedProject);
}

$pageTitle = 'Задачи — taskCRM';
$navActive = 'tasks';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel board-panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / Доска</p>
                <h1>Задачи</h1>
            </div>
            <?php if ($view === 'closed'): ?>
                <span class="month-chip"><?= h($board['month_title'] ?? russianMonthTitle($monthStart)) ?></span>
            <?php else: ?>
                <span class="month-chip">Команда · сейчас</span>
            <?php endif; ?>
        </div>

        <div class="board-toolbar">
            <div class="segmented" role="tablist" aria-label="Тип задач">
                <a class="seg<?= $view === 'active' ? ' is-active' : '' ?>"
                   role="tab" aria-selected="<?= $view === 'active' ? 'true' : 'false' ?>"
                   href="tasks.php?view=active&amp;period=<?= h(rawurlencode($period)) ?>">Активные задачи</a>
                <a class="seg<?= $view === 'closed' ? ' is-active' : '' ?>"
                   role="tab" aria-selected="<?= $view === 'closed' ? 'true' : 'false' ?>"
                   href="tasks.php?view=closed&amp;period=<?= h(rawurlencode($period)) ?>">Закрытые задачи</a>
            </div>

            <?php if ($view === 'closed'): ?>
                <div class="board-period">
                    <select id="boardMonth" aria-label="Месяц">
                        <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                            <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>><?= h($monthName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="boardYear" aria-label="Год">
                        <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                            <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>><?= h((string)$year) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            <?php else: ?>
                <p class="board-hint">Показаны все активные задачи команды, сгруппированные по проектам.</p>
            <?php endif; ?>
        </div>

        <?php if ($view === 'active'): ?>
            <div class="board-filters" aria-label="Фильтры по статусу">
                <button class="filter-toggle" type="button" data-status="2" aria-pressed="true">
                    <span>Скрыть ждущие выполнения</span>
                </button>
                <button class="filter-toggle" type="button" data-status="3" aria-pressed="true">
                    <span>Скрыть в работе</span>
                </button>
                <button class="filter-toggle" type="button" data-status="6" aria-pressed="true">
                    <span>Скрыть отложенные</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($boardError !== ''): ?>
            <div class="board-empty board-empty--error">
                <p>Не удалось загрузить задачи из Битрикс.</p>
                <p class="board-empty-detail"><?= h($boardError) ?></p>
            </div>
        <?php elseif (empty($board['projects'])): ?>
            <div class="board-empty">
                <p><?= $view === 'closed'
                    ? 'За выбранный месяц закрытых задач команды не найдено.'
                    : 'Активных задач команды сейчас нет.' ?></p>
            </div>
        <?php else: ?>
            <p class="board-stats">
                Проектов: <strong><?= (int)$board['projects_count'] ?></strong> ·
                Задач: <strong><?= (int)$board['tasks_total'] ?></strong>
                <span class="board-stats-hint">Колонки листаются вправо, задачи — вниз.</span>
            </p>
            <div class="board" tabindex="0" aria-label="Доска задач по проектам">
                <?php foreach ($board['projects'] as $project): ?>
                    <div class="board-column">
                        <div class="board-column-head">
                            <h2 class="board-column-title">
                                <?php if (!empty($project['url'])): ?>
                                    <a href="<?= h($project['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($project['name']) ?></a>
                                <?php else: ?>
                                    <?= h($project['name']) ?>
                                <?php endif; ?>
                            </h2>
                            <span class="board-count"><?= (int)$project['count'] ?></span>
                        </div>
                        <div class="board-cards">
                            <?php foreach ($project['tasks'] as $task): ?>
                                <article class="task-card" data-status="<?= (int)$task['status_code'] ?>">
                                    <div class="task-card-top">
                                        <span class="task-id">#<?= (int)$task['id'] ?></span>
                                        <span class="task-badge"><?= h($task['status']) ?></span>
                                    </div>
                                    <h3 class="task-title">
                                        <?php if (!empty($task['url'])): ?>
                                            <a href="<?= h($task['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($task['title']) ?></a>
                                        <?php else: ?>
                                            <?= h($task['title']) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <dl class="task-meta">
                                        <div>
                                            <dt>Ответственный</dt>
                                            <dd><?= h($task['responsible']) ?></dd>
                                        </div>
                                        <?php if ($task['company'] !== '-' && $task['company'] !== ''): ?>
                                            <div>
                                                <dt>Компания</dt>
                                                <dd><?= h($task['company']) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <?php if ($view === 'closed'): ?>
                                                <dt>Закрыта</dt>
                                                <dd><?= h($task['closed']) ?></dd>
                                            <?php else: ?>
                                                <dt>Дедлайн</dt>
                                                <dd><?= h($task['deadline']) ?></dd>
                                            <?php endif; ?>
                                        </div>
                                    </dl>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!empty($project['truncated'])): ?>
                                <p class="task-truncated">…и ещё <?= (int)$project['truncated'] ?> задач(и)</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<script>
    (function () {
        var labels = {
            '2': 'ждущие выполнения',
            '3': 'в работе',
            '6': 'отложенные'
        };

        function refreshColumns() {
            document.querySelectorAll('.board-column').forEach(function (column) {
                var cards = column.querySelectorAll('.task-card');
                var visibleCount = 0;

                cards.forEach(function (card) {
                    if (!card.classList.contains('is-filtered-out')) {
                        visibleCount++;
                    }
                });

                // Проект без видимых задач скрывается вместе с колонкой.
                column.classList.toggle('is-filtered-out', cards.length > 0 && visibleCount === 0);

                var counter = column.querySelector('.board-count');
                if (counter) {
                    counter.textContent = String(visibleCount);
                }
            });
        }

        document.querySelectorAll('.filter-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var status = button.dataset.status;
                var visible = button.getAttribute('aria-pressed') === 'true';
                var nextVisible = !visible;

                button.setAttribute('aria-pressed', nextVisible ? 'true' : 'false');
                button.querySelector('span').textContent =
                    (nextVisible ? 'Скрыть ' : 'Показать ') + (labels[status] || '');

                document.querySelectorAll('.task-card[data-status="' + status + '"]').forEach(function (card) {
                    card.classList.toggle('is-filtered-out', !nextVisible);
                });

                refreshColumns();
            });
        });
    }());
</script>
<?php if ($view === 'closed'): ?>
<script>
    (function () {
        var month = document.getElementById('boardMonth');
        var year = document.getElementById('boardYear');
        if (!month || !year) {
            return;
        }

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function reload() {
            var period = year.value + '-' + pad(month.value);
            window.location.href = 'tasks.php?view=closed&period=' + encodeURIComponent(period);
        }

        month.addEventListener('change', reload);
        year.addEventListener('change', reload);
    }());
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/foot.php'; ?>
