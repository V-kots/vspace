#!/bin/sh
# Рендерит конфиг Patroni из шаблона и запускает Patroni от пользователя postgres.
set -eu

: "${PATRONI_NAME:?PATRONI_NAME is required}"
: "${PATRONI_SCOPE:?PATRONI_SCOPE is required}"
: "${PATRONI_CONNECT_ADDRESS:?PATRONI_CONNECT_ADDRESS is required}"
: "${PGDATA:?PGDATA is required}"
: "${ETCD_HOSTS:?ETCD_HOSTS is required}"
: "${PATRONI_SUPERUSER_PASSWORD:?PATRONI_SUPERUSER_PASSWORD is required}"
: "${PATRONI_REPLICATION_PASSWORD:?PATRONI_REPLICATION_PASSWORD is required}"
: "${PATRONI_APP_PASSWORD:?PATRONI_APP_PASSWORD is required}"

envsubst '${PATRONI_NAME} ${PATRONI_SCOPE} ${PATRONI_CONNECT_ADDRESS} ${PGDATA} ${ETCD_HOSTS} ${PATRONI_SUPERUSER_PASSWORD} ${PATRONI_REPLICATION_PASSWORD}' \
    < /etc/patroni.yml.tmpl > /etc/patroni.yml

mkdir -p "$PGDATA" /var/run/postgresql
chown -R postgres:postgres "$PGDATA" /var/run/postgresql /etc/patroni.yml
chmod 700 "$PGDATA"

exec gosu postgres patroni /etc/patroni.yml
