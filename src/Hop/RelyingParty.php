<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Hop;

use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class RelyingParty
{
    public function __construct(protected ModuleConfig $moduleConfig)
    {
    }


    public function outgoingUrl(string $target): string
    {
        $endpoint = $this->moduleConfig->getHopEndpoint();
        $entityId = $this->moduleConfig->getFederationEntityId();
        if ($endpoint === null || $entityId === null || $this->moduleConfig->getInitiateLoginUri() === null) {
            throw new BadRequestHttpException('HOP requires an endpoint, RP entity ID, and initiate_login_uri.');
        }
        $parameters = ['entity_id' => Protocol::httpsUrl($entityId, false), 'target_link_uri' => $target];
        $hint = $this->moduleConfig->getHopHint();
        if ($hint !== null) {
            $parameters['hint'] = $hint;
        }
        return Protocol::withQuery($endpoint, $parameters);
    }


    public function target(string $target): string
    {
        $initiateUri = $this->moduleConfig->getInitiateLoginUri();
        if ($initiateUri === null) {
            throw new AccessDeniedHttpException('Third-party login is not enabled on this RP.');
        }
        $origins = [Protocol::origin($initiateUri)];
        foreach ($this->moduleConfig->getHopAllowedTargetOrigins() as $uri) {
            $origins[] = Protocol::origin($uri);
        }
        if (!in_array(Protocol::origin($target), $origins, true)) {
            throw new BadRequestHttpException('The target_link_uri is not on an approved RP origin.');
        }
        return $target;
    }
}
