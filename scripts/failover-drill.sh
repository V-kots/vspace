#!/usr/bin/env bash
# Учебный failover-дрилл для legacy-режима (мастер postgres + 2 реплики).
#
# Два режима нагрузки:
#   --manual   нагрузку запускаете сами из Postman; скрипт снимает baseline,
#              по Enter убивает мастер, промоутит свежую реплику и считает
#              потери по числу успешных POST /post/create из отчёта Postman.
#   (default)  встроенный writer пишет в failover_probe и журналирует id
#              подтверждённых вставок на хосте.
#
# Общие шаги после убийства мастера:
#   - выбирается самая свежая реплика по pg_last_wal_replay_lsn;
#   - pg_promote(true, 60) и ожидание pg_is_in_recovery() = false;
#   - второй слейв переключается на нового мастера (слот, .pgpass, primary_conninfo);
#   - Laravel переключается на нового мастера (DB_HOST / DB_READ_HOSTS);
#   - считается RPO и пишется report.json.
#
# ВНИМАНИЕ: дрилл разрушает текущую топологию. Только одноразовый стенд.
# Восстановление: scripts/failover-drill.sh restore
#
# Usage:
#   scripts/failover-drill.sh status
#   scripts/failover-drill.sh drill --manual --user <loadUserId>
#   scripts/failover-drill.sh drill --manual --user <uuid> --sent 400
#   scripts/failover-drill.sh drill --duration 30 --batch 50
#   scripts/failover-drill.sh restore
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-${ROOT}/.env.docker}"
APP_ENV_FILE="${APP_ENV_FILE:-${ROOT}/src/.env}"
PROBE_TABLE="failover_probe"
REPLICAS=(postgres-replica-1 postgres-replica-2)

COMMAND=""
DURATION=30
BATCH=50
ASSUME_YES=0
MANUAL=0
LOAD_USER_ID="${LOAD_USER_ID:-}"
SENT_OK="${SENT_OK:-}"

usage() {
    cat <<'EOF'
Usage: scripts/failover-drill.sh <command> [options]

Commands:
  status            Мастер, реплики, лаг, count по failover_probe (read-only)
  drill             Убить мастер, промоутить свежую реплику, переключить вторую, посчитать потери
  restore           Вернуть исходную топологию: поднять мастер, пересоздать реплики

Options:
  --manual          Нагрузка вручную из Postman (нужен --user)
  --user UUID       loadUserId, от имени которого Postman создаёт посты (обязателен для --manual)
  --sent N          Сколько POST /post/create вернуло 200 (иначе спросит после остановки нагрузки)
  --duration N      Длительность встроенной нагрузки, сек (по умолчанию 30)
  --batch N         Строк на один INSERT во встроенной нагрузке (по умолчанию 50)
  --yes             Без подтверждения destructive-действия
  -h, --help        Справка

Переменные: COMPOSE_ENV_FILE, APP_ENV_FILE, LOAD_USER_ID, SENT_OK.
Отчёт: ht/manual_restore_master/<timestamp>/report.json
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        status | drill | restore) COMMAND="$1"; shift ;;
        --manual) MANUAL=1; shift ;;
        --user) LOAD_USER_ID="$2"; shift 2 ;;
        --sent) SENT_OK="$2"; shift 2 ;;
        --duration) DURATION="$2"; shift 2 ;;
        --batch) BATCH="$2"; shift 2 ;;
        --yes) ASSUME_YES=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) echo "Неизвестный аргумент: $1" >&2; usage >&2; exit 1 ;;
    esac
done

[[ -n "$COMMAND" ]] || { usage >&2; exit 1; }

COMPOSE=(docker compose --env-file "$COMPOSE_ENV_FILE" --profile replicas)
compose() { "${COMPOSE[@]}" "$@"; }

set -a
# shellcheck disable=SC1090
. "$COMPOSE_ENV_FILE"
set +a

