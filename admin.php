<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

if (!userCan('admin')) {
    http_response_code(403);
    $pageTitle = 'Нет доступа — taskCRM';
    $navActive = '';
    require __DIR__ . '/partials/head.php';
    echo '<section class="panel page-panel">';
    echo '<div class="heading"><div><p class="eyebrow">taskCRM / Доступ</p><h1>Нет доступа</h1></div></div>';
    echo '<p class="board-hint">Администрирование доступно только роли «Администратор».</p>';
    echo '</section>';
    require __DIR__ . '/partials/foot.php';
    exit;
}

$message = '';
$messageState = 'success';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $login = trim((string)($_POST['login'] ?? ''));

    try {
        if ($login === APP_AUTH_USER) {
            throw new InvalidArgumentException('Учётка администратора задаётся в конфигурации, менять её здесь нельзя.');
        }

        if ($action === 'save') {
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? '');

            if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $login)) {
                throw new InvalidArgumentException('Логин: 3-32 символа, латиница, цифры, точки, дефисы.');
            }
            if (!isset(AUTH_ROLES[$role]) ) {
                throw new InvalidArgumentException('Неизвестная роль.');
            }

            $users = loadUsers();
            $exists = isset($users[$login]);

            if (!$exists && strlen($password) < 4) {
                throw new InvalidArgumentException('Пароль нового пользователя: минимум 4 символа.');
            }

            $users[$login] = [
                'login' => $login,
                'password_hash' => $password !== ''
                    ? password_hash($password, PASSWORD_DEFAULT)
                    : $users[$login]['password_hash'],
                'role' => $role,
            ];

            if (!saveUsers($users)) {
                throw new RuntimeException('Не удалось записать data/users.json (нет прав на запись?).');
            }

            $message = $exists
                ? 'Пользователь ' . $login . ' обновлён.'
                : 'Пользователь ' . $login . ' добавлен (' . AUTH_ROLES[$role] . ').';
        } elseif ($action === 'delete') {
            $users = loadUsers();
            if (!isset($users[$login])) {
                throw new InvalidArgumentException('Пользователь не найден.');
            }

            unset($users[$login]);
            if (!saveUsers($users)) {
                throw new RuntimeException('Не удалось записать data/users.json.');
            }

            $message = 'Пользователь ' . $login . ' удалён.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageState = 'error';
    }
}

$users = loadUsers();

$pageTitle = 'Администрирование — taskCRM';
$navActive = '';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel">
        <div class="heading">
            <div>
                <p class="eyebrow">taskCRM / Доступ</p>
                <h1>Администрирование</h1>
            </div>
            <span class="month-chip">Вы: <?= h(currentUserLogin()) ?></span>
        </div>

        <?php if ($message !== ''): ?>
            <p class="action-status admin-message" data-state="<?= h($messageState) ?>"><?= h($message) ?></p>
        <?php endif; ?>

        <div class="admin-grid">
            <div class="admin-card">
                <h2>Пользователи</h2>
                <ul class="admin-users">
                    <li class="admin-user">
                        <div class="admin-user-main">
                            <strong><?= h(APP_AUTH_USER) ?></strong>
                            <span class="role-badge role-badge--admin"><?= h(AUTH_ROLES['admin']) ?></span>
                        </div>
                        <span class="admin-user-note">из конфигурации, нельзя удалить</span>
                    </li>
                    <?php foreach ($users as $user): ?>
                        <li class="admin-user">
                            <div class="admin-user-main">
                                <strong><?= h($user['login']) ?></strong>
                                <span class="role-badge role-badge--<?= h($user['role']) ?>"><?= h(AUTH_ROLES[$user['role']] ?? $user['role']) ?></span>
                            </div>
                            <form method="post" class="admin-inline-form">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="login" value="<?= h($user['login']) ?>">
                                <button type="submit" class="admin-delete" onclick="return confirm('Удалить пользователя <?= h($user['login']) ?>?')">
                                    <span>Удалить</span>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <li class="admin-user admin-user--empty">Дополнительных пользователей пока нет.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="admin-card">
                <h2>Добавить / обновить пользователя</h2>
                <form method="post" class="admin-form">
                    <input type="hidden" name="action" value="save">
                    <label for="adminLogin">Логин</label>
                    <input id="adminLogin" name="login" type="text" required minlength="3" maxlength="32"
                           pattern="[a-zA-Z0-9._-]+" placeholder="например, ivanov">
                    <label for="adminPassword">Пароль <span class="admin-hint">(для существующего — пусто, чтобы не менять)</span></label>
                    <input id="adminPassword" name="password" type="password" autocomplete="new-password" placeholder="минимум 4 символа">
                    <label for="adminRole">Роль</label>
                    <select id="adminRole" name="role">
                        <?php foreach (AUTH_ROLES as $roleKey => $roleName): ?>
                            <option value="<?= h($roleKey) ?>"<?= $roleKey === 'employee' ? ' selected' : '' ?>><?= h($roleName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"><span>Сохранить</span></button>
                </form>

                <div class="admin-roles-help">
                    <h3>Права ролей</h3>
                    <p><strong>Администратор</strong> — все права, включая УНФ и эту панель.</p>
                    <p><strong>Сотрудник</strong> — Задачи и Дашборд, скачивание Excel; без создания документов в УНФ.</p>
                    <p><strong>Внешний</strong> — видит интерфейс, но данные зашифрованы; без отчёта и без записи в базы.</p>
                </div>
            </div>
        </div>
    </section>
<?php require __DIR__ . '/partials/foot.php'; ?>
