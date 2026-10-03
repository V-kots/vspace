# Нагрузочное тестирование login и GET /user/get

По умолчанию в БД создаётся/поддерживается **5000** активных пользователей (`LOADTEST_USER_COUNT`), затем экспорт в файлы.

**PHP под нагрузку:** в `.env.docker` задайте `XDEBUG_MODE=off` (иначе xdebug блокирует OPcache JIT), при необходимости `PHP_OPCACHE_VALIDATE_TIMESTAMPS=0`, затем `docker compose up -d --build php`. Подробнее — README, раздел *PHP: OPcache, JIT, preload*.

## 1. Подготовка (один раз)

Пароли в БД после ручной регистрации разные. Для **login** нужен общий пароль:

```bash
cd /path/to/vspace
chmod +x scripts/loadtest/prepare-loadtest-passwords.sh scripts/loadtest/export-loadtest-users.sh

./scripts/loadtest/prepare-loadtest-passwords.sh --yes   # LOADTEST_PASSWORD=Secret123! по умолчанию
./scripts/loadtest/export-loadtest-users.sh              # ensure 5000 users + export
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
  -d "{\"id\":\"${ID}\",\"password\":\"Secret123!\"}" \
  "${BASE_URL}/login"
```

## 5. Обновление данных

После новых регистраций:

```bash
./scripts/loadtest/export-loadtest-users.sh
# если нужен login — снова scripts/loadtest/prepare-loadtest-passwords.sh --yes
```

`credentials.jsonl` и `user-ids.txt` можно не коммитить (генерируются локально).
