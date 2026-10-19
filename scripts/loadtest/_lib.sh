#!/usr/bin/env bash
# Общий резолвер пароля нагрузочного теста.
#
# Пароль НЕ хранится в репозитории. Берётся из первого доступного источника:
#   1) переменная окружения LOADTEST_PASSWORD;
#   2) gitignored-файл loadtest/meta.env (строка LOADTEST_PASSWORD=...).
# Если пароль не задан — скрипт завершается с инструкцией.

loadtest_password() {
    if [[ -n "${LOADTEST_PASSWORD:-}" ]]; then
        printf '%s' "$LOADTEST_PASSWORD"
        return 0
    fi

    local meta="${ROOT:-.}/loadtest/meta.env"
    if [[ -f "$meta" ]]; then
        local value
        value="$(sed -n 's/^LOADTEST_PASSWORD=//p' "$meta" | tail -n1)"
        if [[ -n "$value" ]]; then
            printf '%s' "$value"
            return 0
        fi
    fi

    cat >&2 <<'EOF'
LOADTEST_PASSWORD не задан.
Пароль нагрузочного теста не хранится в репозитории — задайте его локально, например:

  export LOADTEST_PASSWORD='<локальный-пароль>'

или создайте файл loadtest/meta.env (он в .gitignore) со строкой:

  LOADTEST_PASSWORD=<локальный-пароль>
EOF
    return 1
}
