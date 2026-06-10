<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

header('Content-Type: application/json; charset=utf-8');

/*
 * Общая таблица рекордов мини-игры. Хранится в data/leaderboard.json,
 * ключ — логин Basic Auth, сохраняется лучший результат каждого игрока.
 * GET  — топ и личный рекорд; POST {score} — заявка нового результата.
 */

function gameLeaderboardPath(): string
{
    return rtrim(DASHBOARD_DATA_DIR, "/\\") . '/leaderboard.json';
}

function gameLoadLeaderboard(): array
{
    $path = gameLeaderboardPath();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        return [];
    }

    $result = [];
    foreach ($data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = trim((string)($entry['name'] ?? ''));
        $score = (int)($entry['score'] ?? 0);
        if ($name === '' || $score <= 0) {
            continue;
        }
        $result[$name] = [
            'name' => $name,
            'score' => $score,
            'updated_at' => (string)($entry['updated_at'] ?? ''),
        ];
    }

    return $result;
}

function gameSaveLeaderboard(array $board): bool
{
    $dir = dirname(gameLeaderboardPath());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $json = json_encode(array_values($board), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents(gameLeaderboardPath(), $json, LOCK_EX) !== false;
}

function gameResponse(array $board, string $login): array
{
    usort($board, static function (array $left, array $right): int {
        if ($left['score'] !== $right['score']) {
            return $right['score'] <=> $left['score'];
        }

        return strnatcasecmp($left['name'], $right['name']);
    });

    $leaders = array_map(static function (array $entry): array {
        return ['name' => $entry['name'], 'score' => $entry['score']];
    }, array_slice($board, 0, 10));

    $myBest = 0;
    foreach ($board as $entry) {
        if ($entry['name'] === $login) {
            $myBest = $entry['score'];
            break;
        }
    }

    return ['ok' => true, 'leaders' => $leaders, 'my_best' => $myBest];
}

$login = currentUserLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = $_POST;
    if ($input === []) {
        $rawBody = file_get_contents('php://input');
        $decoded = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $score = (int)($input['score'] ?? 0);
    if ($score < 1 || $score > 1000000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Некорректный счёт.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $board = gameLoadLeaderboard();
    $currentBest = (int)($board[$login]['score'] ?? 0);

    if ($score > $currentBest) {
        $board[$login] = [
            'name' => $login,
            'score' => $score,
            'updated_at' => date('c'),
        ];
        gameSaveLeaderboard($board);
    }

    echo json_encode(gameResponse(array_values($board), $login), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(gameResponse(array_values(gameLoadLeaderboard()), $login), JSON_UNESCAPED_UNICODE);
