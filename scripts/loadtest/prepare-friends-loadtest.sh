#!/usr/bin/env bash
# Подготовка нагрузочного сценария «лента» для Postman Performance.
#
# 1. Выбирает 50 пользователей: 2 заданных UUID + 48 случайных из тех, кому
#    миграция 2026_11_21_153124_seed_posts_for_users раздала тестовые посты.
# 2. Из них выбирает 20 случайных и связывает их дружбой между собой
#    (у каждого в группе >= 6 друзей, связи взаимные).
# 3. Выпускает Sanctum-токены (имя friends-loadtest) и пересоздаёт их при повторе.
# 4. Пишет loadtest/friends-postman.csv: baseUrl,userId,userShort,token,runId —
#    ровно одна строка на пользователя. В Postman Performance (VU data mapping =
#    Ordered) одна строка закрепляется за одной VU, поэтому число VU должно
#    совпадать с числом строк. Тексты постов генерируются в pre-request скрипте
#    коллекции (уникальны на каждую итерацию). Состав дружеской группы —
#    loadtest/friends-group.txt.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

BASE_URL="${LOADTEST_BASE_URL:-https://vspace.loc}"
FRIENDS_PER_USER="${FRIENDS_PER_USER:-6}"
FRIENDS_GROUP_SIZE="${FRIENDS_GROUP_SIZE:-20}"
EXTRA_USERS="${FRIENDS_EXTRA_USERS:-48}"
OUT="${ROOT}/loadtest/friends-postman.csv"
GROUP_OUT="${ROOT}/loadtest/friends-group.txt"

REQUIRED_IDS=(
  "0ee50b1e-451d-4734-9508-f97f8745858a"
  "1a740597-87f7-49cf-8fcb-d8114a4a64d8"
)

HAS_TABLE="$(docker compose exec -T postgres psql -U vspace -d vspace -t -A -c \
  "SELECT to_regclass('public.seeded_post_users') IS NOT NULL;" | tr -d '[:space:]')"

if [[ "$HAS_TABLE" != "t" ]]; then
  echo "Нет таблицы seeded_post_users — сначала примените миграцию 2026_11_21_153124_seed_posts_for_users." >&2
  exit 1
fi

IDS_FILE="$(mktemp)"
TOKENS_FILE="$(mktemp)"
GROUP_FILE="$(mktemp)"
trap 'rm -f "$IDS_FILE" "$TOKENS_FILE" "$GROUP_FILE"' EXIT

docker compose exec -T postgres psql -U vspace -d vspace -t -A -c "
WITH fixed AS (
    SELECT id FROM unnest(ARRAY['${REQUIRED_IDS[0]}','${REQUIRED_IDS[1]}']::uuid[]) AS id
),
extra AS (
    SELECT user_id AS id
    FROM seeded_post_users
    WHERE user_id NOT IN ('${REQUIRED_IDS[0]}','${REQUIRED_IDS[1]}')
    ORDER BY random()
    LIMIT ${EXTRA_USERS}
)
SELECT id FROM fixed
UNION ALL
SELECT id FROM extra;
" | sed '/^$/d' > "$IDS_FILE"

EXPECTED_USERS=$(( ${#REQUIRED_IDS[@]} + EXTRA_USERS ))
USER_COUNT="$(wc -l < "$IDS_FILE" | tr -d ' ')"

if [[ "$USER_COUNT" -ne "$EXPECTED_USERS" ]]; then
  echo "Ожидалось ${EXPECTED_USERS} пользователей, получено ${USER_COUNT}." >&2
  exit 1
fi

mapfile -t IDS < "$IDS_FILE"
SQL_IDS="$(printf "'%s'," "${IDS[@]}")"
ARRAY_SQL="ARRAY[${SQL_IDS%,}]::uuid[]"
IDS_CSV="$(paste -sd, "$IDS_FILE")"

# 20 случайных пользователей из 50 — дружеская группа.
docker compose exec -T postgres psql -U vspace -d vspace -t -A -c "
SELECT id
FROM unnest(${ARRAY_SQL}) AS id
ORDER BY random()
LIMIT ${FRIENDS_GROUP_SIZE};
" | sed '/^$/d' > "$GROUP_FILE"

GROUP_COUNT="$(wc -l < "$GROUP_FILE" | tr -d ' ')"

if [[ "$GROUP_COUNT" -ne "$FRIENDS_GROUP_SIZE" ]]; then
  echo "Ожидалось ${FRIENDS_GROUP_SIZE} участников группы, получено ${GROUP_COUNT}." >&2
  exit 1
fi

mapfile -t GROUP_IDS < "$GROUP_FILE"
SQL_GROUP_IDS="$(printf "'%s'," "${GROUP_IDS[@]}")"
GROUP_ARRAY_SQL="ARRAY[${SQL_GROUP_IDS%,}]::uuid[]"

docker compose exec -T postgres psql -U vspace -d vspace -c "
WITH selected AS (
    SELECT unnest(${GROUP_ARRAY_SQL}) AS id
),
pairs AS (
    SELECT a.id AS user_id, b.id AS friend_user_id,
           row_number() OVER (PARTITION BY a.id ORDER BY random()) AS rn
    FROM selected a
    JOIN selected b ON b.id <> a.id
)
INSERT INTO friendships (user_id, friend_user_id, created_at)
SELECT LEAST(user_id, friend_user_id), GREATEST(user_id, friend_user_id), now()
FROM pairs
WHERE rn <= ${FRIENDS_PER_USER}
ON CONFLICT DO NOTHING;
" > /dev/null

docker compose exec -T postgres psql -U vspace -d vspace -c \
  "DELETE FROM personal_access_tokens WHERE name = 'friends-loadtest';" > /dev/null

docker compose exec -T -e IDS="$IDS_CSV" php php artisan tinker --execute '
foreach (explode(",", (string) getenv("IDS")) as $id) {
    $user = App\Models\User::find($id);

    if ($user === null) {
        continue;
    }

    $user->tokens()->where("name", "friends-loadtest")->delete();
    echo "TOKEN|".$id."|".$user->createToken("friends-loadtest")->plainTextToken.PHP_EOL;
}' 2> /dev/null | grep '^TOKEN|' | sed 's/^TOKEN|//' > "$TOKENS_FILE"

TOKEN_COUNT="$(wc -l < "$TOKENS_FILE" | tr -d ' ')"

if [[ "$TOKEN_COUNT" -ne "$USER_COUNT" ]]; then
  echo "Токенов получено ${TOKEN_COUNT} из ${USER_COUNT}. Проверьте, что контейнер php запущен." >&2
  exit 1
fi

RUN_ID="$(date +%s)"

{
  echo "baseUrl,userId,userShort,token,runId"
  while IFS='|' read -r user_id token; do
    short="${user_id:0:8}"
    printf '%s,%s,%s,%s,%s\n' "$BASE_URL" "$user_id" "$short" "$token" "$RUN_ID"
  done < "$TOKENS_FILE"
} > "$OUT"

cp "$GROUP_FILE" "$GROUP_OUT"

echo "Пользователей: ${USER_COUNT} (2 заданных + ${EXTRA_USERS} случайных, все с тестовыми постами)"
echo "Дружеская группа: ${FRIENDS_GROUP_SIZE} случайных, у каждого >= ${FRIENDS_PER_USER} друзей внутри группы"
echo "Строк в CSV: ${USER_COUNT} — одна строка на пользователя (VU в Performance = ${USER_COUNT})"
echo "  ${OUT}"
echo "  ${GROUP_OUT} — состав дружеской группы"
