<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    require_once $localConfigPath;
}

function configValue(string $envName, string $localConstant, string $default = ''): string
{
    $envValue = getenv($envName);
    if (is_string($envValue) && $envValue !== '') {
        return $envValue;
    }

    if (defined($localConstant)) {
        return (string)constant($localConstant);
    }

    return $default;
}

define('BITRIX_WEBHOOK', configValue('BITRIX_WEBHOOK', 'LOCAL_BITRIX_WEBHOOK'));

define('APP_AUTH_USER', configValue('APP_AUTH_USER', 'LOCAL_APP_AUTH_USER', 'admin'));
define('APP_AUTH_PASSWORD', configValue('APP_AUTH_PASSWORD', 'LOCAL_APP_AUTH_PASSWORD', 'change-me'));

// Оригинальный task/csv_closed.php считает закрытые задачи по REAL_STATUS 4 и 5.
define('REPORT_TASK_STATUSES', [4, 5]);

// Пользователи из оригинального users.php. Этот список используется как фильтр списанного времени.
define('REPORT_USER_IDS', [
    1,
    1731,
    1863,
    1899,
    1909,
    1967,
    2053,
    2055,
    2107,
    2211,
    2493,
    2513,
    2679,
    3261,
    3359,
    3465,
    4047,
    4079,
    4291,
    4309,
]);
