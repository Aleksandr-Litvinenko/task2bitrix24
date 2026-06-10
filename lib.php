<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bitrixRequest(string $method, array $data = [], int $attempt = 0): array
{
    if (BITRIX_WEBHOOK === '') {
        throw new RuntimeException('Не настроен BITRIX_WEBHOOK. Укажите webhook через переменную окружения или task2/config.local.php.');
    }

    $url = rtrim(BITRIX_WEBHOOK, '/') . '/' . ltrim($method, '/');
    $body = http_build_query($data);

    if (function_exists('curl_init')) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);

        if ($response === false) {
            throw new RuntimeException('Ошибка запроса к Битрикс: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 120,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('Ошибка запроса к Битрикс.');
        }
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Битрикс вернул некорректный JSON.');
    }

    if (($decoded['error'] ?? '') === 'QUERY_LIMIT_EXCEEDED' && $attempt < 8) {
        sleep(1);
        return bitrixRequest($method, $data, $attempt + 1);
    }

    if (isset($decoded['error'])) {
        $description = (string)($decoded['error_description'] ?? $decoded['error']);
        throw new RuntimeException('Ошибка Битрикс API: ' . $description);
    }

    return $decoded;
}

function getNestedValue(array $array, array $path)
{
    $value = $array;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
}

function bitrixPagedItems(string $method, array $data, array $itemsPath): array
{
    $items = [];
    $start = 0;

    while (true) {
        $pageData = $data;
        $pageData['start'] = $start;

        $response = bitrixRequest($method, $pageData);
        $pageItems = getNestedValue($response, $itemsPath);

        if (!is_array($pageItems) || empty($pageItems)) {
            break;
        }

        $items = array_merge($items, $pageItems);

        if (!isset($response['next'])) {
            break;
        }

        $next = (int)$response['next'];
        if ($next <= $start) {
            break;
        }
        $start = $next;
    }

    return $items;
}

function bitrixCommand(string $method, array $params): string
{
    return $method . '?' . http_build_query($params);
}

function bitrixBatch(array $commands, int $attempt = 0): array
{
    if (empty($commands)) {
        return [
            'result' => [],
            'result_error' => [],
            'result_total' => [],
            'result_next' => [],
        ];
    }

    $response = bitrixRequest('batch', [
        'halt' => 0,
        'cmd' => $commands,
    ]);

    $batch = $response['result'] ?? [];
    if (!is_array($batch)) {
        throw new RuntimeException('Битрикс вернул некорректный batch-ответ.');
    }

    $errors = $batch['result_error'] ?? [];
    if (is_array($errors) && !empty($errors)) {
        $hasQueryLimit = false;
        $messages = [];

        foreach ($errors as $commandKey => $error) {
            if (empty($error)) {
                continue;
            }

            $errorCode = is_array($error) ? (string)($error['error'] ?? '') : (string)$error;
            $errorDescription = is_array($error) ? (string)($error['error_description'] ?? $errorCode) : (string)$error;

            if ($errorCode === 'QUERY_LIMIT_EXCEEDED') {
                $hasQueryLimit = true;
            }

            $messages[] = $commandKey . ': ' . $errorDescription;
        }

        if ($hasQueryLimit && $attempt < 8) {
            sleep(1);
            return bitrixBatch($commands, $attempt + 1);
        }

        if (!empty($messages)) {
            throw new RuntimeException('Ошибка Bitrix batch: ' . implode('; ', $messages));
        }
    }

    return $batch;
}

function bitrixBatchAll(array $commands): array
{
    $merged = [
        'result' => [],
        'result_error' => [],
        'result_total' => [],
        'result_next' => [],
    ];

    foreach (array_chunk($commands, 50, true) as $chunk) {
        $batch = bitrixBatch($chunk);

        foreach ($merged as $key => $_) {
            if (isset($batch[$key]) && is_array($batch[$key])) {
                $merged[$key] = array_replace($merged[$key], $batch[$key]);
            }
        }
    }

    return $merged;
}

function userDisplayName(array $user): string
{
    $parts = [
        trim((string)($user['LAST_NAME'] ?? '')),
        trim((string)($user['NAME'] ?? '')),
        trim((string)($user['SECOND_NAME'] ?? '')),
    ];
    $name = trim(implode(' ', array_filter($parts, static function (string $part): bool {
        return $part !== '';
    })));

    if ($name !== '') {
        return $name;
    }

    foreach (['EMAIL', 'LOGIN', 'ID'] as $field) {
        if (!empty($user[$field])) {
            return (string)$user[$field];
        }
    }

    return '-';
}

function fetchUsersPage(array $filter): array
{
    return bitrixPagedItems('user.get', [
        'FILTER' => $filter,
        'sort' => 'LAST_NAME',
        'order' => 'ASC',
    ], ['result']);
}

function fetchActiveUsers(): array
{
    try {
        $users = fetchUsersPage(['ACTIVE' => true]);
    } catch (Throwable $e) {
        $users = [];
    }

    if (empty($users)) {
        $users = fetchUsersPage([]);
    }

    $result = [];
    foreach ($users as $user) {
        $id = (int)($user['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        if (!isReportEmployee($user)) {
            continue;
        }

        $result[$id] = userDisplayName($user);
    }

    uasort($result, static function (string $left, string $right): int {
        return strnatcasecmp($left, $right);
    });

    return $result;
}

function fetchReportUsers(): array
{
    $result = [];

    foreach (REPORT_USER_IDS as $userId) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            continue;
        }

        $result[$userId] = getUserName($userId);
    }

    uasort($result, static function (string $left, string $right): int {
        return strnatcasecmp($left, $right);
    });

    return $result;
}

function isReportEmployee(array $user): bool
{
    if (isset($user['ACTIVE']) && !in_array($user['ACTIVE'], [true, 'Y', '1', 1], true)) {
        return false;
    }

    if (isset($user['USER_TYPE']) && $user['USER_TYPE'] !== '' && $user['USER_TYPE'] !== 'employee') {
        return false;
    }

    if (array_key_exists('UF_DEPARTMENT', $user) && empty($user['UF_DEPARTMENT'])) {
        return false;
    }

    return true;
}

function getUserName(int $id): string
{
    static $cache = [];

    if ($id <= 0) {
        return '-';
    }

    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $response = bitrixRequest('user.get', ['id' => $id]);
    $user = $response['result'][0] ?? [];
    $cache[$id] = is_array($user) ? userDisplayName($user) : (string)$id;

    return $cache[$id];
}

function monthPeriod(string $period): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Укажите месяц в формате YYYY-MM.');
    }

    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    if (!$monthStart) {
        throw new InvalidArgumentException('Некорректный месяц отчёта.');
    }

    $createdStart = $monthStart->modify('-7 days')->setTime(0, 0, 0);
    $createdEnd = $monthStart->modify('last day of this month')->modify('+7 days')->setTime(23, 59, 59);

    return [$monthStart, $createdStart, $createdEnd];
}

