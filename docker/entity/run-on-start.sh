#!/bin/bash
# Generate persistent keys for an embeddeddisco federation entity before Apache starts.
set -eu

CERT_DIR=/var/simplesamlphp/cert
mkdir -p "$CERT_DIR"

if [ -f /usr/local/share/ca-certificates/local-federation-ca.crt ]; then
    update-ca-certificates >/dev/null
fi

generate_key() {
    name="$1"
    if [ ! -f "$CERT_DIR/$name.key" ]; then
        echo "[embeddeddisco] generating $name signing key"
        openssl genrsa -out "$CERT_DIR/$name.key" 3072 2>/dev/null
    fi

    chown www-data:www-data "$CERT_DIR/$name.key"
    chmod 640 "$CERT_DIR/$name.key"
}

generate_key embeddeddisco_federation

if [ "${EMBEDDED_DISCO_ENTITY_ROLE:-}" = "openid_relying_party" ]; then
    generate_key embeddeddisco_protocol
fi

echo "[embeddeddisco] federation entity keys ready"
