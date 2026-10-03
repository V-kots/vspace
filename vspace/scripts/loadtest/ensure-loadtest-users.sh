#!/usr/bin/env bash
# Добивает users до LOADTEST_USER_COUNT активных записей (для нагрузочных файлов).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

TARGET="${LOADTEST_USER_COUNT:-5000}"
PASSWORD="${LOADTEST_PASSWORD:-Secret123!}"

if [[ ! "$TARGET" =~ ^[0-9]+$ ]] || [[ "$TARGET" -lt 1 ]]; then
  echo "LOADTEST_USER_COUNT должен быть положительным числом, сейчас: ${TARGET}" >&2
  exit 1
fi

CURRENT="$(docker compose exec -T postgres psql -U vspace -d vspace -t -A -c \
  "SELECT COUNT(*)::int FROM users WHERE is_active = true;")"

NEED=$((TARGET - CURRENT))
if [[ "$NEED" -le 0 ]]; then
  echo "Пользователей в users (active): ${CURRENT}, цель ${TARGET} — вставка не нужна."
  exit 0
fi

echo "Пользователей сейчас: ${CURRENT}, добавляем: ${NEED} (цель ${TARGET})…"

HASH="$(docker compose exec -T -e P="$PASSWORD" php php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT, ["cost" => 12]);')"
ESCAPED_HASH="$(printf '%s' "$HASH" | sed "s/'/''/g")"

INSERTED="$(docker compose exec -T postgres psql -U vspace -d vspace -t -A -c "
INSERT INTO users (
    id,
    first_name,
    second_name,
    birthdate,
    email,
    gender,
    biography,
    city,
    password,
    is_active,
    created_at
)
SELECT
    gen_random_uuid(),
    'Load',
    'Test',
    DATE '1995-05-12',
    'loadtest-' || gen_random_uuid()::text || '@vspace.loc',
    'male',
    '',
    'Loadtest',
    '${ESCAPED_HASH}',
    true,
    NOW()
FROM generate_series(1, ${NEED})
RETURNING id;
" | wc -l)"

echo "Добавлено пользователей: ${INSERTED} (пароль: ${PASSWORD})"
