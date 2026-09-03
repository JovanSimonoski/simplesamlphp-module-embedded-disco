<?php

declare(strict_types=1);

use SimpleSAML\Module\embeddeddisco\ModuleConfig;

$trustAnchorId = 'https://host.docker.internal:8446/simplesaml/module.php/embeddeddisco/federation';

$config = [
    ModuleConfig::OPTION_TRUST_ANCHOR_ID => $trustAnchorId,
    ModuleConfig::OPTION_USE_MOCK_DATA => false,
    ModuleConfig::OPTION_FEDERATION_ENTITY_ID => $trustAnchorId,
    ModuleConfig::OPTION_FEDERATION_ENTITY_ROLE => ModuleConfig::FEDERATION_ROLE_TRUST_ANCHOR,
    ModuleConfig::OPTION_FEDERATION_SUBORDINATES => [
        'https://host.docker.internal:8444/simplesaml/module.php/oidc',
        'https://host.docker.internal:8445/simplesaml/module.php/oidc',
        'https://host.docker.internal:8443/simplesaml/module.php/embeddeddisco/federation',
    ],
    ModuleConfig::OPTION_FEDERATION_PRIVATE_KEY =>
        '/var/simplesamlphp/cert/embeddeddisco_federation.key',
    ModuleConfig::OPTION_FEDERATION_DISPLAY_NAME => 'Local OpenID Federation Trust Anchor',
    ModuleConfig::OPTION_FEDERATION_STATEMENT_TTL => 86400,
    ModuleConfig::OPTION_HTTP_CA_BUNDLE => '/usr/local/share/ca-certificates/local-federation-ca.crt',
    ModuleConfig::OPTION_HTTP_CONNECT_TIMEOUT => 3,
    ModuleConfig::OPTION_HTTP_TIMEOUT => 5,
    ModuleConfig::OPTION_CACHE_DURATION => 300,
];
