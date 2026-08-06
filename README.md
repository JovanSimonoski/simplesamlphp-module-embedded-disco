# simplesamlphp-module-embedded-disco

Embedded discovery for a SimpleSAMLphp relying party, built on
[simplesamlphp/openid](https://github.com/simplesamlphp/openid) OpenID Federation
discovery (trust-anchor driven).

## Naming

Three names are involved, and they deliberately differ:

| | Value |
| --- | --- |
| Repository / directory | `simplesamlphp-module-embedded-disco` |
| Composer package | `simplesamlphp/simplesamlphp-module-embeddeddisco` |
| Module name, namespace, URL segment | `embeddeddisco` |

SimpleSAMLphp derives the module directory from the package name and then builds
the PHP namespace as `SimpleSAML\Module\<module>\`. A hyphen there would be an
invalid PHP identifier, so the package and module name have to be unhyphenated
even though the repository is not.

## Requirements

* PHP 8.3+
* SimpleSAMLphp 2.5+
* `simplesamlphp/openid` ^0.5

## Status: proof of concept

The picker works end to end against a **mock federation**. Discovery runs through
the real library pipeline, but the entity data is seeded rather than fetched, and
selecting a provider does not yet start an authentication request.

| Endpoint | Purpose |
| --- | --- |
| `module.php/embeddeddisco/disco` | The embedded picker (search, filter, sort, paginate) |
| `module.php/embeddeddisco/entities` | Same result set as an OpenID Federation entity collection response |
| `module.php/embeddeddisco/select` | Selection hand-off (currently a stub) |
| `module.php/embeddeddisco/status` | Wiring smoke test |

## How `simplesamlphp/openid` is used

No discovery, filtering, sorting, pagination or serialization logic is
reimplemented here. The module supplies configuration, mock data, a controller
and a template; every operation below is the library's own code.

### Where each piece lives

| File | Library surface it touches |
| --- | --- |
| `src/Federation/FederationFactory.php` | Constructs the `Federation` facade |
| `src/Federation/DiscoveryService.php` | Runs the discovery pipeline |
| `src/Federation/DiscoveryQuery.php` | Builds the library's filter criteria array |
| `src/Federation/MockEntityCollectionStore.php` | Extends `InMemoryEntityCollectionStore` |
| `src/Federation/MockFederation.php` | Emits payloads keyed by `ClaimsEnum` / `EntityTypesEnum` |

### 1. Building the facade

`SimpleSAML\OpenID\Federation` is the library's single composition root. It
lazily constructs the discovery, filter, sorter, paginator, fetchers and factories
internally, so nothing here instantiates them by hand:

```php
new Federation(
    cache: new Psr16Cache(new FilesystemAdapter(...)), // PSR-16
    logger: new SimpleSAML\Compat\Logger(),            // PSR-3
    maxDiscoveryDepth: 10,
    entityCollectionStore: $mockStore,                 // or null for live
);
```

Both infrastructure dependencies come from SimpleSAMLphp itself: the PSR-16 cache
is Symfony's cache component (already an SSP dependency), and the PSR-3 logger is
SSP's own `Compat\Logger`, so the library's log lines land in the SSP log next to
everything else.

### 2. The pipeline

The library documents a **discover → filter → sort → paginate → serialize**
pipeline for implementing a `federation_collection_endpoint`. `DiscoveryService`
runs exactly that, so the picker and a future endpoint cannot drift apart:

```php
$collection = $federation->federationDiscovery()->discover($trustAnchorId);
$collection->filter($criteria);          // entity_type / trust_mark_type / query
$collection->sort($claimPaths, $order);  // nested claim paths
$collection->paginate($limit, $from);    // opaque cursor
$collection->toCollectionEndpointResponseArray();
```

**`discover()`** consults the entity collection store first and only traverses
the federation over the network when the store is empty. On a live traversal it
fetches the Trust Anchor's Entity Configuration, follows `federation_list_endpoint`
links recursively, tracks visited IDs for loop protection and deduplicates.

**`filter()`** implements the spec's own criteria, with semantics worth knowing:

| Criterion | Logic | Fields searched |
| --- | --- | --- |
| `entity_type` | OR — any match wins | `metadata` keys |
| `trust_mark_type` | AND — all must be present | `trust_marks[].trust_mark_type` |
| `query` | case-insensitive substring | `sub`, `display_name`, `organization_name` |

Note `query` does **not** search `description` or `keywords`. Searching for
"research" matches a provider named "MARnet National Research Network" but not one
that only mentions research in its description.

**`sort()`** takes claim paths relative to the JWT payload root, so metadata
claims need the `metadata` prefix. The module passes a fallback chain, ending at
the entity ID so ordering stays total even for entities with no display name:

```php
[['metadata', 'openid_provider', 'display_name'],
 ['metadata', 'federation_entity', 'display_name'],
 ['sub']]
```

**`paginate()`** uses opaque base64url cursors — the encoded entity ID to start
*after*. There is deliberately no backward cursor, which is why the UI offers
"Next page" and "Reset" rather than previous/next.

**`toCollectionEndpointResponseArray()`** produces the spec-shaped response
(`entities[]` with `entity_id`, `entity_types`, `ui_infos`, `trust_marks`, plus
`next` and `last_updated`). The module renders the template from *this*, not from
raw payloads, so the UI consumes precisely what a remote collection endpoint
would return.

### 3. Claim vocabulary

Claim and entity-type names come from `ClaimsEnum` and `EntityTypesEnum` rather
than string literals — including inside the mock fixture, so the fixture cannot
drift from what the filter and sorter actually read. Nested claim access uses the
library's `Helpers::arr()->getNestedValue()`.

### 4. Mock vs. live — the store seam

`FederationDiscovery::discover()` reads `EntityCollectionStoreInterface` before
doing any network work. `MockEntityCollectionStore` extends the library's own
`InMemoryEntityCollectionStore` and seeds it in its constructor, so the real
discovery code path returns fixture data and the HTTP traversal is simply never
reached.

Set `use_mock_data => false` in `config/module_embeddeddisco.php` and the facade
builds its own `CacheEntityCollectionStore` on top of the PSR-16 cache instead;
discovery then traverses a live federation from the configured Trust Anchor.
Nothing else in the module changes.

### Not used yet

Deliberately unused while the POC runs on mock data, and needed for live
operation:

* `EntityStatementFetcher` / `TrustChainResolver` — signature verification and
  trust chain resolution. Mock mode short-circuits before any statement is fetched.
* `TrustMarkValidator` — the fixture's `trust_marks` carry only
  `trust_mark_type`, which is all `filter()` reads. Real Trust Marks are signed
  JWTs and must be validated before being shown as a badge.
* `fetchFromCollectionEndpoint()` — pulling from a remote
  `federation_collection_endpoint` instead of traversing. Much cheaper where the
  federation offers one, and `DiscoveryQuery` already uses the matching parameter
  names so it can be swapped in without translation.
* `discoverEntityIds()` — the IDs-only convenience call.

## Development environment

Two ways to run it, both installing this module from the working copy so edits
on the host take effect without rebuilding. Run either from the repository root.

### Option A — plain `docker run` (no build)

Pulls the stock SimpleSAMLphp image and installs the module with composer on
every container start (~40s). Nothing to rebuild when `composer.json` changes.

PowerShell:

```powershell
docker run -d --name ssp-embedded-disco `
  -v "${PWD}:/var/simplesamlphp/staging-modules/embeddeddisco:ro" `
  -e STAGINGCOMPOSERREPOS=embeddeddisco `
  -e COMPOSER_REQUIRE="simplesamlphp/simplesamlphp-module-embeddeddisco:@dev" `
  -e SSP_ADMIN_PASSWORD=secret1 `
  -e SSP_ENABLED_MODULES="exampleauth embeddeddisco" `
  -e SSP_LOG_LEVEL=7 `
  -e SSP_SECRET_SALT=testsalt `
  -v "${PWD}/docker/ssp/authsources.php:/var/simplesamlphp/config/authsources.php:ro" `
  -v "${PWD}/docker/ssp/config-override.php:/var/simplesamlphp/config/config-override.php:ro" `
  -p 8443:443 `
  cirrusid/simplesamlphp:v2.5.0
```

bash (on Git Bash for Windows, prefix with `MSYS_NO_PATHCONV=1`):

```bash
docker run -d --name ssp-embedded-disco \
  -v "$(pwd)":/var/simplesamlphp/staging-modules/embeddeddisco:ro \
  -e STAGINGCOMPOSERREPOS=embeddeddisco \
  -e COMPOSER_REQUIRE="simplesamlphp/simplesamlphp-module-embeddeddisco:@dev" \
  -e SSP_ADMIN_PASSWORD=secret1 \
  -e SSP_ENABLED_MODULES="exampleauth embeddeddisco" \
  -e SSP_LOG_LEVEL=7 \
  -e SSP_SECRET_SALT=testsalt \
  -v "$(pwd)/docker/ssp/authsources.php":/var/simplesamlphp/config/authsources.php:ro \
  -v "$(pwd)/docker/ssp/config-override.php":/var/simplesamlphp/config/config-override.php:ro \
  -p 8443:443 \
  cirrusid/simplesamlphp:v2.5.0
```

Or use the wrapper scripts, which also remove any previous container:
`./docker/run.sh` / `.\docker\run.ps1`.

Stop and remove with `docker rm -f ssp-embedded-disco`; follow logs with
`docker logs -f ssp-embedded-disco`.

### Option B — Docker Compose (build cached)

Bakes the composer install into an image layer, so restarts are fast.

```bash
docker compose -f docker/docker-compose.yml up --build -d
```

Rebuild only when `composer.json` changes; otherwise `up -d` is enough. Follow
logs with `docker compose -f docker/docker-compose.yml logs -f ssp`.

### Either way

Browse to <https://localhost:8443/simplesaml/>. The base image ships a
self-signed development certificate, so expect a browser warning.

| What | Where |
| --- | --- |
| **Embedded discovery** | <https://localhost:8443/simplesaml/module.php/embeddeddisco/disco> |
| Entity collection JSON | <https://localhost:8443/simplesaml/module.php/embeddeddisco/entities> |
| SSP admin UI | <https://localhost:8443/simplesaml/> — user `admin`, password `secret1` |
| Module smoke test | <https://localhost:8443/simplesaml/module.php/embeddeddisco/status> |
| Test auth source | `example-userpass`, e.g. `student` / `studentpass` |

### Layout

* `docker/run.sh`, `docker/run.ps1` — option A wrappers (no build).
* `docker/Dockerfile` — SSP base image plus this module, installed via a composer
  path repository at `/var/simplesamlphp/staging-modules/embeddeddisco`.
* `docker/docker-compose.yml` — single `ssp` service, host port `8443` → 443.
* `docker/ssp/config-override.php` — appended to the container's SSP config.
* `docker/ssp/authsources.php` — example auth sources for testing.
* `config-templates/module_embeddeddisco.php` — module configuration, mounted
  into the container as `config/module_embeddeddisco.php`.