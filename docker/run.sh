#!/usr/bin/env bash
# Start the complete local OpenID Federation environment.
set -eu

SSP_VERSION="${SSP_VERSION:-v2.5.0}"
export SSP_VERSION

# Repository root, regardless of where this script is invoked from.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

docker compose -f "$ROOT/docker/docker-compose.yml" up --build -d

cat <<EOF

The local federation is starting. The first build can take a few minutes.

  RP login      https://localhost:8443/simplesaml/
  OP1           https://host.docker.internal:8444/simplesaml/
  OP2           https://host.docker.internal:8445/simplesaml/
  Trust Anchor  https://host.docker.internal:8446/simplesaml/module.php/embeddeddisco/federation/.well-known/openid-federation
  Logs          docker compose -f docker/docker-compose.yml logs -f
  Stop          docker compose -f docker/docker-compose.yml down
EOF