function fetchTasksCreatedForReport(DateTimeImmutable $createdStart, DateTimeImmutable $createdEnd): array
{
    $baseData = [
        'order' => ['ID' => 'asc'],
        'filter' => [
            '>=CREATED_DATE' => $createdStart->format('Y-m-d H:i:s'),
            '<=CREATED_DATE' => $createdEnd->format('Y-m-d H:i:s'),
            'REAL_STATUS' => REPORT_TASK_STATUSES,
        ],
        'select' => [
            'ID',
            'TITLE',
            'STATUS',
            'REAL_STATUS',
            'CREATED_DATE',
            'CREATED_BY',
            'RESPONSIBLE_ID',
            'CLOSED_DATE',
            'UF_CRM_TASK',
            'UF_AUTO_692242927731',
            'UF_AUTO_899199298816',
        ],
    ];

    $firstPageData = $baseData;
    $firstPageData['start'] = 0;
    $firstResponse = bitrixRequest('tasks.task.list', $firstPageData);
    $tasks = getNestedValue($firstResponse, ['result', 'tasks']);
    $tasks = is_array($tasks) ? $tasks : [];

    $total = (int)($firstResponse['total'] ?? count($tasks));
    $next = isset($firstResponse['next']) ? (int)$firstResponse['next'] : 0;

    if ($next > 0 && $total > count($tasks)) {
        $commands = [];

        for ($start = $next; $start < $total; $start += 50) {
            $pageData = $baseData;
            $pageData['start'] = $start;
            $commands['p' . $start] = bitrixCommand('tasks.task.list', $pageData);
        }

        $batch = bitrixBatchAll($commands);
        foreach ($batch['result'] as $pageResult) {
            if (!is_array($pageResult)) {
                continue;
            }

            $pageTasks = $pageResult['tasks'] ?? [];
            if (is_array($pageTasks) && !empty($pageTasks)) {
                $tasks = array_merge($tasks, $pageTasks);
            }
        }
    }

    usort($tasks, static function (array $left, array $right): int {
        return (int)($left['id'] ?? $left['ID'] ?? 0) <=> (int)($right['id'] ?? $right['ID'] ?? 0);
    });

    return $tasks;
}

function fetchTasksClosedForReport(DateTimeImmutable $closedStart, DateTimeImmutable $closedEnd): array
{
    $baseData = [
        'order' => ['ID' => 'asc'],
        'filter' => [
            '>=CLOSED_DATE' => $closedStart->format('Y-m-d H:i:s'),
            '<=CLOSED_DATE' => $closedEnd->format('Y-m-d H:i:s'),
            'REAL_STATUS' => REPORT_TASK_STATUSES,
        ],
        'select' => [
            'ID',
            'TITLE',
            'STATUS',
            'REAL_STATUS',
            'CREATED_DATE',
            'CREATED_BY',
            'RESPONSIBLE_ID',
            'CLOSED_DATE',
            'UF_CRM_TASK',
            'UF_AUTO_692242927731',
            'UF_AUTO_899199298816',
        ],
    ];

    $firstPageData = $baseData;
    $firstPageData['start'] = 0;
    $firstResponse = bitrixRequest('tasks.task.list', $firstPageData);
    $tasks = getNestedValue($firstResponse, ['result', 'tasks']);
    $tasks = is_array($tasks) ? $tasks : [];

    $total = (int)($firstResponse['total'] ?? count($tasks));
    $next = isset($firstResponse['next']) ? (int)$firstResponse['next'] : 0;

    if ($next > 0 && $total > count($tasks)) {
        $commands = [];

        for ($start = $next; $start < $total; $start += 50) {
            $pageData = $baseData;
            $pageData['start'] = $start;
            $commands['p' . $start] = bitrixCommand('tasks.task.list', $pageData);
        }

        $batch = bitrixBatchAll($commands);
        foreach ($batch['result'] as $pageResult) {
            if (!is_array($pageResult)) {
                continue;
            }

            $pageTasks = $pageResult['tasks'] ?? [];
            if (is_array($pageTasks) && !empty($pageTasks)) {
                $tasks = array_merge($tasks, $pageTasks);
            }
        }
    }

    usort($tasks, static function (array $left, array $right): int {
        return (int)($left['id'] ?? $left['ID'] ?? 0) <=> (int)($right['id'] ?? $right['ID'] ?? 0);
    });

    return $tasks;
}

function extractTaskResultText(array $result): string
{
    $text = (string)($result['text'] ?? $result['formattedText'] ?? '');
    $text = str_replace(['[p]', '[/p]'], '', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/u", "\n", $text);
    $text = preg_replace("/[ \t]+/u", ' ', (string)$text);
    $text = preg_replace("/\n{2,}/u", "\n", (string)$text);

    return trim((string)$text);
}

function fetchTaskResults(int $taskId): array
{
    $results = [];
    $start = 0;

    while (true) {
        $response = bitrixRequest('tasks.task.result.list', [
            'taskId' => $taskId,
            'start' => $start,
        ]);

        $items = $response['result'] ?? [];
        if (!is_array($items) || empty($items)) {
            break;
        }

        $results = array_merge($results, $items);

        if (!isset($response['next'])) {
            break;
        }

        $next = (int)$response['next'];
        if ($next <= $start) {
            break;
        }
        $start = $next;
    }

    usort($results, static function (array $left, array $right): int {
        return strtotime((string)($left['createdAt'] ?? '')) <=> strtotime((string)($right['createdAt'] ?? ''));
    });

    return $results;
}

function fetchTaskResultsBulk(array $taskIds): array
{
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
    if (empty($taskIds)) {
        return [];
    }

    $commands = [];
    foreach ($taskIds as $taskId) {
        $commands['r' . $taskId] = bitrixCommand('tasks.task.result.list', [
            'taskId' => $taskId,
            'start' => 0,
        ]);
    }

    $batch = bitrixBatchAll($commands);
    $result = [];

    foreach ($taskIds as $taskId) {
        $key = 'r' . $taskId;
        $items = $batch['result'][$key] ?? [];

        if (!empty($batch['result_next'][$key])) {
            $items = fetchTaskResults($taskId);
        }

        if (!is_array($items)) {
            $items = [];
        }

        usort($items, static function (array $left, array $right): int {
            return strtotime((string)($left['createdAt'] ?? '')) <=> strtotime((string)($right['createdAt'] ?? ''));
        });

        $result[$taskId] = $items;
    }

    return $result;
}

function parseRussianResultDates(string $text): array
{
    $dateStrings = [];

    if (preg_match_all('/дата\s+выполн\p{L}*(?:\s+\p{L}+){0,4}[^0-9]{0,80}(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/iu', $text, $matches)) {
        $dateStrings = array_merge($dateStrings, $matches[1]);
    }

    if (empty($dateStrings) && preg_match_all('/(?<!\d)(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})(?!\d)/u', $text, $matches)) {
        $dateStrings = array_merge($dateStrings, $matches[1]);
    }

    $dates = [];
    foreach (array_unique($dateStrings) as $dateString) {
        $dates[] = parseFlexibleDate($dateString);
    }

    return array_values(array_filter($dates));
}

function parseFlexibleDate(string $dateString): ?DateTimeImmutable
{
    $dateString = str_replace(['/', '-'], '.', trim($dateString));
    $formats = ['!d.m.Y', '!j.n.Y', '!d.m.y', '!j.n.y'];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $dateString);
        if (!$date) {
            continue;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            continue;
        }

        if ((int)$date->format('Y') < 2000) {
            $date = $date->modify('+2000 years');
        }

        return $date;
    }

    return null;
}

