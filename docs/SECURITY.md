# Безопасность

## Секреты

Не коммитить:

- реальный `BITRIX_WEBHOOK`;
- `task2/config.local.php`;
- production-пароли Basic Auth;
- выгруженные Excel-файлы с реальными задачами, компаниями и комментариями.

## Где хранить webhook

Production:

```sh
BITRIX_WEBHOOK="https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/"
```

Локально:

```php
// task2/config.local.php
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
- CRM-компаниям.

Не выдавайте webhook права шире, чем нужно для отчёта.
