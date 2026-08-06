<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\OpenID\Federation\EntityCollection\InMemoryEntityCollectionStore;

use function time;

/**
 * The library's in-memory store, pre-seeded with a fake federation.
 *
 * FederationDiscovery::discover() reads the store before doing any network work,
 * so seeding it here makes the real discovery code path return mock data. The
 * traversal, HTTP fetching and signature validation are simply never reached --
 * everything downstream of the store is the library's own code.
 */
class MockEntityCollectionStore extends InMemoryEntityCollectionStore
{
    /**
     * @param non-empty-string $trustAnchorId Anchor the mock entities are seeded under.
     * @param array<string, array<string, mixed>>|null $entities Defaults to MockFederation::entities().
     */
    public function __construct(
        string $trustAnchorId,
        ?array $entities = null,
        int $ttl = 3600,
    ) {
        $entities ??= MockFederation::entities();

        // discover() sorts by entity ID before storing, so match that here to
        // keep mock and live behaviour identical.
        ksort($entities);

        $this->store($trustAnchorId, $entities, $ttl);
        $this->storeLastUpdated($trustAnchorId, time(), $ttl);
    }
}