function taskResultMatchesMonth(int $taskId, DateTimeImmutable $monthStart): ?array
{
    return taskResultsMatchMonth(fetchTaskResults($taskId), $monthStart);
}

function taskResultsMatchMonth(array $results, DateTimeImmutable $monthStart): ?array
{
    if (empty($results)) {
        return null;
    }

    $completed = array_values(array_filter($results, static function (array $result): bool {
        return (int)($result['status'] ?? 0) === 1;
    }));
    $candidates = !empty($completed) ? $completed : $results;

    $targetMonth = $monthStart->format('Y-m');
    foreach (array_reverse($candidates) as $result) {
        $text = extractTaskResultText($result);
        if ($text === '') {
            continue;
        }

        foreach (parseRussianResultDates($text) as $date) {
            if ($date->format('Y-m') === $targetMonth) {
                return [
                    'date' => $date,
                    'text' => $text,
                ];
            }
        }
    }

    return null;
}

function formatSpentMinutes(int $minutes): string
{
    return floor($minutes / 60) . 'ч. ' . ($minutes % 60) . 'м.';
}

function formatTaskDateValue($date): string
{
    if (empty($date)) {
        return '-';
    }

    $timestamp = strtotime((string)$date);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : '-';
}

function formatTaskFieldValue($value): string
{
    if (is_array($value)) {
        $items = array_filter(array_map(static function ($item): string {
            return is_scalar($item) ? trim((string)$item) : '';
        }, $value), static function (string $item): bool {
            return $item !== '';
        });

        return empty($items) ? '-' : implode(', ', $items);
    }

    $value = trim((string)$value);

    return $value === '' ? '-' : $value;
}

function taskStatusName(array $task): string
{
    $status = (string)($task['status'] ?? $task['STATUS'] ?? $task['realStatus'] ?? $task['REAL_STATUS'] ?? '');
    $labels = [
        '2' => 'Ждет выполнения',
        '3' => 'Задача выполняется',
        '4' => 'Условно завершена',
        '5' => 'Задача завершена',
        '6' => 'Задача отложена',
        '7' => 'Завершена',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : '-');
}

