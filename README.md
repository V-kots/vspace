# VSpace

Локальная среда разработки на **Laravel 13** с Docker Compose: nginx, PHP 8.3-FPM, PostgreSQL, Redis, Mailpit, Loki, **Grafana Alloy** и Grafana.

## Стек

| Сервис    | Контейнер        | Назначение                          |
|-----------|------------------|-------------------------------------|
| nginx     | `{COMPOSE_PROJECT_NAME}-nginx-1` | HTTPS (порты из `.env.docker`) |
| php-fpm   | `{COMPOSE_PROJECT_NAME}-php-*`   | Laravel 13 (PHP 8.3), масштабируется |
| postgres  | …                | БД Laravel                          |
| redis     | …                | Кэш, сессии, очереди                |
| mailpit   | …                | Перехват исходящей почты            |
| loki / alloy / grafana | … | Логи и UI (Prometheus в compose закомментирован) |

## Требования

- Docker и Docker Compose
- Свободные порты **80** и **443** (nginx биндится напрямую, без Traefik)
- Запись в `/etc/hosts` **не обязательна для Laravel**: по адресу **https://127.0.0.1** (или `https://localhost`) открывается основное приложение — nginx отдаёт его как default vhost. Для удобного URL, валидного SSL без предупреждений и доступа к Grafana/Mailpit добавьте:

```
127.0.0.1 vspace.loc grafana.vspace.loc mail.vspace.loc
```

## Быстрый старт

```bash
# 1. Переменные окружения Docker
cp .env.docker.example .env.docker
# COMPOSE_PROJECT_NAME — имя проекта Compose (префикс контейнеров, сеть).
# Отредактируйте пароли и при необходимости HTTP_PORT / HTTPS_PORT / COMPOSE_DATA_ROOT.

# 2. Laravel: env-файл (ключ сгенерируем после старта, шаг 7)
#    ВАЖНО: DB_PASSWORD в src/.env должен совпадать с POSTGRES_PASSWORD в
#    .env.docker, иначе `artisan migrate` упадёт с "password authentication failed".
cp src/.env.example src/.env

# 3. SSL-сертификаты (локальный CA)
./docker/ssl/generate-certs.sh
./docker/ssl/install-ca.sh   # доверие CA в системе и Chrome

# 4. Сборка образов
docker compose --env-file .env.docker build

# 5. Composer-зависимости — обязательны: без vendor/ Laravel не стартует
#    (и preload в too-many-переменных PHP_OPCACHE_PRELOAD=1 требует vendor/)
docker compose --env-file .env.docker run --rm --no-deps php composer install

# 6. Сборка фронта: Vite (React) собирает тему в src/public/build
./scripts/frontend-build.sh   # внутри контейнера node:22, при отсутствии node_modules делает npm ci

# 7. Запуск
docker compose --env-file .env.docker up -d

# 8. Права на каталоги данных (нужно при первом запуске)
#    storage/bootstrap/cache — под www-data; grafana (472) и loki (10001) — под свои uid
./scripts/fix-data-permissions.sh

# 9. Ключ приложения и миграции
docker compose --env-file .env.docker exec php php artisan key:generate
docker compose --env-file .env.docker exec php php artisan config:clear
docker compose --env-file .env.docker exec php php artisan migrate
```

> **Почему нужен шаг 8.** Bind-mount `src` приходит с хоста под uid текущего пользователя, а php-fpm-воркеры работают под `www-data` — Laravel не сможет писать в `storage`/`bootstrap/cache` (ошибки `Please provide a valid cache path`, `tempnam(): file created in the system's temporary directory`). Каталоги Grafana/Loki в `docker-data` создаются Docker от `root`, а контейнеры работают под uid **472** и **10001** — без смены владельца они уходят в restart loop с `Permission denied`, а nginx падает с `host not found in upstream "grafana"`.

> **Пароль PostgreSQL.** Логин/пароль/БД задаются переменными `POSTGRES_*` в `.env.docker` и применяются только при **первой** инициализации `docker-data/postgres`. Если поменять `POSTGRES_PASSWORD` после первого запуска, пароль в существующем томе не изменится — правьте тогда `DB_PASSWORD` в `src/.env` под фактический, либо пересоздайте данные: `docker compose down && sudo rm -rf docker-data/postgres && docker compose up -d`.

> `run --rm --no-deps` — одноразовый контейнер php без зависимостей (nginx/postgres не запускаются).
> Артефакты фронта попадают в `src/public/build/`, nginx отдаёт их без перезапуска (каталог `src` смонтирован).


## URL