PG_USER="${POSTGRES_USER:-vspace}"
PG_DB="${POSTGRES_DB:-vspace}"
REPL_USER="${POSTGRES_REPLICATION_USER:-replicator}"
REPL_PASS="${POSTGRES_REPLICATION_PASSWORD:-replicator-secret}"

# переменные, которые заполняются по ходу дрилла
FRESH_SVC=""
OTHER_SVC=""
FRESH_ID=""
OTHER_ID=""
KILL_TS=""
PROMOTE_TS=""
RUN_DIR=""
RUN_TS=""

container_id() { compose ps -q "$1" 2>/dev/null | head -1; }
psql_at() { local cid="$1"; shift; docker exec "$cid" psql -U "$PG_USER" -d "$PG_DB" -Atc "$@"; }
fail() { echo "ОШИБКА: $*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }

lsn_to_num() { local hi="${1%%/*}" lo="${1##*/}"; echo $(( 16#$hi * 4294967296 + 16#$lo )); }

require_yes() {
    [[ "$ASSUME_YES" -eq 1 ]] && return 0
    echo "Действие разрушительное: текущая топология репликации будет сломана."
    read -r -p "Продолжить? Введите 'yes': " answer
    [[ "$answer" == "yes" ]] || fail "отменено"
}

pause_for() {
    read -r -p "$1" _ || true
}

replica_state() { # -> "recovery|lsn|replay_ts"
    psql_at "$1" "select pg_is_in_recovery()::text || '|' ||
                         coalesce(pg_last_wal_replay_lsn()::text, '') || '|' ||
                         coalesce(pg_last_xact_replay_timestamp()::text, '')" 2>/dev/null || true
}

wait_until_primary() {
    local cid="$1" timeout="${2:-60}" i=0
    while (( i < timeout * 5 )); do
        [[ "$(psql_at "$cid" 'select pg_is_in_recovery()' 2>/dev/null)" == "f" ]] && return 0
        sleep 0.2; i=$((i + 1))
    done
    return 1
}

preflight() {
    local n cid streaming
    cid="$(container_id postgres)"
    [[ -n "$cid" ]] || fail "мастер postgres не запущен"

    for n in "${REPLICAS[@]}"; do
        [[ -n "$(container_id "$n")" ]] || fail "реплика $n не запущена (./scripts/replication.sh up)"
    done

    streaming="$(psql_at "$cid" "select count(*) from pg_stat_replication where state = 'streaming'")"
    [[ "$streaming" -eq 2 ]] || fail "ожидались 2 стриминговые реплики, видно: ${streaming}"

    grep -q '^DB_HOST=postgres$' "$APP_ENV_FILE" \
        || fail "в ${APP_ENV_FILE} DB_HOST не 'postgres' — дрилл уже выполнялся? Сначала restore"
}

choose_freshest() {
    local n cid state is_recovery lsn num best=0
    for n in "${REPLICAS[@]}"; do
        cid="$(container_id "$n")"
        state="$(replica_state "$cid")"
        is_recovery="${state%%|*}"
        lsn="${state#*|}"; lsn="${lsn%%|*}"
        [[ "$is_recovery" == "true" ]] || fail "реплика $n не в recovery"
        [[ -n "$lsn" ]] || fail "не удалось получить LSN реплики $n"
        num="$(lsn_to_num "$lsn")"
        info "  ${n}: replay_lsn=${lsn}"
        if (( num > best )); then best="$num"; FRESH_SVC="$n"; fi
    done
    [[ -n "$FRESH_SVC" ]] || fail "не выбрана свежая реплика"

    for n in "${REPLICAS[@]}"; do
        [[ "$n" == "$FRESH_SVC" ]] || OTHER_SVC="$n"
    done
    [[ -n "$OTHER_SVC" ]] || fail "не найден второй слейв"

    FRESH_ID="$(container_id "$FRESH_SVC")"
    OTHER_ID="$(container_id "$OTHER_SVC")"
    info "новый мастер: ${FRESH_SVC}, второй слейв: ${OTHER_SVC}"
}

promote_and_repoint() {
    local slot rejoined="false" j=0

    psql_at "$FRESH_ID" "select pg_promote(true, 60)" >/dev/null
    wait_until_primary "$FRESH_ID" 60 || fail "pg_promote не завершился за 60с"
    PROMOTE_TS="$(date +%s.%N)"

    REPLAY_LAG_S="$(psql_at "$FRESH_ID" \
        "select coalesce(round(extract(epoch from now() - pg_last_xact_replay_timestamp())::numeric, 3), 0)" 2>/dev/null || echo 0)"
    info "промоут за $(awk -v a="$KILL_TS" -v b="$PROMOTE_TS" 'BEGIN{printf "%.2f", b-a}')с, replay lag: ${REPLAY_LAG_S}с"

    slot="vspace_replica_${OTHER_SVC##*-}"
    if [[ -z "$(psql_at "$FRESH_ID" "select 1 from pg_replication_slots where slot_name = '${slot}'")" ]]; then
        psql_at "$FRESH_ID" "select pg_create_physical_replication_slot('${slot}')" >/dev/null
    fi

    docker exec "$OTHER_ID" sh -c '
        f="$(getent passwd postgres | cut -d: -f6)/.pgpass"
        grep -q "^'"${FRESH_SVC}"':" "$f" || echo "'"${FRESH_SVC}"':5432:replication:'"${REPL_USER}"':'"${REPL_PASS}"'" >> "$f"
        chown postgres:postgres "$f"; chmod 600 "$f"
    '

    psql_at "$OTHER_ID" "alter system set primary_conninfo = 'user=${REPL_USER} passfile=''/var/lib/postgresql/.pgpass'' host=${FRESH_SVC} port=5432 application_name=vspace_${OTHER_SVC##*-} sslmode=prefer'" >/dev/null
    psql_at "$OTHER_ID" "select pg_reload_conf()" >/dev/null

    while (( j < 75 )); do
        if [[ "$(psql_at "$FRESH_ID" "select count(*) from pg_stat_replication where state = 'streaming'")" -ge 1 ]]; then
            rejoined="true"; break
        fi
        sleep 0.2; j=$((j + 1))
    done
    REJOINED="$rejoined"
    info "второй слейв подключился к новому мастеру: ${REJOINED}"
}

switch_app() {
    sed -i "s|^DB_HOST=.*|DB_HOST=${FRESH_SVC}|" "$APP_ENV_FILE"
    sed -i "s|^DB_PORT=.*|DB_PORT=5432|" "$APP_ENV_FILE"
    sed -i "s|^DB_READ_HOSTS=.*|DB_READ_HOSTS=${OTHER_SVC}|" "$APP_ENV_FILE"
    sed -i "s|^DB_READ_PORT=.*|DB_READ_PORT=5432|" "$APP_ENV_FILE"
    compose exec -T php php artisan config:clear >/dev/null 2>&1 || true
}

write_report() { # <mode> <sent> <written> <lost> <extra-json>
    cat > "${RUN_DIR}/report.json" <<JSON
{
  "run": "${RUN_TS}",
  "mode": "$1",
  "master_killed": "postgres",
  "promoted": "${FRESH_SVC}",
  "repointed": "${OTHER_SVC}",
  "sent_ok": $2,
  "written": $3,
  "lost_transactions": $4,
  "replica_rejoined": ${REJOINED},
  "replay_lag_seconds_at_promotion": ${REPLAY_LAG_S:-0},
  "promotion_seconds": $(awk -v a="$KILL_TS" -v b="$PROMOTE_TS" 'BEGIN{printf "%.3f", b-a}')$5
}
JSON
}

print_result() {
    info ""
    info "================ РЕЗУЛЬТАТ ================"
    info "отправлено успешно (клиент): ${1}"
    info "записано в БД:               ${2}"
    info "потеряно транзакций:         ${3}"
    info "replay lag на промоуте:      ${REPLAY_LAG_S:-0}с"
    info "отчёт: ${RUN_DIR}/report.json"
    info "=========================================="
    info "Откат: scripts/failover-drill.sh restore --yes"
}

# --- drill: нагрузка вручную из Postman --------------------------------------

cmd_drill_manual() {
    require_yes
    [[ -t 0 ]] || fail "--manual требует интерактивный терминал"
    [[ -n "$LOAD_USER_ID" ]] || fail "для --manual укажите --user <loadUserId> (из «Нагрузочный тест: подготовка»)"

    local cid baseline after written lost sent
    cid="$(container_id postgres)"

    RUN_TS="$(date +%Y%m%d-%H%M%S)"
    RUN_DIR="${ROOT}/ht/manual_restore_master/${RUN_TS}"
    mkdir -p "$RUN_DIR"

    pause_for "Postman остановлен. Enter — снять baseline..."
    baseline="$(psql_at "$cid" "select count(*) from posts where author_user_id = '${LOAD_USER_ID}'")"
    echo "$baseline" > "${RUN_DIR}/baseline.txt"
    info "baseline постов пользователя ${LOAD_USER_ID}: ${baseline}"

    pause_for "Запустите Postman, дайте нагрузке разогреться. Enter — убить мастер..."
    KILL_TS="$(date +%s.%N)"
    info "убиваем мастер ($(date '+%H:%M:%S'))"
    docker update --restart=no "$cid" >/dev/null
    docker kill "$cid" >/dev/null

    pause_for "Остановите Postman. Enter — продолжить..."
    if [[ -z "$SENT_OK" ]]; then
        read -r -p "Сколько POST /post/create вернуло 200? " SENT_OK
    fi
    [[ "$SENT_OK" =~ ^[0-9]+$ ]] || fail "--sent должен быть числом, получено: '${SENT_OK}'"
    sent="$SENT_OK"
    info "успешных create по отчёту Postman: ${sent}"

    choose_freshest
    promote_and_repoint
    switch_app

    after="$(psql_at "$FRESH_ID" "select count(*) from posts where author_user_id = '${LOAD_USER_ID}'")"
    written=$(( after - baseline ))
    (( written >= 0 )) || written=0
    lost=$(( sent - written ))
    if (( lost < 0 )); then
        info "ВНИМАНИЕ: записано больше, чем отправлено (${written} > ${sent}) — проверьте baseline/фильтр по автору"
        lost=0
    fi

    write_report "manual" "$sent" "$written" "$lost" ""
    print_result "$sent" "$written" "$lost"
}

# --- drill: встроенная нагрузка в failover_probe ------------------------------

cmd_drill_auto() {
    require_yes

    local cid ids writer_pid acknowledged present lost result
    cid="$(container_id postgres)"

    RUN_TS="$(date +%Y%m%d-%H%M%S)"
    RUN_DIR="${ROOT}/ht/manual_restore_master/${RUN_TS}"
    mkdir -p "$RUN_DIR"
    local committed_log="${RUN_DIR}/committed.log" stop_flag="${RUN_DIR}/stop"
    : > "$committed_log"

    psql_at "$cid" "create table if not exists ${PROBE_TABLE} (
        id bigint generated always as identity primary key,
        payload text not null default 'x',
        created_at timestamptz not null default now()
    )" >/dev/null

    info "нагрузка: ${DURATION}s, batch=${BATCH}"
    (
        while [[ ! -f "$stop_flag" ]]; do
            ids="$(docker exec "$cid" psql -U "$PG_USER" -d "$PG_DB" -Atc \
                "insert into ${PROBE_TABLE}(payload) select 'x' from generate_series(1, ${BATCH}) returning id" 2>/dev/null)" || break
            [[ -n "$ids" ]] && printf '%s\n' "$ids" >> "$committed_log"
        done
    ) &
    writer_pid=$!

    sleep "$DURATION"

    KILL_TS="$(date +%s.%N)"
    info "убиваем мастер ($(date '+%H:%M:%S'))"
    docker update --restart=no "$cid" >/dev/null
    docker kill "$cid" >/dev/null

    touch "$stop_flag"
    wait "$writer_pid" 2>/dev/null || true
    acknowledged="$(wc -l < "$committed_log" | tr -d ' ')"
    info "подтверждено клиентом вставок: ${acknowledged}"

    choose_freshest
    promote_and_repoint
    switch_app

    docker cp "$committed_log" "$FRESH_ID:/tmp/committed.log" >/dev/null
    docker exec -u root "$FRESH_ID" chmod 644 /tmp/committed.log
    result="$(docker exec "$FRESH_ID" psql -U "$PG_USER" -d "$PG_DB" -At <<SQL
create temp table _logged(id bigint);
\copy _logged from '/tmp/committed.log'
select (select count(*) from _logged)
       || '|' || (select count(*) from _logged l join ${PROBE_TABLE} p on p.id = l.id)
       || '|' || (select count(*) from _logged l left join ${PROBE_TABLE} p on p.id = l.id where p.id is null);
SQL
)"
    present="$(printf '%s' "$result" | cut -d'|' -f2)"
    lost="$(printf '%s' "$result" | cut -d'|' -f3)"
    [[ -n "$present" ]] || present="?"
    [[ -n "$lost" ]] || lost="?"

    write_report "auto" "$acknowledged" "$present" "$lost" ""
    print_result "$acknowledged" "$present" "$lost"
}

cmd_drill() {
    preflight
    if [[ "$MANUAL" -eq 1 ]]; then
        cmd_drill_manual
    else
        cmd_drill_auto
    fi
}

# --- status -------------------------------------------------------------------

cmd_status() {
    local cid master_lsn n state
    cid="$(container_id postgres)"
    [[ -n "$cid" ]] || fail "мастер postgres не запущен"

    master_lsn="$(psql_at "$cid" 'select pg_current_wal_lsn()')"
    info "мастер: ${cid}  lsn=${master_lsn}"
    info ""
    info "репликация:"
    psql_at "$cid" "select application_name || ' | ' || state || ' | lag_bytes=' ||
                           pg_wal_lsn_diff(sent_lsn, replay_lsn)
                      from pg_stat_replication order by application_name" || true

    for n in "${REPLICAS[@]}"; do
        cid="$(container_id "$n")"
        if [[ -z "$cid" ]]; then
            info "  ${n}: не запущена"
            continue
        fi
        state="$(replica_state "$cid")"
        info "  ${n}: ${state}"
    done

    cid="$(container_id postgres)"
    info ""
    info "failover_probe: $(psql_at "$cid" "select count(*) from ${PROBE_TABLE}" 2>/dev/null || echo 'нет таблицы')"
}

# --- restore ------------------------------------------------------------------

cmd_restore() {
    require_yes

    local master_id i=0
    master_id="$(compose ps -a -q postgres | head -1)"
    [[ -n "$master_id" ]] || fail "контейнер postgres не найден"

    info "поднимаем старый мастер"
    docker update --restart=unless-stopped "$master_id" >/dev/null
    compose start postgres >/dev/null

    until compose exec -T postgres pg_isready -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1; do
        sleep 1; i=$((i + 1))
        (( i < 60 )) || fail "мастер не поднялся за 60с"
    done

    info "пересоздаём реплики из мастера (данные дрилла отбрасываются)"
    "${ROOT}/scripts/replication.sh" reset

    sed -i 's|^DB_HOST=.*|DB_HOST=postgres|' "$APP_ENV_FILE"
    sed -i 's|^DB_PORT=.*|DB_PORT=5432|' "$APP_ENV_FILE"
    sed -i 's|^DB_READ_HOSTS=.*|DB_READ_HOSTS=postgres-replica-1,postgres-replica-2|' "$APP_ENV_FILE"
    sed -i 's|^DB_READ_PORT=.*|DB_READ_PORT=5432|' "$APP_ENV_FILE"
    compose exec -T php php artisan config:clear >/dev/null 2>&1 || true

    info "готово: мастер postgres, реплики postgres-replica-1/2"
}

case "$COMMAND" in
    status) cmd_status ;;
    drill) cmd_drill ;;
    restore) cmd_restore ;;
esac
