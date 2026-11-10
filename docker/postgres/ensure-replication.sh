#!/bin/sh
# Идемпотентная подготовка мастера к потоковой репликации.
# Выполняется одноразовым сервисом postgres-replication-init при каждом
# включении профиля replicas. Нужен для уже существующего PGDATA, где
# docker-entrypoint-initdb.d (init/10-replication.sh) больше не запускается.
set -eu

: "${PGDATA:?PGDATA is required}"
: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${REPLICATION_USER:?REPLICATION_USER is required}"
: "${REPLICATION_PASSWORD:?REPLICATION_PASSWORD is required}"

PRIMARY_HOST="${PRIMARY_HOST:-postgres}"
PRIMARY_PORT="${PRIMARY_PORT:-5432}"
REPLICA_SLOTS="${REPLICA_SLOTS:-}"

export PGPASSWORD="$POSTGRES_PASSWORD"

until pg_isready -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; do
    echo "waiting for primary ${PRIMARY_HOST}:${PRIMARY_PORT}"
    sleep 2
done

psql -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${REPLICATION_USER}') THEN
        CREATE ROLE ${REPLICATION_USER} WITH REPLICATION LOGIN PASSWORD '${REPLICATION_PASSWORD}';
    ELSE
        ALTER ROLE ${REPLICATION_USER} WITH REPLICATION LOGIN PASSWORD '${REPLICATION_PASSWORD}';
    END IF;
END
\$\$;
SQL

HBA_CHANGED=0
for RULE in \
    "host replication ${REPLICATION_USER} 0.0.0.0/0 scram-sha-256" \
    "host replication ${REPLICATION_USER} ::/0 scram-sha-256"; do
    if ! grep -qE "^[[:space:]]*${RULE}[[:space:]]*$" "$PGDATA/pg_hba.conf"; then
        printf '%s\n' "$RULE" >> "$PGDATA/pg_hba.conf"
        HBA_CHANGED=1
    fi
done

for SLOT in $REPLICA_SLOTS; do
    psql -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_replication_slots WHERE slot_name = '${SLOT}') THEN
        PERFORM pg_create_physical_replication_slot('${SLOT}');
    END IF;
END
\$\$;
SQL
done

if [ "$HBA_CHANGED" -eq 1 ]; then
    psql -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
        -c "SELECT pg_reload_conf()" >/dev/null
fi

echo "replication is ready: role '${REPLICATION_USER}', slots '${REPLICA_SLOTS}'"
