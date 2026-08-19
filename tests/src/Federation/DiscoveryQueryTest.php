<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Federation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DiscoveryQuery::class)]
class DiscoveryQueryTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected function moduleConfig(array $options = []): ModuleConfig
    {
        return new ModuleConfig(Configuration::loadFromArray($options));
    }


    /**
     * @param array<string, mixed> $parameters
     */
    protected function request(array $parameters): Request
    {
        return new Request($parameters);
    }


    public function testCriteriaFallBackToTheConfiguredDefaults(): void
    {
        $discoveryQuery = DiscoveryQuery::fromRequest($this->request([]), $this->moduleConfig([
            ModuleConfig::OPTION_ENTITY_TYPES => [EntityTypesEnum::OpenIdProvider->value],
            ModuleConfig::OPTION_REQUIRED_TRUST_MARK_TYPES => ['https://ta.example.org/marks/certified'],
            ModuleConfig::OPTION_PAGE_SIZE => 3,
        ]));

        $this->assertSame('', $discoveryQuery->query);
        $this->assertSame([EntityTypesEnum::OpenIdProvider->value], $discoveryQuery->entityTypes);
        $this->assertSame(['https://ta.example.org/marks/certified'], $discoveryQuery->trustMarkTypes);
        $this->assertSame(3, $discoveryQuery->limit);
        $this->assertNull($discoveryQuery->from);
        $this->assertSame('asc', $discoveryQuery->sortOrder);
    }


    public function testEntityTypeArrivesAsAScalarFromTheFormAndAsAListFromAnApiCaller(): void
    {
        $fromForm = DiscoveryQuery::fromRequest(
            $this->request(['entity_type' => EntityTypesEnum::FederationEntity->value]),
            $this->moduleConfig(),
        );
        $fromApi = DiscoveryQuery::fromRequest(
            $this->request(['entity_type' => [EntityTypesEnum::FederationEntity->value, '']]),
            $this->moduleConfig(),
        );

        $this->assertSame([EntityTypesEnum::FederationEntity->value], $fromForm->entityTypes);
        $this->assertSame([EntityTypesEnum::FederationEntity->value], $fromApi->entityTypes);
    }


    public function testFilterCriteriaOnlyCarryWhatWasAskedFor(): void
    {
        $discoveryQuery = new DiscoveryQuery(query: ' skopje ', entityTypes: ['openid_provider']);

        $this->assertSame(
            ['entity_type' => ['openid_provider'], 'query' => ' skopje '],
            $discoveryQuery->toFilterCriteria(),
        );
        $this->assertArrayNotHasKey('trust_mark_type', $discoveryQuery->toFilterCriteria());
    }


    public function testQueryIsTrimmedWhenItComesFromTheSearchBox(): void
    {
        $discoveryQuery = DiscoveryQuery::fromRequest($this->request(['query' => '  finki  ']), $this->moduleConfig());

        $this->assertSame('finki', $discoveryQuery->query);
    }


    public function testCollectionEndpointParamsCarryTheAnchorAndThePage(): void
    {
        $discoveryQuery = new DiscoveryQuery(
            query: 'skopje',
            entityTypes: ['openid_provider'],
            limit: 5,
            from: 'Y3Vyc29y',
        );

        $this->assertSame(
            [
                'entity_type' => ['openid_provider'],
                'query' => 'skopje',
                // A collection endpoint can serve several anchors, so which one
                // is being asked about travels with the query.
                'trust_anchor' => 'https://ta.example.org',
                'limit' => 5,
                'from' => 'Y3Vyc29y',
            ],
            $discoveryQuery->toCollectionEndpointParams('https://ta.example.org'),
        );
    }


    public function testOnlyDescendingSortIsTakenFromTheRequest(): void
    {
        $descending = DiscoveryQuery::fromRequest($this->request(['sort_dir' => 'desc']), $this->moduleConfig());
        $nonsense = DiscoveryQuery::fromRequest($this->request(['sort_dir' => 'sideways']), $this->moduleConfig([
            ModuleConfig::OPTION_SORT_ORDER => 'desc',
        ]));

        $this->assertSame('desc', $descending->sortOrder);
        $this->assertSame('desc', $nonsense->sortOrder);
    }
}
