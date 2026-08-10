<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/*
 * Роли:
 *  - admin    — все права (учётка из config: APP_AUTH_USER/APP_AUTH_PASSWORD);
 *  - employee — Задачи и Дашборд, может скачивать Excel, не может создавать документы в УНФ;
 *  - external — видит всё, но данные замаскированы; не скачивает отчёт и ничего не создаёт;
 *  - guest    — то же, что external, но без пароля: так сайт открывается любому.
 * Дополнительные пользователи хранятся в data/users.json (пароли — password_hash).
 *
 * Гостя нельзя завести в data/users.json: это не учётка, а режим «без входа»,
 * поэтому его нет в AUTH_ROLES — иначе он появился бы в выпадашке админки.
 */

const AUTH_ROLES = [
    'admin' => 'Администратор',
    'employee' => 'Сотрудник',
    'external' => 'Внешний',
];

const AUTH_GUEST_ROLE = 'guest';

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

    $hasCredentials = (string)$user !== '' || (string)$password !== '';

    // Гостевой режим включён и логин/пароль не присланы — пускаем без входа.
    // Браузер сам не покажет форму, пока сервер не ответит 401, поэтому
    // «Войти» ведёт на ?login=1: только он и запрашивает пароль.
    if (!$hasCredentials && APP_GUEST_ACCESS && !isset($_GET['login'])) {
        $GLOBALS['CURRENT_AUTH_USER'] = ['login' => '', 'role' => AUTH_GUEST_ROLE];
        return;
    }

    $resolved = $hasCredentials ? authResolveUser((string)$user, (string)$password) : null;
    if ($resolved === null) {
        header('WWW-Authenticate: Basic realm="Closed Hours"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Авторизация не пройдена';
        exit;
    }

    $GLOBALS['CURRENT_AUTH_USER'] = $resolved;
}

function isGuest(): bool
{
    return currentUserRole() === AUTH_GUEST_ROLE;
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
        case 'payroll':     // оклады, ставки и расчёт зарплаты
            // Зарплаты — самые чувствительные данные в системе, а сайт открыт
            // без пароля. Раздел виден только администратору: маскировки тут
            // мало, суммы не должны попасть даже к «Внешнему».
            return $role === 'admin';
        case 'excel':       // скачивание отчёта
        case 'refresh':     // обновление дашборда (ходит в Битрикс)
            return $role === 'admin' || $role === 'employee';
        case 'live':        // живые запросы в Битрикс при открытии страницы
            // Гостю нельзя: страница открыта всему интернету, а каждая сборка
            // доски или проверки задач — это десятки REST-запросов, и анонимный
            // трафик выжег бы лимиты вебхука для своих.
            return $role !== AUTH_GUEST_ROLE;
        case 'view':
            return true;
        default:
            return false;
    }
}

function isMaskedView(): bool
{
    $role = currentUserRole();

    return $role === 'external' || $role === AUTH_GUEST_ROLE;
}

/**
 * Обезличивает значение, но оставляет понятным, что это за сущность:
 * не «49FC1B431857BB», а «Сотрудник #3» или «Проект #7».
 *
 * Номер выдаётся по порядку первого появления в пределах одной страницы,
 * поэтому одно и то же имя везде на странице выглядит одинаково, а разные
 * сущности не сливаются в один номер. Между страницами нумерация своя —
 * данные всё равно обезличены, а внутри страницы список читается.
 */
function maskValue($value, string $kind = 'generic'): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '-') {
        return $value;
    }

    static $labels = [
        'employee' => 'Сотрудник',
        'company' => 'Компания',
        'project' => 'Проект',
        'task' => 'Задача',
        'generic' => 'Запись',
    ];
    static $registry = [];

    $kind = isset($labels[$kind]) ? $kind : 'generic';

    if (!isset($registry[$kind][$value])) {
        $registry[$kind][$value] = count($registry[$kind] ?? []) + 1;
    }

    return $labels[$kind] . ' #' . $registry[$kind][$value];
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
