<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function trim;

/**
 * One user-facing discovery request: what to search for, and where in the
 * result set we are.
 *
 * The field names match the OpenID Federation entity collection query
 * parameters, so the same query maps onto a remote collection endpoint later
 * without translation.
 */
class DiscoveryQuery
{
    /**
     * @param string[] $entityTypes
     * @param string[] $trustMarkTypes
     * @param positive-int $limit
     * @param 'asc'|'desc' $sortOrder
     */
    public function __construct(
        public readonly string $query = '',
        public readonly array $entityTypes = [],
        public readonly array $trustMarkTypes = [],
        public readonly int $limit = 6,
        public readonly ?string $from = null,
        public readonly string $sortOrder = 'asc',
    ) {
    }


    public static function fromRequest(Request $request, ModuleConfig $moduleConfig): self
    {
        // Read the bag wholesale: trust_mark_type arrives as a scalar from a
        // form and as a list from an API caller, and InputBag::all($key) rejects
        // the scalar form outright.
        $parameters = $request->query->all();

        $sortOrder = $parameters['sort_dir'] ?? null;
        $query = $parameters['query'] ?? '';

        return new self(
            query: is_string($query) ? trim($query) : '',
            // Not taken from the request. Which entity types a picker offers is
            // a property of the deployment, not a choice the person logging in
            // gets to make: only an OpenID Provider can authenticate them, and
            // a Relying Party or an intermediate authority in the list would be
            // a row that cannot lead anywhere.
            entityTypes: $moduleConfig->getEntityTypes(),
            trustMarkTypes: self::stringList($parameters['trust_mark_type'] ?? null)
                ?: $moduleConfig->getRequiredTrustMarkTypes(),
            limit: $moduleConfig->getPageSize(),
            from: self::nullableString($parameters['from'] ?? null),
            sortOrder: $sortOrder === 'desc' ? 'desc' : $moduleConfig->getSortOrder(),
        );
    }


    /**
     * Filter criteria in the shape EntityCollection::filter() expects.
     *
     * @return array{entity_type?: string[], trust_mark_type?: string[], query?: string}
     */
    public function toFilterCriteria(): array
    {
        $criteria = [];

        if ($this->entityTypes !== []) {
            $criteria['entity_type'] = $this->entityTypes;
        }

        if ($this->trustMarkTypes !== []) {
            $criteria['trust_mark_type'] = $this->trustMarkTypes;
        }

        if ($this->query !== '') {
            $criteria['query'] = $this->query;
        }

        return $criteria;
    }


    /**
     * The same criteria as query parameters for a remote
     * federation_collection_endpoint, which filters and pages server side.
     *
     * The endpoint serves whichever Trust Anchors it knows about, so the anchor
     * travels with the query rather than being implied by it.
     *
     * @param non-empty-string $trustAnchorId
     * @return array{
     *     entity_type?: string[],
     *     trust_mark_type?: string[],
     *     query?: string,
     *     trust_anchor?: string,
     *     limit?: positive-int,
     *     from?: string,
     * }
     */
    public function toCollectionEndpointParams(string $trustAnchorId): array
    {
        $parameters = $this->toFilterCriteria();

        $parameters['trust_anchor'] = $trustAnchorId;
        $parameters['limit'] = $this->limit;

        if ($this->from !== null) {
            $parameters['from'] = $this->from;
        }

        return $parameters;
    }


    /**
     * Normalize a scalar or list query parameter to a list of non-empty strings.
     *
     * @return string[]
     */
    protected static function stringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        ));
    }


    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
