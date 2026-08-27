#!/bin/bash
#
# Runs as root inside the OP container, after composer has installed the oidc
# module and before Apache starts. The image calls it if it is mounted at
# /opt/simplesaml/run-on-start.sh.
#
# Idempotent: everything here checks before it acts, because the image runs this
# on every container start, not just the first.
set -eu

SSP_DIR=/var/simplesamlphp
CERT_DIR="$SSP_DIR/cert"
DATA_DIR="$SSP_DIR/data"

echo "[op] preparing OpenID Provider"

mkdir -p "$DATA_DIR" "$CERT_DIR"

# Signing keys for issued tokens, and the separate key the module signs its
# federation Entity Configuration with.
if [ ! -f "$CERT_DIR/oidc_module.key" ]; then
    echo "[op] generating OIDC signing key"
    openssl genrsa -out "$CERT_DIR/oidc_module.key" 3072 2>/dev/null
    openssl rsa -in "$CERT_DIR/oidc_module.key" -pubout -out "$CERT_DIR/oidc_module.crt" 2>/dev/null
fi

if [ ! -f "$CERT_DIR/oidc_module_federation.key" ]; then
    echo "[op] generating OIDC federation key"
    openssl genrsa -out "$CERT_DIR/oidc_module_federation.key" 3072 2>/dev/null
    openssl rsa -in "$CERT_DIR/oidc_module_federation.key" -pubout \
        -out "$CERT_DIR/oidc_module_federation.crt" 2>/dev/null
fi

chown -R www-data:www-data "$CERT_DIR" "$DATA_DIR"
chmod 640 "$CERT_DIR"/oidc_module*.key

# Database schema for clients, tokens and grants.
if [ -f "$SSP_DIR/modules/oidc/bin/install.php" ]; then
    echo "[op] running OIDC database migrations"
    su www-data -s /bin/bash -c "php $SSP_DIR/modules/oidc/bin/install.php" || {
        echo "[op] migrations reported a problem; continuing so the container still starts"
    }
fi

# Register the relying party, unless it is already there. Done with SQL because
# the module offers client administration through its admin UI only, and a demo
# environment should come up without anyone clicking through a form first.
if [ -n "${OP_CLIENT_ID:-}" ] && [ -f "$DATA_DIR/oidc.sqlite" ]; then
    echo "[op] ensuring relying party client ${OP_CLIENT_ID} is registered"
    su www-data -s /bin/bash -c "php /opt/simplesaml/register-client.php" || {
        echo "[op] client registration failed"
    }
fi

echo "[op] ready"
