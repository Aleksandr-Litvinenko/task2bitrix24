# Настройка и деплой

## Требования

- PHP 8.x.
- PHP-расширения `ZipArchive` (создание `.xlsx`) и `curl` (запросы к Bitrix24/УНФ; есть fallback на streams).
- Доступ с сервера до Bitrix24 (и до 1С:Фреш, если используется кнопка УНФ).
- Входящий webhook Bitrix24 с правами на задачи, пользователей, CRM-компании и рабочие группы (`sonet_group.get`).

## Настройка через переменные окружения

Рекомендуемый способ для сервера:

```sh
BITRIX_WEBHOOK="https://<portal>.bitrix24.ru/rest/<user-id>/<webhook-code>/"
APP_AUTH_USER="admin"
APP_AUTH_PASSWORD="<strong-password>"
```

`APP_AUTH_USER` и `APP_AUTH_PASSWORD` используются для Basic Auth веб-интерфейса.

Дополнительно (необязательно):

```sh
TELEGRAM_CHANNEL_URL="https://t.me/kodar1c"   # кнопка «ХОЧУ ТАКЖЕ»
DASHBOARD_DATA_DIR="/var/www/taskcrm-data"    # папка снимков дашборда (по умолчанию ./data)
```

## Настройка через локальный файл

```sh
cp config.local.example.php config.local.php
```

`config.local.php` находится в `.gitignore` и не должен попадать в GitHub.

## Проверка настройки

Перед первым запуском (и после переезда на новый сервер):

```sh
php tools/preflight.php
```

Скрипт проходит по всем требованиям этой страницы и говорит, чего не хватает:
версия PHP, расширения `zip` / `curl` / `mbstring`, наличие вебхука, пароль по
умолчанию, права на запись в папку снимков, учётные данные УНФ.

С флагом `--online` дополнительно проверяются права самого вебхука — по одному
запросу на каждый используемый метод Битрикса и пинг OData УНФ:

```sh
php tools/preflight.php --online
```

Проверка ничего не меняет: только чтение. Код возврата `0` — можно работать,
`1` — есть блокирующие проблемы.

## Локальный запуск

```sh
php -S 127.0.0.1:8080
```

Открыть `http://127.0.0.1:8080/`.

## Деплой на хостинг

Загрузите содержимое папки проекта в web root домена или поддомена.

Минимальный набор файлов:

- `index.php`, `tasks.php`, `dashboard.php` — вкладки;
- `report.php`, `unf_time.php`, `dashboard_refresh.php` — обработчики;
- `partials/head.php`, `partials/foot.php`, `effects.js`, `style.css`;
- `auth.php`, `config.php`, `config.local.example.php`, `lib.php`, `unf.php`;
- папка `data/` — **должна быть доступна на запись** пользователю веб-сервера (снимки дашборда `dashboard-YYYY-MM.json`).

На production-сервере задайте webhook и пароль через переменные окружения или создайте некоммитимый `config.local.php`.

Желательно закрыть `data/` от прямой раздачи веб-сервером (например, в nginx: `location /data/ { deny all; }`) — в снимках ФИО и часы сотрудников.

## Кеш CSS/JS

`partials/head.php` и `partials/foot.php` подключают статику как:

```php
style.css?v=<filemtime>
effects.js?v=<filemtime>
```

Это нужно, потому что nginx/хостинг может кешировать статику на сутки.
