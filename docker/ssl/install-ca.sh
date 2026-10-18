#!/usr/bin/env bash
# Установка локального CA — доверие в curl, Chrome и Firefox
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CA_FILE="$SCRIPT_DIR/ca.pem"
NSSDB="${HOME}/.pki/nssdb"
CA_NICKNAME="VSpace Dev CA"

if [[ ! -f "$CA_FILE" ]]; then
    echo "CA not found. Run ./generate-certs.sh first."
    exit 1
fi

ensure_certutil() {
    if command -v certutil >/dev/null 2>&1; then
        return 0
    fi
    echo "Installing libnss3-tools..."
    sudo apt-get install -y libnss3-tools
}

import_nss_ca() {
    local db_path="$1"
    local label="$2"

    mkdir -p "$(dirname "$db_path")"
    certutil -d "sql:${db_path}" -D -n "$CA_NICKNAME" 2>/dev/null || true
    certutil -d "sql:${db_path}" -A -t "C,," -n "$CA_NICKNAME" -i "$CA_FILE"
    echo "$label: OK"
}

echo "=== 1/3 System trust store (curl, wget, etc.) ==="
if [[ "$(uname -s)" == "Linux" ]]; then
    sudo cp "$CA_FILE" /usr/local/share/ca-certificates/vspace-dev-ca.crt
    sudo update-ca-certificates --fresh 2>/dev/null || sudo update-ca-certificates
    echo "System CA installed."
elif [[ "$(uname -s)" == "Darwin" ]]; then
    sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain "$CA_FILE"
    echo "macOS keychain updated."
fi

echo ""
echo "=== 2/3 Chrome / Chromium NSS store ==="
ensure_certutil
import_nss_ca "$NSSDB" "Chrome NSS"

echo ""
echo "=== 3/3 Firefox profiles ==="
firefox_installed=false
shopt -s nullglob
for profile_dir in "${HOME}/.mozilla/firefox"/*.default*; do
    if [[ -d "$profile_dir" ]]; then
        firefox_installed=true
        import_nss_ca "$profile_dir" "Firefox profile $(basename "$profile_dir")"
    fi
done
shopt -u nullglob

if [[ "$firefox_installed" == false ]]; then
    echo "Firefox profiles not found in ~/.mozilla/firefox/"
    echo "Import manually: Settings -> Privacy -> Certificates -> Authorities -> Import"
    echo "  File: $CA_FILE"
    echo "  Enable: \"Trust this CA to identify websites\""
    echo ""
    echo "Or enable system CAs in Firefox: about:config -> security.enterprise_roots.enabled = true"
fi

echo ""
echo "=== Verify ==="
if curl --silent --fail --resolve vspace.loc:443:127.0.0.1 https://vspace.loc/ >/dev/null 2>&1; then
    echo "curl + system CA: OK"
else
    echo "curl + system CA: FAILED (is vspace-nginx running on :443?)"
fi

certutil -d "sql:${NSSDB}" -L 2>/dev/null | grep -i "$CA_NICKNAME" && echo "Chrome NSS: OK"

echo ""
echo "=== Done ==="
echo "1. Close ALL browser windows completely"
echo "2. Open https://mail.vspace.loc (or https://vspace.loc)"
echo ""
echo "Firefox still warns?"
echo "  - Re-run: ./docker/ssl/install-ca.sh"
echo "  - Or import manually: $CA_FILE -> Authorities -> trust websites"
echo "  - Or about:config -> security.enterprise_roots.enabled = true"