function taskPersonName(array $task, string $objectKey, string $idKey): string
{
    $person = $task[$objectKey] ?? null;
    if (is_array($person)) {
        $name = trim((string)($person['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $parts = [
            trim((string)($person['lastName'] ?? $person['LAST_NAME'] ?? '')),
            trim((string)($person['name'] ?? $person['NAME'] ?? '')),
        ];
        $name = trim(implode(' ', array_filter($parts)));
        if ($name !== '') {
            return $name;
        }
    }

    $userId = (int)($task[$idKey] ?? 0);

    return $userId > 0 ? getUserName($userId) : '-';
}

function taskCompanyId(array $task): int
{
    $crm = $task['ufCrmTask'] ?? $task['UF_CRM_TASK'] ?? [];
    if (!is_array($crm)) {
        $crm = [$crm];
    }

    foreach ($crm as $item) {
        $id = preg_replace('/[^0-9]/', '', (string)$item);
        if ($id !== '') {
            return (int)$id;
        }
    }

    return 0;
}

function fetchCompanyNamesBulk(array $companyIds): array
{
    $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    if (empty($companyIds)) {
        return [];
    }

    $commands = [];
    foreach ($companyIds as $companyId) {
        $commands['c' . $companyId] = bitrixCommand('crm.company.list', [
            'filter' => ['ID' => $companyId],
            'select' => ['TITLE'],
        ]);
    }

    $batch = bitrixBatchAll($commands);
    $result = [];

    foreach ($companyIds as $companyId) {
        $items = $batch['result']['c' . $companyId] ?? [];
        $title = '-';

        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            $title = trim((string)($items[0]['TITLE'] ?? '-'));
        }

        $result[$companyId] = $title !== '' ? $title : '-';
    }

    return $result;
}

function taskBaseDetails(array $task, array $companyNames, string $resultText): array
{
    $companyId = taskCompanyId($task);

    return [
        'task_id' => (int)($task['id'] ?? $task['ID'] ?? 0),
        'title' => (string)($task['title'] ?? $task['TITLE'] ?? ''),
        'company' => $companyId > 0 ? ($companyNames[$companyId] ?? '-') : '-',
        'created_date' => formatTaskDateValue($task['createdDate'] ?? $task['CREATED_DATE'] ?? ''),
        'closed_date' => formatTaskDateValue($task['closedDate'] ?? $task['CLOSED_DATE'] ?? ''),
        'creator' => taskPersonName($task, 'creator', 'createdBy'),
        'responsible' => taskPersonName($task, 'responsible', 'responsibleId'),
        'status' => taskStatusName($task),
        'category' => formatTaskFieldValue($task['ufAuto692242927731'] ?? $task['UF_AUTO_692242927731'] ?? '-'),
        'customer_employee' => formatTaskFieldValue($task['ufAuto899199298816'] ?? $task['UF_AUTO_899199298816'] ?? '-'),
        'result_text' => $resultText !== '' ? $resultText : '-',
    ];
}

function fetchTaskElapsedItems(int $taskId, array $userIds): array
{
    $items = [];
    $page = 1;
    $pageSize = 50;
    $userIds = array_values(array_filter(array_map('intval', $userIds)));

    if (empty($userIds)) {
        return [];
    }

    while ($page < 1000) {
        $response = bitrixRequest('task.elapseditem.getlist', [
            'TASKID' => $taskId,
            'ORDER' => ['ID' => 'asc'],
            'FILTER' => ['USER_ID' => $userIds],
            'SELECT' => ['ID', 'TASK_ID', 'USER_ID', 'MINUTES', 'COMMENT_TEXT'],
            'PARAMS' => [
                'NAV_PARAMS' => [
                    'nPageSize' => $pageSize,
                    'iNumPage' => $page,
                ],
            ],
        ]);

        $pageItems = $response['result'] ?? [];
        if (!is_array($pageItems) || empty($pageItems)) {
            break;
        }

        $items = array_merge($items, $pageItems);
        $total = (int)($response['total'] ?? count($items));
        if (count($items) >= $total) {
            break;
        }

        $page++;
    }

    return $items;
}

function fetchTaskElapsedItemsBulk(array $taskIds, array $userIds): array
{
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
    $userIds = array_values(array_filter(array_map('intval', $userIds)));

    if (empty($taskIds) || empty($userIds)) {
        return [];
    }

    $commands = [];
    foreach ($taskIds as $taskId) {
        $commands['e' . $taskId] = bitrixCommand('task.elapseditem.getlist', [
            'TASKID' => $taskId,
            'ORDER' => ['ID' => 'asc'],
            'FILTER' => ['USER_ID' => $userIds],
            'SELECT' => ['ID', 'TASK_ID', 'USER_ID', 'MINUTES', 'COMMENT_TEXT'],
            'PARAMS' => [
                'NAV_PARAMS' => [
                    'nPageSize' => 50,
                    'iNumPage' => 1,
                ],
            ],
        ]);
    }

    $batch = bitrixBatchAll($commands);
    $result = [];

    foreach ($taskIds as $taskId) {
        $key = 'e' . $taskId;
        $items = $batch['result'][$key] ?? [];

        if (!is_array($items)) {
            $items = [];
        }

        $total = (int)($batch['result_total'][$key] ?? count($items));
        if ($total > count($items)) {
            $items = fetchTaskElapsedItems($taskId, $userIds);
        }

        $result[$taskId] = $items;
    }

    return $result;
}

function buildClosedHoursReport(string $period): array
{
    // Отчёт делает десятки REST-запросов и на больших месяцах не укладывается
    // в стандартные 30 секунд max_execution_time.
    set_time_limit(600);

    [$monthStart, $periodStart, $periodEnd] = monthPeriod($period);

    $users = fetchReportUsers();
    $rows = [];
    foreach ($users as $userId => $name) {
        $rows[$userId] = [
            'id' => $userId,
            'name' => $name,
            'minutes' => 0,
            'task_ids' => [],
            'details' => [],
        ];
    }

    $tasks = fetchTasksClosedForReport($periodStart, $periodEnd);
    $taskIds = [];
    $tasksById = [];

    foreach ($tasks as $task) {
        $taskId = (int)($task['id'] ?? $task['ID'] ?? 0);
        if ($taskId <= 0) {
            continue;
        }

        $taskIds[] = $taskId;
        $tasksById[$taskId] = $task;
    }

    $taskResultsById = fetchTaskResultsBulk($taskIds);
    $matchedTaskIds = [];
    $matchedResultByTask = [];

    foreach ($taskIds as $taskId) {
        $matchedResult = taskResultsMatchMonth($taskResultsById[$taskId] ?? [], $monthStart);
        if ($matchedResult !== null) {
            $matchedTaskIds[] = $taskId;
            $matchedResultByTask[$taskId] = $matchedResult;
        }
    }

    $companyIds = [];
    foreach ($matchedTaskIds as $taskId) {
        $companyId = taskCompanyId($tasksById[$taskId] ?? []);
        if ($companyId > 0) {
            $companyIds[] = $companyId;
        }
    }
    $companyNames = fetchCompanyNamesBulk($companyIds);

    $elapsedItemsByTask = fetchTaskElapsedItemsBulk($matchedTaskIds, array_keys($rows));
    $allTaskRows = [];
    $matchedTasks = [];

    foreach ($elapsedItemsByTask as $taskId => $elapsedItems) {
        $taskId = (int)$taskId;
        $task = $tasksById[$taskId] ?? [];
        $resultText = (string)($matchedResultByTask[$taskId]['text'] ?? '');
        $baseDetails = taskBaseDetails($task, $companyNames, $resultText);
        $matchedTasks[$taskId] = $baseDetails;

        if (empty($elapsedItems)) {
            $allTaskRows[] = array_merge($baseDetails, [
                'user_id' => 0,
                'user_name' => '-',
                'minutes' => 0,
                'hours' => 0,
                'comment' => '-',
                'performer_text' => '-',
            ]);
            continue;
        }

        foreach ($elapsedItems as $item) {
            $userId = (int)($item['USER_ID'] ?? 0);
            $minutes = (int)($item['MINUTES'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            if (!isset($rows[$userId])) {
                $rows[$userId] = [
                    'id' => $userId,
                    'name' => getUserName($userId),
                    'minutes' => 0,
                    'task_ids' => [],
                    'details' => [],
                ];
            }

            $userName = $rows[$userId]['name'];
            $comment = trim((string)($item['COMMENT_TEXT'] ?? ''));
            $detail = array_merge($baseDetails, [
                'user_id' => $userId,
                'user_name' => $userName,
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 2),
                'comment' => $comment !== '' ? $comment : '-',
                'performer_text' => formatSpentMinutes($minutes) . ' - ' . $userName,
            ]);

            $rows[$userId]['minutes'] += $minutes;
            $rows[$userId]['task_ids'][$taskId] = true;
            $rows[$userId]['details'][] = $detail;
            $allTaskRows[] = $detail;
        }
    }

    uasort($rows, static function (array $left, array $right): int {
        return strnatcasecmp((string)$left['name'], (string)$right['name']);
    });

    $totalMinutes = 0;
    foreach ($rows as &$row) {
        $row['hours'] = round($row['minutes'] / 60, 2);
        $row['tasks_count'] = count($row['task_ids']);
        unset($row['task_ids']);
        usort($row['details'], static function (array $left, array $right): int {
            return [$left['task_id'], $left['comment'], $left['minutes']] <=> [$right['task_id'], $right['comment'], $right['minutes']];
        });
        $totalMinutes += $row['minutes'];
    }
    unset($row);

    $rows = array_filter($rows, static function (array $row): bool {
        return (float)$row['hours'] > 0 || (int)$row['tasks_count'] > 0;
    });

    usort($allTaskRows, static function (array $left, array $right): int {
        return [$left['task_id'], $left['user_name'], $left['comment']] <=> [$right['task_id'], $right['user_name'], $right['comment']];
    });

    return [
        'period' => $period,
        'month_title' => russianMonthTitle($monthStart),
        'created_start' => $periodStart,
        'created_end' => $periodEnd,
        'rows' => array_values($rows),
        'tasks_scanned' => count($tasks),
        'tasks_matched' => count($matchedTaskIds),
        'matched_tasks' => array_values($matchedTasks),
        'all_task_rows' => $allTaskRows,
        'total_minutes' => $totalMinutes,
        'total_hours' => round($totalMinutes / 60, 2),
    ];
}

function russianMonthTitle(DateTimeImmutable $monthStart): string
{
    $months = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    return $months[(int)$monthStart->format('n')] . ' ' . $monthStart->format('Y');
}

function xlsxXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function columnName(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function xlsxCell(int $column, int $row, $value, int $style = 0): string
{
    $ref = columnName($column) . $row;
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';

    if ($value === null || $value === '') {
        return '<c r="' . $ref . '"' . $styleAttr . '/>';
    }

    if (is_int($value) || is_float($value)) {
        $number = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
        return '<c r="' . $ref . '"' . $styleAttr . '><v>' . $number . '</v></c>';
    }

    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t xml:space="preserve">' . xlsxXml((string)$value) . '</t></is></c>';
}

function xlsxRow(int $rowNumber, array $cells): string
{
    $xml = '<row r="' . $rowNumber . '">';
    foreach ($cells as $column => $cell) {
        $xml .= xlsxCell((int)$column, $rowNumber, $cell['value'] ?? '', (int)($cell['style'] ?? 0));
    }
    $xml .= '</row>';

    return $xml;
}

function buildSheetXml(array $report): string
{
    $rows = [];
    $rowNumber = 1;

    $rows[] = xlsxRow($rowNumber++, [
        1 => ['value' => 'Специалист', 'style' => 1],
        2 => ['value' => 'Количество часов', 'style' => 1],
        3 => ['value' => 'Количество задач', 'style' => 1],
    ]);

    foreach ($report['rows'] as $row) {
        $rows[] = xlsxRow($rowNumber++, [
            1 => ['value' => (string)$row['name'], 'style' => 2],
            2 => ['value' => (float)$row['hours'], 'style' => 3],
            3 => ['value' => (int)$row['tasks_count'], 'style' => 4],
        ]);
    }

    $rows[] = xlsxRow($rowNumber++, [
        1 => ['value' => 'Итого', 'style' => 5],
        2 => ['value' => (float)$report['total_hours'], 'style' => 6],
        3 => ['value' => (int)$report['tasks_matched'], 'style' => 7],
    ]);

    $dimension = 'A1:C' . ($rowNumber - 1);

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<dimension ref="' . $dimension . '"/>' .
        '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
        '<sheetFormatPr defaultRowHeight="18"/>' .
        '<cols>' .
        '<col min="1" max="1" width="26" customWidth="1"/>' .
        '<col min="2" max="2" width="18" customWidth="1"/>' .
        '<col min="3" max="3" width="18" customWidth="1"/>' .
        '</cols>' .
        '<sheetData>' . implode('', $rows) . '</sheetData>' .
        '<autoFilter ref="' . $dimension . '"/>' .
        '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>' .
        '</worksheet>';
}

function xlsxRowFromValues(int $rowNumber, array $values, int $defaultStyle = 2): string
{
    $cells = [];
    foreach (array_values($values) as $index => $value) {
        if (is_array($value) && array_key_exists('value', $value)) {
            $cells[$index + 1] = $value;
        } else {
            $cells[$index + 1] = [
                'value' => $value,
                'style' => $defaultStyle,
            ];
        }
    }

    return xlsxRow($rowNumber, $cells);
}

function buildTableSheetXml(array $headers, array $rows, array $widths): string
{
    $rowXml = [];
    $rowNumber = 1;
    $headerCells = [];

    foreach ($headers as $index => $header) {
        $headerCells[$index + 1] = [
            'value' => $header,
            'style' => 1,
        ];
    }
    $rowXml[] = xlsxRow($rowNumber++, $headerCells);

    if (empty($rows)) {
        $rowXml[] = xlsxRowFromValues($rowNumber++, [
            ['value' => 'Нет данных', 'style' => 8],
        ]);
    } else {
        foreach ($rows as $row) {
            $rowXml[] = xlsxRowFromValues($rowNumber++, $row, 8);
        }
    }

    $lastColumn = max(1, count($headers));
    $lastRow = $rowNumber - 1;
    $dimension = 'A1:' . columnName($lastColumn) . $lastRow;
    $cols = '';

    for ($column = 1; $column <= $lastColumn; $column++) {
        $width = (float)($widths[$column - 1] ?? 16);
        $cols .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<dimension ref="' . $dimension . '"/>' .
        '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
        '<sheetFormatPr defaultRowHeight="18"/>' .
        '<cols>' . $cols . '</cols>' .
        '<sheetData>' . implode('', $rowXml) . '</sheetData>' .
        '<autoFilter ref="' . $dimension . '"/>' .
        '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>' .
        '</worksheet>';
}

function detailHeaders(bool $includePerformer): array
{
    $headers = [
        'Номер',
        'Название',
        'Компания',
        'Дата создания',
        'Дата завершения',
        'Постановщик',
        'Ответственный',
        'Статус',
        'Категория',
        'Сотрудник заказчика',
        'Результат завершенной задачи',
        'Комментарии',
    ];

    if ($includePerformer) {
        $headers[] = 'Кто списал время';
    }

    $headers[] = 'Затрачено';

    if ($includePerformer) {
        $headers[] = 'Итого';
    }

    return $headers;
}

function detailWidths(bool $includePerformer): array
{
    $widths = [10, 34, 22, 18, 18, 22, 22, 18, 18, 22, 48, 48];

    if ($includePerformer) {
        $widths[] = 30;
    }

    $widths[] = 12;

    if ($includePerformer) {
        $widths[] = 12;
    }

    return $widths;
}

function detailRow(array $detail, bool $includePerformer, bool $blankTaskColumns, ?float $taskTotalHours = null): array
{
    $taskValues = $blankTaskColumns ? array_fill(0, 11, '') : [
        ['value' => (string)(int)$detail['task_id'], 'style' => 8],
        ['value' => (string)$detail['title'], 'style' => 8],
        ['value' => (string)$detail['company'], 'style' => 8],
        ['value' => (string)$detail['created_date'], 'style' => 8],
        ['value' => (string)$detail['closed_date'], 'style' => 8],
        ['value' => (string)$detail['creator'], 'style' => 8],
        ['value' => (string)$detail['responsible'], 'style' => 8],
        ['value' => (string)$detail['status'], 'style' => 8],
        ['value' => (string)$detail['category'], 'style' => 8],
        ['value' => (string)$detail['customer_employee'], 'style' => 8],
        ['value' => (string)$detail['result_text'], 'style' => 8],
    ];

    $values = array_merge($taskValues, [
        ['value' => (string)$detail['comment'], 'style' => 8],
    ]);

    if ($includePerformer) {
        $values[] = ['value' => (string)$detail['performer_text'], 'style' => 8];
    }

    $values[] = ['value' => (float)$detail['hours'], 'style' => 9];

    if ($includePerformer) {
        $values[] = [
            'value' => $taskTotalHours === null ? '' : $taskTotalHours,
            'style' => $taskTotalHours === null ? 8 : 9,
        ];
    }

    return $values;
}

function buildEmployeeSheetXml(array $employee): string
{
    $rows = [];
    foreach ($employee['details'] as $detail) {
        $rows[] = detailRow($detail, false, false);
    }

    $rows[] = [
        ['value' => 'Итого', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => '', 'style' => 5],
        ['value' => (float)$employee['hours'], 'style' => 6],
    ];

    return buildTableSheetXml(detailHeaders(false), $rows, detailWidths(false));
}

function buildAllTasksSheetXml(array $report): string
{
    $rows = [];
    $details = array_values($report['all_task_rows']);
    $totalRows = count($details);
    $taskTotals = [];

    foreach ($details as $detail) {
        $taskId = (int)$detail['task_id'];
        if ($taskId <= 0) {
            continue;
        }

        $taskTotals[$taskId] = ($taskTotals[$taskId] ?? 0) + (int)$detail['minutes'];
    }

    $taskGroupIndex = -1;

    foreach ($details as $index => $detail) {
        $taskId = (int)$detail['task_id'];
        $previousTaskId = $index > 0 ? (int)$details[$index - 1]['task_id'] : null;
        $nextTaskId = $index + 1 < $totalRows ? (int)$details[$index + 1]['task_id'] : null;
        $isFirstTaskRow = $previousTaskId !== $taskId;

        if ($isFirstTaskRow) {
            $taskGroupIndex++;
        }

        if ($isFirstTaskRow && $nextTaskId !== $taskId) {
            $position = 'single';
        } elseif ($isFirstTaskRow) {
            $position = 'first';
        } elseif ($nextTaskId !== $taskId) {
            $position = 'last';
        } else {
            $position = 'middle';
        }

        $taskTotalHours = $isFirstTaskRow ? round(($taskTotals[$taskId] ?? 0) / 60, 2) : null;
        $rows[] = applyTaskGroupBorderStyles(
            detailRow($detail, true, false, $taskTotalHours),
            $position,
            $taskGroupIndex % 2 === 1
        );
    }

    return buildTableSheetXml(detailHeaders(true), $rows, detailWidths(true));
}

function applyTaskGroupBorderStyles(array $row, string $position, bool $useGrayFill): array
{
    if ($useGrayFill) {
        $textStyles = [
            'first' => 17,
            'last' => 18,
            'single' => 19,
            'middle' => 16,
        ];
        $numberStyles = [
            'first' => 21,
            'last' => 22,
            'single' => 23,
            'middle' => 20,
        ];
    } else {
        $textStyles = [
            'first' => 10,
            'last' => 11,
            'single' => 12,
            'middle' => 8,
        ];
        $numberStyles = [
            'first' => 13,
            'last' => 14,
            'single' => 15,
            'middle' => 9,
        ];
    }

    foreach ($row as &$cell) {
        $currentStyle = (int)($cell['style'] ?? 8);
        $isNumber = in_array($currentStyle, [3, 6, 7, 9], true);
        $cell['style'] = $isNumber ? $numberStyles[$position] : $textStyles[$position];
    }
    unset($cell);

    return $row;
}

function worksheetNamePart(string $name, int $maxLength): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, $maxLength, 'UTF-8');
    }

    return substr($name, 0, $maxLength);
}

function uniqueWorksheetName(string $name, array &$usedNames): string
{
    $name = preg_replace('/[:\\\\\\/\\?\\*\\[\\]]/u', ' ', $name);
    $name = trim((string)preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') {
        $name = 'Лист';
    }

    $base = worksheetNamePart($name, 31);
    $candidate = $base;
    $index = 2;

    while (isset($usedNames[$candidate])) {
        $suffix = ' ' . $index;
        $candidate = worksheetNamePart($base, 31 - strlen($suffix)) . $suffix;
        $index++;
    }

    $usedNames[$candidate] = true;

    return $candidate;
}

function buildWorkbookSheets(array $report): array
{
    $usedNames = [];
    $sheets = [];

    $sheets[] = [
        'name' => uniqueWorksheetName('Свод', $usedNames),
        'xml' => buildSheetXml($report),
    ];

    foreach ($report['rows'] as $employee) {
        $sheets[] = [
            'name' => uniqueWorksheetName((string)$employee['name'], $usedNames),
            'xml' => buildEmployeeSheetXml($employee),
        ];
    }

    $sheets[] = [
        'name' => uniqueWorksheetName('Все задачи', $usedNames),
        'xml' => buildAllTasksSheetXml($report),
    ];

    return $sheets;
}

function buildStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>' .
        '<fonts count="3">' .
        '<font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
        '</fonts>' .
        '<fills count="5">' .
        '<fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF4F81BD"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFD9EAF7"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill>' .
        '</fills>' .
        '<borders count="5">' .
        '<border><left/><right/><top/><bottom/><diagonal/></border>' .
        '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>' .
        '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="medium"><color rgb="FF666666"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>' .
        '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="medium"><color rgb="FF666666"/></bottom><diagonal/></border>' .
        '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="medium"><color rgb="FF666666"/></top><bottom style="medium"><color rgb="FF666666"/></bottom><diagonal/></border>' .
        '</borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="24">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' .
        '<xf numFmtId="164" fontId="2" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="2" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="3" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="4" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="2" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="3" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="4" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="4" borderId="2" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="4" borderId="3" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="4" borderId="4" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="4" borderId="1" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="4" borderId="2" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="4" borderId="3" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '<xf numFmtId="164" fontId="0" fillId="4" borderId="4" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' .
        '</cellXfs>' .
        '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
        '</styleSheet>';
}

