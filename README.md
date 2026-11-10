# VSpace

Локальная среда разработки на **Laravel 13** с Docker Compose: nginx, PHP 8.3-FPM, PostgreSQL, Redis, Mailpit, Loki, **Grafana Alloy** и Grafana.

## Стек

| Сервис    | Контейнер        | Назначение                          |
|-----------|------------------|-------------------------------------|
| nginx     | `{COMPOSE_PROJECT_NAME}-nginx-1` | HTTPS (порты из `.env.docker`) |
| php-fpm   | `{COMPOSE_PROJECT_NAME}-php-*`   | Laravel 13 (PHP 8.3), масштабируется |
| postgres  | …                | БД Laravel (мастер)                 |
| postgres-replica-1/2 | …      | Реплики чтения (profile `replicas`) |
| redis     | …                | Кэш, сессии, очереди                |
| mailpit   | …                | Перехват исходящей почты            |
| loki / alloy / grafana | … | Логи и UI |
| prometheus / cadvisor / node-exporter | … | Метрики контейнеров и хоста |

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

# 2. Laravel: env-файл (ключ сгенерируем после старта, шаг 8)
#    ВАЖНО: DB_PASSWORD в src/.env должен совпадать с POSTGRES_PASSWORD в
#    .env.docker, иначе `artisan migrate` упадёт с "password authentication failed".
cp src/.env.example src/.env

# 3. SSL-сертификаты (локальный CA) — необязательно
#    Если пропустить, nginx сгенерирует самоподписанный сертификат на старте.
./docker/ssl/generate-certs.sh
./docker/ssl/install-ca.sh   # доверие CA в системе и Chrome

# 4. Сборка образов
docker compose --env-file .env.docker build

# 5. Composer-зависимости — обязательны: без vendor/ Laravel не стартует
#    (и preload в too-many-переменных PHP_OPCACHE_PRELOAD=1 требует vendor/)
docker compose --env-file .env.docker run --rm --no-deps php composer install

# 6. Сборка фронта: Vite (React) собирает тему в src/public/build
./scripts/frontend-build.sh   # внутри контейнера node:22, при отсутствии node_modules делает npm ci

# 7. Запуск (права на каталоги данных применяются автоматически)
docker compose --env-file .env.docker up -d

