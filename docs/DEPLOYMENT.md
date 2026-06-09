# Настройка и деплой

## Требования

- PHP 8.x.
- PHP-расширение `ZipArchive` для создания `.xlsx`.
- Доступ с сервера до Bitrix24.
- Входящий webhook Bitrix24 с правами на задачи, пользователей и CRM-компании.

## Настройка через переменные окружения

Рекомендуемый способ для сервера:

```sh
BITRIX_WEBHOOK="https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/"
APP_AUTH_USER="admin"
APP_AUTH_PASSWORD="<strong-password>"
```

`APP_AUTH_USER` и `APP_AUTH_PASSWORD` используются для Basic Auth веб-интерфейса.

## Настройка через локальный файл

Для локального запуска можно создать файл:

```sh
cp task2/config.local.example.php task2/config.local.php
```

И заполнить:

```php
define('LOCAL_BITRIX_WEBHOOK', 'https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/');
define('LOCAL_APP_AUTH_USER', 'admin');
define('LOCAL_APP_AUTH_PASSWORD', '<strong-password>');
```

`task2/config.local.php` находится в `.gitignore` и не должен попадать в GitHub.

## Локальный запуск

```sh
php -S 127.0.0.1:8080 -t task2
```

Открыть:

```text
http://127.0.0.1:8080/
```

## Деплой на хостинг

Загрузите содержимое папки `task2/` в web root домена или поддомена.

Минимальный набор файлов:

- `auth.php`
- `config.php`
- `config.local.example.php`
- `index.php`
- `lib.php`
- `report.php`
- `style.css`
- `README.md`

На production-сервере задайте webhook и пароль через переменные окружения или создайте некоммитимый `config.local.php`.

## Кеш CSS

`index.php` подключает CSS как:

```php
style.css?v=<filemtime>
```

Это нужно, потому что nginx/хостинг может кешировать `style.css` на сутки.
