# Безопасность

## Секреты

Не коммитить:

- реальный `BITRIX_WEBHOOK`;
- логин/пароль OData УНФ (`UNF_ODATA_USER` / `UNF_ODATA_PASSWORD`);
- `config.local.php`;
- production-пароли Basic Auth;
- выгруженные Excel-файлы с реальными задачами, компаниями и комментариями;
- снимки дашборда `data/*.json` (содержат ФИО и часы реальных сотрудников).

## Где хранить webhook

Production:

```sh
BITRIX_WEBHOOK="https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/"
```

Локально:

```php
// config.local.php
define('LOCAL_BITRIX_WEBHOOK', 'https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/');
```

## Если webhook попал в публичный доступ

1. Удалить или деактивировать входящий webhook в Bitrix24.
2. Создать новый webhook с минимально нужными правами.
3. Обновить переменную окружения или `config.local.php`.
4. Проверить историю git и GitHub на наличие старого секрета.

## Права Bitrix24

Webhook должен иметь доступ к:

- задачам и результатам задач;
- списанному времени задач;
- пользователям;
- CRM-компаниям;
- рабочим группам / проектам (`sonet_group.get`) — для названий колонок на доске «Задачи». Без этого права доска работает, но проекты называются `Проект #<id>`.

Не выдавайте webhook права шире, чем нужно.