function createXlsx(array $report): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Для формирования .xlsx нужен PHP-модуль ZipArchive.');
    }

    $path = tempnam(sys_get_temp_dir(), 'closed-hours-');
    if ($path === false) {
        throw new RuntimeException('Не удалось создать временный файл.');
    }

    $xlsxPath = $path . '.xlsx';
    rename($path, $xlsxPath);

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось открыть временный .xlsx.');
    }

    $created = gmdate('Y-m-d\TH:i:s\Z');
    $sheets = buildWorkbookSheets($report);
    $sheetCount = count($sheets);
    $contentTypesSheets = '';
    $workbookSheets = '';
    $workbookRels = '';
    $titlesOfParts = '';

    foreach ($sheets as $index => $sheet) {
        $sheetNumber = $index + 1;
        $relationshipId = 'rId' . $sheetNumber;

        $contentTypesSheets .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . xlsxXml((string)$sheet['name']) . '" sheetId="' . $sheetNumber . '" r:id="' . $relationshipId . '"/>';
        $workbookRels .= '<Relationship Id="' . $relationshipId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNumber . '.xml"/>';
        $titlesOfParts .= '<vt:lpstr>' . xlsxXml((string)$sheet['name']) . '</vt:lpstr>';
    }

    $stylesRelationshipId = 'rId' . ($sheetCount + 1);

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        $contentTypesSheets .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
        '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
        '</Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
        '<dc:title>Закрытые часы за ' . xlsxXml((string)$report['month_title']) . '</dc:title>' .
        '<dc:creator>task2.kodar-msk.ru</dc:creator>' .
        '<cp:lastModifiedBy>task2.kodar-msk.ru</cp:lastModifiedBy>' .
        '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>' .
        '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>' .
        '</cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
        '<Application>task2.kodar-msk.ru</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>' .
        '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . $sheetCount . '</vt:i4></vt:variant></vt:vector></HeadingPairs>' .
        '<TitlesOfParts><vt:vector size="' . $sheetCount . '" baseType="lpstr">' . $titlesOfParts . '</vt:vector></TitlesOfParts>' .
        '</Properties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' . $workbookSheets . '</sheets>' .
        '</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        $workbookRels .
        '<Relationship Id="' . $stylesRelationshipId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>');
    $zip->addFromString('xl/styles.xml', buildStylesXml());
    foreach ($sheets as $index => $sheet) {
        $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', (string)$sheet['xml']);
    }

    $zip->close();

    return $xlsxPath;
}

