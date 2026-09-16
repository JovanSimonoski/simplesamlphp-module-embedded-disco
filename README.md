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
* Outbound HTTPS from the SimpleSAMLphp host to the federation's entities

## Status

The picker runs against a **live federation** and **logs people in**. It fetches
the Trust Anchor's Entity Configuration, walks the federation, and renders what
it finds. Picking a provider resolves a Trust Chain for it, and — inside a login
— redirects to that provider's authorization endpoint, handles the callback,
validates the ID token against the keys the federation published, and completes
the SimpleSAMLphp session.

| Endpoint | Purpose |
| --- | --- |
| `module.php/embeddeddisco/disco` | The embedded picker (search, sort, paginate) |
| `module.php/embeddeddisco/entities` | Same result set as an OpenID Federation entity collection response |
| `module.php/embeddeddisco/select` | Trust Chain verification, then the hand-off to the provider |
| `module.php/embeddeddisco/callback` | Where the provider returns the user; token exchange and ID token validation |
| `module.php/embeddeddisco/status` | Wiring smoke test, including whether the Trust Anchor is reachable |
| `module.php/embeddeddisco/federation/.well-known/openid-federation` | This RP or local authority's signed Entity Configuration |
| `module.php/embeddeddisco/federation/list` | Local Trust Anchor subordinate listing |
| `module.php/embeddeddisco/federation/fetch?sub=…` | Local Trust Anchor Subordinate Statement issuance |

Configured as an authentication source, so anything on this installation can use
federated discovery without knowing about it:

```php
'embedded-disco' => [
    'embeddeddisco:OpenIdFederation',
],
```

The bundled fixture is still there behind `use_mock_data`, for demoing offline
and for the tests. It is off by default.

### Federation-native registration

The local Docker relying party is a full federation entity. It publishes a
self-signed Entity Configuration, is enrolled beneath the local Trust Anchor,
and signs its authorization Request Objects. Each OP resolves the RP's Trust
Chain and registers it automatically: the RP entity ID is the client ID and no
shared client secret is configured. The `clients` option remains available only
as a backwards-compatible fallback for non-federation providers.

This is also why the public demo providers cannot be logged in to. Asked to
start a login, `https://op-uni.hier.fed.oidfed.com` answers:

```
400 {"error":"invalid_client","error_description":"client is invalid"}
```

It is not a bug in the module; we are simply not a member of that federation.

## Trust Anchor selection

The Docker configuration uses the dedicated local Trust Anchor on port `8446`.
Outside Docker, the module's code-level fallback is the GÉANT Trust and Identity
Incubator testbed at `https://oidfed-ta-demo.incubator.geant.org`, but production
deployments should always set `trust_anchor_id` explicitly.

Appending `/.well-known/openid-federation` to an entity ID obtains that entity's
self-signed Entity Configuration. For an authority, its federation metadata
advertises the list and fetch endpoints used to discover subordinates and build
Trust Chains. Nothing in the discovery code is tied to the local anchor; changing
the configured anchor is enough to inspect another compatible federation. Login
still requires the RP and OP to share a Trust Anchor accepted by both parties.

## Configuration

`config-templates/module_embeddeddisco.php`, copied to
`config/module_embeddeddisco.php` in the SimpleSAMLphp installation.

| Option | Default | What it does |
| --- | --- | --- |
| `trust_anchor_id` | GÉANT demo TA | The anchor everything is discovered beneath |
| `entity_types` | `['openid_provider']` | Types the picker offers — configuration, not a user control |
| `required_trust_mark_types` | `[]` | Only offer entities claiming all of these |
| `page_size` | `6` | Results per page |
| `sort_order` | `'asc'` | Display-name sort direction |
| `use_mock_data` | `false` | Serve the bundled fixture instead of a federation |
| `collection_endpoint` | `'auto'` | `'auto'`, an endpoint URL, or `false` to always traverse |
| `max_discovery_depth` | `10` | Recursion limit for the traversal |
| `max_discovered_entities` | `1000` | Ceiling on entities one traversal may collect |
| `http_connect_timeout` | `3` | Seconds to wait for a federation endpoint to connect |
| `http_timeout` | `5` | Seconds to wait for one federation request |
| `verify_selection` | `true` | Resolve a Trust Chain for the picked entity |
| `validate_trust_marks` | `true` | Validate that entity's Trust Marks |
| `expose_error_details` | `true` | Show technical failure reasons in the UI |
| `signature_algorithms` | all but `none` | Algorithms an entity statement may be signed with |
| `federation_entity_id` | `null` | This RP or authority's public Entity Identifier |
| `federation_entity_role` | `null` | `openid_relying_party` or `trust_anchor` |
| `federation_authority_hints` | `[]` | Immediate superiors advertised by a leaf entity |
| `federation_subordinates` | `[]` | Explicit enrollment allow-list for a local Trust Anchor |
| `federation_private_key` | `null` | PEM key used to sign Entity Statements |
| `protocol_private_key` | `null` | Separate RP key used to sign authorization Request Objects |
| `federation_redirect_uris` | `[]` | Callback URLs published in RP metadata |
| `federation_display_name` | module name | Name published in federation metadata |
| `federation_statement_ttl` | `86400` | Entity and subordinate statement lifetime in seconds |
| `clients` | `[]` | Legacy per-provider credentials when federation registration is unavailable |
| `scopes` | `['openid']` | OIDC scopes requested by the RP |
| `http_ca_bundle` | `null` | Extra CA bundle for private HTTPS endpoints |
| `cache_directory` | SSP `cachedir` | PSR-16 cache location |
| `cache_duration` | `3600` | Cache lifetime, in seconds |