# 8. Ключ приложения и миграции
docker compose --env-file .env.docker exec php php artisan key:generate
docker compose --env-file .env.docker exec php php artisan config:clear
docker compose --env-file .env.docker exec php php artisan migrate
```

> **Права на каталоги данных** (`storage`, `bootstrap/cache`, `docker-data/grafana`, `docker-data/loki`) выставляются автоматически: php-контейнер делает `chown` для `www-data` в entrypoint, а одноразовый сервис `init-data` — для Grafana (uid **472**) и Loki (uid **10001**). Ручной скрипт `./scripts/fix-data-permissions.sh` остаётся для починки существующей инсталляции.
>
> **TLS.** Если `docker/ssl/${VSPACE_DOMAIN}.pem` есть — nginx использует его. Если нет, контейнер генерирует самоподписанный сертификат на старте (`docker/nginx/docker-entrypoint.d/05-generate-site-cert.sh`), поэтому свежая копия проекта поднимается без ошибки `cannot load certificate ... no start line`.

> **Пароль PostgreSQL.** Логин/пароль/БД задаются переменными `POSTGRES_*` в `.env.docker` и применяются только при **первой** инициализации `docker-data/postgres`. Если поменять `POSTGRES_PASSWORD` после первого запуска, пароль в существующем томе не изменится — правьте тогда `DB_PASSWORD` в `src/.env` под фактический, либо пересоздайте данные: `docker compose down && sudo rm -rf docker-data/postgres && docker compose up -d`.

> **PgBouncer отключён.** Laravel подключается напрямую: `DB_HOST=postgres`, `DB_READ_HOSTS=postgres-replica-1,postgres-replica-2`. Сервисы PgBouncer закомментированы в `docker-compose.yml`.
>
> <!-- PgBouncer отключён; блок оставлен для возврата:
> **PgBouncer.** Приложение подключается к `pgbouncer:5432` (`DB_HOST=pgbouncer`), PgBouncer — к `postgres:5432`. По умолчанию: `pool_mode=transaction`, `default_pool_size=32` серверных соединений, `max_client_conn=1000`. PHP-FPM: `pm.max_children=96` (`PHP_FPM_MAX_CHILDREN`). Для transaction-режима в `src/config/database.php` включён `PDO::ATTR_EMULATE_PREPARES`. Наружу PgBouncer опубликован на `localhost:${PGBOUNCER_PUBLISH_PORT:-6432}`.
> -->

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

- `GET /mail-test` — отправляет письмо в Mailpit

### Посты

- `POST /post/create` — создать пост, Bearer-токен, body `{"text":"..."}`;
- `GET /post/get/{id}` — получить пост по id (публично);
- `GET /post/feed` — лента постов, Bearer-токен, `offset`, `limit`, опционально `author_user_id`.

В SPA: `/cabinet` — создание поста и общая лента; `/profile/{id}` → вкладка **Посты** — посты выбранного пользователя.

## Репликация PostgreSQL (1 мастер + 2 реплики)

Реплики живут в профиле compose `replicas` и по умолчанию не запускаются.

- `postgres` — мастер: сюда идёт запись;
- `postgres-replica-1`, `postgres-replica-2` — потоковые реплики (`hot_standby`, только чтение);
- Laravel: `DB_HOST=postgres` для записи, `DB_READ_HOSTS=postgres-replica-1,postgres-replica-2` для чтения.

Кратко:

```bash
./scripts/replication.sh up        # поднять обе реплики, чтение → на них
./scripts/replication.sh status    # состояние, слоты, лаг
./scripts/replication.sh down      # остановить, чтение → на мастер
./scripts/replication.sh reset 1   # пересоздать реплику (повторный base backup)
```

Подкоманды принимают номер реплики: `up 2`, `down 1`, `reset 2` (по умолчанию `all`).

Скрипт сам правит `DB_READ_HOSTS` в `src/.env`. Если включён `config:cache`, после смены выполните `php artisan config:cache` и перезагрузите php-fpm.

### Подключение из HeidiSQL

С хоста опубликованы только localhost-порты:

- мастер: `127.0.0.1:5432`;
- реплика 1: `127.0.0.1:5433`;
- реплика 2: `127.0.0.1:5434`.

PgBouncer и HAProxy отключены; порты `6432–6434` и `5000–5001` больше не используются.

Параметры подключения: тип **PostgreSQL**, user `vspace`, password `secret` (из `.env.docker`), database `vspace`, SSL off.

Реплики read-only: `SELECT` и просмотр процессов работают, а `INSERT/UPDATE/DELETE` вернут ошибку `cannot execute ... in a read-only transaction`. Для записи подключайтесь к мастеру.

Список процессов на реплике:

```sql
select pid, backend_type, usename, application_name,
       client_addr, state, wait_event_type, wait_event,
       left(query, 100) as query
  from pg_stat_activity
 order by pid;
```

Состояние приёма WAL на реплике:

```sql
select status, sender_host, sender_port, slot_name, latest_end_lsn
  from pg_stat_wal_receiver;
```

Состояние репликации на мастере:

```sql
select application_name, client_addr, state,
       pg_wal_lsn_diff(sent_lsn, replay_lsn) as lag_bytes
  from pg_stat_replication;
