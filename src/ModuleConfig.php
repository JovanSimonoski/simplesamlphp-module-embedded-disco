<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco;

use SimpleSAML\Configuration;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

use function array_filter;
use function array_values;
use function in_array;
use function is_string;
use function max;
use function rtrim;
use function sys_get_temp_dir;

/**
 * Typed access to config/module_embeddeddisco.php.
 */
class ModuleConfig
{
    public const string MODULE_NAME = 'embeddeddisco';

    public const string CONFIG_FILE_NAME = 'module_embeddeddisco.php';

    public const string OPTION_TRUST_ANCHOR_ID = 'trust_anchor_id';

    public const string OPTION_ENTITY_TYPES = 'entity_types';

    public const string OPTION_REQUIRED_TRUST_MARK_TYPES = 'required_trust_mark_types';

    public const string OPTION_PAGE_SIZE = 'page_size';

    public const string OPTION_SORT_ORDER = 'sort_order';

    public const string OPTION_USE_MOCK_DATA = 'use_mock_data';

    public const string OPTION_MAX_DISCOVERY_DEPTH = 'max_discovery_depth';

    public const string OPTION_MAX_DISCOVERED_ENTITIES = 'max_discovered_entities';

    public const string OPTION_COLLECTION_ENDPOINT = 'collection_endpoint';

    public const string OPTION_HTTP_CONNECT_TIMEOUT = 'http_connect_timeout';

    public const string OPTION_HTTP_TIMEOUT = 'http_timeout';

    public const string OPTION_VERIFY_SELECTION = 'verify_selection';

    public const string OPTION_VALIDATE_TRUST_MARKS = 'validate_trust_marks';

    public const string OPTION_EXPOSE_ERROR_DETAILS = 'expose_error_details';

    public const string OPTION_SIGNATURE_ALGORITHMS = 'signature_algorithms';

    public const string OPTION_CLIENTS = 'clients';

    public const string OPTION_SCOPES = 'scopes';

    public const string OPTION_FEDERATION_ENTITY_ID = 'federation_entity_id';

    public const string OPTION_FEDERATION_ENTITY_ROLE = 'federation_entity_role';

    public const string OPTION_FEDERATION_AUTHORITY_HINTS = 'federation_authority_hints';

    public const string OPTION_FEDERATION_SUBORDINATES = 'federation_subordinates';

    public const string OPTION_FEDERATION_PRIVATE_KEY = 'federation_private_key';

    public const string OPTION_PROTOCOL_PRIVATE_KEY = 'protocol_private_key';

    public const string OPTION_FEDERATION_REDIRECT_URIS = 'federation_redirect_uris';

    public const string OPTION_FEDERATION_DISPLAY_NAME = 'federation_display_name';

    public const string OPTION_FEDERATION_STATEMENT_TTL = 'federation_statement_ttl';

    public const string OPTION_HTTP_CA_BUNDLE = 'http_ca_bundle';

    public const string OPTION_CACHE_DIRECTORY = 'cache_directory';

    public const string OPTION_CACHE_DURATION = 'cache_duration';

    public const string FEDERATION_ROLE_RELYING_PARTY = 'openid_relying_party';

    public const string FEDERATION_ROLE_TRUST_ANCHOR = 'trust_anchor';

    /**
     * Ask the Trust Anchor's own Entity Configuration whether it offers a
     * federation_collection_endpoint, instead of naming one here.
     */
    public const string COLLECTION_ENDPOINT_AUTO = 'auto';

    /**
     * Fallback Trust Anchor, used when the module has no config file.
     *
     * The GÉANT Trust and Identity Incubator runs this one as an OpenID
     * Federation testbed; its subordinates are the demo OP and RP on the same
     * domain.
     */
    public const string DEFAULT_TRUST_ANCHOR_ID = 'https://oidfed-ta-demo.incubator.geant.org';


    protected Configuration $config;


    public function __construct(?Configuration $config = null)
    {
        $this->config = $config ?? Configuration::getOptionalConfig(self::CONFIG_FILE_NAME);
    }