Two of these are worth expanding on.

**`signature_algorithms`.** The library verifies **RS256 only** unless told
otherwise, and an entity signed with anything else does not fail loudly — the
statement simply does not verify, and that branch of the federation quietly
disappears. Real federations sign with EC keys: the fed.oidfed.com entities use
ES256, the GÉANT demo RP uses ES512. The module therefore enables everything the
library can verify, minus `none`, which is dropped wherever it is configured
because accepting it would mean accepting unsigned entity statements.

**`collection_endpoint`.** Turning it off is written as `false`, not `null`:
SimpleSAMLphp's `Configuration` reads a null option as "not set", so `null` would
silently leave the default in place. There is a test asserting exactly this, so
the code and the comment cannot drift apart.

## How `simplesamlphp/openid` is used

No discovery, trust chain resolution, filtering, sorting, pagination or
serialization logic is reimplemented here. The module supplies configuration, a
controller, templates, and the decisions about which library surface to call;
every federation operation below is the library's own code.

| File | Library surface it touches |
| --- | --- |
| `src/Federation/FederationFactory.php` | Constructs the `Federation` facade |
| `src/Federation/DiscoveryService.php` | Runs the discovery pipeline; decides traversal vs. collection endpoint |
| `src/Federation/DiscoveryQuery.php` | Builds filter criteria and collection endpoint parameters |
| `src/Federation/TrustChainService.php` | Trust Chain resolution and Trust Mark validation |
| `src/Federation/DiscoveryResult.php` | One discovery run, including how it was produced and whether it failed |
| `src/Federation/MockEntityCollectionStore.php` | Extends `InMemoryEntityCollectionStore` (fixture mode) |
| `src/Federation/MockFederation.php` | Fixture payloads keyed by `ClaimsEnum` / `EntityTypesEnum` |

### 1. Building the facade

`SimpleSAML\OpenID\Federation` is the library's single composition root. It
lazily constructs the discovery, trust chain, filter, sorter, paginator, fetchers
and factories internally, so nothing here instantiates them by hand:

```php
new Federation(
    supportedAlgorithms: new SupportedAlgorithms(new SignatureAlgorithmBag(...)),
    maxCacheDuration: new DateInterval('PT3600S'),
    cache: new Psr16Cache(new FilesystemAdapter(...)),   // PSR-16
    logger: new SimpleSAML\Compat\Logger(),              // PSR-3
    maxDiscoveryDepth: 10,
    entityCollectionStore: null,                         // or the fixture store
    httpClientConfig: [                                  // merged over the library's defaults
        'connect_timeout' => 3,
        'timeout' => 5,
    ],
    maxDiscoveredEntities: 1000,
);
```

Both infrastructure dependencies come from SimpleSAMLphp itself: the PSR-16 cache
is Symfony's cache component (already an SSP dependency), and the PSR-3 logger is
SSP's own `Compat\Logger`, so the library's log lines land in the SSP log next to
everything else.

The HTTP options are merged **over** the library's hardening defaults, so only
the timeouts are changed and the redirect restrictions (at most 3 hops, https
only) stay as the library set them. The connect timeout is what bounds a Trust
Anchor that is dark rather than merely slow — without it, one unresponsive
federation endpoint occupies a web worker indefinitely.

### 2. Where the entity collection comes from

Two sources, decided per request by `DiscoveryService`:

**A remote `federation_collection_endpoint`**, when one is configured or the
Trust Anchor advertises one. One request instead of one per entity, with the
filtering and paging done server side:

