#!/bin/sh
# Готовит TLS-сертификат для nginx при старте контейнера.
#
# Если в docker/ssl есть сертификат проекта (${VSPACE_DOMAIN}.pem/-key.pem,
# выпущенный ./docker/ssl/generate-certs.sh) — используется он. Иначе
# генерируется самоподписанный сертификат, чтобы nginx не падал с
# "cannot load certificate ... no start line" в свежей копии проекта.
set -e

HOST_SSL_DIR=/etc/nginx/ssl-host
SSL_DIR=/etc/nginx/ssl
DOMAIN="${VSPACE_DOMAIN:-vspace.loc}"
GRAFANA_DOMAIN="${GRAFANA_DOMAIN:-grafana.$DOMAIN}"
MAIL_DOMAIN="${MAIL_DOMAIN:-mail.$DOMAIN}"
CERT="$SSL_DIR/site.pem"
KEY="$SSL_DIR/site-key.pem"

mkdir -p "$SSL_DIR"

src_cert="$HOST_SSL_DIR/$DOMAIN.pem"
src_key="$HOST_SSL_DIR/$DOMAIN-key.pem"

if [ -s "$src_cert" ] && [ -s "$src_key" ] \
    && openssl x509 -in "$src_cert" -noout >/dev/null 2>&1; then
    echo "nginx-cert: using project certificate $src_cert"
    cp -f "$src_cert" "$CERT"
    cp -f "$src_key" "$KEY"
else
    echo "nginx-cert: generating self-signed certificate for $DOMAIN"
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
        -keyout "$KEY" -out "$CERT" \
        -subj "/C=RU/ST=Moscow/L=Moscow/O=VSpace Dev/CN=$DOMAIN" \
        -addext "subjectAltName=DNS:$DOMAIN,DNS:$GRAFANA_DOMAIN,DNS:$MAIL_DOMAIN,DNS:localhost,IP:127.0.0.1" \
        >/dev/null 2>&1
fi
