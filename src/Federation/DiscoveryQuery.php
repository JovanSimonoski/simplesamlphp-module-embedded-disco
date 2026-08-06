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
        // Read the bag wholesale: entity_type and trust_mark_type arrive as a
        // scalar from the picker's <select> and as a list from an API caller,
        // and InputBag::all($key) rejects the scalar form outright.
        $parameters = $request->query->all();

        $sortOrder = $parameters['sort_dir'] ?? null;
        $query = $parameters['query'] ?? '';

        return new self(
            query: is_string($query) ? trim($query) : '',
            entityTypes: self::stringList($parameters['entity_type'] ?? null)
                ?: $moduleConfig->getEntityTypes(),
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