    /**
     * @return non-empty-string
     */
    public function getTrustAnchorId(): string
    {
        $value = (string) $this->config->getOptionalString(
            self::OPTION_TRUST_ANCHOR_ID,
            self::DEFAULT_TRUST_ANCHOR_ID,
        );

        // The library types the Trust Anchor ID as non-empty-string.
        return $value === '' ? self::DEFAULT_TRUST_ANCHOR_ID : $value;
    }


    /**
     * Entity types the picker offers, e.g. ['openid_provider'].
     *
     * @return string[]
     */
    public function getEntityTypes(): array
    {
        return $this->config->getOptionalArray(
            self::OPTION_ENTITY_TYPES,
            [EntityTypesEnum::OpenIdProvider->value],
        );
    }


    /**
     * @return string[]
     */
    public function getRequiredTrustMarkTypes(): array
    {
        return $this->config->getOptionalArray(self::OPTION_REQUIRED_TRUST_MARK_TYPES, []);
    }


    /**
     * @return positive-int
     */
    public function getPageSize(): int
    {
        return max(1, $this->config->getOptionalInteger(self::OPTION_PAGE_SIZE, 6));
    }


    /**
     * @return 'asc'|'desc'
     */
    public function getSortOrder(): string
    {
        return $this->config->getOptionalString(self::OPTION_SORT_ORDER, 'asc') === 'desc' ? 'desc' : 'asc';
    }


    /**
     * Serve discovery from the bundled fixture instead of a live federation.
     *
     * Off by default: the module talks to a real federation, and the fixture is
     * only there for offline demos and tests.
     */
    public function useMockData(): bool
    {
        return $this->config->getOptionalBoolean(self::OPTION_USE_MOCK_DATA, false);
    }


    public function getMaxDiscoveryDepth(): int
    {
        return $this->config->getOptionalInteger(self::OPTION_MAX_DISCOVERY_DEPTH, 10);
    }


    /**
     * Ceiling on how many entities one traversal may collect. Depth alone does
     * not bound the work, since a single listing can name any number of
     * subordinates.
     *
     * @return positive-int
     */
    public function getMaxDiscoveredEntities(): int
    {
        return max(1, $this->config->getOptionalInteger(self::OPTION_MAX_DISCOVERED_ENTITIES, 1000));
    }


    /**
     * A remote federation_collection_endpoint to read the entity collection
     * from, 'auto' to take it from the Trust Anchor's Entity Configuration, or
     * null to always traverse.
     *
     * Turning it off is configured as false rather than null: SimpleSAMLphp's
     * Configuration reads a null value as "not set", so null would silently mean
     * 'auto' here.
     */
    public function getCollectionEndpoint(): ?string
    {
        $value = $this->config->getOptionalValue(
            self::OPTION_COLLECTION_ENDPOINT,
            self::COLLECTION_ENDPOINT_AUTO,
        );

        if ($value === false || $value === '') {
            return null;
        }

        return is_string($value) ? $value : self::COLLECTION_ENDPOINT_AUTO;
    }


    public function isCollectionEndpointAutomatic(): bool
    {
        return $this->getCollectionEndpoint() === self::COLLECTION_ENDPOINT_AUTO;
    }


    /**
     * Seconds to wait for a federation endpoint to accept the connection. This
     * is what bounds a Trust Anchor that is simply dark, rather than slow.
     */
    public function getHttpConnectTimeout(): float
    {
        return max(0.1, (float) $this->config->getOptionalValue(self::OPTION_HTTP_CONNECT_TIMEOUT, 3));
    }


    /**
     * Seconds to wait for a single federation request to complete.
     */
    public function getHttpTimeout(): float
    {
        return max(0.1, (float) $this->config->getOptionalValue(self::OPTION_HTTP_TIMEOUT, 5));
    }


