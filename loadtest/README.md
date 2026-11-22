# Нагрузочное тестирование login, GET /user/get, post/create и post/feed

По умолчанию в БД создаётся/поддерживается **5000** активных пользователей (`LOADTEST_USER_COUNT`), затем экспорт в файлы.

**PHP под нагрузку:** в `.env.docker` задайте `XDEBUG_MODE=off` (иначе xdebug блокирует OPcache JIT), при необходимости `PHP_OPCACHE_VALIDATE_TIMESTAMPS=0`, затем `docker compose up -d --build php`. Подробнее — README, раздел *PHP: OPcache, JIT, preload*.

## 1. Подготовка (один раз)

Пароли в БД после ручной регистрации разные. Для **login** нужен общий пароль. Пароль **не хранится в репозитории** — задайте его локально через `LOADTEST_PASSWORD` или файл `loadtest/meta.env` (он в `.gitignore`):

```bash
cd /path/to/vspace
chmod +x scripts/loadtest/prepare-loadtest-passwords.sh scripts/loadtest/export-loadtest-users.sh

export LOADTEST_PASSWORD='<локальный-пароль>'              # либо строка в loadtest/meta.env
./scripts/loadtest/prepare-loadtest-passwords.sh --yes     # выставит пароль всем users
./scripts/loadtest/export-loadtest-users.sh                # ensure 5000 users + export
```

Файлы:

| Файл | Назначение |
|------|------------|
| `user-ids.txt` | один UUID на строку — для GET |
| `credentials.jsonl` | `{"id":"…","password":"…"}` — для login |
| `meta.env` | число записей, base URL, пароль (shell/k6) |
| `meta_postman.csv` | `baseUrl,userId,password` — **Collection Runner** в Postman |

Переменные: `LOADTEST_USER_COUNT=5000` (сколько пользователей в БД), `LOADTEST_LIMIT=5000` (сколько строк в файлах; `0` — все active). Отдельно: `scripts/loadtest/ensure-loadtest-users.sh` — только вставка без экспорта.

## 2. Postman Collection Runner

1. Окружение `api/postman/VSpace.postman_environment.json` (можно не менять — `baseUrl` и `password` есть в CSV).
2. **Run collection** → **Select File** → `loadtest/meta_postman.csv`.
3. В Runner отметьте **только** **POST /login (id + password)** и **GET /user/get/{id}** (без register). На каждой строке CSV — `baseUrl`, `userId`, `password`; после login сохраняются `token` и `userId` в окружение/коллекцию для GET.

   **Не** включайте **POST /user/register** в прогон с CSV: он создаёт нового пользователя и не соответствует строкам файла.

Перед прогоном: `./scripts/loadtest/prepare-loadtest-passwords.sh --yes`, иначе login по CSV не пройдёт.

Прогон в Postman:

1. Откройте коллекцию **VSpace API** → **Run**.
2. Выберите окружение **VSpace local**.
3. Папка **«Нагрузочный тест: подготовка»**, Iterations = **1**, Data не выбирайте → **Run**. После прогона сохраните окружение (Ctrl+S), чтобы `loadUserId`/`loadToken` остались между запусками.
4. Папка **«Нагрузочный тест: нагрузка»**, Data не выбирайте, Iterations = **500**, Delay = **0** → **Run**.

Одна итерация папки: `1 × GET /user/get/{id}` + `3 × GET /user/search` + `4 × POST /post/create` + `1 × GET /post/feed`. Текст поста — глобальный автоинкремент `loadPostSeq` (`1`, `2`, `3`, …), он не сбрасывается между итерациями, поэтому каждый пост получает уникальный текст. Create и feed идут под `loadToken` из «Нагрузочного теста: подготовка», id последнего созданного поста сохраняется в `loadPostId`.

При 500 итерациях это 2000 постов за прогон — при необходимости уменьшите Iterations.

GET использует `loadUserId` из подготовки, а search выбирает случайные имена из встроенных пулов по 100 значений.

