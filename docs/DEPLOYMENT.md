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

- `index.php`, `tasks.php`, `dashboard.php`, `quality.php`, `kpi.php`, `admin.php` — страницы;
- `report.php`, `unf_time.php`, `unf_workorder.php`, `dashboard_refresh.php` — обработчики;
- `partials/head.php`, `partials/foot.php`, `effects.js`, `style.css`;
- `auth.php`, `config.php`, `config.local.example.php`, `lib.php`, `unf.php`;
- папка `data/` — **должна быть доступна на запись** пользователю веб-сервера (снимки дашборда, KPI, проверки задач и `users.json`).

На production-сервере задайте webhook и пароль через переменные окружения или создайте некоммитимый `config.local.php`.

Желательно закрыть `data/` от прямой раздачи веб-сервером (например, в nginx: `location /data/ { deny all; }`) — в снимках ФИО, часы сотрудников и хеши паролей.

## Пример: nginx + PHP-FPM на VPS

Рабочая конфигурация стенда. Пакеты:

```bash
apt-get install -y php8.3-fpm php8.3-zip php8.3-curl php8.3-mbstring php8.3-xml
```

Отдельный пул PHP-FPM (`/etc/php/8.3/fpm/pool.d/task2.conf`) — отчёт за месяц и документы УНФ держат воркер минутами, и это не должно влиять на другие сайты сервера:

```ini
[task2]
user = www-data
group = www-data
listen = /run/php/task2.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10

; 30 секунд по умолчанию убивают выгрузку за реальный месяц
php_admin_value[max_execution_time] = 600
php_admin_value[memory_limit] = 512M
request_terminate_timeout = 600s

php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/php8.3-fpm.task2.log
php_admin_value[default_charset] = UTF-8
php_admin_value[open_basedir] = /var/www/<домен>:/tmp:/usr/share/php
```

Сайт nginx:

```nginx
server {
    listen 80;
    server_name <домен>;
    root /var/www/<домен>;
    index index.php;
    charset utf-8;

    client_max_body_size 30m;

    # снимки и учётки наружу не отдаём
    location ^~ /data/ { deny all; return 404; }
    location ~ ^/(config\.local\.php|tools/) { deny all; return 404; }

    location / { try_files $uri $uri/ =404; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;   # try_files уже внутри сниппета
        fastcgi_pass unix:/run/php/task2.sock;
        fastcgi_read_timeout 600s;
        fastcgi_buffering off;
    }
}
```

Права:

```bash
chown -R www-data:www-data /var/www/<домен>
chmod 750 /var/www/<домен>/data
chmod 640 /var/www/<домен>/config.local.php
```

Если перед VPS стоит прокси хостинга, который терминирует HTTPS и ходит на origin по 80 порту (так работает Jino), **редирект на HTTPS в этом конфиге не нужен** — он зациклит запрос.

После деплоя:

```bash
sudo -u www-data php tools/preflight.php --online
```

## Кеш CSS/JS

`partials/head.php` и `partials/foot.php` подключают статику как:

```php
style.css?v=<filemtime>
effects.js?v=<filemtime>
```

Это нужно, потому что nginx/хостинг может кешировать статику на сутки.