```

### Failover-дрилл: проверка потерь транзакций

Интерактивный скрипт: убивает мастер, промоутит самую свежую реплику, переключает на неё второй слейв и Laravel,
затем считает, сколько подтверждённых вставок потерялось (RPO).

```bash
./scripts/failover-drill.sh drill --manual --yes --user <loadUserId>
```

Скрипт ведёт по шагам подсказками: снять baseline → запустить Postman → Enter (убить мастер) →
остановить Postman → ввести число успешных `POST /post/create` (или передать `--sent N` заранее).

Показывает: `promoted`, `sent_ok`, `written`, `lost_transactions`, `replica_rejoined`, `promotion_seconds`.
Отчёт: `ht/manual_restore_master/<timestamp>/report.json` (внутри проекта). `status` — только чтение.

Дрилл разрушает топологию, запускать на одноразовом стенде. Восстановление:

```bash
./scripts/failover-drill.sh restore --yes
```

## Patroni: кворумная синхронная репликация

Отдельный HA-кластер в профиле `patroni`:

- 3 × etcd — DCS с кворумом;
- 3 × PostgreSQL под управлением Patroni (1 лидер + 2 реплики);
- Laravel подключается напрямую к текущему лидеру и репликам `patroni-*`; HAProxy отключён.

Кластер не заменяет текущий `postgres`/`postgres-replica-*` и живёт в отдельных
томах `docker-data/patroni-*`, `docker-data/etcd*`.

Patroni не обязателен: обычный `docker compose up -d` его не запускает.
Остановить и освободить ресурсы: `./scripts/patroni.sh down`.

Кратко:

```bash
./scripts/patroni.sh up          # etcd + 3 узла Patroni
./scripts/patroni.sh use         # Laravel → текущий лидер
./scripts/patroni.sh status      # кластер, лидер, синхронность
./scripts/patroni.sh sync on     # кворумная синхронная репликация
./scripts/patroni.sh use-legacy  # Laravel → postgres + реплики
./scripts/patroni.sh down        # остановить Patroni-стек
```

Запуск:

```bash
./scripts/patroni.sh up
./scripts/patroni.sh status
```

Кворумная синхронная репликация включена по умолчанию (`synchronous_mode: quorum`).
Проверить и при необходимости включить заново:

```bash
./scripts/patroni.sh sync status
./scripts/patroni.sh sync on
```

`synchronous_node_count=1` — для 3 узлов это кворум «лидер + 1 реплика».
Чтобы ждать подтверждения от обеих реплик:

```bash
PATRONI_SYNC_NODE_COUNT=2 ./scripts/patroni.sh sync on
```

Выключить обратно в async:

```bash
./scripts/patroni.sh sync off
```

Проверка:

```bash
./scripts/patroni.sh sync status
```

Смотреть `synchronous_standby_names` (в quorum-режиме будет
`ANY 1 ("patroni-1","patroni-2")`) и `pg_stat_replication.sync_state`.

Переключить Laravel на Patroni:

```bash
./scripts/patroni.sh use
```

`use` находит текущего лидера через `patronictl` и прописывает в `src/.env`
`DB_HOST=<leader>`, `DB_READ_HOSTS=<replicas>`, порт `5432`.
После failover в Patroni нужно повторно выполнить `./scripts/patroni.sh use`,
чтобы обновить адрес лидера.

Вернуть обратно на прямой legacy:

```bash
./scripts/patroni.sh use-legacy
```

Проверить, какой режим сейчас у Laravel:

```bash
./scripts/patroni.sh mode
```

Быстрое переключение:

```bash
# на Patroni
./scripts/patroni.sh up        # если кластер ещё не запущен
./scripts/patroni.sh use

# обратно на мастер + 2 реплики
./scripts/patroni.sh use-legacy
./scripts/replication.sh up    # если legacy-реплики остановлены
```

Скрипты меняют только `src/.env` и делают `config:clear`, без рестарта
php-fpm (в dev конфиг не закэширован). Если включён `config:cache` — выполните
`php artisan config:cache` и перезагрузите php-fpm.

Важно: это два независимых кластера. Запись в legacy не попадает в Patroni и
наоборот. Для одинаковых данных перед сравнением выполните
`./scripts/patroni.sh migrate-data` (legacy → Patroni).

Скопировать текущую БД в Patroni (кластер создаётся пустым):

```bash
./scripts/patroni.sh migrate-data
```

Команда делает `pg_restore --clean`: объекты в Patroni заменяются данными из
текущего `postgres`. Старый кластер при этом не меняется.

Остановить Patroni и вернуть Laravel на старый стек:

```bash
./scripts/patroni.sh down
```

Важно: `synchronous_mode_strict=false` по умолчанию — при потере кворума
Patroni отключает синхронный режим и записи продолжаются. Для режима «лучше
не принимать записи, чем потерять подтверждённые»:

```bash
PATRONI_SYNC_STRICT=true ./scripts/patroni.sh sync on
```

HAProxy отключён. Наружу порты Patroni-узлов не публикуются; для отладки
используйте `psql` внутри сети compose или direct legacy-порты `5432–5434`.

## Структура проекта

```
vspace/
├── docker-compose.yml          # описание стека
├── docker-compose.patroni.yml  # Patroni-кластер (profile patroni)
├── .env.docker                 # секреты Docker (не в git)
├── .env.docker.example         # шаблон
├── api/                        # OpenAPI (swagger/) и Postman (postman/)
├── design/mockups/             # макеты UI
├── docker/                     # конфиги nginx, php, ssl, loki, grafana, patroni
├── docker-data/                # данные контейнеров (не в git)
├── scripts/                    # служебные скрипты, включая patroni.sh
└── src/                        # Laravel-приложение
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

## Prometheus (Docker Containers)

Метрики контейнеров и Docker-хоста:

- **Prometheus** — `http://127.0.0.1:9090`, retention `7d` (`PROMETHEUS_RETENTION`);
- **cAdvisor** — `http://127.0.0.1:8080` (CPU/memory/network/fs по контейнерам);
- **node-exporter** — `http://127.0.0.1:9100` (CPU/RAM/disk/load хоста).

В Grafana datasource **Prometheus** (`prometheus`) и дашборд **Docker Containers**
(uid `docker-containers`, папка **VSpace**):

`https://grafana.vspace.loc/d/docker-containers`