Важно: Collection Runner выполняет запросы последовательно. Это тест latency одного клиента, а не конкурентная нагрузка. Для проверки эффекта реплик нужны k6/`hey`/`ab` или несколько параллельных запусков.

## 3. k6 (login + get параллельно)

```bash
# TLS vspace.loc (нужен локальный CA из docker/ssl/install-ca.sh)
docker run --rm -i --network host \
  -v "$PWD/loadtest:/loadtest" -w /loadtest/k6 \
  grafana/k6 run -e BASE_URL=https://vspace.loc login-get.js

# только login
docker run --rm -i --network host \
  -v "$PWD/loadtest:/loadtest" -w /loadtest/k6 \
  grafana/k6 run -e BASE_URL=https://vspace.loc -e SCENARIO=login login-get.js

# только get
docker run --rm -i --network host \
  -v "$PWD/loadtest:/loadtest" -w /loadtest/k6 \
  grafana/k6 run -e BASE_URL=https://vspace.loc -e SCENARIO=get login-get.js
```

Перед тестом при необходимости масштабируйте PHP: `docker compose up -d --scale php=3` (см. README проекта).

## 4. hey (простые прогоны)

GET по случайным id из файла (bash 5+):

```bash
BASE_URL=https://vspace.loc
ID=$(shuf -n 1 loadtest/user-ids.txt)
hey -z 30s -c 50 -m GET -disable-keepalive "${BASE_URL}/user/get/${ID}"
```

Login — один пользователь (hey не ротирует body без скрипта; для полной выборки используйте k6):

```bash
read -r ID < <(head -n 1 loadtest/user-ids.txt)
hey -z 30s -c 30 -m POST -T application/json \
  -d "{\"id\":\"${ID}\",\"password\":\"${LOADTEST_PASSWORD}\"}" \
  "${BASE_URL}/login"
```

## 5. Обновление данных

После новых регистраций:

```bash
./scripts/loadtest/export-loadtest-users.sh
# если нужен login — снова scripts/loadtest/prepare-loadtest-passwords.sh --yes
```

`credentials.jsonl` и `user-ids.txt` можно не коммитить (генерируются локально).

## 6. Лента: 50 пользователей, 12 чтений + 3 записи (Postman Performance)

```bash
./scripts/loadtest/prepare-friends-loadtest.sh
```

Скрипт:
- берёт 2 заданных UUID (`0ee50b1e…`, `1a740597…`) и 48 случайных пользователей, которым миграция `2026_11_21_153124_seed_posts_for_users` раздала тестовые посты;
- из 50 выбирает **20 случайных** и связывает их дружбой между собой: у каждого ≥ 6 друзей внутри группы (`FRIENDS_PER_USER`, `FRIENDS_GROUP_SIZE`);
- пересоздаёт Sanctum-токены с именем `friends-loadtest` и пишет `loadtest/friends-postman.csv`;
- сохраняет состав дружеской группы в `loadtest/friends-group.txt` (справочно — для проверки дружбы и разбора результатов).

Переменные: `FRIENDS_EXTRA_USERS` (по умолчанию 48 — всего 50 пользователей), `FRIENDS_GROUP_SIZE` (20), `FRIENDS_PER_USER` (6), `LOADTEST_BASE_URL`.

CSV: `baseUrl,userId,userShort,token,runId` — **одна строка на пользователя, всего 50 строк**. Текстов постов в файле нет: pre-request скрипт папки собирает `postText1..3` на каждую итерацию как `friends-load-<runId>-<userShort>-<stamp>-a|b|c`, поэтому тексты уникальны и не повторяются между итерациями.

**Postman Performance:** Run → Run type = **Performance** → Run method = **App** → Load profile = **Ramp up** (initial 10, max **50**) → Test duration 1–2 мин → **Data file** = `loadtest/friends-postman.csv` → **VU data mapping = Randomized**. Каждый VU берёт случайную строку на каждую итерацию, поэтому число VU можно менять (20/50/100) без пересборки CSV — все VU работают от имени тех же 50 пользователей, просто с большей активностью. Режим Ordered тоже работает, но только при VU = числу строк (50). Токен берётся из CSV (`Bearer {{token}}`), Environment `loadToken` его не перекрывает.

