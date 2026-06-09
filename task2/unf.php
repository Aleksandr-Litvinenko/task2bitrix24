<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const UNF_ZERO_GUID = '00000000-0000-0000-0000-000000000000';

function unfOdataRequest(string $method, string $path, ?array $payload = null, array $params = []): array
{
    if (UNF_ODATA_USER === '' || UNF_ODATA_PASSWORD === '') {
        throw new RuntimeException('Не настроены UNF_ODATA_USER и UNF_ODATA_PASSWORD.');
    }

    $url = unfOdataUrl($path, $params);
    $headers = [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(UNF_ODATA_USER . ':' . UNF_ODATA_PASSWORD),
    ];
    $body = null;

    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Не удалось подготовить JSON для УНФ.');
        }
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }

    if (function_exists('curl_init')) {
        $curl = curl_init();
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_URL => $url,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 180,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Ошибка запроса к УНФ: ' . $error);
        }
    } else {
        $contextHeaders = implode("\r\n", $headers) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $contextHeaders,
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 180,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        $statusCode = 0;

        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headerLine, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }

        if ($response === false) {
            throw new RuntimeException('Ошибка запроса к УНФ.');
        }
    }

    $decoded = [];
    if ($response !== '') {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('УНФ вернула некорректный JSON.');
        }
    }

    if ($statusCode >= 400) {
        throw new RuntimeException('Ошибка УНФ OData: ' . unfOdataErrorMessage($decoded, $statusCode));
    }

    return $decoded;
}

function unfOdataUrl(string $path, array $params = []): string
{
    $segments = array_filter(explode('/', ltrim($path, '/')), static function (string $segment): bool {
        return $segment !== '';
    });
    $encodedPath = implode('/', array_map('rawurlencode', $segments));
    $query = $params !== [] ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';

    return rtrim(UNF_ODATA_BASE_URL, '/') . '/' . $encodedPath . $query;
}

function unfOdataErrorMessage(array $decoded, int $statusCode): string
{
    $paths = [
        ['error', 'message', 'value'],
        ['error', 'message'],
        ['odata.error', 'message', 'value'],
        ['odata.error', 'message'],
    ];

    foreach ($paths as $path) {
        $value = $decoded;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                continue 2;
            }
            $value = $value[$key];
        }

        if (is_scalar($value) && (string)$value !== '') {
            return (string)$value;
        }
    }

    return 'HTTP ' . $statusCode;
}

function unfOdataValues(array $response): array
{
    if (isset($response['value']) && is_array($response['value'])) {
        return $response['value'];
    }

    if (isset($response['d']['results']) && is_array($response['d']['results'])) {
        return $response['d']['results'];
    }

    return [];
}

function unfOdataEntity(array $response): array
{
    if (isset($response['d']) && is_array($response['d']) && !isset($response['d']['results'])) {
        return $response['d'];
    }

    return $response;
}

function unfFetchEmployees(): array
{
    static $employees = null;

    if (is_array($employees)) {
        return $employees;
    }

    $employees = [];
    $top = 500;
    $skip = 0;

    do {
        $response = unfOdataRequest('GET', 'Catalog_Сотрудники', null, [
            '$format' => 'json',
            '$select' => 'Ref_Key,Description,DeletionMark',
            '$orderby' => 'Description',
            '$top' => (string)$top,
            '$skip' => (string)$skip,
        ]);
        $items = unfOdataValues($response);

        foreach ($items as $item) {
            if (!is_array($item) || (bool)($item['DeletionMark'] ?? false)) {
                continue;
            }

            $key = (string)($item['Ref_Key'] ?? '');
            $description = trim((string)($item['Description'] ?? ''));
            if ($key === '' || $description === '') {
                continue;
            }

            $employees[] = [
                'Ref_Key' => $key,
                'Description' => $description,
                'normalized' => unfNormalizeName($description),
                'tokens' => unfNameTokens($description),
            ];
        }

        $skip += $top;
    } while (count($items) === $top);

    if ($employees === []) {
        throw new RuntimeException('В УНФ не найдены сотрудники через Catalog_Сотрудники.');
    }

    return $employees;
}