Панели: CPU Load, CPU Cores, Memory Load, Used Memory, Storage Load, Used Storage,
Running Containers, System Load, I/O Usage, а также CPU/Memory/Network/FS по
каждому контейнеру. Переменная **Container** фильтрует контейнеры.

Сравнение нагрузки без реплик и с репликами (legacy-стек):

```bash
./scripts/patroni.sh use-legacy   # если приложение сейчас на Patroni
./scripts/replication.sh down     # чтение идёт на мастер
# прогнать нагрузку (k6 / Postman Performance / hey)
./scripts/replication.sh up       # чтение идёт на реплики
# повторить тот же прогон
```

Смотреть в дашборде контейнеры `postgres`, `postgres-replica-1`,
`postgres-replica-2`, `php`, `nginx`: CPU, memory, network, fs.
Для Patroni — `patroni-1/2/3`, `etcd1/2/3`.

Важно: обычный Postman Collection Runner последовательный, поэтому для
честного сравнения нужен конкурентный прогон (Postman Performance с VU,
k6 или `hey`).

## Grafana (Loki)

Дашборды Loki используют **LogQL** (Alloy → Loki).

В Grafana (**Dashboards → VSpace**):

- **VSpace** (uid `vspace-overview`) — nginx 4xx/499/502/504, **total RPS**, **RPS по эндпоинтам** (график top-10 и таблица за период), Laravel errors, latency **p50 / p90 / p99** (общая и по endpoint), log-панели; переменная **`request_id`** для фильтра логов.
  - RPS по эндпоинтам считается из nginx access-лога (`vhost=vspace.loc`): путь нормализуется — UUID и path-параметры (`/user/get/<id>`, `/profile/<id>`) заменяются на `{id}`, статика (`/build`, `/favicon.ico`, `/robots.txt`) и `/log-test` исключены.
- **Grafana auth:** `GF_AUTH_ANONYMOUS_ENABLED=false`, пароль из `.env.docker` (`GRAFANA_ADMIN_PASSWORD`).

Alloy не шлёт в Loki docker-логи loki/alloy; nginx access — из `access.log`. Конфиг: `docker/alloy/config.alloy`; фильтр compose-проекта (`regex: vspace`) должен совпадать с `COMPOSE_PROJECT_NAME`.

Миграция с Promtail: nginx tail с **конца файла** (`tail_from_end`), чтобы не заливать в Loki повторно весь `access.log`. Старые логи остаются в Loki от Promtail.

UI Alloy (метрики/отладка): внутри сети `http://alloy:12345/` (не проброшен наружу).

Если графики пустые: `docker compose ps loki alloy`, трафик на сайт, Explore `{log_type="access", vhost="vspace.loc"}`, затем `docker compose restart grafana` (подхват provisioning).

**Loki, Grafana и права на `docker-data`:** каталоги создаются Docker от `root`, а контейнеры работают под непривилегированными uid: Loki — **10001**, Grafana — **472**. Признаки проблемы в `docker compose logs`: Loki — `mkdir /loki/rules: permission denied` (restart loop на WAL/rules), Grafana — `GF_PATHS_DATA '/var/lib/grafana' is not writable` / `mkdir: can't create directory '/var/lib/grafana/plugins': Permission denied`, nginx — `host not found in upstream "grafana"` (Grafana не поднимается и не попадает в DNS). Сейчас права выставляются автоматически (php-entrypoint + сервис `init-data`); для починки существующей инсталляции:

```bash
./scripts/fix-data-permissions.sh
docker compose restart grafana loki nginx
```

`storage` и `bootstrap/cache` Laravel также чинятся автоматически в entrypoint php-контейнера. Вручную, при необходимости:

```bash
docker compose exec -T php sh -c \
  'mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions && chown -R www-data:www-data storage bootstrap/cache'
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

Параллелизм:

`реплики × pm.max_children` (по умолчанию **3 × 50 = 150** одновременных запросов).

При `ab -c 500` очередь в nginx растёт, клиенты ждут, соединения рвутся — у **ab** часто **`SSL read failed (5)`** (особенно по HTTPS: много handshakes, плохой keep-alive).

Рекомендации:

- `-c` не выше суммарного числа FPM-воркеров (или снижайте нагрузку на запрос для throughput-теста);
- для HTTPS лучше **`hey`** / **`wrk`**, либо HTTP на :80 (редирект) / тест из контейнера без TLS;
- **`ab -k` и keepalive_requests**: nginx по умолчанию закрывает соединение после **1000** запросов на одном keep-alive; `ab` считает это failed/reset. В `nginx.conf` стоит **`keepalive_requests 0`** (без лимита). При `-c 1 -n 100000` прогон может занять очень долго — используйте `-c 150` и разумное `-n`;
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