Каждое чтение выполняется **4 раза подряд** (оригинал + 3 дубликата) — чтобы один и тот же ключ кеша запрашивался повторно. Записи не дублируются. Итого на итерацию: 12 чтений × 4 + 3 записи = **51 запрос**.

| # | Шаг | Повторов |
|---|-----|----------|
| 1 | `GET /post/feed?limit=100` — все посты | 4 |
| 2 | `GET /post/feed?offset=20&limit=20` — страница 2 | 4 |
| 3 | `GET /post/feed?offset=40&limit=20` — страница 3 | 4 |
| 4 | `POST /post/create` — запись #1 (`postText1`) | 1 |
| 5 | `GET /friend/list` — список друзей | 4 |
| 6 | `GET /post/feed?offset=20&limit=20` — страница 2 | 4 |
| 7 | `GET /post/feed?limit=100` — все посты | 4 |
| 8 | `GET /post/feed?offset=40&limit=20` — страница 3 | 4 |
| 9 | `POST /post/create` — запись #2 (`postText2`) | 1 |
| 10 | `GET /post/feed?offset=40&limit=20` — страница 3 | 4 |
| 11 | `GET /post/feed?offset=20&limit=20` — страница 2 | 4 |
| 12 | `GET /post/feed?limit=100` — все посты | 4 |
| 13 | `POST /post/create` — запись #3 (`postText3`) | 1 |
| 14 | `GET /post/feed?offset=940&limit=20` — страница 48 | 4 |
| 15 | `GET /post/feed?offset=960&limit=20` — страница 49 | 4 |

### Отдельные прогоны: read-only и write-only

| Папка | Шагов | Назначение |
|-------|-------|-----------|
| «Нагрузочный тест: сценарий ленты (12 чтений + 3 записи)» | 51 | смешанный профиль — реальная нагрузка |
| «Нагрузочный тест: read-only (только чтения)» | 24 | замер кеша: 6 ключей ленты × 4, без друзей и записей |
| «Нагрузочный тест: write-only (только записи)» | 3 | стоимость записи отдельно от чтений |

Как сравнивать:

1. **read-only** — до внедрения кеша и после. Это чистый замер: 6 ключей ленты (page 1 `limit=20`, page 2, page 3, `limit=100`, page 48 `offset=940`, page 49 `offset=960`) по 4 запроса каждый, без `friend/list` и записей. Одинаковые данные, одинаковые ключи, никакой инвалидации — именно тут виден потенциал кеша (p95, RPS).
2. **смешанный** — до и после кеша. Показывает реальный hit rate: каждая запись сбрасывает ленту, поэтому выигрыш будет меньше, чем в read-only. Если в read-only ускорение есть, а в смешанном нет — виновата инвалидация, а не кеш.
3. **write-only** — базовая стоимость записи: сколько RPS держит БД и как запись влияет на общий p95.

Запускаются как отдельные Performance-прогоны: выберите папку в Run Sequence, data file тот же.

### Что добавить в смешанный сценарий для реализма

Сейчас в нём есть лента (3 варианта), список друзей и 3 записи — соотношение чтений к записям ≈ 13:1, что близко к реальной соцсети. Для большей реалистичности можно добавить:

- `GET /post/feed?limit=20` — первый экран ленты (сейчас есть только `limit=100` и страницы 2–3);
- `GET /user/get/{id}` — открытие своего или чужого профиля;
- `GET /user/search?query=...` — поиск анкет;
- `GET /post/get/{id}` — открытие конкретного поста;
- `PUT /friend/set/{id}` / `PUT /friend/delete/{id}` — добавление и удаление друга;
- `PUT /post/update`, `PUT /post/delete/{id}` — редактирование и удаление своего поста.