function downloadXlsx(array $report): void
{
    $path = createXlsx($report);
    $filename = 'Закрытые часы ' . $report['period'] . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"closed-hours-" . $report['period'] . ".xlsx\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate');

    readfile($path);
    unlink($path);
}

/* =====================================================================
 * Навигация / доска задач / дашборд (новый интерфейс)
 * ===================================================================== */

function bitrixPortalBase(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $parts = parse_url(BITRIX_WEBHOOK);
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        $base = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
    } else {
        $base = '';
    }

    return $base;
}

function bitrixTaskUrl(int $taskId, int $groupId = 0): string
{
    $base = bitrixPortalBase();
    if ($base === '' || $taskId <= 0) {
        return '';
    }

    if ($groupId > 0) {
        return $base . '/workgroups/group/' . $groupId . '/tasks/task/view/' . $taskId . '/';
    }

    return $base . '/company/personal/user/0/tasks/task/view/' . $taskId . '/';
}

function bitrixProjectUrl(int $groupId): string
{
    $base = bitrixPortalBase();
    if ($base === '' || $groupId <= 0) {
        return '';
    }

    return $base . '/workgroups/group/' . $groupId . '/';
}

function fetchUserNamesBulk(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (empty($userIds)) {
        return [];
    }

    $commands = [];
    foreach ($userIds as $userId) {
        $commands['u' . $userId] = bitrixCommand('user.get', ['ID' => $userId]);
    }

    try {
        $batch = bitrixBatchAll($commands);
    } catch (Throwable $e) {
        $batch = ['result' => []];
    }

    $result = [];
    foreach ($userIds as $userId) {
        $items = $batch['result']['u' . $userId] ?? [];
        $user = (is_array($items) && isset($items[0]) && is_array($items[0])) ? $items[0] : [];
        $result[$userId] = !empty($user) ? userDisplayName($user) : getUserName($userId);
    }

    return $result;
}

