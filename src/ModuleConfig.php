<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco;

use SimpleSAML\Configuration;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

/**
 * Typed access to config/module_embeddeddisco.php.
 */
class ModuleConfig
{
    public const string MODULE_NAME = 'embeddeddisco';

    public const string CONFIG_FILE_NAME = 'module_embeddeddisco.php';

    public const string OPTION_TRUST_ANCHOR_ID = 'trust_anchor_id';

    public const string OPTION_ENTITY_TYPES = 'entity_types';

    public const string OPTION_REQUIRED_TRUST_MARK_TYPES = 'required_trust_mark_types';

    public const string OPTION_PAGE_SIZE = 'page_size';

    public const string OPTION_SORT_ORDER = 'sort_order';

    public const string OPTION_USE_MOCK_DATA = 'use_mock_data';

    public const string OPTION_MAX_DISCOVERY_DEPTH = 'max_discovery_depth';

    public const string OPTION_CACHE_DIRECTORY = 'cache_directory';

    public const string OPTION_CACHE_DURATION = 'cache_duration';

    /**
     * Fallback Trust Anchor, used when the module has no config file.
     */
    public const string DEFAULT_TRUST_ANCHOR_ID = 'https://ta.embedded-disco.test';


    protected Configuration $config;


    public function __construct(?Configuration $config = null)
    {
        $this->config = $config ?? Configuration::getOptionalConfig(self::CONFIG_FILE_NAME);
    }


    /**
     * @return non-empty-string
     */
    public function getTrustAnchorId(): string
    {
        $value = (string) $this->config->getOptionalString(
            self::OPTION_TRUST_ANCHOR_ID,
            self::DEFAULT_TRUST_ANCHOR_ID,
        );

        // The library types the Trust Anchor ID as non-empty-string.
        return $value === '' ? self::DEFAULT_TRUST_ANCHOR_ID : $value;
    }


    /**
     * Entity types the picker offers, e.g. ['openid_provider'].
     *
     * @return string[]
     */
    public function getEntityTypes(): array
    {
        return $this->config->getOptionalArray(
            self::OPTION_ENTITY_TYPES,
            [EntityTypesEnum::OpenIdProvider->value],
        );
    }


    /**
     * @return string[]
     */
    public function getRequiredTrustMarkTypes(): array
    {
        return $this->config->getOptionalArray(self::OPTION_REQUIRED_TRUST_MARK_TYPES, []);
    }


    /**
     * @return positive-int
     */
    public function getPageSize(): int
    {
        return max(1, $this->config->getOptionalInteger(self::OPTION_PAGE_SIZE, 6));
    }


    /**
     * @return 'asc'|'desc'
     */
    public function getSortOrder(): string
    {
        return $this->config->getOptionalString(self::OPTION_SORT_ORDER, 'asc') === 'desc' ? 'desc' : 'asc';
    }


    public function useMockData(): bool
    {
        return $this->config->getOptionalBoolean(self::OPTION_USE_MOCK_DATA, true);
    }


    public function getMaxDiscoveryDepth(): int
    {
        return $this->config->getOptionalInteger(self::OPTION_MAX_DISCOVERY_DEPTH, 10);
    }


    public function getCacheDirectory(): string
    {
        $configured = $this->config->getOptionalString(self::OPTION_CACHE_DIRECTORY, null);
        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        $sspConfig = Configuration::getInstance();
        // 'tempdir' is deprecated in favour of 'cachedir'; fall back for older installs.
        $cacheDir = $sspConfig->getPathValue('cachedir')
            ?? $sspConfig->getPathValue('tempdir', sys_get_temp_dir());

        return rtrim((string) $cacheDir, '/') . '/embeddeddisco';
    }


    public function getCacheDuration(): int
    {
        return max(1, $this->config->getOptionalInteger(self::OPTION_CACHE_DURATION, 3600));
    }
}
