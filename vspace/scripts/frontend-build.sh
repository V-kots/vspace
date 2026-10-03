#!/usr/bin/env bash
# Production-сборка Vite (React) в контейнере Node 22.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT}/.env.docker"
INSTALL=0

usage() {
    cat <<'EOF'
Usage: scripts/frontend-build.sh [options]

  --install     всегда выполнить npm ci перед сборкой
  --env-file F  env-файл Compose (по умолчанию .env.docker)
  -h, --help    эта справка

Примеры:
  ./scripts/frontend-build.sh
  ./scripts/frontend-build.sh --install
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --install)
            INSTALL=1
            shift
            ;;
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

export DOCKER_UID="$(id -u)"
export DOCKER_GID="$(id -g)"

COMPOSE=(
    docker compose --env-file "$ENV_FILE"
    -f "${ROOT}/docker-compose.yml"
    -f "${ROOT}/docker-compose.tools.yml"
    --profile tools
)

cd "$ROOT"

run_node() {
    "${COMPOSE[@]}" run --rm --no-TTY node "$@"
}

needs_ci=0
if [[ "$INSTALL" -eq 1 ]]; then
    needs_ci=1
elif ! run_node sh -c 'test -x node_modules/.bin/vite' 2>/dev/null; then
    needs_ci=1
fi

if [[ "$needs_ci" -eq 1 ]]; then
    echo "→ npm ci (зависимости в Docker-volume, не на хосте)"
    run_node npm ci
fi

echo "→ npm run build"
run_node npm run build

echo "→ chown public/build"
"${COMPOSE[@]}" run --rm --no-TTY --user root node \
    chown -R "${DOCKER_UID}:${DOCKER_GID}" /var/www/html/public/build

echo "Done: ${ROOT}/src/public/build/"