function fetchUserPhotosBulk(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (empty($userIds)) {
        return [];
    }

    $commands = [];
    foreach ($userIds as $userId) {
        $commands['u' . $userId] = bitrixCommand('user.get', ['ID' => $userId]);
    }

    try {
        $batch = bitrixBatchAll($commands);
    } catch (Throwable $e) {
        return [];
    }

    $result = [];
    foreach ($userIds as $userId) {
        $items = $batch['result']['u' . $userId] ?? [];
        $user = (is_array($items) && isset($items[0]) && is_array($items[0])) ? $items[0] : [];
        $photo = trim((string)($user['PERSONAL_PHOTO'] ?? ''));
        if ($photo !== '' && preg_match('~^https?://~i', $photo)) {
            $result[$userId] = $photo;
        }
    }

    return $result;
}

function fetchProjectNamesBulk(array $groupIds): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
    if (empty($groupIds)) {
        return [];
    }

    $names = [];

    foreach ([['ID' => $groupIds], []] as $filter) {
        try {
            $groups = bitrixPagedItems('sonet_group.get', [
                'FILTER' => $filter,
                'SELECT' => ['ID', 'NAME'],
            ], ['result']);
        } catch (Throwable $e) {
            $groups = [];
        }

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $id = (int)($group['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string)($group['NAME'] ?? ''));
            if ($name !== '') {
                $names[$id] = $name;
            }
        }

        // Если по фильтру что-то нашли — второй (полный) проход не нужен.
        if (!empty(array_intersect_key($names, array_flip($groupIds)))) {
            break;
        }
    }

    foreach ($groupIds as $groupId) {
        if (!isset($names[$groupId])) {
            $names[$groupId] = 'Проект #' . $groupId;
        }
    }

    return $names;
}

function fetchAllTasks(array $filter, array $select): array
{
    $baseData = [
        'order' => ['ID' => 'asc'],
        'filter' => $filter,
        'select' => $select,
    ];

    $firstPageData = $baseData;
    $firstPageData['start'] = 0;
    $firstResponse = bitrixRequest('tasks.task.list', $firstPageData);
    $tasks = getNestedValue($firstResponse, ['result', 'tasks']);
    $tasks = is_array($tasks) ? $tasks : [];

    $total = (int)($firstResponse['total'] ?? count($tasks));
    $next = isset($firstResponse['next']) ? (int)$firstResponse['next'] : 0;

    if ($next > 0 && $total > count($tasks)) {
        $commands = [];

        for ($start = $next; $start < $total; $start += 50) {
            $pageData = $baseData;
            $pageData['start'] = $start;
            $commands['p' . $start] = bitrixCommand('tasks.task.list', $pageData);
        }

        $batch = bitrixBatchAll($commands);
        foreach ($batch['result'] as $pageResult) {
            if (!is_array($pageResult)) {
                continue;
            }

            $pageTasks = $pageResult['tasks'] ?? [];
            if (is_array($pageTasks) && !empty($pageTasks)) {
                $tasks = array_merge($tasks, $pageTasks);
            }
        }
    }

    return $tasks;
}

function boardTaskCard(array $task, array $userNames, array $companyNames): array
{
    $id = (int)($task['id'] ?? $task['ID'] ?? 0);
    $groupId = (int)($task['groupId'] ?? $task['GROUP_ID'] ?? 0);
    $responsibleId = (int)($task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? 0);
    $companyId = taskCompanyId($task);

    $createdRaw = (string)($task['createdDate'] ?? $task['CREATED_DATE'] ?? '');
    $closedRaw = (string)($task['closedDate'] ?? $task['CLOSED_DATE'] ?? '');
    $deadlineRaw = (string)($task['deadline'] ?? $task['DEADLINE'] ?? '');

    $title = trim((string)($task['title'] ?? $task['TITLE'] ?? ''));
    if ($title === '') {
        $title = 'Задача #' . $id;
    }

    return [
        'id' => $id,
        'title' => $title,
        'url' => bitrixTaskUrl($id, $groupId),
        'status' => taskStatusName($task),
        'status_code' => (int)($task['realStatus'] ?? $task['REAL_STATUS'] ?? $task['status'] ?? $task['STATUS'] ?? 0),
        'responsible' => $responsibleId > 0 ? ($userNames[$responsibleId] ?? getUserName($responsibleId)) : '-',
        'company' => $companyId > 0 ? ($companyNames[$companyId] ?? '-') : '-',
        'created' => formatTaskDateValue($createdRaw),
        'closed' => formatTaskDateValue($closedRaw),
        'deadline' => formatTaskDateValue($deadlineRaw),
        'created_ts' => $createdRaw !== '' ? (int)(strtotime($createdRaw) ?: 0) : 0,
        'closed_ts' => $closedRaw !== '' ? (int)(strtotime($closedRaw) ?: 0) : 0,
        'deadline_ts' => $deadlineRaw !== '' ? (int)(strtotime($deadlineRaw) ?: 0) : 0,
    ];
}

