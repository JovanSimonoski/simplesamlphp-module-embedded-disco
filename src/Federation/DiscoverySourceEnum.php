<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

/**
 * Where the entity collection behind a discovery result came from.
 *
 * The picker renders the same way for all three, but which one served a request
 * decides how much of it happened over the network, and how much the result can
 * be trusted to be complete.
 */
enum DiscoverySourceEnum: string
{
    /**
     * The bundled fixture, seeded into the library's in-memory entity
     * collection store. No network access at all.
     */
    case Mock = 'mock';

    /**
     * A remote federation_collection_endpoint: one request, with the filtering
     * and paging done by the endpoint.
     */
    case CollectionEndpoint = 'collection_endpoint';

    /**
     * A top-down traversal from the Trust Anchor: one subordinate listing per
     * authority plus one Entity Configuration per entity.
     */
    case Traversal = 'traversal';


    public function isLive(): bool
    {
        return $this !== self::Mock;
    }
}
