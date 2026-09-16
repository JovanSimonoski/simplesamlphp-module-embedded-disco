<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Federation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryResult;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryService;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoverySourceEnum;
use SimpleSAML\Module\embeddeddisco\Federation\MockFederation;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

/**
 * Runs the pipeline over the bundled fixture, which is the one configuration in
 * which it touches no network.
 */
#[CoversClass(DiscoveryService::class)]
#[CoversClass(DiscoveryResult::class)]
#[CoversClass(DiscoverySourceEnum::class)]
class DiscoveryServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected function discoveryService(array $options = []): DiscoveryService
    {
        $moduleConfig = new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_USE_MOCK_DATA => true,
            ModuleConfig::OPTION_TRUST_ANCHOR_ID => 'https://ta.embedded-disco.test',
            ModuleConfig::OPTION_CACHE_DIRECTORY => sys_get_temp_dir() . '/embeddeddisco-tests',
            ...$options,
        ]));

        return new DiscoveryService($moduleConfig, new OfflineFederationFactory($moduleConfig));
    }


    protected function query(string $query = '', ?int $limit = null, ?string $from = null): DiscoveryQuery
    {
        return new DiscoveryQuery(
            query: $query,
            entityTypes: [EntityTypesEnum::OpenIdProvider->value],
            limit: $limit ?? 6,
            from: $from,
        );
    }


    public function testFixtureDiscoveryReportsItselfAsSuchAndCannotFail(): void
    {
        $result = $this->discoveryService()->discover($this->query());

        $this->assertSame(DiscoverySourceEnum::Mock, $result->source);
        $this->assertFalse($result->source->isLive());
        $this->assertFalse($result->hasFailed());
        $this->assertNull($result->error);
        $this->assertNotNull($result->lastUpdated);
        $this->assertSame('https://ta.embedded-disco.test', $result->trustAnchorId);
    }


    public function testARefreshCannotTurnFixtureModeIntoALiveTraversal(): void
    {
        // A refresh tells discover() to ignore the store, and the store is the
        // only thing keeping fixture mode off the network.
        $result = $this->discoveryService()->discover($this->query(), forceRefresh: true);

        $this->assertSame(DiscoverySourceEnum::Mock, $result->source);
        $this->assertFalse($result->hasFailed());
        $this->assertSame(10, $result->total);
    }


    public function testEntityTypeFilteringLeavesOnlyProviders(): void
    {
        $result = $this->discoveryService()->discover($this->query());

        $this->assertSame(10, $result->total);

        foreach ($result->entities as $entity) {
            $this->assertContains(EntityTypesEnum::OpenIdProvider->value, $entity['entityTypes']);
        }
    }


    public function testPagingCutsThePageAndOffersACursor(): void
    {
        $service = $this->discoveryService();

        $firstPage = $service->discover($this->query(limit: 4));
        $this->assertCount(4, $firstPage->entities);
        $this->assertNotNull($firstPage->nextPageToken);
        // The total is the whole match set, not the page.
        $this->assertSame(10, $firstPage->total);

        $secondPage = $service->discover($this->query(limit: 4, from: $firstPage->nextPageToken));
        $this->assertNotSame(
            $firstPage->entities[0]['entityId'],
            $secondPage->entities[0]['entityId'],
        );
    }


    public function testSearchMatchesNamesAndOrganisationsRatherThanDescriptions(): void
    {
        $byName = $this->discoveryService()->discover($this->query('faculty of computer science'));
        $this->assertSame(1, $byName->total);
        $this->assertSame('https://op.finki.ukim.mk', $byName->entities[0]['entityId']);

        // "research" appears in several descriptions, and in exactly one name.
        $byDescription = $this->discoveryService()->discover($this->query('research'));
        $this->assertSame(1, $byDescription->total);
    }


    public function testRequiredTrustMarkTypesNarrowTheOffer(): void
    {
        $result = $this->discoveryService([
            ModuleConfig::OPTION_REQUIRED_TRUST_MARK_TYPES => [MockFederation::TRUST_MARK_CERTIFIED],
        ])->discover(new DiscoveryQuery(
            entityTypes: [EntityTypesEnum::OpenIdProvider->value],
            trustMarkTypes: [MockFederation::TRUST_MARK_CERTIFIED],
            limit: 10,
        ));

        $this->assertSame(6, $result->total);

        foreach ($result->entities as $entity) {
            $this->assertContains(MockFederation::TRUST_MARK_CERTIFIED, $entity['trustMarkTypes']);
        }
    }


    public function testRowsCarryWhatThePickerRenders(): void
    {
        $result = $this->discoveryService()->discover($this->query('ukim'));

        $entity = $result->entities[0];

        $this->assertSame('https://op.finki.ukim.mk', $entity['entityId']);
        $this->assertSame('Faculty of Computer Science and Engineering', $entity['displayName']);
        $this->assertSame('FINKI', $entity['organizationName']);
        $this->assertNotNull($entity['logoUri']);
        $this->assertNotNull($entity['informationUri']);
    }


    public function testTheSerializedResponseIsTheCollectionEndpointShape(): void
    {
        $service = $this->discoveryService();
        $result = $service->discover($this->query(limit: 2));

        $response = $service->toCollectionEndpointResponse($result->collection);

        $this->assertCount(2, $response['entities']);
        $this->assertArrayHasKey('entity_id', $response['entities'][0]);
        $this->assertArrayHasKey('entity_types', $response['entities'][0]);
        $this->assertArrayHasKey('ui_infos', $response['entities'][0]);
        $this->assertArrayHasKey('next', $response);
        $this->assertArrayHasKey('last_updated', $response);
    }
}