function unfFindEmployeeForReportRow(array $row, array $employees): array
{
    $bitrixUserId = (string)(int)($row['id'] ?? 0);
    $name = trim((string)($row['name'] ?? ''));
    $normalized = unfNormalizeName($name);
    $configuredKey = unfConfiguredEmployeeKey($bitrixUserId, $name, $normalized);

    if ($configuredKey !== '') {
        return [
            'Ref_Key' => $configuredKey,
            'Description' => $name !== '' ? $name : 'Bitrix user ' . $bitrixUserId,
        ];
    }

    foreach ($employees as $employee) {
        if ($employee['normalized'] === $normalized) {
            return $employee;
        }
    }

    $tokens = unfNameTokens($name);
    $matches = [];
    foreach ($employees as $employee) {
        if (unfTokensAreSubset($tokens, $employee['tokens'])) {
            $matches[] = $employee;
        }
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    if (count($matches) > 1) {
        $names = array_map(static function (array $employee): string {
            return (string)$employee['Description'];
        }, array_slice($matches, 0, 5));
        throw new RuntimeException('Найдено несколько сотрудников УНФ для "' . $name . '": ' . implode(', ', $names));
    }

    throw new RuntimeException('Не найден сотрудник УНФ для "' . $name . '". Добавьте соответствие в UNF_EMPLOYEE_KEY_MAP.');
}

function unfConfiguredEmployeeKey(string $bitrixUserId, string $name, string $normalizedName): string
{
    $map = UNF_EMPLOYEE_KEY_MAP;
    $keys = [$bitrixUserId, $name, $normalizedName];

    foreach ($keys as $key) {
        if ($key !== '' && isset($map[$key]) && is_scalar($map[$key])) {
            return (string)$map[$key];
        }
    }

    return '';
}

function unfNormalizeName(string $name): string
{
    $name = str_replace(['Ё', 'ё'], ['Е', 'е'], $name);
    if (function_exists('mb_strtolower')) {
        $name = mb_strtolower($name, 'UTF-8');
    } else {
        $name = strtolower($name);
    }

    $cleaned = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);
    if (is_string($cleaned)) {
        $name = $cleaned;
    }

    $collapsed = preg_replace('/\s+/u', ' ', trim($name));
    return is_string($collapsed) ? $collapsed : trim($name);
}

function unfNameTokens(string $name): array
{
    $normalized = unfNormalizeName($name);
    if ($normalized === '') {
        return [];
    }

    $tokens = preg_split('/\s+/u', $normalized);
    if (!is_array($tokens)) {
        return [];
    }

    $tokens = array_values(array_unique(array_filter($tokens, static function (string $token): bool {
        return $token !== '';
    })));
    sort($tokens);

    return $tokens;
}

function unfTokensAreSubset(array $required, array $available): bool
{
    if ($required === []) {
        return false;
    }

    foreach ($required as $token) {
        if (!in_array($token, $available, true)) {
            return false;
        }
    }

    return true;
}

