<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Federation\EntityCollection;
use SimpleSAML\OpenID\Helpers;
use Throwable;

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
 *
 * Two things about live discovery are worth keeping in mind while reading this:
 *
 * 1. The entities it returns are self-asserted Entity Configurations. Each one
 *    is signature checked against its own keys, but nothing here proves the
 *    entity really is subordinate to the Trust Anchor -- that is a Trust Chain
 *    resolution, and it happens once the user picks one. See TrustChainService.
 * 2. The library reports an unreachable federation by logging and returning an
 *    empty collection, so "no results" and "could not reach the Trust Anchor"
 *    arrive here looking identical. They are told apart below, because a picker
 *    that renders an empty list for a dead Trust Anchor is a bug report waiting
 *    to happen.
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
     * @param bool $forceRefresh Re-read the federation even if the entity collection store has it. Costs a
     * full traversal, so callers are expected to gate it.
     */
    public function discover(DiscoveryQuery $discoveryQuery, bool $forceRefresh = false): DiscoveryResult
    {
        $trustAnchorId = $this->moduleConfig->getTrustAnchorId();

        if ($this->moduleConfig->useMockData()) {
            return $this->fromEntityCollectionStore(
                $discoveryQuery,
                $trustAnchorId,
                DiscoverySourceEnum::Mock,
                // Never refreshed. A refresh tells discover() to ignore the
                // store, which in fixture mode is the only thing standing
                // between "no network access" and a live traversal of whichever
                // Trust Anchor happens to be configured.
                forceRefresh: false,
            );
        }

        $collectionEndpoint = $this->resolveCollectionEndpoint($trustAnchorId, $forceRefresh);

        if ($collectionEndpoint !== null) {
            $result = $this->fromCollectionEndpoint(
                $discoveryQuery,
                $trustAnchorId,
                $collectionEndpoint,
                $forceRefresh,
            );

            // Null means the endpoint could not serve us. It is an optimisation
            // over traversing, not a replacement for it, so fall through.
            if ($result instanceof DiscoveryResult) {
                return $result;
            }
        }

        return $this->fromEntityCollectionStore(
            $discoveryQuery,
            $trustAnchorId,
            DiscoverySourceEnum::Traversal,
            $forceRefresh,
        );
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
     * Discovery by traversal (or, in mock mode, from the seeded store), with the
     * filtering, sorting and paging done here.
     *
     * @param non-empty-string $trustAnchorId
     */
    protected function fromEntityCollectionStore(
        DiscoveryQuery $discoveryQuery,
        string $trustAnchorId,
        DiscoverySourceEnum $discoverySourceEnum,
        bool $forceRefresh,
    ): DiscoveryResult {
        // 1. Discover. Reads the entity collection store; only traverses the
        //    federation over the network when the store has nothing.
        //
        //    No filters are passed down to the subordinate listings on purpose.
        //    They would be applied at every authority, and an intermediate is a
        //    federation_entity: filtering the listings for openid_provider would
        //    prune the very nodes the traversal has to descend through.
        $collection = $this->federation->federationDiscovery()->discover($trustAnchorId, [], $forceRefresh);

        $error = $this->discoveryError($collection, $trustAnchorId, $discoverySourceEnum);

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

        return new DiscoveryResult(
            collection: $collection,
            // 5. Serialize to the spec-compliant entity collection shape.
            entities: $this->presentableEntities($collection),
            total: $total,
            nextPageToken: $collection->getNextPageToken(),
            lastUpdated: $collection->getLastUpdated(),
            trustAnchorId: $trustAnchorId,
            source: $discoverySourceEnum,
            error: $error,
        );
    }


    /**
     * Discovery by asking a remote federation_collection_endpoint, which filters
     * and pages server side.
     *
     * The criteria are not re-applied locally: the endpoint decides what matched
     * and what a page is, and re-running the filter over the trimmed ui_infos it
     * chose to return would drop entities it matched on claims it did not send.
     *
     * @param non-empty-string $trustAnchorId
     * @param non-empty-string $collectionEndpoint
     * @return ?\SimpleSAML\Module\embeddeddisco\Federation\DiscoveryResult Null when the endpoint could not
     * be used, so the caller can traverse instead.
     */
    protected function fromCollectionEndpoint(
        DiscoveryQuery $discoveryQuery,
        string $trustAnchorId,
        string $collectionEndpoint,
        bool $forceRefresh,
    ): ?DiscoveryResult {
        try {
            $collection = $this->federation->federationDiscovery()->fetchFromCollectionEndpoint(
                $collectionEndpoint,
                $discoveryQuery->toCollectionEndpointParams($trustAnchorId),
                $forceRefresh,
            );
        } catch (Throwable) {
            // Already logged by the library, with the endpoint and the cause.
            return null;
        }

        // Ordering is the one thing a collection endpoint has no parameter for,
        // so it is applied to the page that came back.
        $collection->sort($this->sortClaimPaths(), $discoveryQuery->sortOrder);

        return new DiscoveryResult(
            collection: $collection,
            entities: $this->presentableEntities($collection),
            // Only the endpoint knows how many entities matched in total; what
            // came back is one page of them.
            total: null,
            nextPageToken: $collection->getNextPageToken(),
            lastUpdated: $collection->getLastUpdated(),
            trustAnchorId: $trustAnchorId,
            source: DiscoverySourceEnum::CollectionEndpoint,
            error: null,
        );
    }


    /**
     * The collection endpoint to read from, or null to traverse.
     *
     * Configured as 'auto', the Trust Anchor is asked whether it offers one. Its
     * Entity Configuration has to be fetched for the traversal anyway, and the
     * fetcher caches it, so the answer is effectively free.
     *
     * Not asked at all while a discovered collection is already stored: that
     * request would be a fetch made only to decide not to fetch, and it would be
     * paid on every page view for as long as the store stays warm.
     *
     * @param non-empty-string $trustAnchorId
     * @return ?non-empty-string
     */
    protected function resolveCollectionEndpoint(string $trustAnchorId, bool $forceRefresh): ?string
    {
        $configured = $this->moduleConfig->getCollectionEndpoint();

        if ($configured === null) {
            return null;
        }

        if (!$this->moduleConfig->isCollectionEndpointAutomatic()) {
            return $configured;
        }

        if (!$forceRefresh && $this->federation->entityCollectionStore()->get($trustAnchorId) !== null) {
            return null;
        }

        try {
            return $this->federation->entityStatementFetcher()
                ->fromCacheOrWellKnownEndpoint($trustAnchorId)
                ->getFederationCollectionEndpoint();
        } catch (Throwable) {
            // Unreachable Trust Anchor. Reported once, by the traversal that
            // follows and fails for the same reason.
            return null;
        }
    }


    /**
     * Why a discovery came back with nothing, or null when it did not.
     *
     * FederationDiscovery::discover() catches everything it hits, so a failed
     * run is only recognisable by its shape: no entities and no last-updated
     * stamp. A successful traversal always holds at least the Trust Anchor
     * itself, and always stamps the time.
     *
     * @param non-empty-string $trustAnchorId
     */
    protected function discoveryError(
        EntityCollection $entityCollection,
        string $trustAnchorId,
        DiscoverySourceEnum $discoverySourceEnum,
    ): ?string {
        if (!$discoverySourceEnum->isLive()) {
            return null;
        }

        if ($entityCollection->getEntities() !== [] || $entityCollection->getLastUpdated() !== null) {
            return null;
        }

        // One extra request, on a path that has already failed, to turn "no
        // results" into something an operator can act on.
        try {
            $this->federation->entityStatementFetcher()->fromCacheOrWellKnownEndpoint($trustAnchorId);
        } catch (Throwable $throwable) {
            return $this->shorten($throwable->getMessage());
        }

        return 'The Trust Anchor answered, but no entities could be discovered beneath it.';
    }


    protected function shorten(string $message): string
    {
        return ErrorDetail::shorten($message);
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
                'displayName' => $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::DisplayName)
                    ?? $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::ClientName)
                    ?? $this->firstClaim($uiInfos, $entityTypes, ClaimsEnum::OrganizationName)
                    ?? $entityId,
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
     * Trust Mark types the entity claims, unvalidated.
     *
     * A real Trust Mark is a signed JWT alongside this type, and checking one
     * costs a Trust Chain resolution per mark. That is affordable for the single
     * entity a user picks, not for every row of every page, so the badges are
     * claims until TrustChainService confirms them.
     *
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
