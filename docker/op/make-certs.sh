#!/usr/bin/env bash
# Self-signed TLS certificate for the local OpenID Provider container.
#
# The name matters: host.docker.internal resolves both from the host browser
# (Docker Desktop writes it into the hosts file) and from inside the relying
# party container, so the OP has one URL that is the same for the person logging
# in and for the server-to-server calls. That is what lets the issuer in an ID
# token match what the RP expects.
#
# The relying party trusts this certificate through the module's
# http_ca_bundle option rather than by turning verification off.
set -eu

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/tls"
mkdir -p "$DIR"

if [ -f "$DIR/op.crt" ] && [ -f "$DIR/op.key" ]; then
    echo "Certificate already exists at $DIR/op.crt; delete it to regenerate."
    openssl x509 -in "$DIR/op.crt" -noout -subject -dates
    exit 0
fi

openssl req -x509 -newkey rsa:2048 -nodes \
    -keyout "$DIR/op.key" -out "$DIR/op.crt" \
    -days 825 -sha256 \
    -subj "/CN=host.docker.internal" \
    -addext "subjectAltName=DNS:host.docker.internal,DNS:localhost,IP:127.0.0.1"

chmod 644 "$DIR/op.crt"
chmod 600 "$DIR/op.key"

echo "Wrote $DIR/op.crt and $DIR/op.key"
openssl x509 -in "$DIR/op.crt" -noout -subject -ext subjectAltName
