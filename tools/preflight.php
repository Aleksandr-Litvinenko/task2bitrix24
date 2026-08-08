<?php
/**
 * Предполётная проверка taskCRM.
 *
 * Проходит по всем требованиям из docs/DEPLOYMENT.md и говорит, что именно
 * не настроено, — вместо того чтобы это выяснялось в момент, когда бухгалтерия
 * ждёт выгрузку.
 *
 * Запуск:
 *   php tools/preflight.php              # только локальные проверки
 *   php tools/preflight.php --online     # плюс реальные запросы к Битрикс и УНФ
 *
 * Скрипт ничего не меняет: не пишет файлы, не создаёт документы,
 * не трогает конфигурацию. Онлайн-режим делает только чтение.
 *
 * Код возврата: 0 — всё готово, 1 — есть блокирующие проблемы.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Предполётная проверка запускается только из командной строки.\n");
}

require_once __DIR__ . '/../lib.php';
// Нужен для онлайн-проверки OData: без него unfFetchEmployees() не определена
// и проверка УНФ всегда падала с «Call to undefined function».
require_once __DIR__ . '/../unf.php';

$online = in_array('--online', $argv, true);

$failures = 0;
$warnings = 0;

function section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('─', mb_strlen($title, 'UTF-8')) . "\n";
}

function ok(string $message): void
{
    echo "  [ ok ] " . $message . "\n";
}

function warn(string $message): void
{
    global $warnings;
    $warnings++;
    echo "  [ ~~ ] " . $message . "\n";
}

function fail(string $message): void
{
    global $failures;
    $failures++;
    echo "  [FAIL] " . $message . "\n";
}

function maskSecret(string $value): string
{
    $length = strlen($value);
    if ($length === 0) {
        return '(пусто)';
    }
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
}

echo "taskCRM — предполётная проверка\n";
echo $online
    ? "Режим: локальные проверки + запросы к Битрикс и УНФ\n"
    : "Режим: только локальные проверки (добавьте --online для запросов к Битрикс и УНФ)\n";

// ── Окружение ───────────────────────────────────────────────────────────────
section('Окружение');

if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    ok('PHP ' . PHP_VERSION);
} else {
    fail('PHP ' . PHP_VERSION . ' — нужен 7.4 или новее');
}

if (class_exists('ZipArchive')) {
    ok('Расширение zip — выгрузка .xlsx возможна');
} else {
    fail('Нет расширения zip (ZipArchive) — Excel-выгрузка работать не будет');
}

if (function_exists('curl_init')) {
    ok('Расширение curl');
} else {
    warn('Нет curl — запросы пойдут через streams: без ретраев по DNS и медленнее');
}

foreach (['json' => true, 'mbstring' => true, 'openssl' => false] as $extension => $required) {
    if (extension_loaded($extension)) {
        ok('Расширение ' . $extension);
    } elseif ($required) {
        fail('Нет расширения ' . $extension);
    } else {
        warn('Нет расширения ' . $extension . ' — HTTPS может не работать');
    }
}

// ── Конфигурация ────────────────────────────────────────────────────────────
section('Конфигурация');

$localConfig = dirname(__DIR__) . '/config.local.php';
if (is_file($localConfig)) {
    ok('config.local.php найден');
} elseif (getenv('BITRIX_WEBHOOK')) {
    ok('config.local.php нет, настройки берутся из переменных окружения');
} else {
    warn('Нет ни config.local.php, ни переменных окружения — см. docs/DEPLOYMENT.md');
}

if (BITRIX_WEBHOOK === '') {
    fail('BITRIX_WEBHOOK не задан — без него не работает ничего');
} elseif (!preg_match('~^https://[^/]+/rest/\d+/[^/]+/?$~', BITRIX_WEBHOOK)) {
    warn('BITRIX_WEBHOOK не похож на входящий вебхук вида https://<портал>/rest/<id>/<код>/');
} else {
    ok('BITRIX_WEBHOOK задан: ' . preg_replace('~/rest/(\d+)/[^/]+~', '/rest/$1/****', BITRIX_WEBHOOK));
}

if (APP_AUTH_PASSWORD === '' || APP_AUTH_PASSWORD === 'change-me') {
    fail('APP_AUTH_PASSWORD не сменён с значения по умолчанию — панель открыта всем, кто знает адрес');
} elseif (strlen(APP_AUTH_PASSWORD) < 12) {
    warn('APP_AUTH_PASSWORD короче 12 символов');
} else {
    ok('APP_AUTH_PASSWORD задан (' . maskSecret(APP_AUTH_PASSWORD) . ')');
}

$userIds = REPORT_USER_IDS;
if (count($userIds) === 0) {
    fail('REPORT_USER_IDS пуст — в отчёт не попадёт ни один сотрудник');
} else {
    ok('REPORT_USER_IDS: ' . count($userIds) . ' сотрудников');
}

// ── Папка данных ────────────────────────────────────────────────────────────
section('Папка снимков дашборда');

$dataDir = DASHBOARD_DATA_DIR;
if (!is_dir($dataDir)) {
    fail('Нет папки ' . $dataDir);
} elseif (!is_writable($dataDir)) {
    fail('Папка ' . $dataDir . ' недоступна на запись пользователю ' . (get_current_user() ?: '?'));
} else {
    $snapshots = glob(rtrim($dataDir, '/') . '/dashboard-*.json') ?: [];
    ok('Папка ' . $dataDir . ' доступна на запись, снимков: ' . count($snapshots));
}

$exposed = dirname(__DIR__) . '/data/.htaccess';
if ($dataDir === dirname(__DIR__) . '/data' && !is_file($exposed)) {
    warn('Папка data/ лежит внутри web root. Закройте её от прямой раздачи: в снимках ФИО и часы сотрудников');
}

// ── УНФ ─────────────────────────────────────────────────────────────────────
section('1С:УНФ (кнопки создания документов)');

if (UNF_ODATA_USER === '' || UNF_ODATA_PASSWORD === '') {
    warn('UNF_ODATA_USER / UNF_ODATA_PASSWORD не заданы — кнопки УНФ работать не будут (остальное будет)');
} else {
    ok('Учётные данные OData заданы (' . UNF_ODATA_USER . ' / ' . maskSecret(UNF_ODATA_PASSWORD) . ')');
    ok('База: ' . UNF_ODATA_BASE_URL);
    echo '  [ i  ] Автопроведение документов: ' . (UNF_TIME_POST_DOCUMENTS ? 'включено' : 'выключено') . "\n";
    echo '  [ i  ] Коэффициент плана в «Задании на работу»: ×' . UNF_WORK_ORDER_UPLIFT . "\n";
    if (UNF_WORK_ORDER_RATE <= 0) {
        warn('UNF_WORK_ORDER_RATE не задан — сумма в задании на работу выйдет нулевой');
    }
}

// ── Онлайн-проверки ─────────────────────────────────────────────────────────
if ($online && BITRIX_WEBHOOK !== '') {
    section('Битрикс24: доступность методов');

    $methods = [
        'user.get' => 'список сотрудников',
        'tasks.task.list' => 'задачи (отчёт и доска)',
        'sonet_group.get' => 'рабочие группы — колонки доски',
        'crm.company.list' => 'компании CRM',
        'task.elapseditem.getlist' => 'списанное время — основа отчёта',
    ];

    foreach ($methods as $method => $purpose) {
        try {
            bitrixRequest($method, ['start' => 0]);
            ok($method . ' — доступен (' . $purpose . ')');
        } catch (Throwable $error) {
            $message = $error->getMessage();
            if (stripos($message, 'ACCESS_DENIED') !== false || stripos($message, 'права') !== false) {
                if ($method === 'sonet_group.get') {
                    warn($method . ' — нет прав. Доска будет работать, но проекты получат имена «Проект #<id>»');
                } else {
                    fail($method . ' — нет прав у вебхука (' . $purpose . ')');
                }
            } else {
                fail($method . ' — ' . $message);
            }
        }
    }

    if (UNF_ODATA_USER !== '' && UNF_ODATA_PASSWORD !== '') {
        section('1С:УНФ: доступность OData');
        try {
            $employees = unfFetchEmployees();
            ok('OData отвечает, сотрудников в справочнике: ' . count($employees));
        } catch (Throwable $error) {
            fail('OData недоступен — ' . $error->getMessage());
        }
    }
} elseif ($online) {
    section('Онлайн-проверки');
    warn('Пропущены: не задан BITRIX_WEBHOOK');
}

// ── Итог ────────────────────────────────────────────────────────────────────
section('Итог');

if ($failures === 0 && $warnings === 0) {
    echo "  Всё готово к работе.\n";
} elseif ($failures === 0) {
    echo "  Блокирующих проблем нет. Предупреждений: " . $warnings . ".\n";
} else {
    echo "  Блокирующих проблем: " . $failures . ", предупреждений: " . $warnings . ".\n";
    echo "  Что означает каждая — docs/DEPLOYMENT.md и docs/BITRIX24.md.\n";
}

echo "\n";
exit($failures === 0 ? 0 : 1);
