<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Federation\TrustChain;
use Throwable;

use function in_array;

/**
 * Verifies the entity a user picked, by resolving its Trust Chain to the
 * configured Trust Anchor.
 *
 * This is the half of OpenID Federation discovery that the entity listing does
 * not do. A discovered Entity Configuration is self-asserted: it is signed with
 * the entity's own keys, and says whatever the entity wants it to say. What
 * makes it a member of the federation is an unbroken chain of Subordinate
 * Statements from the Trust Anchor down to it, and what makes its metadata
 * usable is that chain's metadata policy applied on the way down.
 *
 * Resolving the chain costs several fetches, which is why it happens for the one
 * entity the user picked rather than for every row of the picker.
 */
class TrustChainService
{
    protected readonly Federation $federation;


    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        ?FederationFactory $federationFactory = null,
    ) {
        $this->federation = ($federationFactory ?? new FederationFactory($moduleConfig))->build();
    }


    public function verify(string $entityId): SelectionVerification
    {
        $trustAnchorId = $this->moduleConfig->getTrustAnchorId();

        // Mock entities exist only in this process, and there is nothing to
        // resolve them against.
        if ($this->moduleConfig->useMockData() || !$this->moduleConfig->verifySelection()) {
            return SelectionVerification::skipped($entityId, $trustAnchorId);
        }

        if ($entityId === '') {
            return SelectionVerification::failed($entityId, $trustAnchorId, 'No entity was selected.');
        }

        try {
            $trustChain = $this->federation->trustChainResolver()
                ->for($entityId, [$trustAnchorId])
                // Several chains can lead to the same Trust Anchor when an
                // entity has more than one authority. The shortest is the one
                // with the fewest intermediates able to rewrite the metadata.
                ->getShortest();
        } catch (Throwable $throwable) {
            return SelectionVerification::failed($entityId, $trustAnchorId, $this->shorten($throwable->getMessage()));
        }

        try {
            [$entityType, $metadata] = $this->resolveMetadata($trustChain);

            return new SelectionVerification(
                entityId: $entityId,
                trustAnchorId: $trustAnchorId,
                verified: true,
                chain: $this->chainPath($trustChain),
                expiresAt: $trustChain->getResolvedExpirationTime(),
                entityType: $entityType,
                metadata: $metadata,
                trustMarks: $this->trustMarks($trustChain),
            );
        } catch (Throwable $throwable) {
            return SelectionVerification::failed($entityId, $trustAnchorId, $this->shorten($throwable->getMessage()));
        }
    }


    /**
     * Metadata for the first configured entity type the chain resolves, and the
     * type it belongs to.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     * @throws \SimpleSAML\OpenID\Exceptions\OpenIdException
     */
    protected function resolveMetadata(TrustChain $trustChain): array
    {
        $entityTypes = [
            ...$this->moduleConfig->getEntityTypes(),
            EntityTypesEnum::OpenIdProvider->value,
            EntityTypesEnum::FederationEntity->value,
        ];

        foreach ($entityTypes as $entityType) {
            $entityTypesEnum = EntityTypesEnum::tryFrom((string) $entityType);

            if (!$entityTypesEnum instanceof EntityTypesEnum) {
                continue;
            }

            $metadata = $trustChain->getResolvedMetadata($entityTypesEnum);

            if ($metadata !== null && $metadata !== []) {
                return [$entityTypesEnum->value, $metadata];
            }
        }

        return [null, []];
    }


    /**
     * The chain as entity IDs, from the picked entity up to the Trust Anchor.
     *
     * Read off subject and issuer of each statement rather than assuming a
     * shape: the first element is the leaf's own Entity Configuration, the last
     * the Trust Anchor's, and the Subordinate Statements in between each name
     * their issuer (the superior) and subject (the subordinate).
     *
     * @return string[]
     * @throws \SimpleSAML\OpenID\Exceptions\OpenIdException
     */
    protected function chainPath(TrustChain $trustChain): array
    {
        $path = [];

        foreach ($trustChain->getEntities() as $entityStatement) {
            foreach ([$entityStatement->getSubject(), $entityStatement->getIssuer()] as $entityId) {
                if (!in_array($entityId, $path, true)) {
                    $path[] = $entityId;
                }
            }
        }

        return $path;
    }


    /**
     * The leaf's Trust Marks, each either validated or reported as unvalidated
     * with the reason.
     *
     * Validation is per mark: the signature, the issuer's own right to issue
     * that type (which is another Trust Chain), and any delegation. One bad mark
     * does not invalidate the entity, it just does not earn a badge.
     *
     * @return array<int, array{type: string, validated: bool, error: ?string}>
     * @throws \SimpleSAML\OpenID\Exceptions\OpenIdException
     */
    protected function trustMarks(TrustChain $trustChain): array
    {
        $leafConfiguration = $trustChain->getResolvedLeaf();
        $trustMarksClaimBag = $leafConfiguration->getTrustMarks();

        if ($trustMarksClaimBag === null) {
            return [];
        }

        $shouldValidate = $this->moduleConfig->validateTrustMarks();
        $trustAnchorConfiguration = $trustChain->getResolvedTrustAnchor();
        $trustMarks = [];

        foreach ($trustMarksClaimBag->getAll() as $trustMarksClaimValue) {
            if (!$shouldValidate) {
                $trustMarks[] = [
                    'type' => $trustMarksClaimValue->getTrustMarkType(),
                    'validated' => false,
                    'error' => null,
                ];

                continue;
            }

            try {
                $this->federation->trustMarkValidator()->fromCacheOrDoForTrustMarksClaimValue(
                    $trustMarksClaimValue,
                    $leafConfiguration,
                    $trustAnchorConfiguration,
                );

                $trustMarks[] = [
                    'type' => $trustMarksClaimValue->getTrustMarkType(),
                    'validated' => true,
                    'error' => null,
                ];
            } catch (Throwable $throwable) {
                $trustMarks[] = [
                    'type' => $trustMarksClaimValue->getTrustMarkType(),
                    'validated' => false,
                    'error' => $this->shorten($throwable->getMessage()),
                ];
            }
        }

        return $trustMarks;
    }


    protected function shorten(string $message): string
    {
        return ErrorDetail::shorten($message);
    }
}
