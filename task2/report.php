<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? '';

try {
    if (!is_string($period) || $period === '') {
        throw new InvalidArgumentException('Не выбран месяц отчёта.');
    }

    $report = buildClosedHoursReport($period);
    downloadXlsx($report);
} catch (Throwable $e) {
    $styleVersion = is_file(__DIR__ . '/style.css') ? (string)filemtime(__DIR__ . '/style.css') : '1';
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка</title><link rel="stylesheet" href="style.css?v=' . h($styleVersion) . '"></head><body>';
    echo '<main class="shell"><section class="panel error"><h1>Не удалось сформировать отчёт</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    echo '<a href="index.php">Вернуться</a>';
    echo '</section></main></body></html>';
}
