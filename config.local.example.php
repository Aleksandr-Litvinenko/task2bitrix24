<?php
declare(strict_types=1);

define('LOCAL_BITRIX_WEBHOOK', 'https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/');
define('LOCAL_APP_AUTH_USER', 'admin');
define('LOCAL_APP_AUTH_PASSWORD', 'change-me');

define('LOCAL_UNF_ODATA_BASE_URL', 'https://1cfresh.com/a/sbm/767684/odata/standard.odata');
define('LOCAL_UNF_ODATA_USER', '<odata-user>');
define('LOCAL_UNF_ODATA_PASSWORD', '<odata-password>');

// Необязательно: ручные соответствия, если ФИО в Битрикс и УНФ отличаются.
// Ключом может быть Bitrix user ID, ФИО из отчёта или нормализованное ФИО.
define('LOCAL_UNF_EMPLOYEE_KEY_MAP', [
    // '1234' => '00000000-0000-0000-0000-000000000000',
]);

// По умолчанию документы только записываются. Включайте проведение осознанно.
define('LOCAL_UNF_TIME_POST_DOCUMENTS', false);
define('LOCAL_UNF_TIME_RATE', '0');

// Необязательно: новый интерфейс (вкладки Задачи / Дашборд / ХОЧУ ТАКЖЕ).
// define('LOCAL_TELEGRAM_CHANNEL_URL', 'https://t.me/kodar1c');
// define('LOCAL_DASHBOARD_DATA_DIR', __DIR__ . '/data');
