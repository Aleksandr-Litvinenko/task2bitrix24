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

function configBoolValue(string $envName, string $localConstant, bool $default = false): bool
{
    $value = configValue($envName, $localConstant, $default ? '1' : '0');
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function configArrayValue(string $envName, string $localConstant): array
{
    $envValue = getenv($envName);
    if (is_string($envValue) && $envValue !== '') {
        $decoded = json_decode($envValue, true);
        return is_array($decoded) ? $decoded : [];
    }

    if (defined($localConstant)) {
        $constantValue = constant($localConstant);
        return is_array($constantValue) ? $constantValue : [];
    }

    return [];
}

define('BITRIX_WEBHOOK', configValue('BITRIX_WEBHOOK', 'LOCAL_BITRIX_WEBHOOK'));

define('APP_AUTH_USER', configValue('APP_AUTH_USER', 'LOCAL_APP_AUTH_USER', 'admin'));
define('APP_AUTH_PASSWORD', configValue('APP_AUTH_PASSWORD', 'LOCAL_APP_AUTH_PASSWORD', 'change-me'));

define('UNF_ODATA_BASE_URL', configValue('UNF_ODATA_BASE_URL', 'LOCAL_UNF_ODATA_BASE_URL', 'https://1cfresh.com/a/sbm/767684/odata/standard.odata'));
define('UNF_ODATA_USER', configValue('UNF_ODATA_USER', 'LOCAL_UNF_ODATA_USER'));
define('UNF_ODATA_PASSWORD', configValue('UNF_ODATA_PASSWORD', 'LOCAL_UNF_ODATA_PASSWORD'));

define('UNF_TIME_ORGANIZATION_KEY', configValue('UNF_TIME_ORGANIZATION_KEY', 'LOCAL_UNF_TIME_ORGANIZATION_KEY', '496d3534-cb93-11e8-ba98-0050568931bf'));
define('UNF_TIME_STRUCTURAL_UNIT_KEY', configValue('UNF_TIME_STRUCTURAL_UNIT_KEY', 'LOCAL_UNF_TIME_STRUCTURAL_UNIT_KEY', '2f5ae0c4-cb93-11e8-ba98-0050568931bf'));
define('UNF_TIME_AUTHOR_KEY', configValue('UNF_TIME_AUTHOR_KEY', 'LOCAL_UNF_TIME_AUTHOR_KEY', '19701044-a34b-11f0-9405-fa163ecd09c5'));
define('UNF_TIME_BUSINESS_OPERATION_KEY', configValue('UNF_TIME_BUSINESS_OPERATION_KEY', 'LOCAL_UNF_TIME_BUSINESS_OPERATION_KEY', 'dabb22de-cba8-11e8-ba98-0050568931bf'));
define('UNF_TIME_PRICE_TYPE_KEY', configValue('UNF_TIME_PRICE_TYPE_KEY', 'LOCAL_UNF_TIME_PRICE_TYPE_KEY', '4ac66342-1752-11e9-8b16-14dae903f2d3'));
define('UNF_TIME_NOMENCLATURE_KEY', configValue('UNF_TIME_NOMENCLATURE_KEY', 'LOCAL_UNF_TIME_NOMENCLATURE_KEY', '8996c0c8-4e1d-11e9-fb97-fa163ecd09c5'));
define('UNF_TIME_WORK_TYPE_KEY', configValue('UNF_TIME_WORK_TYPE_KEY', 'LOCAL_UNF_TIME_WORK_TYPE_KEY', 'a7bfd5c2-0d5a-11f1-9cde-fa163ecd09c5'));
define('UNF_TIME_RATE', (float)configValue('UNF_TIME_RATE', 'LOCAL_UNF_TIME_RATE', '0'));
define('UNF_TIME_POST_DOCUMENTS', configBoolValue('UNF_TIME_POST_DOCUMENTS', 'LOCAL_UNF_TIME_POST_DOCUMENTS', false));
define('UNF_EMPLOYEE_KEY_MAP', configArrayValue('UNF_EMPLOYEE_KEY_MAP', 'LOCAL_UNF_EMPLOYEE_KEY_MAP'));

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
