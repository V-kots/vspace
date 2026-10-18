#!/usr/bin/env bash
# Экспорт id пользователей из Postgres в loadtest/ (GET и login).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

OUT_DIR="${ROOT}/loadtest"
TARGET_COUNT="${LOADTEST_USER_COUNT:-5000}"
LIMIT="${LOADTEST_LIMIT:-$TARGET_COUNT}"
BASE_URL="${LOADTEST_BASE_URL:-https://vspace.loc}"

# shellcheck source=scripts/loadtest/_lib.sh
source "${ROOT}/scripts/loadtest/_lib.sh"
PASSWORD="$(loadtest_password)"

mkdir -p "$OUT_DIR"

"$(dirname "$0")/ensure-loadtest-users.sh"

LIMIT_SQL=""
if [[ "$LIMIT" =~ ^[0-9]+$ ]] && [[ "$LIMIT" -gt 0 ]]; then
  LIMIT_SQL="LIMIT ${LIMIT}"
fi

IDS_FILE="${OUT_DIR}/user-ids.txt"
CREDS_FILE="${OUT_DIR}/credentials.jsonl"
META_FILE="${OUT_DIR}/meta.env"
POSTMAN_CSV="${OUT_DIR}/meta_postman.csv"

csv_field() {
  local value="$1"
  if [[ "$value" == *','* || "$value" == *'"'* || "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    value="${value//\"/\"\"}"
    printf '"%s"' "$value"
  else
    printf '%s' "$value"
  fi
}

docker compose exec -T postgres psql -U vspace -d vspace -t -A -c \
  "SELECT id FROM users WHERE is_active = true ORDER BY id ${LIMIT_SQL};" \
  | sed '/^$/d' > "$IDS_FILE"

COUNT="$(wc -l < "$IDS_FILE" | tr -d ' ')"
: > "$CREDS_FILE"
echo "baseUrl,userId,password" > "$POSTMAN_CSV"
while IFS= read -r id; do
  printf '{"id":"%s","password":"%s"}\n' "$id" "$PASSWORD" >> "$CREDS_FILE"
  printf '%s,%s,%s\n' \
    "$(csv_field "$BASE_URL")" \
    "$(csv_field "$id")" \
    "$(csv_field "$PASSWORD")" >> "$POSTMAN_CSV"
done < "$IDS_FILE"

cat > "$META_FILE" <<EOF
# Сгенерировано: $(date -Iseconds)
LOADTEST_USER_COUNT=${COUNT}
LOADTEST_USER_TARGET=${TARGET_COUNT}
LOADTEST_PASSWORD=${PASSWORD}
LOADTEST_BASE_URL=${BASE_URL}
EOF

echo "Записей: ${COUNT}"
echo "  ${IDS_FILE}       — GET /user/get/{id}"
echo "  ${CREDS_FILE} — POST /login (нужен общий пароль, см. scripts/loadtest/prepare-loadtest-passwords.sh)"
echo "  ${META_FILE}"
echo "  ${POSTMAN_CSV}  — Postman Collection Runner (baseUrl, userId, password)"