```php
$federation->federationDiscovery()->fetchFromCollectionEndpoint($uri, $criteria);
```

The criteria are not re-applied locally afterwards. The endpoint decided what
matched and what a page is, and re-running the filter over the trimmed `ui_infos`
it chose to return would drop entities it matched on claims it did not send. The
total match count is therefore unknown, and the picker says "N shown" rather than
"N of M".

In `'auto'` mode the Trust Anchor is asked only when a traversal is about to
happen anyway. While a discovered collection is already stored, that request
would be a fetch made solely to decide not to fetch — paid on every page view for
as long as the store stays warm.

**A top-down traversal**, otherwise:

```php
$federation->federationDiscovery()->discover($trustAnchorId);
```

It consults the entity collection store first and only goes over the network when
the store is empty. On a live traversal it fetches the Trust Anchor's Entity
Configuration, follows `federation_list_endpoint` links breadth-first, tracks
visited IDs for loop protection, and stops at `max_discovery_depth` or
`max_discovered_entities`.

No filters are passed down into the subordinate listings, deliberately. They
would be applied at every authority, and an intermediate is a `federation_entity`
— filtering the listings for `openid_provider` would prune the very nodes the
traversal has to descend through to reach any providers.

### 3. The pipeline

For a traversal, the library's documented **discover → filter → sort → paginate →
serialize** pipeline runs here in full, which is the same pipeline a
`federation_collection_endpoint` implementation would run:

```php
$collection->filter($criteria);          // entity_type / trust_mark_type / query
$collection->sort($claimPaths, $order);  // nested claim paths
$collection->paginate($limit, $from);    // opaque cursor
$collection->toCollectionEndpointResponseArray();
```

**`filter()`** implements the spec's own criteria, with semantics worth knowing:

| Criterion | Logic | Fields searched |
| --- | --- | --- |
| `entity_type` | OR — any match wins | `metadata` keys |
| `trust_mark_type` | AND — all must be present | `trust_marks[].trust_mark_type` |
| `query` | case-insensitive substring | `sub`, `display_name`, `organization_name` |

Note `query` does **not** search `description` or `keywords`.

**`sort()`** takes claim paths relative to the JWT payload root, so metadata
claims need the `metadata` prefix. The module passes a fallback chain ending at
the entity ID, so ordering stays total even for entities with no display name —
which, in a real federation, is most of them.

**`paginate()`** uses opaque base64url cursors — the encoded entity ID to start
*after*. There is deliberately no backward cursor, which is why the UI offers
"Next page" and "Reset" rather than previous/next.

**`toCollectionEndpointResponseArray()`** produces the spec-shaped response
(`entities[]` with `entity_id`, `entity_types`, `ui_infos`, `trust_marks`, plus
`next` and `last_updated`). The picker renders from *this*, not from raw payloads,
so the UI consumes precisely what a remote collection endpoint would return, and
`/entities` serves it verbatim.

### 4. Discovery that fails, rather than discovery that is empty

`FederationDiscovery::discover()` catches everything it hits, logs it, and
returns an empty collection. A picker built naively on that renders "no providers
match these filters" when the truth is that the Trust Anchor is down — which is
exactly what the default Trust Anchor did throughout development.

`DiscoveryService` tells the two apart by shape: a successful traversal always
holds at least the Trust Anchor itself and always stamps `last_updated`, so *no
entities and no stamp* means the run failed. It then re-fetches the Trust Anchor's
Entity Configuration once, on a path that has already failed, to turn the failure
into something an operator can act on:

```
The federation could not be read, so no providers can be offered.
Server error: `GET https://…/.well-known/openid-federation` resulted in a `503 Service Unavailable` response
```

The technical line is behind `expose_error_details`. `/entities` answers the same
condition with HTTP 503 and an `error` body rather than an empty `entities` array,
so a widget consuming it cannot mistake an outage for an empty federation.

### 5. Caching, and the refresh that is not public

Three things are cached in the same PSR-16 store: fetched artifacts (entity
statements, listings, collection responses), the discovered entity collection,
and resolved Trust Chains. A warm cache is what keeps the picker usable while the
Trust Anchor is briefly unreachable, and what makes a page view cost nothing —
a cold traversal of the three-level demo federation takes about two seconds, a
warm one is immediate.

Re-discovering is offered as a "Discover again" link, but only to SimpleSAMLphp
administrators: a full traversal is the most expensive thing one visitor can ask
the deployment to do, and it is fetch-per-entity work against third parties.

### 6. Verifying the entity the user picked

Discovery lists candidates; it does not establish trust. Every payload it returns
comes from a **self-asserted** Entity Configuration — signature-checked against
the entity's own keys, and otherwise saying whatever the entity wants it to say.
Nothing in that shows the entity is part of the federation.

`TrustChainService` does, on selection:

```php
$trustChain = $federation->trustChainResolver()->for($entityId, [$trustAnchorId])->getShortest();
$metadata = $trustChain->getResolvedMetadata(EntityTypesEnum::OpenIdProvider);
```

That yields an unbroken chain of Subordinate Statements from the Trust Anchor down
to the entity, and the metadata that survives the chain's metadata policy — which
is the metadata an RP should act on, not the entity's own claims. The shortest
chain is used when several exist: it is the one with the fewest intermediates able
to rewrite the metadata on the way down.

The selection page shows the chain, when it expires, and the resolved metadata; a
failure is reported as "not verified", with the reason. This is why the picker's
Trust Mark badges are labelled *claimed*: validating a Trust Mark costs a Trust
Chain resolution per mark, which is affordable for the one entity a user picked
and not for every row of every page. On selection they are validated properly —
signature, issuer's right to issue that type, and any delegation — and shown as
✓ or ✗ individually.

Resolution costs a handful of fetches (roughly five for a two-hop chain) and is
cached, so it is a few hundred milliseconds cold and free afterwards.

## Development environment

The Docker Compose environment is a complete, local OpenID Federation. It mounts
this module from the working copy, so PHP and template edits take effect without
an image rebuild. Run either wrapper from the repository root:

```powershell
.\docker\run.ps1
```

```bash
./docker/run.sh
```

Or invoke Compose directly:

```bash
docker compose -f docker/docker-compose.yml up --build -d
```

The first build can take a few minutes. Later starts are cached; `up -d` is enough
unless dependencies changed. Follow logs with
`docker compose -f docker/docker-compose.yml logs -f` and stop everything with
`docker compose -f docker/docker-compose.yml down`.

### Local federation topology

Compose brings up four services:

```text
                         dedicated Trust Anchor (:8446)
                           /          |          \
                          /           |           \
                 OP 1 (:8444)   OP 2 (:8445)   RP (:8443)
```

The Trust Anchor is a separate authority and explicitly enrolls all three leaf
entities. Both OPs run `simplesamlphp-module-oidc`; the RP and Trust Anchor use
this module to publish their federation endpoints and signed Entity
Configurations. Discovery therefore returns the two OPs, while RP automatic
registration resolves independently through the same anchor.

Generate the local development TLS certificate if it is not already present:

```bash
bash docker/op/make-certs.sh
docker compose -f docker/docker-compose.yml up -d
```

The certificate names `host.docker.internal`, which resolves both from the host
browser and from inside the relying party container. That matters more than it
sounds: the issuer in an ID token has to be the same string the RP fetched the
provider's metadata from, so both sides need one URL that means the same thing.
Every container installs that certificate into its local trust store. TLS
verification remains enabled.

On first start, the RP, Trust Anchor, and both OPs generate independent signing
keys. Each OP also initializes its own SQLite database. Keys and provider data
live in named Docker volumes, so ordinary rebuilds and restarts preserve entity
identity and automatic registrations. `docker compose -f docker/docker-compose.yml down -v`
removes those volumes and creates a fresh federation on the next start.

| What | Where |
| --- | --- |
| Trust Anchor entity | `https://host.docker.internal:8446/simplesaml/module.php/embeddeddisco/federation` |
| Trust Anchor configuration | <https://host.docker.internal:8446/simplesaml/module.php/embeddeddisco/federation/.well-known/openid-federation> |
| Trust Anchor subordinate list | <https://host.docker.internal:8446/simplesaml/module.php/embeddeddisco/federation/list> |
| OP1 issuer | `https://host.docker.internal:8444/simplesaml/module.php/oidc` |
| OP2 issuer | `https://host.docker.internal:8445/simplesaml/module.php/oidc` |
| RP entity | `https://host.docker.internal:8443/simplesaml/module.php/embeddeddisco/federation` |
| Leaf Entity Configurations | Append `/.well-known/openid-federation` to the entity ID |
| OP1 admin | <https://host.docker.internal:8444/simplesaml/> — `admin` / `secret1` |
| OP2 admin | <https://host.docker.internal:8445/simplesaml/> — `admin` / `secret1` |
| Test users | `student` / `studentpass`, `staff` / `staffpass` |

The mounted Docker configuration points `trust_anchor_id` at the dedicated local
anchor. Every leaf therefore resolves as `leaf → Trust Anchor`. Point it at
`https://ta.hier.fed.oidfed.com` instead to inspect discovery across a public,
multi-level federation; its OPs will not accept this locally enrolled RP.

