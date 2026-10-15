<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Hop;

use SimpleSAML\Module\embeddeddisco\Federation\FederationFactory;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Federation\EntityStatement;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** The picker trusts explicitly enrolled RPs, without sharing their sessions. */
class PickerService
{
    public function __construct(protected ModuleConfig $moduleConfig)
    {
    }


    public function initiateLoginUri(string $entityId): string
    {
        // Check enrollment BEFORE any fetch. An arbitrary entity_id must not
        // turn the public picker into a metadata fetch proxy or open redirect.
        $expected = $this->moduleConfig->getHopTrustedRps()[$entityId] ?? null;
        if ($expected === null) {
            throw new AccessDeniedHttpException('This relying party is not enrolled at this picker.');
        }
        Protocol::httpsUrl($entityId, false);
        Protocol::httpsUrl($expected);
        $configuration = $this->configuration($entityId);
        $configuration->verifyWithKeySet();
        if ($configuration->getIssuer() !== $entityId || $configuration->getSubject() !== $entityId) {
            throw new BadRequestHttpException('The RP Entity Configuration has a different identity.');
        }
        $uri = $configuration->getMetadata()['openid_relying_party']['initiate_login_uri'] ?? null;
        if (!is_string($uri) || $uri !== $expected) {
            throw new BadRequestHttpException('The RP does not publish its approved initiate_login_uri.');
        }
        return $uri;
    }


    protected function configuration(string $entityId): EntityStatement
    {
        return (new FederationFactory($this->moduleConfig))->build()->entityStatementFetcher()
            ->fromCacheOrWellKnownEndpoint($entityId);
    }


    /** @param array<string, string> $context */
    public function returnUrl(string $initiateLoginUri, string $issuer, array $context): string
    {
        $parameters = ['iss' => Protocol::httpsUrl($issuer, false)];
        foreach (['target_link_uri', 'login_hint'] as $name) {
            if (isset($context[$name])) {
                $parameters[$name] = $context[$name];
            }
        }
        return Protocol::withQuery($initiateLoginUri, $parameters);
    }
}
