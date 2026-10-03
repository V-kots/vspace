#!/usr/bin/env bash
# Один общий пароль для всех users — только для локального нагрузочного теста login.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PASSWORD="${LOADTEST_PASSWORD:-Secret123!}"

if [[ "${1:-}" != "--yes" ]]; then
  echo "Будет обновлён password у всех строк в users (bcrypt)."
  echo "Пароль: ${PASSWORD}"
  echo "Запуск: $0 --yes"
  exit 1
fi

HASH="$(docker compose exec -T -e P="$PASSWORD" php php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT, ["cost" => 12]);')"
ESCAPED_HASH="$(printf '%s' "$HASH" | sed "s/'/''/g")"

UPDATED="$(docker compose exec -T postgres psql -U vspace -d vspace -t -A -c "UPDATE users SET password = '${ESCAPED_HASH}' RETURNING id;" | wc -l)"

echo "Обновлено пользователей: ${UPDATED}"
echo "Пароль для login: ${PASSWORD}"
