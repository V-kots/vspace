#!/usr/bin/env bash
# Patroni: 3 PostgreSQL-узла + 3 etcd. HAProxy отключён — Laravel подключается
# напрямую к текущему лидеру и репликам.
#
# Кластер живёт в отдельном profile `patroni` и не мешает текущему
# мастеру/репликам из docker-compose.yml. Приложение переключается на Patroni
# командой `use`, обратно — `use-legacy`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_ENV_FILE="${ROOT}/.env.docker"
APP_ENV_FILE="${ROOT}/src/.env"

BASE_COMPOSE=(docker compose --env-file "$COMPOSE_ENV_FILE" -f "${ROOT}/docker-compose.yml")
PATRONI_COMPOSE=("${BASE_COMPOSE[@]}" -f "${ROOT}/docker-compose.patroni.yml" --profile patroni)
PATRONI_SERVICES=(etcd1 etcd2 etcd3 patroni-1 patroni-2 patroni-3)

if [[ -f "$COMPOSE_ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    . "$COMPOSE_ENV_FILE"
    set +a
fi

PATRONI_SUPERUSER_PASSWORD="${PATRONI_SUPERUSER_PASSWORD:-postgres}"
PATRONI_APP_PASSWORD="${PATRONI_APP_PASSWORD:-secret}"
PATRONI_APP_DB="${PATRONI_APP_DB:-vspace}"
PATRONI_SYNC_NODE_COUNT="${PATRONI_SYNC_NODE_COUNT:-1}"
PATRONI_SYNC_STRICT="${PATRONI_SYNC_STRICT:-false}"

usage() {
    cat <<'EOF'
Usage: scripts/patroni.sh <command>

Commands:
  up               Запустить etcd + 3 Patroni
  down             Остановить Patroni-стек и вернуть Laravel на прямой legacy
  status           Состояние кластера и синхронной репликации
  use              Переключить Laravel на Patroni (текущий лидер + реплики)
  use-legacy       Вернуть Laravel на postgres + postgres-replica-*
  mode             Показать текущий режим Laravel (patroni/legacy)
  sync on          Включить кворумную синхронную репликацию (Patroni quorum)
  sync off         Выключить синхронную репликацию (async)
  sync status      Показать synchronous_mode / synchronous_standby_names
  migrate-data     Скопировать БД из текущего postgres в Patroni (pg_dump/restore)
  logs             Логи Patroni-стека

Переменные:
  PATRONI_SYNC_NODE_COUNT  сколько реплик должны подтвердить коммит (по умолчанию 1)
  PATRONI_SYNC_STRICT      true — блокировать записи без кворума (по умолчанию false)

Примеры:
  ./scripts/patroni.sh up
  ./scripts/patroni.sh use
  ./scripts/patroni.sh sync on
  ./scripts/patroni.sh mode
  ./scripts/patroni.sh sync off
  ./scripts/patroni.sh down
EOF
}

compose_patroni() {
    "${PATRONI_COMPOSE[@]}" "$@"
}

compose_base() {
    "${BASE_COMPOSE[@]}" "$@"
}

set_app_env() {
    local key="$1"
    local value="$2"

    if [[ ! -f "$APP_ENV_FILE" ]]; then
        echo "Пропуск $key: файл $APP_ENV_FILE не найден" >&2
        return 0
    fi

    if grep -q "^${key}=" "$APP_ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$APP_ENV_FILE"
    else
        printf '\n%s=%s\n' "$key" "$value" >>"$APP_ENV_FILE"
    fi
}

app_env() {
    sed -n "s/^$1=//p" "$APP_ENV_FILE" 2>/dev/null | tail -n1
}

clear_config() {
    compose_base exec -T php php artisan config:clear >/dev/null 2>&1 || true
}

patronictl() {
    local running node
    running="$(compose_patroni ps --services --status running 2>/dev/null || true)"

    for node in patroni-1 patroni-2 patroni-3; do
        if grep -qx "$node" <<<"$running"; then
            compose_patroni exec -T "$node" patronictl -c /etc/patroni.yml "$@"
            return $?
        fi
    done

    return 1
}

patroni_leader() {
    patronictl list vspace 2>/dev/null | awk -F'|' '
        $2 ~ /patroni-[0-9]+/ {
            gsub(/[[:space:]]/, "", $2);
            gsub(/[[:space:]]/, "", $4);
            gsub(/[[:space:]]/, "", $5);
            if ($4 == "Leader" && $5 == "running") {
                print $2;
                exit;
            }
        }'
}

patroni_replicas() {
    patronictl list vspace 2>/dev/null | awk -F'|' '
        $2 ~ /patroni-[0-9]+/ {
            gsub(/[[:space:]]/, "", $2);
            gsub(/[[:space:]]/, "", $4);
            gsub(/[[:space:]]/, "", $5);
            if ($4 != "Leader" && ($5 == "streaming" || $5 == "running")) {
                out = out (out ? "," : "") $2;
            }
        }
        END { print out }'
}

use_patroni() {
    local leader replicas

    leader="$(patroni_leader)"
    if [[ -z "$leader" ]]; then
        echo "Patroni-лидер не найден. Кластер запущен?" >&2
        exit 1
    fi
    replicas="$(patroni_replicas)"

    set_app_env DB_MODE patroni
    set_app_env DB_HOST "$leader"
    set_app_env DB_PORT 5432
    set_app_env DB_READ_HOSTS "$replicas"
    set_app_env DB_READ_PORT 5432
    set_app_env DB_STICKY true
    clear_config

    echo "Laravel → Patroni: write ${leader}:5432, read ${replicas:-$leader}:5432"
}

show_mode() {
    local mode host port read_hosts read_port
    mode="$(app_env DB_MODE)"
    host="$(app_env DB_HOST)"
    port="$(app_env DB_PORT)"
    read_hosts="$(app_env DB_READ_HOSTS)"
    read_port="$(app_env DB_READ_PORT)"

    if [[ "$mode" == "patroni" || "$host" == patroni-* ]]; then
        echo "Laravel: Patroni (write ${host}:${port}, read ${read_hosts:-$host}:${read_port:-5432})"
    elif [[ "$mode" == "legacy" || "$host" == "postgres" || "$host" == "pgbouncer" ]]; then
        echo "Laravel: legacy (write ${host}:${port}, read ${read_hosts:-<master>}:${read_port:-5432})"
    else
        echo "Laravel: неизвестный режим"
        grep -E '^DB_(HOST|PORT|READ_HOSTS|READ_PORT|MODE)=' "$APP_ENV_FILE" 2>/dev/null || true
    fi
}

use_legacy() {
    set_app_env DB_MODE legacy
    set_app_env DB_HOST postgres
    set_app_env DB_PORT 5432
    set_app_env DB_READ_HOSTS postgres-replica-1,postgres-replica-2
    set_app_env DB_READ_PORT 5432
    set_app_env DB_STICKY true
    clear_config
    echo "Laravel → legacy: postgres:5432 + postgres-replica-1/2:5432"
}

wait_for_leader() {
    local i
    for i in $(seq 1 90); do
        if [[ -n "$(patroni_leader)" ]]; then
            return 0
        fi
        sleep 2
    done

    echo "Patroni не выбрал лидера за 180 секунд" >&2
    return 1
}

sync_standby_names() {
    local leader
    leader="$(patroni_leader)"
    [[ -z "$leader" ]] && return 1

    compose_patroni exec -T -e PGPASSWORD="$PATRONI_SUPERUSER_PASSWORD" patroni-1 \
        psql -h "$leader" -p 5432 -U postgres -d postgres \
        -tAc "show synchronous_standby_names;" 2>/dev/null | tr -d '[:space:]'
}

wait_sync_state() {
    local want="$1"
    local i value

    for i in $(seq 1 20); do
        value="$(sync_standby_names || true)"
        if [[ "$want" == "on" && -n "$value" ]]; then
            return 0
        fi
        if [[ "$want" == "off" && -z "$value" ]]; then
            return 0
        fi
        sleep 2
    done

    return 0
}

sync_status() {
    local leader
    leader="$(patroni_leader)"

    echo "== patronictl list =="
    patronictl list vspace || true

    echo
    echo "== dynamic config =="
    patronictl show-config vspace 2>/dev/null | grep -E 'synchronous_' || true

    if [[ -z "$leader" ]]; then
        echo
        echo "Лидер Patroni не найден — PostgreSQL-метрики пропущены" >&2
        return 0
    fi

    echo
    echo "== PostgreSQL (${leader}) =="
    compose_patroni exec -T -e PGPASSWORD="$PATRONI_SUPERUSER_PASSWORD" patroni-1 \
        psql -h "$leader" -p 5432 -U postgres -d postgres \
        -c "show synchronous_standby_names;" \
        -c "show synchronous_commit;" \
        -c "select application_name, state, sync_state,
                   pg_wal_lsn_diff(sent_lsn, replay_lsn) as lag_bytes
              from pg_stat_replication
             order by application_name;" 2>/dev/null || true
}

sync_on() {
    patronictl edit-config vspace \
        --set synchronous_mode=quorum \
        --set "synchronous_node_count=${PATRONI_SYNC_NODE_COUNT}" \
        --set "synchronous_mode_strict=${PATRONI_SYNC_STRICT}" \
        --force

    wait_sync_state on
    sync_status
}

sync_off() {
    patronictl edit-config vspace \
        --set synchronous_mode=off \
        --force

    wait_sync_state off
    sync_status
}

cmd_up() {
    compose_patroni up -d "${PATRONI_SERVICES[@]}"
    wait_for_leader

    local leader replicas
    leader="$(patroni_leader)"
    replicas="$(patroni_replicas)"

    echo
    echo "Patroni готов."
    echo "  leader: ${leader}:5432"
    echo "  replicas: ${replicas:-<none>}"
    echo
    patronictl list vspace || true
}

cmd_down() {
    compose_patroni stop "${PATRONI_SERVICES[@]}" >/dev/null 2>&1 || true

    if [[ "$(app_env DB_MODE)" == "patroni" || "$(app_env DB_HOST)" == patroni-* ]]; then
        use_legacy
    fi

    echo "Patroni-стек остановлен"
}

cmd_migrate_data() {
    local dump network leader

    if ! compose_base ps --services --status running | grep -qx 'postgres'; then
        echo "Контейнер postgres не запущен — миграция невозможна" >&2
        exit 1
    fi

    leader="$(patroni_leader)"
    if [[ -z "$leader" ]]; then
        echo "Patroni-лидер не найден — миграция невозможна" >&2
        exit 1
    fi

    dump="$(mktemp /tmp/vspace-patroni-XXXXXX.dump)"
    network="${COMPOSE_PROJECT_NAME:-vspace}_net"

    echo "Дамп vspace из текущего postgres → ${dump}"
    compose_base exec -T postgres pg_dump -U "${POSTGRES_USER:-vspace}" -d "${POSTGRES_DB:-vspace}" -Fc >"$dump"

    echo "Восстановление в Patroni через ${leader}:5432"
    docker run --rm --network "$network" \
        -v "${dump}:/dump.dump:ro" \
        -e PGPASSWORD="$PATRONI_APP_PASSWORD" \
        postgres:17.6-alpine \
        pg_restore -h "$leader" -p 5432 -U vspace -d "$PATRONI_APP_DB" \
        --clean --if-exists --no-owner --no-privileges /dump.dump

    rm -f "$dump"
    echo "Данные скопированы в Patroni"
}

cmd_logs() {
    compose_patroni logs -f "${PATRONI_SERVICES[@]}"
}

COMMAND="${1:-}"
if [[ $# -gt 0 ]]; then
    shift
fi

case "$COMMAND" in
    up) cmd_up ;;
    down) cmd_down ;;
    status) sync_status ;;
    use) use_patroni ;;
    use-legacy) use_legacy ;;
    mode) show_mode ;;
    migrate-data) cmd_migrate_data ;;
    logs) cmd_logs ;;
    sync)
        case "${1:-status}" in
            on) sync_on ;;
            off) sync_off ;;
            status) sync_status ;;
            *) usage; exit 1 ;;
        esac
        ;;
    -h | --help | "") usage ;;
    *) usage; exit 1 ;;
esac
