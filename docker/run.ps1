# Run a SimpleSAMLphp instance with this module installed from the working copy.
# No image build: composer installs the module on container start.
param(
    [string]$SspVersion    = 'v2.5.0',
    [string]$ContainerName = 'ssp-embedded-disco',
    [int]   $HostPort      = 8443
)

$ErrorActionPreference = 'Stop'

# Repository root, regardless of where this script is invoked from.
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

# Nothing to remove on a first run, and "no such container" must not abort.
try { docker rm -f $ContainerName 2>$null | Out-Null } catch {}
$global:LASTEXITCODE = 0

docker run -d --name $ContainerName `
  -v "${root}:/var/simplesamlphp/staging-modules/embeddeddisco:ro" `
  -e STAGINGCOMPOSERREPOS=embeddeddisco `
  -e COMPOSER_REQUIRE="simplesamlphp/simplesamlphp-module-embeddeddisco:@dev" `
  -e SSP_ADMIN_PASSWORD=secret1 `
  -e SSP_ENABLED_MODULES="exampleauth embeddeddisco" `
  -e SSP_LOG_LEVEL=7 `
  -e SSP_SECRET_SALT=testsalt `
  -v "${root}/docker/ssp/authsources.php:/var/simplesamlphp/config/authsources.php:ro" `
  -v "${root}/docker/ssp/config-override.php:/var/simplesamlphp/config/config-override.php:ro" `
  -v "${root}/config-templates/module_embeddeddisco.php:/var/simplesamlphp/config/module_embeddeddisco.php:ro" `
  -p "${HostPort}:443" `
  "cirrusid/simplesamlphp:$SspVersion"

Write-Host @"

Starting. Composer runs on boot, so give it ~40s.

  Admin UI     https://localhost:$HostPort/simplesaml/   (admin / secret1)
  Smoke test   https://localhost:$HostPort/simplesaml/module.php/embeddeddisco/status
  Logs         docker logs -f $ContainerName
  Stop         docker rm -f $ContainerName
"@
