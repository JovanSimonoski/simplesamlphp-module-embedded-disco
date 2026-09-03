<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use RuntimeException;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\ApplicationTypesEnum;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\Codebooks\TokenEndpointAuthMethodsEnum;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Jwk;
use SimpleSAML\OpenID\Jwk\JwkDecorator;

use function bin2hex;
use function implode;
use function in_array;
use function is_file;
use function random_bytes;
use function time;

/**
 * Publishes and signs the local RP or Trust Anchor Entity Configuration.
 *
 * It also implements the two authority endpoints needed by the deliberately
 * flat Docker federation. Enrollment is an explicit allow-list. The public
 * federation keys in a subordinate statement are read from, and checked
 * against, the subordinate's self-signed Entity Configuration.
 */
class LocalEntityService
{
    protected readonly Federation $federation;

    protected readonly Jwk $jwk;

    protected ?JwkDecorator $federationSigningKey = null;

    protected ?JwkDecorator $protocolSigningKey = null;


    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        ?FederationFactory $federationFactory = null,
    ) {
        $this->federation = ($federationFactory ?? new FederationFactory($moduleConfig))->build();
        $this->jwk = new Jwk();
    }


    public function entityConfiguration(): string
    {
        $entityId = $this->requireEntityId();
        $now = time();
        $payload = [
            ClaimsEnum::Iss->value => $entityId,
            ClaimsEnum::Sub->value => $entityId,
            ClaimsEnum::Iat->value => $now,
            ClaimsEnum::Exp->value => $now + $this->moduleConfig->getFederationStatementTtl(),
            ClaimsEnum::Jwks->value => $this->publicJwks($this->federationKey()),
            ClaimsEnum::Metadata->value => $this->metadata($entityId),
        ];

        $authorityHints = $this->moduleConfig->getFederationAuthorityHints();
        if ($authorityHints !== []) {
            $payload[ClaimsEnum::AuthorityHints->value] = $authorityHints;
        }

        return $this->signEntityStatement($payload, $this->federationKey());
    }


    public function subordinateStatement(string $subject): string
    {
        if (!$this->moduleConfig->isFederationTrustAnchor()) {
            throw new RuntimeException('This entity is not configured as a Trust Anchor.');
        }

        if (!in_array($subject, $this->moduleConfig->getFederationSubordinates(), true)) {
            throw new RuntimeException('The requested subject is not enrolled under this Trust Anchor.');
        }

        $configuration = $this->federation->entityStatementFetcher()->fromCacheOrWellKnownEndpoint($subject);
        if ($configuration->getIssuer() !== $subject || $configuration->getSubject() !== $subject) {
            throw new RuntimeException('The fetched Entity Configuration belongs to another entity.');
        }

        if (!in_array($this->requireEntityId(), $configuration->getAuthorityHints() ?? [], true)) {
            throw new RuntimeException('The subordinate does not name this Trust Anchor in authority_hints.');
        }

        $jwks = $configuration->getJwks()->getValue();
        $configuration->verifyWithKeySet($jwks);

        $now = time();

        return $this->signEntityStatement([
            ClaimsEnum::Iss->value => $this->requireEntityId(),
            ClaimsEnum::Sub->value => $subject,
            ClaimsEnum::Iat->value => $now,
            ClaimsEnum::Exp->value => $now + $this->moduleConfig->getFederationStatementTtl(),
            ClaimsEnum::Jwks->value => $jwks,
        ], $this->federationKey());
    }


    /**
     * Sign an authorization Request Object for automatic client registration.
     *
     * @param array<string, mixed> $authorizationParameters
     */
    public function requestObject(string $audience, array $authorizationParameters): string
    {
        if (!$this->moduleConfig->isFederationRelyingParty()) {
            throw new RuntimeException('This entity is not configured as a federation Relying Party.');
        }

        $now = time();
        $payload = [
            ...$authorizationParameters,
            ClaimsEnum::Iss->value => $this->requireEntityId(),
            ClaimsEnum::Aud->value => $audience,
            ClaimsEnum::Iat->value => $now,
            ClaimsEnum::Exp->value => $now + 300,
            ClaimsEnum::Jti->value => bin2hex(random_bytes(16)),
        ];
        $key = $this->protocolKey();
        $kid = (string) $key->jwk()->get(ClaimsEnum::Kid->value);

        return $this->federation->requestObjectFactory()->fromData(
            $key,
            SignatureAlgorithmEnum::RS256,
            $payload,
            [
                ClaimsEnum::Kid->value => $kid,
                ClaimsEnum::Typ->value => 'oauth-authz-req+jwt',
            ],
        )->getToken();
    }


    /**
     * @return array<string, array<string, mixed>>
     */
    protected function metadata(string $entityId): array
    {
        if ($this->moduleConfig->isFederationTrustAnchor()) {
            return [
                EntityTypesEnum::FederationEntity->value => [
                    ClaimsEnum::FederationFetchEndpoint->value => $entityId . '/fetch',
                    ClaimsEnum::FederationListEndpoint->value => $entityId . '/list',
                    ClaimsEnum::DisplayName->value => $this->moduleConfig->getFederationDisplayName(),
                ],
            ];
        }

        if ($this->moduleConfig->isFederationRelyingParty()) {
            $redirectUris = $this->moduleConfig->getFederationRedirectUris();
            if ($redirectUris === []) {
                throw new RuntimeException('The federation RP has no redirect URIs configured.');
            }

            return [
                EntityTypesEnum::OpenIdRelyingParty->value => [
                    ClaimsEnum::ClientId->value => $entityId,
                    ClaimsEnum::ClientName->value => $this->moduleConfig->getFederationDisplayName(),
                    ClaimsEnum::ApplicationType->value => ApplicationTypesEnum::Web->value,
                    ClaimsEnum::RedirectUris->value => $redirectUris,
                    ClaimsEnum::ResponseTypes->value => ['code'],
                    ClaimsEnum::GrantTypes->value => ['authorization_code'],
                    ClaimsEnum::Scope->value => implode(' ', $this->moduleConfig->getScopes()),
                    ClaimsEnum::TokenEndpointAuthMethod->value => TokenEndpointAuthMethodsEnum::None->value,
                    ClaimsEnum::Jwks->value => $this->publicJwks($this->protocolKey()),
                    ClaimsEnum::ClientRegistrationTypes->value => ['automatic'],
                ],
            ];
        }

        throw new RuntimeException('No supported federation entity role is configured.');
    }


    /**
     * @param array<string, mixed> $payload
     */
    protected function signEntityStatement(array $payload, JwkDecorator $key): string
    {
        $kid = (string) $key->jwk()->get(ClaimsEnum::Kid->value);

        return $this->federation->entityStatementFactory()->fromData(
            $key,
            SignatureAlgorithmEnum::RS256,
            $payload,
            [ClaimsEnum::Kid->value => $kid],
        )->getToken();
    }


    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    protected function publicJwks(JwkDecorator $key): array
    {
        return ['keys' => [$key->jwk()->toPublic()->all()]];
    }


    protected function federationKey(): JwkDecorator
    {
        return $this->federationSigningKey ??= $this->loadKey(
            $this->moduleConfig->getFederationPrivateKey(),
            'federation',
        );
    }


    protected function protocolKey(): JwkDecorator
    {
        return $this->protocolSigningKey ??= $this->loadKey(
            $this->moduleConfig->getProtocolPrivateKey(),
            'protocol',
        );
    }


    protected function loadKey(?string $path, string $purpose): JwkDecorator
    {
        if ($path === null || !is_file($path)) {
            throw new RuntimeException("The $purpose signing key is not configured or is not readable.");
        }

        $factory = $this->jwk->jwkDecoratorFactory();
        $plainKey = $factory->fromPkcs1Or8KeyFile($path);
        $kid = $plainKey->jwk()->toPublic()->thumbprint('sha256');

        return $factory->fromPkcs1Or8KeyFile($path, null, [
            ClaimsEnum::Use->value => 'sig',
            ClaimsEnum::Alg->value => SignatureAlgorithmEnum::RS256->value,
            ClaimsEnum::Kid->value => $kid,
        ]);
    }


    protected function requireEntityId(): string
    {
        return $this->moduleConfig->getFederationEntityId()
            ?? throw new RuntimeException('No federation_entity_id is configured.');
    }
}
