<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Federation;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Federation\LocalEntityService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\Federation;

use function file_put_contents;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(LocalEntityService::class)]
class LocalEntityServiceTest extends TestCase
{
    protected string $federationKeyPath;

    protected string $protocolKeyPath;


    protected function setUp(): void
    {
        $this->federationKeyPath = $this->createPrivateKeyFile('federation_');
        $this->protocolKeyPath = $this->createPrivateKeyFile('protocol_');
    }


    protected function tearDown(): void
    {
        unlink($this->federationKeyPath);
        unlink($this->protocolKeyPath);
    }


    public function testRelyingPartyPublishesVerifiableMetadataAndSignsRequestObjects(): void
    {
        $entityId = 'https://rp.example.org/federation';
        $authorityId = 'https://ta.example.org/federation';
        $redirectUri = 'https://rp.example.org/callback';
        $service = new LocalEntityService(new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_FEDERATION_ENTITY_ID => $entityId,
            ModuleConfig::OPTION_FEDERATION_ENTITY_ROLE => ModuleConfig::FEDERATION_ROLE_RELYING_PARTY,
            ModuleConfig::OPTION_FEDERATION_AUTHORITY_HINTS => [$authorityId],
            ModuleConfig::OPTION_FEDERATION_PRIVATE_KEY => $this->federationKeyPath,
            ModuleConfig::OPTION_PROTOCOL_PRIVATE_KEY => $this->protocolKeyPath,
            ModuleConfig::OPTION_FEDERATION_REDIRECT_URIS => [$redirectUri],
            ModuleConfig::OPTION_FEDERATION_DISPLAY_NAME => 'Test RP',
            ModuleConfig::OPTION_SCOPES => ['openid'],
            ModuleConfig::OPTION_CACHE_DIRECTORY => sys_get_temp_dir() . '/embeddeddisco-local-entity-test',
        ])));
        $federation = new Federation();

        $configuration = $federation->entityStatementFactory()->fromToken($service->entityConfiguration());
        $configuration->verifyWithKeySet();

        $this->assertSame($entityId, $configuration->getIssuer());
        $this->assertSame($entityId, $configuration->getSubject());
        $this->assertSame([$authorityId], $configuration->getAuthorityHints());

        $metadata = $configuration->getMetadata();
        $this->assertIsArray($metadata);
        $rpMetadata = $metadata[EntityTypesEnum::OpenIdRelyingParty->value] ?? null;
        $this->assertIsArray($rpMetadata);
        $this->assertSame($entityId, $rpMetadata[ClaimsEnum::ClientId->value]);
        $this->assertSame([$redirectUri], $rpMetadata[ClaimsEnum::RedirectUris->value]);
        $this->assertSame('none', $rpMetadata[ClaimsEnum::TokenEndpointAuthMethod->value]);
        $this->assertSame(['automatic'], $rpMetadata[ClaimsEnum::ClientRegistrationTypes->value]);

        $protocolJwks = $rpMetadata[ClaimsEnum::Jwks->value] ?? null;
        $this->assertIsArray($protocolJwks);
        $requestObject = $federation->requestObjectFactory()->fromToken($service->requestObject(
            'https://op.example.org',
            [
                ClaimsEnum::ClientId->value => $entityId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
            ],
        ));
        $requestObject->verifyWithKeySet($protocolJwks);

        $this->assertSame($entityId, $requestObject->getIssuer());
        $this->assertSame(['https://op.example.org'], $requestObject->getAudience());
        $this->assertSame('code', $requestObject->getPayloadClaim('response_type'));
    }


    protected function createPrivateKeyFile(string $prefix): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not create a test RSA key.');
        }

        $pem = '';
        if (!openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not export a test RSA key.');
        }

        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false || file_put_contents($path, $pem) === false) {
            throw new RuntimeException('Could not write a test RSA key.');
        }

        return $path;
    }
}