    /**
     * Resolve a Trust Chain for the entity the user picked before handing off
     * to it. Discovery itself only lists candidates; this is what establishes
     * that the candidate really is part of the federation.
     */
    public function verifySelection(): bool
    {
        return $this->config->getOptionalBoolean(self::OPTION_VERIFY_SELECTION, true);
    }


    /**
     * Validate the picked entity's Trust Marks (signature, issuer, delegation)
     * rather than trusting the self-asserted trust_mark_type alone.
     */
    public function validateTrustMarks(): bool
    {
        return $this->config->getOptionalBoolean(self::OPTION_VALIDATE_TRUST_MARKS, true);
    }


    /**
     * Show the technical reason a discovery or verification failed in the UI.
     * Useful while integrating, noise (and information disclosure) in front of
     * end users.
     */
    public function exposeErrorDetails(): bool
    {
        return $this->config->getOptionalBoolean(self::OPTION_EXPOSE_ERROR_DETAILS, true);
    }


    /**
     * Signature algorithms an entity statement may be signed with.
     *
     * The library defaults to RS256 alone, which is not enough to read a real
     * federation: the demo federations already sign with ES256 and ES512, and a
     * statement signed with an algorithm that is not enabled does not fail
     * loudly -- it makes the Trust Chain unresolvable.
     *
     * "none" is dropped wherever it is configured. Accepting it would mean
     * accepting an unsigned entity statement as valid.
     *
     * @return non-empty-array<int, \SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum>
     */
    public function getSignatureAlgorithms(): array
    {
        $configured = $this->config->getOptionalArray(self::OPTION_SIGNATURE_ALGORITHMS, null);

        if ($configured === null) {
            return self::defaultSignatureAlgorithms();
        }

        $algorithms = [];

        foreach ($configured as $name) {
            $signatureAlgorithmEnum = is_string($name) ? SignatureAlgorithmEnum::tryFrom($name) : null;

            if ($signatureAlgorithmEnum === null || $signatureAlgorithmEnum->isNone()) {
                continue;
            }

            $algorithms[] = $signatureAlgorithmEnum;
        }

        return $algorithms === [] ? self::defaultSignatureAlgorithms() : $algorithms;
    }


    /**
     * Every algorithm the library can verify, except "none".
     *
     * @return non-empty-array<int, \SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum>
     */
    protected static function defaultSignatureAlgorithms(): array
    {
        /** @var non-empty-array<int, \SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum> $algorithms */
        $algorithms = array_values(array_filter(
            SignatureAlgorithmEnum::cases(),
            static fn(SignatureAlgorithmEnum $signatureAlgorithmEnum): bool => !$signatureAlgorithmEnum->isNone(),
        ));

        return $algorithms;
    }


    /**
     * Client credentials this relying party holds at a given provider, keyed by
     * the provider's issuer.
     *
     * These are a legacy fallback for providers that do not support automatic
     * registration. A configured federation RP instead uses its entity ID as
     * its client ID and proves its registration through its Trust Chain.
     *
     * @return ?array{client_id: string, client_secret: string, scopes?: string[]}
     */
    public function getClientForIssuer(string $issuer): ?array
    {
        $clients = $this->config->getOptionalArray(self::OPTION_CLIENTS, []);

        $client = $clients[$issuer] ?? null;

        if (!is_array($client)) {
            return null;
        }

        $clientId = $client['client_id'] ?? null;
        $clientSecret = $client['client_secret'] ?? null;

        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            return null;
        }

