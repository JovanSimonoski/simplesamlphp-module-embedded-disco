<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Federation\EntityCollection;
use SimpleSAML\OpenID\Helpers;

use function count;
use function is_array;
use function is_string;

/**
 * Runs the library's discovery pipeline for the embedded picker.
 *
 * The pipeline -- discover, filter, sort, paginate, serialize -- is the one the
 * library documents for implementing a federation_collection_endpoint. Doing the
 * same thing here means the picker and a future collection endpoint stay
 * consistent by construction.
 */
class DiscoveryService
{
    protected readonly Federation $federation;

    protected readonly Helpers $helpers;


    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        ?FederationFactory $federationFactory = null,
    ) {
        $this->federation = ($federationFactory ?? new FederationFactory($moduleConfig))->build();
        // The library's own helpers, rather than hand-rolled array poking.
        $this->helpers = $this->federation->helpers();
    }


    /**
     * @return array{
     *     collection: \SimpleSAML\OpenID\Federation\EntityCollection,
     *     total: int,
     *     entities: array<int, array<string, mixed>>,
     *     nextPageToken: ?string,
     *     lastUpdated: ?int,
     *     trustAnchorId: string
     * }
     */
    public function discover(DiscoveryQuery $discoveryQuery): array
    {
        $trustAnchorId = $this->moduleConfig->getTrustAnchorId();

        // 1. Discover. Reads the entity collection store; only traverses the
        //    federation over the network when the store has nothing.
        $collection = $this->federation->federationDiscovery()->discover($trustAnchorId);

        // 2. Filter, on the spec's own criteria: entity_type (OR),
        //    trust_mark_type (AND) and a case-insensitive query over
        //    sub / display_name / organization_name.
        $collection->filter($discoveryQuery->toFilterCriteria());

        // Total matches, captured before the page is cut.
        $total = count($collection->getEntities());

        // 3. Sort by display name, falling back through entity types and
        //    finally the entity ID so the order is always total.
        $collection->sort($this->sortClaimPaths(), $discoveryQuery->sortOrder);

        // 4. Paginate with the library's opaque cursors.
        $collection->paginate($discoveryQuery->limit, $discoveryQuery->from);

        return [
            'collection' => $collection,
            'total' => $total,
            // 5. Serialize to the spec-compliant entity collection shape.
            'entities' => $this->presentableEntities($collection),
            'nextPageToken' => $collection->getNextPageToken(),
            'lastUpdated' => $collection->getLastUpdated(),
            'trustAnchorId' => $trustAnchorId,
        ];
    }


    /**
     * The spec-shaped response array, exactly as a collection endpoint returns it.
     *
     * @return array{entities: array<int, array<string, mixed>>, next?: string, last_updated?: int}
     */
    public function toCollectionEndpointResponse(EntityCollection $entityCollection): array
    {
        return $entityCollection->toCollectionEndpointResponseArray();
    }


    /**
     * Flatten the serialized collection into rows the template can render.
     *
     * Built from toCollectionEndpointResponseArray() rather than the raw
     * payloads, so the picker consumes precisely what a remote collection
     * endpoint would hand it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function presentableEntities(EntityCollection $entityCollection): array
    {
        $response = $entityCollection->toCollectionEndpointResponseArray();
        $rows = [];

        foreach ($response[ClaimsEnum::Entities->value] as $entity) {
            $entityId = is_string($entity[ClaimsEnum::EntityId->value] ?? null)
                ? $entity[ClaimsEnum::EntityId->value]
                : '';
            $entityTypes = is_array($entity[ClaimsEnum::EntityTypes->value] ?? null)
                ? $entity[ClaimsEnum::EntityTypes->value]
                : [];
            $uiInfos = is_array($entity[ClaimsEnum::UiInfos->value] ?? null)
                ? $entity[ClaimsEnum::UiInfos->value]
                : [];

            $rows[] = [
                'entityId' => $entityId,
                'entityTypes' => $entityTypes,
                'displayName' => $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::DisplayName) ?? $entityId,
                'organizationName' => $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::OrganizationName),
                'description' => $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::Description),
                'logoUri' => $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::LogoUri),
                'informationUri' => $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::InformationUri),
                'trustMarkTypes' => $this->trustMarkTypes($entity),
            ];
        }

        return $rows;
    }


    /**
     * First non-empty value of $claim across the entity's types, preferring the
     * configured types over federation_entity.
     *
     * @param array<string, mixed> $uiInfos
     * @param array<int, mixed> $entityTypes
     */
    protected function firstClaim(array $uiInfos, array $entityTypes, ClaimsEnum $claimsEnum): ?string
    {
        $ordered = [
            ...$this->moduleConfig->getEntityTypes(),
            ...$entityTypes,
            EntityTypesEnum::FederationEntity->value,
        ];

        foreach ($ordered as $entityType) {
            if (!is_string($entityType)) {
                continue;
            }

            $value = $this->helpers->arr()->getNestedValue($uiInfos, $entityType, $claimsEnum->value);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }


    /**
     * @param array<string, mixed> $entity
     * @return string[]
     */
    protected function trustMarkTypes(array $entity): array
    {
        $trustMarks = $entity[ClaimsEnum::TrustMarks->value] ?? null;
        if (!is_array($trustMarks)) {
            return [];
        }

        $types = [];
        foreach ($trustMarks as $trustMark) {
            if (!is_array($trustMark)) {
                continue;
            }

            $type = $trustMark[ClaimsEnum::TrustMarkType->value] ?? null;
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }

        return $types;
    }


    /**
     * Display-name paths to sort on, most specific first, ending at the entity
     * ID so entities missing a display name still order deterministically.
     *
     * @return non-empty-array<int, non-empty-string[]>
     */
    protected function sortClaimPaths(): array
    {
        $paths = [];

        foreach ($this->moduleConfig->getEntityTypes() as $entityType) {
            if ($entityType === '') {
                continue;
            }

            $paths[] = [ClaimsEnum::Metadata->value, $entityType, ClaimsEnum::DisplayName->value];
        }

        $paths[] = [
            ClaimsEnum::Metadata->value,
            EntityTypesEnum::FederationEntity->value,
            ClaimsEnum::DisplayName->value,
        ];
        $paths[] = [ClaimsEnum::Sub->value];

        return $paths;
    }
}
