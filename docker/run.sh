#!/usr/bin/env bash
# Run a SimpleSAMLphp instance with this module installed from the working copy.
# No image build: composer installs the module on container start.
set -eu

SSP_VERSION="${SSP_VERSION:-v2.5.0}"
CONTAINER_NAME="${CONTAINER_NAME:-ssp-embedded-disco}"
HOST_PORT="${HOST_PORT:-8443}"

# Repository root, regardless of where this script is invoked from.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Git Bash on Windows rewrites container-side paths unless this is set.
export MSYS_NO_PATHCONV=1

# Docker silently creates a *directory* for any missing bind-mount source, which
# then fails with an opaque runc "not a directory" error. Fail clearly instead.
for f in docker/ssp/authsources.php docker/ssp/config-override.php; do
    if [ ! -f "$ROOT/$f" ]; then
        echo "error: $ROOT/$f is missing or is not a file." >&2
        echo "       Run this script from a complete checkout of the module." >&2
        exit 1
    fi
done

docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true

docker run -d --name "$CONTAINER_NAME" \
  -v "$ROOT":/var/simplesamlphp/staging-modules/embeddeddisco:ro \
  -e STAGINGCOMPOSERREPOS=embeddeddisco \
  -e COMPOSER_REQUIRE="simplesamlphp/simplesamlphp-module-embeddeddisco:@dev" \
  -e SSP_ADMIN_PASSWORD=secret1 \
  -e SSP_ENABLED_MODULES="exampleauth embeddeddisco" \
  -e SSP_LOG_LEVEL=7 \
  -e SSP_SECRET_SALT=testsalt \
  -v "$ROOT/docker/ssp/authsources.php":/var/simplesamlphp/config/authsources.php:ro \
  -v "$ROOT/docker/ssp/config-override.php":/var/simplesamlphp/config/config-override.php:ro \
  -v "$ROOT/config-templates/module_embeddeddisco.php":/var/simplesamlphp/config/module_embeddeddisco.php:ro \
  -p "$HOST_PORT":443 \
  "cirrusid/simplesamlphp:$SSP_VERSION"

cat <<EOF

Starting. Composer runs on boot, so give it ~40s.

  Admin UI     https://localhost:$HOST_PORT/simplesaml/   (admin / secret1)
  Smoke test   https://localhost:$HOST_PORT/simplesaml/module.php/embeddeddisco/status
  Logs         docker logs -f $CONTAINER_NAME
  Stop         docker rm -f $CONTAINER_NAME
EOF
