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

// По умолчанию документы создаются и проводятся. Поставьте false, чтобы только записывать.
define('LOCAL_UNF_TIME_POST_DOCUMENTS', true);
define('LOCAL_UNF_TIME_RATE', '0');

// Необязательно: "Задания на работу" (план = факт × коэффициент).
// define('LOCAL_UNF_WORK_ORDER_UPLIFT', '1.1');
// define('LOCAL_UNF_WORK_ORDER_BUSINESS_OPERATION_KEY', 'dabafa98-cba8-11e8-ba98-0050568931bf');
// define('LOCAL_UNF_WORK_ORDER_STATE_KEY', '515bf19a-cb93-11e8-ba98-0050568931bf');
// define('LOCAL_UNF_WORK_ORDER_CALENDAR_KEY', '615502ec-f2ed-11f0-8cc9-fa163ecd09c5');

// Необязательно: новый интерфейс (вкладки Задачи / Дашборд / ХОЧУ ТАКЖЕ).
// define('LOCAL_TELEGRAM_CHANNEL_URL', 'https://t.me/kodar1c');
// define('LOCAL_DASHBOARD_DATA_DIR', __DIR__ . '/data');
