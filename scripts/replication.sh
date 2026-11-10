#!/usr/bin/env bash
# Управление потоковой репликацией PostgreSQL: мастер + 2 реплики.
#
# Реплики живут в профиле compose `replicas`, поэтому обычный
# `docker compose up -d` их не поднимает. Скрипт дополнительно переключает
# Laravel-переменную DB_READ_HOSTS в src/.env: в dev конфиг не закэширован,
# поэтому изменения применяются со следующего запроса без рестарта php-fpm.
# Если включён config:cache — выполните `php artisan config:cache` и
# перезагрузите php-fpm.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_ENV_FILE="${ROOT}/.env.docker"
APP_ENV_FILE="${ROOT}/src/.env"
REPLICAS=(1 2)

usage() {
    cat <<'EOF'
Usage: scripts/replication.sh <command> [replicas] [options]

Commands:
  up [1|2|all]     Включить реплики (по умолчанию all) и направить чтение на них
  down [1|2|all]   Выключить реплики (по умолчанию all) и вернуть чтение на мастер
  status           Показать состояние реплик, слоты и отставание
  reset [1|2|all]  Пересоздать реплики с нуля (повторный base backup)

Options:
  --env-file F      compose env-файл (по умолчанию .env.docker)
  --app-env-file F  Laravel .env (по умолчанию src/.env)
  -h, --help        эта справка

Примеры:
  ./scripts/replication.sh up
  ./scripts/replication.sh down 1
  ./scripts/replication.sh up 2
  ./scripts/replication.sh status
  ./scripts/replication.sh reset 2
EOF
}

COMMAND=""
ARGS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        up | down | status | reset)
            COMMAND="$1"
            shift
            ;;
        --env-file)
            COMPOSE_ENV_FILE="$2"
            shift 2
            ;;
        --app-env-file)
            APP_ENV_FILE="$2"
            shift 2
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            ARGS+=("$1")
            shift
            ;;
    esac
done

if [[ -z "$COMMAND" ]]; then
    usage >&2
    exit 1
fi

COMPOSE=(docker compose --env-file "$COMPOSE_ENV_FILE" --profile replicas)

if [[ -f "$COMPOSE_ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    . "$COMPOSE_ENV_FILE"
    set +a
fi

PG_USER="${POSTGRES_USER:-vspace}"
PG_DB="${POSTGRES_DB:-vspace}"

# В Patroni-режиме этот скрипт перезапишет DB_READ_HOSTS на legacy-реплики.
# Управление Patroni — через scripts/patroni.sh.
if grep -q '^DB_MODE=patroni' "$APP_ENV_FILE" 2>/dev/null || grep -q '^DB_HOST=patroni-' "$APP_ENV_FILE" 2>/dev/null; then
    echo "Laravel сейчас на Patroni." >&2
    echo "Сначала: ./scripts/patroni.sh use-legacy" >&2
    exit 1
fi

compose() {
    "${COMPOSE[@]}" "$@"
}

selected_replicas() {
    local requested=("$@")
    [[ ${#requested[@]} -eq 0 ]] && requested=(all)

    local n
    for n in "${REPLICAS[@]}"; do
        if [[ " ${requested[*]} " == *" all "* || " ${requested[*]} " == *" $n "* ]]; then
            echo "$n"
        fi
    done
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

# Оставляем в DB_READ_HOSTS только реально запущенные реплики.
sync_read_hosts() {
    local running n host hosts=() value=""

    running="$(compose ps --services --status running 2>/dev/null || true)"

    for n in "${REPLICAS[@]}"; do
        host="postgres-replica-$n"
        if grep -qx "$host" <<<"$running"; then
            hosts+=("$host")
        fi
    done

    if [[ ${#hosts[@]} -gt 0 ]]; then
        value="$(IFS=,; printf '%s' "${hosts[*]}")"
    fi

    set_app_env "DB_MODE" legacy
    set_app_env "DB_HOST" postgres
    set_app_env "DB_PORT" 5432
    set_app_env "DB_READ_PORT" 5432
    set_app_env "DB_READ_HOSTS" "$value"

    if [[ -n "$value" ]]; then
        echo "Laravel: DB_READ_HOSTS=${value} (чтение с реплик)"
    else
        echo "Laravel: DB_READ_HOSTS=<empty> (чтение с мастера)"
    fi
}

run_replication_init() {
    compose run --rm -T --no-deps postgres-replication-init
}

cmd_up() {
    local n

    compose up -d postgres
    run_replication_init

    for n in $(selected_replicas "$@"); do
        compose up -d "postgres-replica-$n"
    done

    sync_read_hosts
}

cmd_down() {
    local n

    for n in $(selected_replicas "$@"); do
        compose stop "postgres-replica-$n" >/dev/null 2>&1 || true
    done

    sync_read_hosts
}

cmd_status() {
    local n

    compose ps postgres-replica-1 postgres-replica-2 || true

    echo
    echo "== pg_stat_replication (мастер) =="
    compose exec -T postgres psql -U "$PG_USER" -d "$PG_DB" -c \
        "select application_name, client_addr, state, sync_state,
                pg_wal_lsn_diff(sent_lsn, replay_lsn) as lag_bytes
           from pg_stat_replication
          order by application_name;" || true

    for n in "${REPLICAS[@]}"; do
        echo
        echo "== postgres-replica-$n =="
        compose exec -T "postgres-replica-$n" psql -U "$PG_USER" -d "$PG_DB" -tAc \
            "select case when pg_is_in_recovery() then 'in recovery' else 'NOT in recovery' end
                    || ' | replay lag: '
                    || coalesce((now() - pg_last_xact_replay_timestamp())::text, 'n/a');" \
            2>/dev/null || echo "не запущена"
    done

    echo
    echo "== слоты репликации =="
    compose exec -T postgres psql -U "$PG_USER" -d "$PG_DB" -c \
        "select slot_name, active, wal_status,
                pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)) as retained_wal
           from pg_replication_slots
          order by slot_name;" || true

    echo
    if [[ -f "$APP_ENV_FILE" ]]; then
        grep -E '^DB_READ_HOSTS=' "$APP_ENV_FILE" || echo "Laravel: DB_READ_HOSTS не задан"
    fi
}

cmd_reset() {
    local n slot

    for n in $(selected_replicas "$@"); do
        slot="vspace_replica_$n"

        compose stop "postgres-replica-$n" >/dev/null 2>&1 || true

        compose exec -T postgres psql -U "$PG_USER" -d "$PG_DB" -c \
            "select pg_drop_replication_slot('${slot}')
               where exists (select 1 from pg_replication_slots where slot_name = '${slot}');" \
            >/dev/null 2>&1 || true

        compose run --rm -T --no-deps --entrypoint sh "postgres-replica-$n" -c \
            'rm -rf "$PGDATA"/* "$PGDATA"/.[!.]* 2>/dev/null || true'
    done

    compose up -d postgres
    run_replication_init

    for n in $(selected_replicas "$@"); do
        compose up -d "postgres-replica-$n"
    done

    sync_read_hosts
}

case "$COMMAND" in
    up) cmd_up "${ARGS[@]}" ;;
    down) cmd_down "${ARGS[@]}" ;;
    status) cmd_status ;;
    reset) cmd_reset "${ARGS[@]}" ;;
esac