function createUnfTimeDocumentsForReport(array $report): array
{
    $period = (string)($report['period'] ?? '');
    [$documentDate, $dateFrom, $dateTo] = unfTimeDocumentDates($period);
    $rows = array_values(array_filter((array)($report['rows'] ?? []), static function ($row): bool {
        return is_array($row);
    }));
    $created = [];
    $skipped = [];
    $errors = [];

    foreach ($rows as $row) {
        $name = (string)($row['name'] ?? '');
        $hours = round((float)($row['hours'] ?? 0), 2);
        $bitrixUserId = (int)($row['id'] ?? 0);

        if ($hours <= 0) {
            $skipped[] = [
                'bitrix_user_id' => $bitrixUserId,
                'name' => $name,
                'hours' => $hours,
                'reason' => 'Нулевые часы',
            ];
        }
    }

    $rowsToCreate = array_values(array_filter($rows, static function (array $row): bool {
        return round((float)($row['hours'] ?? 0), 2) > 0;
    }));

    if ($rowsToCreate !== []) {
        $employees = unfFetchEmployees();

        foreach ($rowsToCreate as $row) {
            $name = (string)($row['name'] ?? '');
            $hours = round((float)($row['hours'] ?? 0), 2);
            $bitrixUserId = (int)($row['id'] ?? 0);

            try {
                $employee = unfFindEmployeeForReportRow($row, $employees);
                $existing = unfFindExistingTimeDocument((string)$employee['Ref_Key'], $dateFrom, $dateTo);

                if ($existing !== null) {
                    $skipped[] = [
                        'bitrix_user_id' => $bitrixUserId,
                        'name' => $name,
                        'hours' => $hours,
                        'reason' => 'Документ за эту неделю уже есть',
                        'existing_number' => (string)($existing['Number'] ?? ''),
                        'existing_ref_key' => (string)($existing['Ref_Key'] ?? ''),
                    ];
                    continue;
                }

                $payload = unfBuildTimeDocumentPayload($row, (string)$employee['Ref_Key'], $period, $documentDate, $dateFrom, $dateTo);
                $document = unfOdataEntity(unfOdataRequest('POST', 'Document_УчетВремени', $payload, ['$format' => 'json']));

                if (UNF_TIME_POST_DOCUMENTS && isset($document['Ref_Key'])) {
                    unfOdataRequest('POST', "Document_УчетВремени(guid'" . $document['Ref_Key'] . "')/Post()");
                }

                $created[] = [
                    'bitrix_user_id' => $bitrixUserId,
                    'name' => $name,
                    'hours' => $hours,
                    'number' => (string)($document['Number'] ?? ''),
                    'ref_key' => (string)($document['Ref_Key'] ?? ''),
                    'posted' => UNF_TIME_POST_DOCUMENTS,
                ];
            } catch (Throwable $e) {
                $errors[] = [
                    'bitrix_user_id' => $bitrixUserId,
                    'name' => $name,
                    'hours' => $hours,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    return [
        'ok' => $errors === [],
        'period' => $period,
        'month_title' => (string)($report['month_title'] ?? $period),
        'source' => [
            'rows' => count($rows),
            'total_hours' => round((float)($report['total_hours'] ?? 0), 2),
        ],
        'unf_period' => [
            'date' => unfOdataDate($documentDate),
            'date_from' => unfOdataDate($dateFrom),
            'date_to' => unfOdataDate($dateTo),
        ],
        'created' => $created,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

function unfTimeDocumentDates(string $period): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Укажите месяц в формате YYYY-MM.');
    }

    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    if (!$monthStart) {
        throw new InvalidArgumentException('Некорректный месяц отчёта.');
    }

    $monthEnd = $monthStart->modify('last day of this month');
    $documentDate = $monthEnd->setTime(12, 0, 0);
    $dateFrom = ((int)$monthStart->format('N') === 1 ? $monthStart : $monthStart->modify('next monday'))->setTime(0, 0, 0);
    $dateTo = $dateFrom->modify('+6 days')->setTime(0, 0, 0);

    if ($dateTo > $monthEnd->setTime(23, 59, 59)) {
        throw new RuntimeException('В выбранном месяце не найдена полная неделя для документа УНФ.');
    }

    return [$documentDate, $dateFrom, $dateTo];
}

function unfBuildTimeDocumentPayload(
    array $row,
    string $employeeKey,
    string $period,
    DateTimeImmutable $documentDate,
    DateTimeImmutable $dateFrom,
    DateTimeImmutable $dateTo
): array {
    $hours = round((float)($row['hours'] ?? 0), 2);
    $dayDurations = unfDistributeHours($hours);
    $rate = round((float)UNF_TIME_RATE, 2);
    $sum = round($rate * $hours, 2);

    return [
        'Date' => unfOdataDate($documentDate),
        'DeletionMark' => false,
        'Posted' => false,
        'Организация_Key' => UNF_TIME_ORGANIZATION_KEY,
        'СтруктурнаяЕдиница_Key' => UNF_TIME_STRUCTURAL_UNIT_KEY,
        'Сотрудник_Key' => $employeeKey,
        'Комментарий' => unfTimeDocumentComment($row, $period),
        'ДатаС' => unfOdataDate($dateFrom),
        'ДатаПо' => unfOdataDate($dateTo),
        'Автор_Key' => UNF_TIME_AUTHOR_KEY,
        'ХозяйственнаяОперация_Key' => UNF_TIME_BUSINESS_OPERATION_KEY,
        'ДокументОснование_Key' => UNF_ZERO_GUID,
        'Операции' => [
            [
                'LineNumber' => '1',
                'Заказчик' => '',
                'Заказчик_Type' => 'StandardODATA.Undefined',
                'ВидРабот_Key' => UNF_TIME_WORK_TYPE_KEY,
                'Номенклатура_Key' => UNF_TIME_NOMENCLATURE_KEY,
                'Характеристика_Key' => UNF_ZERO_GUID,
                'Расценка' => $rate,
                'ВидЦен_Key' => UNF_TIME_PRICE_TYPE_KEY,
                'Всего' => $hours,
                'ПнДлительность' => $dayDurations[0],
                'ВтДлительность' => $dayDurations[1],
                'СрДлительность' => $dayDurations[2],
                'ЧтДлительность' => $dayDurations[3],
                'ПтДлительность' => $dayDurations[4],
                'СбДлительность' => $dayDurations[5],
                'ВсДлительность' => $dayDurations[6],
                'Сумма' => $sum,
                'Комментарий' => '',
            ],
        ],
    ];
}

function unfTimeDocumentComment(array $row, string $period): string
{
    $name = trim((string)($row['name'] ?? ''));
    $bitrixUserId = (int)($row['id'] ?? 0);

    return trim('task2 закрытые часы ' . $period . '; Bitrix user ' . $bitrixUserId . '; ' . $name);
}

function unfDistributeHours(float $hours): array
{
    if ($hours < 0) {
        throw new InvalidArgumentException('Количество часов не может быть отрицательным.');
    }

    $remaining = round($hours, 2);
    $days = [];

    for ($day = 0; $day < 7; $day++) {
        $duration = min(24.0, max(0.0, $remaining));
        $duration = round($duration, 2);
        $days[] = $duration;
        $remaining = round($remaining - $duration, 2);
    }

    if ($remaining > 0) {
        throw new RuntimeException('В документ УНФ за одну неделю нельзя разложить больше 168 часов.');
    }

    return $days;
}

function unfFindExistingTimeDocument(string $employeeKey, DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo): ?array
{
    $filter = "Сотрудник_Key eq guid'" . $employeeKey . "'"
        . " and ДатаС eq datetime'" . unfOdataDate($dateFrom) . "'"
        . " and ДатаПо eq datetime'" . unfOdataDate($dateTo) . "'";

    $response = unfOdataRequest('GET', 'Document_УчетВремени', null, [
        '$format' => 'json',
        '$select' => 'Ref_Key,Number,Date,Posted,Сотрудник_Key,ДатаС,ДатаПо,Комментарий',
        '$filter' => $filter,
        '$top' => '1',
    ]);
    $items = unfOdataValues($response);

    if ($items === []) {
        return null;
    }

    return is_array($items[0]) ? $items[0] : null;
}

function unfOdataDate(DateTimeImmutable $date): string
{
    return $date->format('Y-m-d\TH:i:s');
}
