<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use SimpleSAML\OpenID\Codebooks\ClaimsEnum;

use function is_string;

/**
 * What could be established about the entity a user picked.
 *
 * Discovery answers "which entities are out there"; this answers "is this one
 * really part of the federation, and what does the federation say it is". The
 * second question is the one an RP has to have answered before it sends a user
 * anywhere.
 */
class SelectionVerification
{
    /**
     * @param string[] $chain Entity IDs from the picked entity up to the Trust Anchor.
     * @param array<string, mixed> $metadata Policy-resolved metadata for $entityType.
     * @param array<int, array{type: string, validated: bool, error: ?string}> $trustMarks
     * @param bool $skipped Verification was not attempted -- mock mode, or verify_selection turned off.
     * Distinct from a verification that ran and failed.
     */
    public function __construct(
        public readonly string $entityId,
        public readonly string $trustAnchorId,
        public readonly bool $verified,
        public readonly ?string $error = null,
        public readonly array $chain = [],
        public readonly ?int $expiresAt = null,
        public readonly ?string $entityType = null,
        public readonly array $metadata = [],
        public readonly array $trustMarks = [],
        public readonly bool $skipped = false,
    ) {
    }


    public static function skipped(string $entityId, string $trustAnchorId): self
    {
        return new self(
            entityId: $entityId,
            trustAnchorId: $trustAnchorId,
            verified: false,
            skipped: true,
        );
    }


    public static function failed(string $entityId, string $trustAnchorId, string $error): self
    {
        return new self(
            entityId: $entityId,
            trustAnchorId: $trustAnchorId,
            verified: false,
            error: $error,
        );
    }


    /**
     * Display name from the resolved metadata, falling back to the entity ID.
     */
    public function getDisplayName(): string
    {
        foreach ([ClaimsEnum::DisplayName, ClaimsEnum::ClientName, ClaimsEnum::OrganizationName] as $claimsEnum) {
            $value = $this->metadata[$claimsEnum->value] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->entityId;
    }
}
