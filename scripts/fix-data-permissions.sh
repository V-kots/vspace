#!/usr/bin/env bash
# Исправление прав на каталоги, с которыми работают контейнеры под своими uid.
#
# 1. Каталоги данных (`docker-data`): Docker создаёт bind-mount от root:root, а
#    Grafana (uid 472) и Loki (uid 10001) работают под непривилегированными
#    пользователями. Без chown они не могут писать, Grafana не поднимается и
#    nginx падает каскадом с `host not found in upstream "grafana"`.
# 2. `src/storage` и `src/bootstrap/cache`: bind-mount `src` приходит с хоста под
#    uid текущего пользователя, а php-fpm-воркеры работают под `www-data`.
#
# Chown по каталогам данных выполняется в одноразовом alpine через docker —
# sudo/root не нужен. Часть для php применяется только если php-контейнер запущен.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT}/.env.docker"

usage() {
    cat <<'EOF'
Usage: scripts/fix-data-permissions.sh [options]

  --env-file F  env-файл Compose (по умолчанию .env.docker)
  -h, --help    эта справка

Примеры:
  ./scripts/fix-data-permissions.sh
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            ENV_FILE="$2"
            shift 2
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Env file not found: $ENV_FILE" >&2
    exit 1
fi

data_root="$(grep -E '^COMPOSE_DATA_ROOT=' "$ENV_FILE" | tail -n1 | cut -d= -f2- || true)"
DATA_ROOT="${data_root:-./docker-data}"
[[ "$DATA_ROOT" = /* ]] || DATA_ROOT="${ROOT}/${DATA_ROOT#./}"

echo "→ Каталог данных: ${DATA_ROOT}"
mkdir -p "$DATA_ROOT"/{grafana,loki,nginx-logs}

echo "→ chown grafana → 472:472, loki → 10001:10001"
docker run --rm -u root \
    -v "${DATA_ROOT}:/d" \
    alpine sh -c 'chown -R 472:472 /d/grafana && chown -R 10001:10001 /d/loki'

COMPOSE=(docker compose --env-file "$ENV_FILE" -f "${ROOT}/docker-compose.yml")

if [[ -n "$("${COMPOSE[@]}" ps -q php 2>/dev/null)" ]]; then
    echo "→ chown src/storage, src/bootstrap/cache → www-data"
    "${COMPOSE[@]}" exec -T php sh -c \
        'mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions && chown -R www-data:www-data storage bootstrap/cache'
else
    echo "→ php-контейнер не запущен, пропускаю права storage/bootstrap/cache (запустите после 'up -d')"
fi

echo "Done."
