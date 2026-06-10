<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

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
    if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Не выбран месяц.');
    }

    $report = buildClosedHoursReport($period);
    $saved = saveDashboardSnapshot($report);
    $snapshot = loadDashboardSnapshot($period);

    echo json_encode([
        'ok' => $saved,
        'period' => $period,
        'saved' => $saved,
        'total_hours' => $snapshot['total_hours'] ?? $report['total_hours'] ?? 0,
        'employees_count' => is_array($snapshot['employees'] ?? null) ? count($snapshot['employees']) : 0,
        'updated_at' => $snapshot['updated_at'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