### Trying the login

<https://localhost:8443/simplesaml/>

The demo RP front page starts the `embedded-disco` authentication source
directly, without an administrator login. The picker appears, Select verifies
the Trust Chain and redirects to the provider, and you authenticate there as
`student`. After the provider redirects back, the RP displays the resulting
session attributes and technical authentication data.

The local certificate is self-signed, so expect a browser warning.

| What | Where |
| --- | --- |
| **Embedded discovery** | <https://localhost:8443/simplesaml/module.php/embeddeddisco/disco> |
| Entity collection JSON | <https://localhost:8443/simplesaml/module.php/embeddeddisco/entities> |
| RP login | <https://localhost:8443/simplesaml/> |
| SSP admin UI | <https://localhost:8443/simplesaml/module.php/admin/> — user `admin`, password `secret1` |
| Module smoke test | <https://localhost:8443/simplesaml/module.php/embeddeddisco/status> |

`status` is the first thing to check when the picker comes up empty. It reports
whether the Trust Anchor answered, when its Entity Configuration expires, and
which federation endpoints it advertises.

### Layout

* `docker/run.sh`, `docker/run.ps1` — wrappers for the Compose environment.
* `docker/Dockerfile` — SSP base image plus this module, installed via a composer
  path repository at `/var/simplesamlphp/staging-modules/embeddeddisco`.
* `docker/docker-compose.yml` — the RP, Trust Anchor, and two OP services on
  host ports `8443`, `8446`, `8444`, and `8445` respectively.
* `docker/entity/run-on-start.sh` — creates persistent RP/TA signing keys and
  installs the local TLS trust root.
* `docker/ta/` — the dedicated Trust Anchor's SimpleSAMLphp configuration.
* `docker/op/` — shared provider configuration and startup initialization.
* `docker/ssp/config-override.php` — appended to the container's SSP config.
* `docker/ssp/authsources.php` — example auth sources for testing.
* `config-templates/module_embeddeddisco.php` — module configuration, mounted
  into the container as `config/module_embeddeddisco.php`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite runs entirely offline: it drives the real pipeline over the bundled
fixture, and covers the configuration decisions that are easy to get wrong
(`none` never being accepted as a signature algorithm, `null` not disabling the
collection endpoint, timeouts never reaching zero).

### Verifying against another federation

To inspect a public federation, point `trust_anchor_id` at one of the
[fed.oidfed.com](https://fed.oidfed.com/) topologies:

| Trust Anchor | Shape |
| --- | --- |
| `https://ta.single.fed.oidfed.com` | Flat: one OP and two RPs directly under the anchor |
| `https://ta.hier.fed.oidfed.com` | Two intermediates, providers under each — exercises multi-level traversal |
| `https://ta.policy.fed.oidfed.com` | Metadata policy, visible in the resolved metadata on the selection page |

Against `ta.hier`, discovery finds 7 entities (2 of them OPs), and selecting
`https://op-uni.hier.fed.oidfed.com` resolves the chain
`op-uni → ia-edu → ta.hier` with policy-resolved `openid_provider` metadata.

## Known limitations and next work

The Compose topology is a working interoperability environment, not a production
federation operator deployment. In particular:

* The dedicated Trust Anchor implements the Entity Configuration, subordinate
  listing, and fetch endpoints needed by this topology. It does not yet implement
  optional list filters, a resolve endpoint, or a collection endpoint.
* Federation and protocol keys persist, but there is no rollover or historical
  key endpoint yet.
* All four HTTPS services reuse one self-signed development certificate. Their
  federation and OIDC signing keys are separate; production TLS certificates
  should also be separate and issued by a proper local or public CA.
* The OP image is pinned to `simplesamlphp-module-oidc` 6.4.5, whose documented
  federation support targets the implementation available in that release.
  Recheck interoperability before upgrading it or targeting a stricter profile
  of the final OpenID Federation 1.0 specification.

* **Trust Mark validation against real marks.** The code path is there and runs,
  but no entity in any reachable demo federation publishes a `trust_marks` claim,
  so it has only been exercised against the fixture's unsigned types.
* **The collection endpoint against a real one.** No demo Trust Anchor advertises
  a `federation_collection_endpoint`, so that path was verified against a local
  stub serving a spec-shaped response, not against a deployed endpoint.
* **Trust Mark badges in the picker are claims.** Validating them per row is
  possible with the cache warm; whether it is worth the first cold page view is a
  judgement call that has not been made.
