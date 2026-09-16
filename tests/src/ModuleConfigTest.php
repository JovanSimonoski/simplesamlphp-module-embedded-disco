<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;

#[CoversClass(ModuleConfig::class)]
class ModuleConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected function moduleConfig(array $options = []): ModuleConfig
    {
        return new ModuleConfig(Configuration::loadFromArray($options));
    }


    public function testDefaultsToLiveDiscoveryAgainstTheConfiguredAnchor(): void
    {
        $moduleConfig = $this->moduleConfig();

        $this->assertFalse($moduleConfig->useMockData());
        $this->assertSame(ModuleConfig::DEFAULT_TRUST_ANCHOR_ID, $moduleConfig->getTrustAnchorId());
        $this->assertSame([EntityTypesEnum::OpenIdProvider->value], $moduleConfig->getEntityTypes());
        $this->assertTrue($moduleConfig->verifySelection());
        $this->assertTrue($moduleConfig->validateTrustMarks());
    }


    public function testAnEmptyTrustAnchorFallsBackRatherThanBeingPassedOn(): void
    {
        // The library types the Trust Anchor as non-empty-string, and an empty
        // one would only fail deeper in.
        $this->assertSame(
            ModuleConfig::DEFAULT_TRUST_ANCHOR_ID,
            $this->moduleConfig([ModuleConfig::OPTION_TRUST_ANCHOR_ID => ''])->getTrustAnchorId(),
        );
    }


    public function testCollectionEndpointIsAutomaticUnlessNamedOrDisabled(): void
    {
        $this->assertTrue($this->moduleConfig()->isCollectionEndpointAutomatic());

        $explicit = $this->moduleConfig([
            ModuleConfig::OPTION_COLLECTION_ENDPOINT => 'https://ta.example.org/collection',
        ]);
        $this->assertFalse($explicit->isCollectionEndpointAutomatic());
        $this->assertSame('https://ta.example.org/collection', $explicit->getCollectionEndpoint());

        $disabled = $this->moduleConfig([ModuleConfig::OPTION_COLLECTION_ENDPOINT => false]);
        $this->assertNull($disabled->getCollectionEndpoint());
        $this->assertFalse($disabled->isCollectionEndpointAutomatic());
    }


    public function testANullOptionCannotDisableTheCollectionEndpoint(): void
    {
        // SimpleSAMLphp's Configuration reads a null value as "not set", so null
        // leaves the default in place. Documented as false for that reason, and
        // asserted here so the two cannot drift apart.
        $this->assertSame(
            ModuleConfig::COLLECTION_ENDPOINT_AUTO,
            $this->moduleConfig([ModuleConfig::OPTION_COLLECTION_ENDPOINT => null])->getCollectionEndpoint(),
        );
    }


    public function testEveryVerifiableAlgorithmIsAcceptedByDefault(): void
    {
        $algorithms = $this->moduleConfig()->getSignatureAlgorithms();

        // RS256 alone -- the library's own default -- cannot read a federation
        // whose entities sign with EC keys, which the demo federations do.
        $this->assertContains(SignatureAlgorithmEnum::RS256, $algorithms);
        $this->assertContains(SignatureAlgorithmEnum::ES256, $algorithms);
        $this->assertContains(SignatureAlgorithmEnum::ES512, $algorithms);
        $this->assertContains(SignatureAlgorithmEnum::EdDSA, $algorithms);
        $this->assertNotContains(SignatureAlgorithmEnum::none, $algorithms);
    }


    public function testNoneIsRefusedEvenWhenConfigured(): void
    {
        // "none" would make an unsigned entity statement verify.
        $algorithms = $this->moduleConfig([
            ModuleConfig::OPTION_SIGNATURE_ALGORITHMS => ['none', 'ES256'],
        ])->getSignatureAlgorithms();

        $this->assertSame([SignatureAlgorithmEnum::ES256], $algorithms);
    }


    public function testUnusableAlgorithmListsFallBackToTheDefaults(): void
    {
        // A typo'd list would otherwise leave nothing able to verify anything.
        $algorithms = $this->moduleConfig([
            ModuleConfig::OPTION_SIGNATURE_ALGORITHMS => ['RS257', 'none', 42],
        ])->getSignatureAlgorithms();

        $this->assertContains(SignatureAlgorithmEnum::RS256, $algorithms);
        $this->assertNotContains(SignatureAlgorithmEnum::none, $algorithms);
    }


    public function testTimeoutsAndPageSizeStayPositive(): void
    {
        $moduleConfig = $this->moduleConfig([
            ModuleConfig::OPTION_PAGE_SIZE => 0,
            ModuleConfig::OPTION_MAX_DISCOVERED_ENTITIES => -5,
            ModuleConfig::OPTION_HTTP_CONNECT_TIMEOUT => 0,
            ModuleConfig::OPTION_HTTP_TIMEOUT => 0,
        ]);

        $this->assertSame(1, $moduleConfig->getPageSize());
        $this->assertSame(1, $moduleConfig->getMaxDiscoveredEntities());
        // Guzzle reads 0 as "wait forever", which is what these bounds exist to
        // prevent.
        $this->assertGreaterThan(0, $moduleConfig->getHttpConnectTimeout());
        $this->assertGreaterThan(0, $moduleConfig->getHttpTimeout());
    }


    public function testFederationRelyingPartyConfigurationIsTyped(): void
    {
        $moduleConfig = $this->moduleConfig([
            ModuleConfig::OPTION_FEDERATION_ENTITY_ID => 'https://rp.example.org/federation/',
            ModuleConfig::OPTION_FEDERATION_ENTITY_ROLE => ModuleConfig::FEDERATION_ROLE_RELYING_PARTY,
            ModuleConfig::OPTION_FEDERATION_AUTHORITY_HINTS => [
                'https://ta.example.org',
                '',
                42,
            ],
            ModuleConfig::OPTION_FEDERATION_REDIRECT_URIS => [
                'https://rp.example.org/callback',
                null,
            ],
            ModuleConfig::OPTION_FEDERATION_PRIVATE_KEY => '/keys/federation.pem',
            ModuleConfig::OPTION_PROTOCOL_PRIVATE_KEY => '/keys/protocol.pem',
            ModuleConfig::OPTION_FEDERATION_DISPLAY_NAME => 'Example RP',
            ModuleConfig::OPTION_FEDERATION_STATEMENT_TTL => 30,
        ]);

        $this->assertTrue($moduleConfig->isFederationRelyingParty());
        $this->assertFalse($moduleConfig->isFederationTrustAnchor());
        $this->assertSame('https://rp.example.org/federation', $moduleConfig->getFederationEntityId());
        $this->assertSame(['https://ta.example.org'], $moduleConfig->getFederationAuthorityHints());
        $this->assertSame(['https://rp.example.org/callback'], $moduleConfig->getFederationRedirectUris());
        $this->assertSame('/keys/federation.pem', $moduleConfig->getFederationPrivateKey());
        $this->assertSame('/keys/protocol.pem', $moduleConfig->getProtocolPrivateKey());
        $this->assertSame('Example RP', $moduleConfig->getFederationDisplayName());
        $this->assertSame(60, $moduleConfig->getFederationStatementTtl());
    }


    public function testFederationTrustAnchorUsesAnExplicitSubordinateAllowList(): void
    {
        $moduleConfig = $this->moduleConfig([
            ModuleConfig::OPTION_FEDERATION_ENTITY_ID => 'https://ta.example.org/federation',
            ModuleConfig::OPTION_FEDERATION_ENTITY_ROLE => ModuleConfig::FEDERATION_ROLE_TRUST_ANCHOR,
            ModuleConfig::OPTION_FEDERATION_SUBORDINATES => [
                'https://op1.example.org',
                false,
                'https://op2.example.org',
            ],
        ]);

        $this->assertTrue($moduleConfig->isFederationTrustAnchor());
        $this->assertFalse($moduleConfig->isFederationRelyingParty());
        $this->assertSame(
            ['https://op1.example.org', 'https://op2.example.org'],
            $moduleConfig->getFederationSubordinates(),
        );
    }


    public function testUnknownFederationRoleDoesNotEnableAnEntity(): void
    {
        $moduleConfig = $this->moduleConfig([
            ModuleConfig::OPTION_FEDERATION_ENTITY_ID => 'https://entity.example.org',
            ModuleConfig::OPTION_FEDERATION_ENTITY_ROLE => 'unknown',
        ]);

        $this->assertNull($moduleConfig->getFederationEntityRole());
        $this->assertFalse($moduleConfig->isFederationRelyingParty());
        $this->assertFalse($moduleConfig->isFederationTrustAnchor());
    }


    public function testAnEmptyFederationEntityIdCannotEnableARole(): void
    {
        $moduleConfig = $this->moduleConfig([
            ModuleConfig::OPTION_FEDERATION_ENTITY_ID => '///',
            ModuleConfig::OPTION_FEDERATION_ENTITY_ROLE => ModuleConfig::FEDERATION_ROLE_RELYING_PARTY,
        ]);

        $this->assertNull($moduleConfig->getFederationEntityId());
        $this->assertFalse($moduleConfig->isFederationRelyingParty());
    }
}
