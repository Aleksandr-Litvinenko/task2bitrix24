<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/*
 * Роли:
 *  - admin    — все права (учётка из config: APP_AUTH_USER/APP_AUTH_PASSWORD);
 *  - employee — Задачи и Дашборд, может скачивать Excel, не может создавать документы в УНФ;
 *  - external — видит всё, но данные замаскированы; не скачивает отчёт и ничего не создаёт.
 * Дополнительные пользователи хранятся в data/users.json (пароли — password_hash).
 */

const AUTH_ROLES = [
    'admin' => 'Администратор',
    'employee' => 'Сотрудник',
    'external' => 'Внешний',
];

function usersFilePath(): string
{
    return rtrim(DASHBOARD_DATA_DIR, "/\\") . '/users.json';
}

function loadUsers(): array
{
    $path = usersFilePath();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $users = $raw !== false ? json_decode($raw, true) : [];
    if (!is_array($users)) {
        return [];
    }

    $result = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $login = trim((string)($user['login'] ?? ''));
        $hash = (string)($user['password_hash'] ?? '');
        $role = (string)($user['role'] ?? '');
        if ($login === '' || $hash === '' || !isset(AUTH_ROLES[$role])) {
            continue;
        }
        $result[$login] = [
            'login' => $login,
            'password_hash' => $hash,
            'role' => $role,
        ];
    }

    return $result;
}

function saveUsers(array $users): bool
{
    $dir = dirname(usersFilePath());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $json = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $tmp = usersFilePath() . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }

    return rename($tmp, usersFilePath());
}

function authResolveUser(string $login, string $password): ?array
{
    // Учётка администратора из конфигурации — всегда работает, роль admin.
    if (hash_equals(APP_AUTH_USER, $login) && hash_equals(APP_AUTH_PASSWORD, $password)) {
        return ['login' => $login, 'role' => 'admin'];
    }

    $users = loadUsers();
    $user = $users[$login] ?? null;
    if ($user !== null && password_verify($password, (string)$user['password_hash'])) {
        return ['login' => $login, 'role' => (string)$user['role']];
    }

    return null;
}

function requireAuth(): void
{
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $password = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($user === '' && $password === '') {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', (string)$authorization, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $password] = explode(':', $decoded, 2);
            }
        }
    }

    $resolved = authResolveUser((string)$user, (string)$password);
    if ($resolved === null) {
        header('WWW-Authenticate: Basic realm="Closed Hours"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Авторизация не пройдена';
        exit;
    }

    $GLOBALS['CURRENT_AUTH_USER'] = $resolved;
}

function currentUser(): array
{
    $user = $GLOBALS['CURRENT_AUTH_USER'] ?? null;

    return is_array($user) ? $user : ['login' => '', 'role' => 'external'];
}

function currentUserRole(): string
{
    return (string)(currentUser()['role'] ?? 'external');
}

function currentUserLogin(): string
{
    return (string)(currentUser()['login'] ?? '');
}

function userCan(string $permission): bool
{
    $role = currentUserRole();

    switch ($permission) {
        case 'admin':       // админ-панель, управление пользователями
        case 'unf':         // создание документов в УНФ
            return $role === 'admin';
        case 'excel':       // скачивание отчёта
        case 'refresh':     // обновление дашборда (ходит в Битрикс)
            return $role === 'admin' || $role === 'employee';
        case 'view':
            return true;
        default:
            return false;
    }
}

function isMaskedView(): bool
{
    return currentUserRole() === 'external';
}

function maskValue($value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '-') {
        return $value;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    $take = max(6, min(14, $length));

    return strtoupper(substr(md5('cc-mask:' . $value), 0, $take));
}

function maskNumber(): string
{
    return '•••';
}

function denyJson(string $message): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
