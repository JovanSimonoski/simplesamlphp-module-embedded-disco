<?php

declare(strict_types=1);

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

$config = [
    // Trust Anchor the discovery starts from. Every entity offered to the user
    // is a subordinate of this anchor. Its Entity Configuration lives at
    // <trust_anchor_id>/.well-known/openid-federation, and that document is the
    // entry point for everything the module does.
    //
    // The default is the first OpenID Provider container that ships with this
    // module. It acts as the Trust Anchor and enrolls the second local provider
    // beneath it. Both providers know about this relying party, so either path
    // can complete a login locally.
    //
    // For discovery across a real multi-level federation, point this at one of
    // the fed.oidfed.com demo topologies instead:
    //
    //   https://ta.hier.fed.oidfed.com   two intermediate authorities, providers
    //                                    under each, so a Trust Chain has an
    //                                    intermediate in the middle
    //   https://ta.single.fed.oidfed.com flat: providers directly under the anchor
    //
    // Those providers verify but cannot be logged in to, because this deployment
    // is not a member of their federation -- see the clients option below.
    //
    // The project's own target federation is the GEANT Trust and Identity
    // Incubator testbed:
    //
    //   https://oidfed-ta-demo.incubator.geant.org
    //
    // As of 2026-08-19 that anchor cannot be traversed or resolved by any
    // conforming client: its Entity Configuration is served from
    // oidfed-ta-demo.incubator.geant.org but declares itself, and publishes all
    // of its endpoints, as oidfed-ta.demo.incubator.geant.org, which does not
    // resolve. Its own resolve endpoint answers "no valid trust path between sub
    // and anchor found" for its own subordinate. Switch back to it once that is
    // fixed; nothing else here has to change.
    ModuleConfig::OPTION_TRUST_ANCHOR_ID => 'https://host.docker.internal:8444/simplesaml/module.php/oidc',

    // Entity types offered in the picker. An RP discovering where to send the
    // user wants OPs, so that is the default.
    //
    // Deliberately not exposed as a control on the page, and not read from the
    // request: only an OpenID Provider can authenticate anyone, so a Relying
    // Party or an intermediate authority in the list would be a row a user can
    // click but not use. Other types are still discovered -- the traversal has
    // to walk through the intermediates to reach the providers -- they are just
    // never offered.
    ModuleConfig::OPTION_ENTITY_TYPES => [
        EntityTypesEnum::OpenIdProvider->value,
    ],

    // Only offer entities carrying all of these Trust Mark types. Empty means
    // no Trust Mark requirement. Note that this filters on the self-asserted
    // trust_mark_type; the marks themselves are validated when a provider is
    // picked (see verify_selection / validate_trust_marks below).
    ModuleConfig::OPTION_REQUIRED_TRUST_MARK_TYPES => [],

    // Results per page. The library paginates with opaque cursors.
    ModuleConfig::OPTION_PAGE_SIZE => 6,

    // 'asc' or 'desc', applied to the display-name sort.
    ModuleConfig::OPTION_SORT_ORDER => 'asc',

    // Serve discovery from the bundled fixture instead of a live federation.
    // Useful offline, or to demo the picker when the Trust Anchor is down. The
    // library pipeline is identical either way -- only the entity collection
    // store differs.
    ModuleConfig::OPTION_USE_MOCK_DATA => false,

    // Where the entity collection comes from:
    //   'auto'  take the Trust Anchor's federation_collection_endpoint if it
    //           advertises one, otherwise traverse the federation
    //   <url>   always read this collection endpoint
    //   false   always traverse
    // A collection endpoint is one request instead of one per entity, and does
    // the filtering and paging server side. Note that false, not null, turns it
    // off: SimpleSAMLphp reads a null option as "not set", which would leave the
    // default in place.
    ModuleConfig::OPTION_COLLECTION_ENDPOINT => ModuleConfig::COLLECTION_ENDPOINT_AUTO,

    // Recursion limit for the top-down traversal (library clamps to 1..20).
    ModuleConfig::OPTION_MAX_DISCOVERY_DEPTH => 10,

    // Ceiling on the entities one traversal may collect. Depth alone does not
    // bound the work: a single listing endpoint can name any number of
    // subordinates, and each one costs a fetch.
    ModuleConfig::OPTION_MAX_DISCOVERED_ENTITIES => 1000,

    // Per-request HTTP limits for every federation fetch. The connect timeout
    // is what keeps a dark Trust Anchor from holding the page open.
    ModuleConfig::OPTION_HTTP_CONNECT_TIMEOUT => 3,
    ModuleConfig::OPTION_HTTP_TIMEOUT => 5,

    // Resolve a Trust Chain from the picked entity up to the Trust Anchor
    // before handing off to it. Discovery lists candidates from self-asserted
    // Entity Configurations; this is the step that proves membership and
    // yields policy-resolved metadata.
    ModuleConfig::OPTION_VERIFY_SELECTION => true,

    // Validate the picked entity's Trust Marks (signature, issuer, delegation)
    // instead of trusting the trust_mark_type it asserts about itself.
    ModuleConfig::OPTION_VALIDATE_TRUST_MARKS => true,

    // Show the technical reason a discovery or verification failed. Handy while
    // integrating; turn it off in front of end users.
    ModuleConfig::OPTION_EXPOSE_ERROR_DETAILS => true,

    // Signature algorithms an entity statement may be signed with. Null (the
    // default) accepts everything the library can verify except "none", which
    // is never accepted. Narrow this only if your federation mandates a set:
    // a statement signed with an algorithm that is not listed does not fail
    // loudly, it makes that part of the federation unreadable.
    ModuleConfig::OPTION_SIGNATURE_ALGORITHMS => null,

    // Client credentials this relying party holds at a provider, keyed by that
    // provider's issuer. A provider with no entry here can be discovered and
    // verified, but not logged in to -- the selection page says so rather than
    // sending anyone anywhere.
    //
    // OpenID Federation's own answer is automatic registration: the RP's entity
    // ID is its client ID, and the provider validates it by resolving the RP's
    // Trust Chain, so no shared secret exists. That needs this deployment
    // published as a federation entity and enrolled under the Trust Anchor,
    // which is the next step for this module.
    ModuleConfig::OPTION_CLIENTS => [
        'https://host.docker.internal:8444/simplesaml/module.php/oidc' => [
            'client_id' => 'embedded-disco-rp',
            'client_secret' => 'embedded-disco-secret',
            'scopes' => ['openid'],
        ],
        'https://host.docker.internal:8445/simplesaml/module.php/oidc' => [
            'client_id' => 'embedded-disco-rp',
            'client_secret' => 'embedded-disco-secret',
            'scopes' => ['openid'],
        ],
    ],

    // Scopes to ask for, unless a client entry above overrides them.
    ModuleConfig::OPTION_SCOPES => ['openid'],

    // A CA bundle to verify federation and provider endpoints against, for a
    // private federation whose certificates the system trust store does not
    // know. This adds a trusted CA; it never disables verification. Null uses
    // the system trust store.
    //
    // The demo OP container serves a self-signed certificate, mounted here by
    // docker-compose.
    ModuleConfig::OPTION_HTTP_CA_BUNDLE => '/var/simplesamlphp/cert/op-ca.crt',

    // PSR-16 cache directory. Null uses SimpleSAMLphp's cachedir.
    ModuleConfig::OPTION_CACHE_DIRECTORY => null,

    // Cache lifetime, in seconds, for discovered entity collections, fetched
    // statements and resolved Trust Chains. A warm cache is also what keeps the
    // picker working while the Trust Anchor is briefly unreachable.
    ModuleConfig::OPTION_CACHE_DURATION => 3600,
];