Для запросов с `{id}` понадобятся идентификаторы: проще всего добавить в CSV колонки `postId` (свой сид-пост) и `peerUserId` (друг из группы) — их может проставлять `prepare-friends-loadtest.sh`. Вариант «взять id из ответа `post/create`» работает, но в Performance переменные между запросами менее предсказуемы.

Паузы (think time) Postman Performance не поддерживает — реалистичность задаётся составом запросов, числом VU и ramp-up.

Файл содержит токены и не коммитится (`.gitignore`). Повторный запуск скрипта пересоздаёт токены/CSV, дружеские связи добавляются через `ON CONFLICT DO NOTHING`.

Проверить результат прогона по текстам постов:

```bash
docker compose --env-file .env.docker exec -T postgres psql -U vspace -d vspace -c \
  "select author_user_id, count(*), count(distinct text) from posts where text like 'friends-load-<runId>-%' group by 1 order by 2 desc;"
```

**Кеш ленты постов (FEED_CACHE_ENABLED).** В API кешируется лента (ZSET id постов); тела постов — отдельно, при `POST_CACHE_ENABLED`. Детали: Redis ZSET `feed:{userId}` с последними `FEED_CACHE_SIZE` (по умолчанию 1000) id постов, обновляется при создании/удалении поста и изменении дружбы. Включается переменной окружения в `src/.env`:

```
FEED_CACHE_ENABLED=true
FEED_CACHE_SIZE=1000
FEED_CACHE_TTL=604800
FEED_PAGE_CACHE_FRESH=2
FEED_PAGE_CACHE_STALE=10
```

Главная страница (5 последних постов, `offset=0&limit=5`) дополнительно кешируется как готовая страница через `Cache::flexible`: первые `FEED_PAGE_CACHE_FRESH` секунд данные считаются свежими, дальше до `FEED_PAGE_CACHE_STALE` — отдаются сразу, а обновление идёт в фоне. Кеш страницы сбрасывается при создании/удалении поста (автор и друзья) и изменении дружбы. Посты из `read-only` используют `limit=20/100`, поэтому L1 их не затрагивает — для замера работает ZSET.

После изменения — `docker compose --env-file .env.docker restart php` (opcache). Ответ `GET /post/feed` отдаёт заголовок `X-Cache`:

- `HIT` — страница собрана из ZSET;
- `MISS` — ключа не было, лента собрана из БД и закеширована (первый запрос пользователя);
- `OFF` — кеш выключен, задан `author_user_id` или Redis недоступен (fallback в БД).

Postman read-only папка проверяет наличие `X-Cache`. Для замера: прогнать `read-only` с `FEED_CACHE_ENABLED=false` (baseline), затем с `true` и сравнить p95/RPS и долю `HIT`. Страницы глубже 1000 записей (offset ≥ 1000) вернут пустой массив — это ожидаемо.

**Кеш друзей (FRIENDS_CACHE_ENABLED).** Список друзей тоже кешируется: `friends:ids:{userId}` и `friends:profiles:{userId}`, TTL `FRIENDS_CACHE_TTL` (300 с). Сбрасываются при добавлении/удалении дружбы для обоих пользователей, поэтому `friend/list` и `friends_count` сразу видят изменения. Кеш живёт в Redis DB 1 (соединение `cache`), ключи получают префиксы `vspace-database-vspace-cache-`. Заодно ускоряется fan-out ленты: `friendUserIds` больше не ходит в БД на каждый пост.

**Как замерять кеш.** Прогнать `read-only` с `FEED_CACHE_ENABLED=false` и сохранить как baseline, затем включить кеш, перезапустить `php` и прогнать тот же сценарий. Сравнить в Postman **Compare runs** p95/RPS и долю `X-Cache: HIT`. Ожидаемо: `MISS` только на первом запросе пользователя (сборка ZSET), дальше `HIT`; запись поста инвалидирует только затронутые ленты, а не всё. Смешанный сценарий покажет реальный hit rate при постоянных записях, `write-only` — цену fan-out на записи. Postman Performance даёт VU и перцентили; для повторяемых бенчмарков и CI — k6.
