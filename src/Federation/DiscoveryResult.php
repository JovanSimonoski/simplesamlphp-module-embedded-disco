<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\OpenID\Federation\EntityCollection;

/**
 * One discovery run: the page of entities to render, and enough about how it was
 * produced for the caller to say so.
 *
 * Discovery failure is a normal outcome here rather than an exception. The
 * library's discover() reports a federation it could not reach by logging and
 * returning nothing, and a picker that cannot reach its Trust Anchor still has
 * to render -- with an explanation instead of a silently empty list.
 */
class DiscoveryResult
{
    /**
     * @param array<int, array<string, mixed>> $entities Rows for the template, one per entity on this page.
     * @param ?int $total Entities matching the criteria, before paging. Null when a remote collection
     * endpoint paged the result, since only the endpoint knows how many there were.
     * @param ?string $error Technical reason discovery produced nothing, or null when it did not fail.
     */
    public function __construct(
        public readonly EntityCollection $collection,
        public readonly array $entities,
        public readonly ?int $total,
        public readonly ?string $nextPageToken,
        public readonly ?int $lastUpdated,
        public readonly string $trustAnchorId,
        public readonly DiscoverySourceEnum $source,
        public readonly ?string $error = null,
    ) {
    }


    public function hasFailed(): bool
    {
        return $this->error !== null;
    }
}
