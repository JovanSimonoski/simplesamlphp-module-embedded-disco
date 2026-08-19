<?php

declare(strict_types=1);

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

$config = [
    // Trust Anchor the discovery starts from. Every entity offered to the user
    // is a subordinate of this anchor.
    //
    // The default is the GEANT Trust and Identity Incubator's OpenID Federation
    // testbed. Its Entity Configuration lives at
    // <trust_anchor_id>/.well-known/openid-federation and names the demo OP and
    // RP as subordinates.
    ModuleConfig::OPTION_TRUST_ANCHOR_ID => 'https://oidfed-ta-demo.incubator.geant.org',

    // Entity types offered in the picker. An RP discovering where to send the
    // user wants OPs, so that is the default.
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

    // PSR-16 cache directory. Null uses SimpleSAMLphp's cachedir.
    ModuleConfig::OPTION_CACHE_DIRECTORY => null,

    // Cache lifetime, in seconds, for discovered entity collections, fetched
    // statements and resolved Trust Chains. A warm cache is also what keeps the
    // picker working while the Trust Anchor is briefly unreachable.
    ModuleConfig::OPTION_CACHE_DURATION => 3600,
];