        /** @var array{client_id: string, client_secret: string, scopes?: string[]} $client */
        return $client;
    }


    /**
     * Entity Identifier published by this module when it acts as a federation
     * leaf (the RP) or as the local Trust Anchor.
     */
    public function getFederationEntityId(): ?string
    {
        $entityId = $this->config->getOptionalString(self::OPTION_FEDERATION_ENTITY_ID, null);

        return ($entityId === null || $entityId === '') ? null : rtrim($entityId, '/');
    }


    public function getFederationEntityRole(): ?string
    {
        $role = $this->config->getOptionalString(self::OPTION_FEDERATION_ENTITY_ROLE, null);

        return in_array($role, [self::FEDERATION_ROLE_RELYING_PARTY, self::FEDERATION_ROLE_TRUST_ANCHOR], true)
            ? $role
            : null;
    }


    public function isFederationRelyingParty(): bool
    {
        return $this->getFederationEntityId() !== null
            && $this->getFederationEntityRole() === self::FEDERATION_ROLE_RELYING_PARTY;
    }


    public function isFederationTrustAnchor(): bool
    {
        return $this->getFederationEntityId() !== null
            && $this->getFederationEntityRole() === self::FEDERATION_ROLE_TRUST_ANCHOR;
    }


    /**
     * @return string[]
     */
    public function getFederationAuthorityHints(): array
    {
        return $this->stringList(self::OPTION_FEDERATION_AUTHORITY_HINTS);
    }


    /**
     * The explicit enrollment allow-list used by the local Trust Anchor.
     *
     * @return string[]
     */
    public function getFederationSubordinates(): array
    {
        return $this->stringList(self::OPTION_FEDERATION_SUBORDINATES);
    }


    public function getFederationPrivateKey(): ?string
    {
        return $this->nonEmptyString(self::OPTION_FEDERATION_PRIVATE_KEY);
    }


    public function getProtocolPrivateKey(): ?string
    {
        return $this->nonEmptyString(self::OPTION_PROTOCOL_PRIVATE_KEY);
    }


    /**
     * @return string[]
     */
    public function getFederationRedirectUris(): array
    {
        return $this->stringList(self::OPTION_FEDERATION_REDIRECT_URIS);
    }


    public function getFederationDisplayName(): string
    {
        return $this->config->getOptionalString(
            self::OPTION_FEDERATION_DISPLAY_NAME,
            'SimpleSAMLphp embedded discovery',
        );
    }


    public function getFederationStatementTtl(): int
    {
        return max(60, $this->config->getOptionalInteger(self::OPTION_FEDERATION_STATEMENT_TTL, 86400));
    }


    /**
     * Scopes to ask for, unless the client entry overrides them.
     *
     * @return non-empty-array<int, string>
     */
    public function getScopes(): array
    {
        $scopes = array_values(array_filter(
            $this->config->getOptionalArray(self::OPTION_SCOPES, ['openid']),
            static fn(mixed $scope): bool => is_string($scope) && $scope !== '',
        ));

        /** @var non-empty-array<int, string> $result */
        $result = $scopes === [] ? ['openid'] : $scopes;

        return $result;
    }


    /**
     * @return string[]
     */
    protected function stringList(string $option): array
    {
        return array_values(array_filter(
            $this->config->getOptionalArray($option, []),
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        ));
    }


    protected function nonEmptyString(string $option): ?string
    {
        $value = $this->config->getOptionalString($option, null);

        return ($value === null || $value === '') ? null : $value;
    }


    /**
     * A CA bundle to verify federation and provider endpoints against, for a
     * private federation whose certificates a public trust store does not know.
     *
     * This adds a trust anchor for TLS; it never turns verification off.
     */
    public function getHttpCaBundle(): ?string
    {
        $bundle = $this->config->getOptionalString(self::OPTION_HTTP_CA_BUNDLE, null);

        return ($bundle === null || $bundle === '') ? null : $bundle;
    }


    public function getCacheDirectory(): string
    {
        $configured = $this->config->getOptionalString(self::OPTION_CACHE_DIRECTORY, null);
        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        $sspConfig = Configuration::getInstance();
        // 'tempdir' is deprecated in favour of 'cachedir'; fall back for older installs.
        $cacheDir = $sspConfig->getPathValue('cachedir')
            ?? $sspConfig->getPathValue('tempdir', sys_get_temp_dir());

        return rtrim((string) $cacheDir, '/') . '/embeddeddisco';
    }


    public function getCacheDuration(): int
    {
        return max(1, $this->config->getOptionalInteger(self::OPTION_CACHE_DURATION, 3600));
    }
}
