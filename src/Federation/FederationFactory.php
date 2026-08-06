<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\Compat\Logger as SspPsrLogger;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Federation;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Builds the library's Federation facade with SimpleSAMLphp's own infrastructure
 * plugged into it.
 *
 * The facade is the library's single composition root -- it constructs the
 * discovery, filtering, sorting and pagination services internally, so nothing
 * in this module has to instantiate them by hand.
 */
class FederationFactory
{
    protected ?Federation $federation = null;


    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
    ) {
    }


    public function build(): Federation
    {
        return $this->federation ??= new Federation(
            // PSR-16, from Symfony's cache component that SimpleSAMLphp already ships.
            cache: $this->buildCache(),
            // PSR-3 adapter over SimpleSAML\Logger, so library log lines land in
            // the SSP log alongside everything else.
            logger: $this->buildLogger(),
            maxDiscoveryDepth: $this->moduleConfig->getMaxDiscoveryDepth(),
            // In mock mode a seeded store short-circuits the network traversal.
            // Left null, the facade builds its own CacheEntityCollectionStore
            // on top of the cache above and discovery goes over the wire.
            entityCollectionStore: $this->moduleConfig->useMockData()
                ? new MockEntityCollectionStore(
                    $this->moduleConfig->getTrustAnchorId(),
                    ttl: $this->moduleConfig->getCacheDuration(),
                )
                : null,
        );
    }


    protected function buildCache(): CacheInterface
    {
        return new Psr16Cache(
            new FilesystemAdapter(
                namespace: 'embeddeddisco',
                defaultLifetime: $this->moduleConfig->getCacheDuration(),
                directory: $this->moduleConfig->getCacheDirectory(),
            ),
        );
    }


    protected function buildLogger(): LoggerInterface
    {
        return new SspPsrLogger();
    }
}
