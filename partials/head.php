<?php
declare(strict_types=1);

/**
 * Общая «шапка» страниц: фон, курсор-трейл, верхняя навигация.
 *
 * Ожидает заданными до подключения:
 * @var string      $pageTitle  Заголовок вкладки браузера.
 * @var string      $navActive  Активный пункт меню: main|tasks|dashboard.
 * @var string|null $period     Выбранный период YYYY-MM (для проброса между вкладками).
 * @var string|null $shellClass Доп. класс для <main class="shell ...">.
 *
 * Требует уже подключённого lib.php (функция h(), константы конфигурации).
 */

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'taskCRM';
$navActive = isset($navActive) ? (string)$navActive : '';
$shellClass = isset($shellClass) ? (string)$shellClass : '';

$navPeriod = (isset($period) && is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period))
    ? $period
    : date('Y-m');
$navQuery = '?period=' . rawurlencode($navPeriod);

$styleVersion = is_file(__DIR__ . '/../style.css') ? (string)filemtime(__DIR__ . '/../style.css') : '1';

$navItems = [
    ['key' => 'main', 'label' => 'Главная', 'href' => 'index.php' . $navQuery, 'external' => false],
    ['key' => 'tasks', 'label' => 'Задачи', 'href' => 'tasks.php' . $navQuery, 'external' => false],
    ['key' => 'dashboard', 'label' => 'Дашборд', 'href' => 'dashboard.php' . $navQuery, 'external' => false],
    ['key' => 'quality', 'label' => 'Проверка задач', 'href' => 'quality.php' . $navQuery, 'external' => false],
    ['key' => 'kpi', 'label' => 'KPI по задачам', 'href' => 'kpi.php' . $navQuery, 'external' => false],
];

$gameUserLogin = trim((string)($_SERVER['PHP_AUTH_USER'] ?? ''));
if ($gameUserLogin === '') {
    $gameUserLogin = 'гость';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= h($styleVersion) ?>">
    <script>window.CC_USER = <?= json_encode($gameUserLogin, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
</head>
<body>
<canvas id="cursorTrail" aria-hidden="true"></canvas>
<main class="shell<?= $shellClass !== '' ? ' ' . h($shellClass) : '' ?>">
    <nav class="topnav panel" aria-label="Основная навигация">
        <div class="topnav-inner">
            <?php foreach ($navItems as $item): ?>
                <?php $isActive = !$item['external'] && $item['key'] === $navActive; ?>
                <a
                    class="navbtn<?= $isActive ? ' is-active' : '' ?>"
                    href="<?= h($item['href']) ?>"
                    <?= $isActive ? 'aria-current="page"' : '' ?>
                    <?= $item['external'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                >
                    <span><?= h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
