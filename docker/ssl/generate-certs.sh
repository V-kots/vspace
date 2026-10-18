#!/usr/bin/env bash
# Локальный бесплатный SSL для vspace.loc (CA + сертификат, аналог mkcert).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSL_DIR="$SCRIPT_DIR"

CA_KEY="$SSL_DIR/ca-key.pem"
CA_CERT="$SSL_DIR/ca.pem"
CA_SERIAL="$SSL_DIR/ca.srl"
CERT_KEY="$SSL_DIR/vspace.loc-key.pem"
CERT_CSR="$SSL_DIR/vspace.loc.csr"
CERT_EXT="$SSL_DIR/vspace.loc.ext"
CERT_LEAF="$SSL_DIR/vspace.loc-leaf.pem"
CERT_FILE="$SSL_DIR/vspace.loc.pem"

DOMAINS=(
    "vspace.loc"
    "grafana.vspace.loc"
    "*.vspace.loc"
)

ensure_ca() {
    if [[ -f "$CA_KEY" && -f "$CA_CERT" ]]; then
        return 0
    fi

    echo "Creating local CA..."
    openssl genrsa -out "$CA_KEY" 4096
    openssl req -new -x509 -days 3650 -key "$CA_KEY" \
        -out "$CA_CERT" \
        -subj "/C=RU/ST=Moscow/L=Moscow/O=VSpace Dev/CN=VSpace Dev CA"
    echo "CA created: $CA_CERT"
}

build_extensions() {
    cat > "$CERT_EXT" <<'EOF'
authorityKeyIdentifier=keyid,issuer
basicConstraints=CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
EOF

    local idx=1
    local domain
    for domain in "${DOMAINS[@]}"; do
        printf 'DNS.%s = %s\n' "$idx" "$domain" >> "$CERT_EXT"
        idx=$((idx + 1))
    done
}

generate_certificate() {
    echo "Generating certificate for:"
    printf '  - %s\n' "${DOMAINS[@]}"

    build_extensions
    openssl genrsa -out "$CERT_KEY" 2048
    openssl req -new -key "$CERT_KEY" \
        -out "$CERT_CSR" \
        -subj "/C=RU/ST=Moscow/L=Moscow/O=VSpace/CN=vspace.loc"

    openssl x509 -req -in "$CERT_CSR" \
        -CA "$CA_CERT" \
        -CAkey "$CA_KEY" \
        -CAcreateserial \
        -CAserial "$CA_SERIAL" \
        -out "$CERT_LEAF" \
        -days 825 \
        -extfile "$CERT_EXT"

    cat "$CERT_LEAF" "$CA_CERT" > "$CERT_FILE"
    rm -f "$CERT_LEAF" "$CERT_CSR" "$CERT_EXT"

    chmod 600 "$CA_KEY" "$CERT_KEY"
    chmod 644 "$CA_CERT" "$CERT_FILE"

    echo "Certificate: $CERT_FILE"
    echo "Private key: $CERT_KEY"
}

install_ca_linux() {
    if [[ "$(uname -s)" != "Linux" ]]; then
        return 0
    fi

    local dest="/usr/local/share/ca-certificates/vspace-dev-ca.crt"
    if sudo cp "$CA_CERT" "$dest" 2>/dev/null; then
        sudo update-ca-certificates 2>/dev/null || true
        echo "CA installed to system trust store."
    else
        echo ""
        echo "Install CA manually (browser will trust HTTPS without warnings):"
        echo "  sudo cp \"$CA_CERT\" $dest"
        echo "  sudo update-ca-certificates"
        echo ""
        echo "Firefox: Settings -> Privacy -> Certificates -> View Certificates -> Authorities -> Import -> $CA_CERT"
    fi
}

ensure_ca
generate_certificate
install_ca_linux

echo "Done."
