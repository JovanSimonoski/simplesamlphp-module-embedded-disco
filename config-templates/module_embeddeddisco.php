<?php

declare(strict_types=1);

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

$config = [
    // Trust Anchor the discovery traversal starts from. Every entity offered to
    // the user is a subordinate of this anchor.
    ModuleConfig::OPTION_TRUST_ANCHOR_ID => 'https://ta.embedded-disco.test',

    // Entity types offered in the picker. An RP discovering where to send the
    // user wants OPs, so that is the default.
    ModuleConfig::OPTION_ENTITY_TYPES => [
        EntityTypesEnum::OpenIdProvider->value,
    ],

    // Only offer entities carrying all of these Trust Mark types. Empty means
    // no Trust Mark requirement.
    ModuleConfig::OPTION_REQUIRED_TRUST_MARK_TYPES => [],

    // Results per page. The library paginates with opaque cursors.
    ModuleConfig::OPTION_PAGE_SIZE => 6,

    // 'asc' or 'desc', applied to the display-name sort.
    ModuleConfig::OPTION_SORT_ORDER => 'asc',

    // POC switch. When true, discovery is served from a seeded in-memory store
    // instead of traversing a live federation, so no network access happens.
    // The library pipeline is identical either way -- only the store differs.
    ModuleConfig::OPTION_USE_MOCK_DATA => true,

    // Recursion limit for the top-down traversal (library clamps to 1..20).
    ModuleConfig::OPTION_MAX_DISCOVERY_DEPTH => 10,

    // PSR-16 cache directory. Null uses SimpleSAMLphp's tempdir.
    ModuleConfig::OPTION_CACHE_DIRECTORY => null,

    // Cache lifetime, in seconds, for discovered entity collections.
    ModuleConfig::OPTION_CACHE_DURATION => 3600,
];