function buildProjectBoard(string $mode, string $period): array
{
    set_time_limit(300);

    $mode = $mode === 'closed' ? 'closed' : 'active';
    [$monthStart] = monthPeriod($period);

    $statuses = $mode === 'closed' ? BOARD_CLOSED_STATUSES : BOARD_ACTIVE_STATUSES;
    $filter = [
        'RESPONSIBLE_ID' => array_values(REPORT_USER_IDS),
        'REAL_STATUS' => $statuses,
    ];

    if ($mode === 'closed') {
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
        $filter['>=CLOSED_DATE'] = $monthStart->format('Y-m-d H:i:s');
        $filter['<=CLOSED_DATE'] = $monthEnd->format('Y-m-d H:i:s');
    }

    $select = [
        'ID',
        'TITLE',
        'STATUS',
        'REAL_STATUS',
        'RESPONSIBLE_ID',
        'CREATED_BY',
        'CREATED_DATE',
        'CLOSED_DATE',
        'DEADLINE',
        'GROUP_ID',
        'UF_CRM_TASK',
    ];

    $tasks = fetchAllTasks($filter, $select);

    $responsibleIds = [];
    $companyIds = [];
    $groupIds = [];
    $projectNames = [];

    foreach ($tasks as $task) {
        $responsibleId = (int)($task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? 0);
        if ($responsibleId > 0) {
            $responsibleIds[] = $responsibleId;
        }

        $companyId = taskCompanyId($task);
        if ($companyId > 0) {
            $companyIds[] = $companyId;
        }

        $groupId = (int)($task['groupId'] ?? $task['GROUP_ID'] ?? 0);
        if ($groupId > 0) {
            $groupIds[] = $groupId;

            // tasks.task.list отдаёт объект group с названием — используем его,
            // не требуя у вебхука прав на sonet_group.get.
            $group = $task['group'] ?? null;
            if (is_array($group)) {
                $groupName = trim((string)($group['name'] ?? $group['NAME'] ?? ''));
                if ($groupName !== '') {
                    $projectNames[$groupId] = $groupName;
                }
            }
        }
    }

    $userNames = fetchUserNamesBulk($responsibleIds);
    $companyNames = fetchCompanyNamesBulk($companyIds);

    $unnamedGroupIds = array_values(array_diff(array_unique($groupIds), array_keys($projectNames)));
    if (!empty($unnamedGroupIds)) {
        $projectNames += fetchProjectNamesBulk($unnamedGroupIds);
    }

    $projects = [];
    foreach ($tasks as $task) {
        $groupId = (int)($task['groupId'] ?? $task['GROUP_ID'] ?? 0);

        if (!isset($projects[$groupId])) {
            $projects[$groupId] = [
                'id' => $groupId,
                'name' => $groupId > 0 ? ($projectNames[$groupId] ?? ('Проект #' . $groupId)) : 'Без проекта',
                'url' => $groupId > 0 ? bitrixProjectUrl($groupId) : '',
                'tasks' => [],
            ];
        }

        $projects[$groupId]['tasks'][] = boardTaskCard($task, $userNames, $companyNames);
    }

    $limit = (int)BOARD_TASKS_PER_PROJECT_LIMIT;
    foreach ($projects as &$project) {
        usort($project['tasks'], static function (array $left, array $right) use ($mode): int {
            if ($mode === 'closed') {
                return $right['closed_ts'] <=> $left['closed_ts'];
            }

            $leftDeadline = $left['deadline_ts'] > 0 ? $left['deadline_ts'] : PHP_INT_MAX;
            $rightDeadline = $right['deadline_ts'] > 0 ? $right['deadline_ts'] : PHP_INT_MAX;
            if ($leftDeadline !== $rightDeadline) {
                return $leftDeadline <=> $rightDeadline;
            }

            return $right['created_ts'] <=> $left['created_ts'];
        });

        $project['count'] = count($project['tasks']);
        $project['truncated'] = 0;
        if ($limit > 0 && $project['count'] > $limit) {
            $project['truncated'] = $project['count'] - $limit;
            $project['tasks'] = array_slice($project['tasks'], 0, $limit);
        }
    }
    unset($project);

    uasort($projects, static function (array $left, array $right): int {
        // "Без проекта" (id 0) всегда в конце.
        if (($left['id'] === 0) !== ($right['id'] === 0)) {
            return $left['id'] === 0 ? 1 : -1;
        }

        if ($left['count'] !== $right['count']) {
            return $right['count'] <=> $left['count'];
        }

        return strnatcasecmp((string)$left['name'], (string)$right['name']);
    });

    return [
        'period' => $period,
        'mode' => $mode,
        'month_title' => russianMonthTitle($monthStart),
        'projects' => array_values($projects),
        'projects_count' => count($projects),
        'tasks_total' => count($tasks),
    ];
}

function dashboardDataDir(): string
{
    return rtrim(DASHBOARD_DATA_DIR, "/\\");
}

function ensureDashboardDataDir(): string
{
    $dir = dashboardDataDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function dashboardSnapshotPath(string $period): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Некорректный период дашборда.');
    }

    return dashboardDataDir() . '/dashboard-' . $period . '.json';
}

function dashboardSnapshotFromReport(array $report): array
{
    $totalMinutes = (int)($report['total_minutes'] ?? 0);
    $employees = [];

    $employeeIds = [];
    foreach (($report['rows'] ?? []) as $row) {
        $employeeIds[] = (int)($row['id'] ?? 0);
    }
    $photos = fetchUserPhotosBulk($employeeIds);

    foreach (($report['rows'] ?? []) as $row) {
        $minutes = (int)($row['minutes'] ?? 0);
        $employeeId = (int)($row['id'] ?? 0);
        $employees[] = [
            'id' => $employeeId,
            'name' => (string)($row['name'] ?? '-'),
            'photo' => $photos[$employeeId] ?? '',
            'minutes' => $minutes,
            'hours' => round($minutes / 60, 2),
            'tasks_count' => (int)($row['tasks_count'] ?? 0),
        ];
    }

    usort($employees, static function (array $left, array $right): int {
        if ($left['minutes'] !== $right['minutes']) {
            return $right['minutes'] <=> $left['minutes'];
        }

        return strnatcasecmp((string)$left['name'], (string)$right['name']);
    });

    return [
        'period' => (string)($report['period'] ?? ''),
        'month_title' => (string)($report['month_title'] ?? ''),
        'updated_at' => date('c'),
        'total_minutes' => $totalMinutes,
        'total_hours' => round($totalMinutes / 60, 2),
        'tasks_matched' => (int)($report['tasks_matched'] ?? 0),
        'employees' => $employees,
    ];
}

function saveDashboardSnapshot(array $report): bool
{
    try {
        $period = (string)($report['period'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return false;
        }

        ensureDashboardDataDir();
        $snapshot = dashboardSnapshotFromReport($report);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        $path = dashboardSnapshotPath($period);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            return false;
        }

        return rename($tmp, $path);
    } catch (Throwable $e) {
        return false;
    }
}

function loadDashboardSnapshot(string $period): ?array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        return null;
    }

    $path = dashboardSnapshotPath($period);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function listDashboardPeriods(): array
{
    $dir = dashboardDataDir();
    if (!is_dir($dir)) {
        return [];
    }

    $periods = [];
    foreach ((glob($dir . '/dashboard-*.json') ?: []) as $file) {
        if (preg_match('/dashboard-(\d{4}-\d{2})\.json$/', $file, $matches)) {
            $periods[] = $matches[1];
        }
    }

    $periods = array_values(array_unique($periods));
    rsort($periods);

    return $periods;
}
