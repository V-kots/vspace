#!/usr/bin/env bash
# Случайные пользователи + их имена/фамилии для папки «Нагрузочный тест: нагрузка».
# Пишет CSV для newman --iteration-data: baseUrl,userId,password,firstName,lastName.
#
# Newman не зацикливает iteration data: если строк меньше, чем --iteration-count,
# последняя строка повторяется. Поэтому строк по умолчанию 500 — по числу
# итераций из README; уникальных пользователей при этом тоже 500.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

SAMPLE="${LOADTEST_USER_SAMPLE:-500}"
ITERATIONS="${LOADTEST_ITERATIONS:-500}"
BASE_URL="${LOADTEST_BASE_URL:-https://vspace.loc}"
OUT_FILE="${ROOT}/loadtest/postman-users.csv"

# shellcheck source=scripts/loadtest/_lib.sh
source "${ROOT}/scripts/loadtest/_lib.sh"
PASSWORD="$(loadtest_password)"

if ! [[ "$SAMPLE" =~ ^[0-9]+$ ]] || [[ "$SAMPLE" -lt 1 ]]; then
    echo "LOADTEST_USER_SAMPLE должен быть положительным числом, сейчас: ${SAMPLE}" >&2
    exit 1
fi

if ! [[ "$ITERATIONS" =~ ^[0-9]+$ ]] || [[ "$ITERATIONS" -lt 1 ]]; then
    echo "LOADTEST_ITERATIONS должен быть положительным числом, сейчас: ${ITERATIONS}" >&2
    exit 1
fi

csv_field() {
    local value="$1"
    if [[ "$value" == *','* || "$value" == *'"'* || "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
        value="${value//\"/\"\"}"
        printf '"%s"' "$value"
    else
        printf '%s' "$value"
    fi
}

ROWS="$(docker compose exec -T postgres psql -U vspace -d vspace -t -A -F $'\t' -c \
    "SELECT id, first_name, second_name
       FROM users
      WHERE is_active = true
      ORDER BY random()
      LIMIT ${SAMPLE};")"

mapfile -t USERS <<<"$ROWS"

if [[ ${#USERS[@]} -eq 0 ]]; then
    echo "PostgreSQL не вернул пользователей для выборки" >&2
    exit 1
fi

# Первые min(SAMPLE, ITERATIONS) строк — уникальные пользователи в случайном
# порядке; если строк больше выборки, остальные добираются случайно.
mapfile -t SHUFFLED < <(printf '%s\n' "${USERS[@]}" | shuf)

{
    echo "baseUrl,userId,password,firstName,lastName"
    for ((i = 0; i < ITERATIONS; i++)); do
        if ((i < ${#SHUFFLED[@]})); then
            line="${SHUFFLED[i]}"
        else
            line="${SHUFFLED[RANDOM % ${#SHUFFLED[@]}]}"
        fi

        IFS=$'\t' read -r id first last <<<"$line"
        [[ -z "$id" ]] && continue
        printf '%s,%s,%s,%s,%s\n' \
            "$(csv_field "$BASE_URL")" \
            "$(csv_field "$id")" \
            "$(csv_field "$PASSWORD")" \
            "$(csv_field "$first")" \
            "$(csv_field "$last")"
    done
} >"$OUT_FILE"

echo "Уникальных пользователей: ${#USERS[@]}, строк: ${ITERATIONS} → ${OUT_FILE}"
echo "Колонки: baseUrl,userId,password,firstName,lastName"
