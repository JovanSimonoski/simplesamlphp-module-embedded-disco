<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use DateInterval;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\Compat\Logger as SspPsrLogger;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\SupportedAlgorithms;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

use function sprintf;

/**
 * Builds the library's Federation facade with SimpleSAMLphp's own infrastructure
 * plugged into it.
 *
 * The facade is the library's single composition root -- it constructs the
 * discovery, fetching, trust chain, filtering, sorting and pagination services
 * internally, so nothing in this module has to instantiate them by hand.
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
            // The library verifies RS256 only unless told otherwise, and a
            // statement signed with anything else does not fail loudly: it just
            // makes that branch of the federation unreadable. Real deployments
            // sign with EC keys too, so the full set is enabled here.
            supportedAlgorithms: $this->buildSupportedAlgorithms(),
            // Bounds how long a fetched artifact stays usable. Everything the
            // module caches -- entity collections, statements, trust chains --
            // is capped by this, and by whatever the artifact's own expiry says.
            maxCacheDuration: $this->buildMaxCacheDuration(),
            // PSR-16, from Symfony's cache component that SimpleSAMLphp already ships.
            cache: $this->buildCache(),
            // PSR-3 adapter over SimpleSAML\Logger, so library log lines land in
            // the SSP log alongside everything else.
            logger: $this->buildLogger(),
            maxDiscoveryDepth: $this->moduleConfig->getMaxDiscoveryDepth(),
            // Left null, the facade builds its own CacheEntityCollectionStore on
            // top of the cache above and discovery goes over the wire. The mock
            // store short-circuits that, and is the only difference between the
            // two modes.
            entityCollectionStore: $this->moduleConfig->useMockData()
                ? new MockEntityCollectionStore(
                    $this->moduleConfig->getTrustAnchorId(),
                    ttl: $this->moduleConfig->getCacheDuration(),
                )
                : null,
            // Merged over the library's hardening defaults. Only the timeouts
            // are touched: a discovery page waits on federation endpoints it
            // does not control, and the connect timeout is what bounds one that
            // is dark rather than merely slow.
            httpClientConfig: $this->buildHttpClientConfig(),
            maxDiscoveredEntities: $this->moduleConfig->getMaxDiscoveredEntities(),
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


    protected function buildSupportedAlgorithms(): SupportedAlgorithms
    {
        return new SupportedAlgorithms(
            new SignatureAlgorithmBag(...$this->moduleConfig->getSignatureAlgorithms()),
        );
    }


    protected function buildMaxCacheDuration(): DateInterval
    {
        return new DateInterval(sprintf('PT%dS', $this->moduleConfig->getCacheDuration()));
    }


    /**
     * @return array<string, mixed>
     */
    protected function buildHttpClientConfig(): array
    {
        return [
            RequestOptions::CONNECT_TIMEOUT => $this->moduleConfig->getHttpConnectTimeout(),
            RequestOptions::TIMEOUT => $this->moduleConfig->getHttpTimeout(),
        ];
    }
}
