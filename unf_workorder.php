<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/unf.php';

requireAuth();

if (!userCan('unf')) {
    denyJson('Создание документов в УНФ доступно только администратору.');
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Метод не поддерживается. Используйте POST.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$input = $_POST;
if ($input === []) {
    $rawBody = file_get_contents('php://input');
    $decoded = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$period = $input['period'] ?? '';

try {
    if (!is_string($period) || $period === '') {
        throw new InvalidArgumentException('Не выбран месяц отчёта.');
    }

    $report = buildClosedHoursReport($period);
    saveDashboardSnapshot($report);
    $result = createUnfWorkOrdersForReport($report);
    http_response_code($result['ok'] ? 200 : 207);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
