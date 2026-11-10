#!/bin/sh
# Старт реплики: если PGDATA пуст — base backup с мастера, затем обычный запуск postgres.
# Слот репликации создаётся заранее (идемпотентно), поэтому pg_basebackup не
# падает, если слот уже остался на мастере после удаления PGDATA реплики.
set -eu

: "${PGDATA:?PGDATA is required}"
: "${PRIMARY_HOST:?PRIMARY_HOST is required}"
: "${REPLICATION_USER:?REPLICATION_USER is required}"
: "${REPLICATION_PASSWORD:?REPLICATION_PASSWORD is required}"
: "${REPLICA_SLOT:?REPLICA_SLOT is required}"

PRIMARY_PORT="${PRIMARY_PORT:-5432}"

# Пароль не попадает в postgresql.auto.conf: pg_basebackup -R пишет только
# host/port/user, а пароль берётся из .pgpass. Файл лежит вне PGDATA и теряется
# при пересоздании контейнера, поэтому пишем его на каждом старте — он нужен
# и pg_basebackup, и walreceiver-у уже запущенного postgres.
PGPASS_FILE="$(getent passwd postgres | cut -d: -f6)/.pgpass"
printf '%s:%s:replication:%s:%s\n' "$PRIMARY_HOST" "$PRIMARY_PORT" "$REPLICATION_USER" "$REPLICATION_PASSWORD" \
    > "$PGPASS_FILE"
chown postgres:postgres "$PGPASS_FILE"
chmod 600 "$PGPASS_FILE"
export PGPASSFILE="$PGPASS_FILE"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
    mkdir -p "$PGDATA"
    chown -R postgres:postgres "$PGDATA"

    until pg_isready -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; do
        echo "waiting for primary ${PRIMARY_HOST}:${PRIMARY_PORT}"
        sleep 2
    done

    # Слот создаём от суперпользователя приложения: роль replicator имеет
    # только REPLICATION и не может управлять слотами.
    export PGPASSWORD="$POSTGRES_PASSWORD"
    psql -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_replication_slots WHERE slot_name = '${REPLICA_SLOT}') THEN
        PERFORM pg_create_physical_replication_slot('${REPLICA_SLOT}');
    END IF;
END
\$\$;
SQL
    unset PGPASSWORD

    echo "pg_basebackup from ${PRIMARY_HOST} into ${PGDATA} (slot ${REPLICA_SLOT})"
    if ! gosu postgres pg_basebackup \
        -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$REPLICATION_USER" \
        -D "$PGDATA" -Fp -Xs -P -R -S "$REPLICA_SLOT"; then
        echo "pg_basebackup failed; cleaning ${PGDATA} for retry" >&2
        rm -rf "$PGDATA"/* "$PGDATA"/.[!.]* 2>/dev/null || true
        exit 1
    fi
fi

exec docker-entrypoint.sh "$@"
