#!/bin/sh
# Инициализация мастера для потоковой репликации.
# Выполняется postgres-образом только при первичной инициализации PGDATA.
set -eu

: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${REPLICATION_USER:?REPLICATION_USER is required}"
: "${REPLICATION_PASSWORD:?REPLICATION_PASSWORD is required}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${REPLICATION_USER}') THEN
            CREATE ROLE ${REPLICATION_USER} WITH REPLICATION LOGIN PASSWORD '${REPLICATION_PASSWORD}';
        END IF;
    END
    \$\$;
SQL

if ! grep -q "^host replication ${REPLICATION_USER} " "$PGDATA/pg_hba.conf"; then
    echo "host replication ${REPLICATION_USER} 0.0.0.0/0 scram-sha-256" >> "$PGDATA/pg_hba.conf"
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -c "select pg_reload_conf()" >/dev/null
fi

echo "replication role '${REPLICATION_USER}' is ready"
