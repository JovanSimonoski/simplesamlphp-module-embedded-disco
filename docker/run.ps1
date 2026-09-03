# Start the complete local OpenID Federation environment.
param(
    [string]$SspVersion = 'v2.5.0'
)

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$env:SSP_VERSION = $SspVersion

docker compose -f (Join-Path $root 'docker/docker-compose.yml') up --build -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host @"

The local federation is starting. The first build can take a few minutes.

  RP login      https://localhost:8443/simplesaml/
  OP1           https://host.docker.internal:8444/simplesaml/
  OP2           https://host.docker.internal:8445/simplesaml/
  Trust Anchor  https://host.docker.internal:8446/simplesaml/module.php/embeddeddisco/federation/.well-known/openid-federation
  Logs          docker compose -f docker/docker-compose.yml logs -f
  Stop          docker compose -f docker/docker-compose.yml down
"@