| URL | Описание |
|-----|---------|
| https://127.0.0.1 или https://localhost | Laravel (без `/etc/hosts`; сертификат выдан на `vspace.loc` — нужен локальный CA из `./docker/ssl/install-ca.sh`) |
| https://vspace.loc | Laravel (рекомендуется при записи в hosts) |
| https://grafana.vspace.loc | Grafana: Loki + дашборд **VSpace** |
| https://mail.vspace.loc | Mailpit UI |
| http://localhost:8025 | Mailpit UI (альтернатива) |

### Тестовые маршруты

- `GET /log-test` — пишет строку в лог (видна в Loki/Grafana)
- `GET /mail-test` — отправляет письмо в Mailpit

## Структура проекта

```
vspace/
├── docker-compose.yml      # описание стека
├── .env.docker             # секреты Docker (не в git)
├── .env.docker.example     # шаблон
├── api/                    # OpenAPI (swagger/) и Postman (postman/)
├── design/mockups/         # макеты UI
├── docker/                 # конфиги nginx, php, ssl, loki, grafana
├── docker-data/            # данные контейнеров (не в git)
└── src/                    # Laravel-приложение
```

## SSL

Сертификаты генерируются локально скриптом `docker/ssl/generate-certs.sh` (аналог mkcert). Приватные ключи и CA **не коммитятся**.

Если браузер (Chrome, Firefox) показывает предупреждение `NET::ERR_CERT_AUTHORITY_INVALID`, выполните:

```bash
./docker/ssl/install-ca.sh
```

и перезапустите браузер. Для curl/wget достаточно системного хранилища CA (скрипт делает оба шага).

**Firefox на Linux** не использует системное хранилище CA по умолчанию. Скрипт `install-ca.sh` импортирует CA в профили Firefox (`~/.mozilla/firefox/`). Если предупреждение остаётся:

1. Закройте Firefox полностью и откройте снова
2. Или вручную: Настройки → Приватность → Сертификаты → Просмотр → Центры сертификации → Импорт → `docker/ssl/ca.pem` → включить «Доверять при идентификации сайтов»
3. Или `about:config` → `security.enterprise_roots.enabled` = `true` (Firefox будет доверять системным CA)

## Xdebug (PHP-FPM)

В образе **Xdebug 3**; по умолчанию **`XDEBUG_MODE=off`**, пока не зададите в **`.env.docker`** (см. `.env.docker.example`).

```bash
# .env.docker
XDEBUG_MODE=debug
XDEBUG_CONFIG=client_host=host.docker.internal client_port=9003

docker compose --env-file .env.docker build php
docker compose --env-file .env.docker up -d php
docker compose exec php php -v | grep -i xdebug
```

1. **Cursor / VS Code:** расширение PHP Debug, конфиг `.vscode/launch.json` → **Listen for Xdebug (vspace)** (порт **9003**), `pathMappings`: `/var/www/html` → `src`.
2. **PhpStorm:** ошибка *«Server with name 'vspace.loc' doesn't exist»* — Xdebug уже доходит до IDE, не хватает **Servers**:
    - **Settings → PHP → Servers** (или **Configure servers** в баннере отладчика).
    - **+** → **Name:** `vspace.loc` (как в `PHP_IDE_CONFIG=serverName=vspace.loc`, регистр важен).
    - **Host:** `vspace.loc`, **Port:** `443`, включить **Use path mappings**.
    - **Absolute path on the server:** `/var/www/html` → **Absolute path on the host:** `<репо>/vspace/src` (например `/home/…/vspace/src`).
    - **Settings → PHP → Debug:** порт **9003**, Xdebug; при необходимости **Break at first line** выключить.
3. **Web (без cookie):** в FPM **`start_with_request=yes`** — при **Listen** в IDE на **9003** отладка сама цепляется к запросу; если IDE не слушает, ~**150 ms** на попытку (`connect_timeout_ms`). Когда не отлаживаешь — **`XDEBUG_MODE=off`** в `.env.docker`.
4. **CLI:** как web — **`start_with_request=yes`** в `99-xdebug.ini`; при **Listen** на 9003 цепляется без cookie. Без IDE — ~150 ms; иначе **`XDEBUG_MODE=off`**.

`host.docker.internal` проброшен через **`extra_hosts: host-gateway`** (сервис `php`).

## Request ID

Nginx генерирует `$request_id`, передаёт в PHP (`HTTP_X_REQUEST_ID`) и в ответе `X-Request-Id`. Middleware `AssignRequestId` дублирует/принимает ID и кладёт в контекст лога (`request_id`, `http_method`, `http_path`). В nginx access log: `req_id=...`.

## Логи в Grafana

Laravel пишет **JSON** в stderr (`LOG_STACK=stderr`, `LOG_STDERR_FORMATTER=Illuminate\Log\Formatters\JsonFormatter`). **Alloy** отправляет в Loki с лейблом **`log_type`**:

| `log_type` | Источник |
|------------|----------|
| `access` | nginx `access.log` (формат `vspace_main`, поля `req_id`, `host`, `rt`; label **`vhost`** в Loki) |
| `error` | nginx `error.log`, PHP notice/fatal и прочий stderr `php` |
| `app` | Laravel (JSON Monolog, labels `level`, `channel`) |
| `phpfpm_access` | access log PHP-FPM (`access.log → stderr`, формат `%R - %u %t "%m %r" %s`) |
| `phpfpm` | master/worker PHP-FPM (старт пула, child exited и т.п.) |
| `postgres` | контейнер `postgres` |
| `redis` | контейнер `redis` |
| `mail` | контейнер `mailpit` |
| `grafana` | контейнер `grafana` |

В Grafana: **Explore → Loki**, примеры:

```logql
{log_type="app"} | json | message=~"VSPACE_LOKI.*"
{log_type="app"} | json | request_id="<uuid>"
{log_type="access"} |= "req_id="
{log_type="access", service="nginx", vhost="vspace.loc"}
{log_type="error", service="nginx"}
{log_type="phpfpm"}
{log_type="phpfpm_access"}
{log_type="cron"}
{log_type="postgres"}
{log_type="redis"}
{log_type="mail"}
{log_type="grafana"}
{log_type="error", service="php"}
```

## Grafana (Loki)

Prometheus и экспортеры в compose **отключены**. Дашборды используют **LogQL** (Alloy → Loki).

В Grafana (**Dashboards → VSpace**):

- **VSpace** (uid `vspace-overview`) — nginx 4xx/499/502/504, Laravel errors, latency **p50 / p90 / p99** (общая и по endpoint), log-панели; переменная **`request_id`** для фильтра логов.
- **Grafana auth:** `GF_AUTH_ANONYMOUS_ENABLED=false`, пароль из `.env.docker` (`GRAFANA_ADMIN_PASSWORD`).

Alloy не шлёт в Loki docker-логи loki/alloy; nginx access — из `access.log`. Конфиг: `docker/alloy/config.alloy`; фильтр compose-проекта (`regex: vspace`) должен совпадать с `COMPOSE_PROJECT_NAME`.

Миграция с Promtail: nginx tail с **конца файла** (`tail_from_end`), чтобы не заливать в Loki повторно весь `access.log`. Старые логи остаются в Loki от Promtail.

UI Alloy (метрики/отладка): внутри сети `http://alloy:12345/` (не проброшен наружу).

Если графики пустые: `docker compose ps loki alloy`, трафик на сайт, Explore `{log_type="access", vhost="vspace.loc"}`, затем `docker compose restart grafana` (подхват provisioning).

**Loki, Grafana и права на `docker-data`:** каталоги создаются Docker от `root`, а контейнеры работают под непривилегированными uid: Loki — **10001**, Grafana — **472**. Признаки проблемы в `docker compose logs`: Loki — `mkdir /loki/rules: permission denied` (restart loop на WAL/rules), Grafana — `GF_PATHS_DATA '/var/lib/grafana' is not writable` / `mkdir: can't create directory '/var/lib/grafana/plugins': Permission denied`, nginx — `host not found in upstream "grafana"` (Grafana не поднимается и не попадает в DNS). Лечится сменой владельца:

```bash
docker run --rm -v "$PWD/docker-data:/d" alpine sh -c \
  'chown -R 472:472 /d/grafana; chown -R 10001:10001 /d/loki'
docker compose restart grafana loki nginx
```

Также `storage` и `bootstrap/cache` Laravel должны принадлежать `www-data` (иначе `Please provide a valid cache path` и `tempnam(): file created in the system's temporary directory`):

```bash
docker compose exec -T php sh -c \
  'mkdir -p storage/framework/{views,cache/data,sessions} && chown -R www-data:www-data storage bootstrap/cache'
```

### Redis: `Cannot assign requested address`

Ошибка **phpredis** при всплеске запросов (сессия + кэш + очередь = много TCP-сокетов с PHP-FPM). Redis при этом обычно жив (`docker compose ps redis`).

В `src/.env` включено **`REDIS_PERSISTENT=true`** (сокеты переиспользуются воркерами). После смены:

```bash
docker compose exec php php artisan config:clear
docker compose restart php
```

Если повторяется — временно `SESSION_DRIVER=file` и `CACHE_STORE=file`, либо снизить параллелизм (Grafana refresh, ab-тесты).

### PHP: OPcache, JIT, preload

Образ `docker/php`: **OPcache 256M**, **JIT** (`PHP_OPCACHE_JIT`, по умолчанию `1255`), **realpath cache**, FPM `pm.max_requests=500`, `listen.backlog=2048`. При старте контейнера `docker-entrypoint.sh` пишет `98-opcache-runtime.ini`:

| Переменная (`.env.docker`) | По умолчанию | Смысл |
|----------------------------|--------------|--------|
| `PHP_OPCACHE_VALIDATE_TIMESTAMPS` | `0` | `0` — без `stat()` на каждый hit (load/prod); `1` — видеть правки PHP без rebuild |
| `PHP_OPCACHE_JIT` | `1255` | `0` — выключить JIT |
| `PHP_OPCACHE_PRELOAD` | `1` | Preload `src/bootstrap/preload.php` (нужен `vendor/`) |

Перед нагрузкой: **`XDEBUG_MODE=off`** (расширение xdebug не грузится → включается **JIT**), `composer install --optimize-autoloader --no-dev` (по желанию), затем `docker compose up -d --build php` (preload подхватывается при старте FPM master).

Проверка:

```bash
docker compose exec php php -i | grep -E 'opcache\.(jit|validate_timestamps|preload|memory_consumption)'
```

### Нагрузочный тест (login, GET /user/get)

Данные для нагрузки (по умолчанию **5000** пользователей): `loadtest/README.md`, скрипты `scripts/loadtest/export-loadtest-users.sh` и `scripts/loadtest/prepare-loadtest-passwords.sh`, сценарий **k6** в `loadtest/k6/login-get.js`.

### Нагрузочный тест (ab) и scale PHP

```bash
# только через «=»: php=3. Вариант «--scale php 3» даёт no such service: 3
docker compose up -d --scale php=3
docker compose exec nginx nginx -t && docker compose exec nginx nginx -s reload
```

Проверка:

```bash
docker compose ps php
# должны быть vspace-php-1, vspace-php-2, vspace-php-3
```

У сервиса **`php` нет `container_name`** — иначе `--scale` не работает. Nginx ходит в **`php:9000`** через Docker DNS (переменный `fastcgi_pass`, резолвер `127.0.0.11`). Очередь на FPM: **`fastcgi_connect_timeout 60s`** (nginx ждёт свободный воркер), **`listen.backlog = 2048`** на сокете FPM; после выдачи воркеру — **`fastcgi_read_timeout 60s`**, **`request_terminate_timeout = 60s`**.

Маршрут **`/log-test`** специально делает `sleep(1)` — это ~1 с удержания воркера FPM на запрос. Параллелизм:

`реплики × pm.max_children` (по умолчанию **3 × 50 = 150** одновременных запросов).

При `ab -c 500` очередь в nginx растёт, клиенты ждут, соединения рвутся — у **ab** часто **`SSL read failed (5)`** (особенно по HTTPS: много handshakes, плохой keep-alive).

Рекомендации:

- `-c` не выше суммарного числа FPM-воркеров (или снижайте `sleep` в `web.php` для throughput-теста);
- для HTTPS лучше **`hey`** / **`wrk`**, либо HTTP на :80 (редирект) / тест из контейнера без TLS;
- **`ab -k` и keepalive_requests**: nginx по умолчанию закрывает соединение после **1000** запросов на одном keep-alive; `ab` считает это failed/reset. В `nginx.conf` стоит **`keepalive_requests 0`** (без лимита). При `-c 1 -n 100000` на `/log-test` с `sleep(1)` прогон займёт ~28 ч — используйте `-c 150` и разумное `-n`;
- смотреть `docker compose logs nginx`, access log (499/502/504).

## Сборка фронта (Vite / React)

На хосте нужен **Node 20+** (Vite 8). Если стоит Node 18 — используйте контейнер **node:22** (профиль `tools`, сервис не стартует с обычным `up -d`):

```bash
chmod +x scripts/frontend-build.sh
./scripts/frontend-build.sh              # npm run build (при отсутствии node_modules — npm ci)
./scripts/frontend-build.sh --install    # принудительно npm ci, затем build
```

Артефакты: `src/public/build/`. Перезапуск nginx/php не нужен (каталог `src` смонтирован).

## Полезные команды

```bash
docker compose ps
docker compose logs -f php
docker compose exec php php artisan tinker
docker compose exec php composer install
docker compose down          # остановить
docker compose down -v       # остановить + удалить docker-data (осторожно!)
```

## Git: что коммитить

**Коммитить:**

- `docker-compose.yml`, `docker/`, `src/` (кроме игнорируемого)
- `.env.docker.example`, `src/.env.example`
- `.gitignore`, `.dockerignore`

**Не коммитить:**

- `.env.docker`, `src/.env` — пароли и ключи
- `docker-data/` — данные БД и Grafana
- `docker/ssl/*.pem`, `*.key` — сертификаты и приватные ключи
- `src/vendor/`, `src/node_modules/`

Перед первым push проверьте:

```bash
git init
git status
git check-ignore -v .env.docker src/.env docker-data/
```

Все перечисленные пути должны быть проигнорированы.
