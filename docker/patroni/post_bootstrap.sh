#!/bin/sh
# Создаёт роль и БД приложения при первичном bootstrap Patroni.
# Patroni 4.x больше не поддерживает bootstrap.users, поэтому делаем это здесь.
# $1 — connection string, который Patroni передаёт скрипту.
set -eu

: "${PATRONI_APP_PASSWORD:?PATRONI_APP_PASSWORD is required}"
APP_DB="${PATRONI_APP_DB:-vspace}"
CONNSTRING="${1:?Patroni connection string is required}"

ESCAPED_PASSWORD="$(printf '%s' "$PATRONI_APP_PASSWORD" | sed "s/'/''/g")"

psql -v ON_ERROR_STOP=1 "$CONNSTRING" <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'vspace') THEN
        CREATE ROLE vspace WITH LOGIN PASSWORD '${ESCAPED_PASSWORD}' CREATEDB;
    ELSE
        ALTER ROLE vspace WITH LOGIN PASSWORD '${ESCAPED_PASSWORD}' CREATEDB;
    END IF;
END
\$\$;
SQL

if ! psql -v ON_ERROR_STOP=1 "$CONNSTRING" -tAc "SELECT 1 FROM pg_database WHERE datname = '${APP_DB}'" | grep -q 1; then
    psql -v ON_ERROR_STOP=1 "$CONNSTRING" -c "CREATE DATABASE ${APP_DB} OWNER vspace;"
fi
