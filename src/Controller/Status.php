<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Federation\ErrorDetail;
use SimpleSAML\Module\embeddeddisco\Federation\FederationFactory;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

use function class_exists;
use function phpversion;

/**
 * Smoke-test endpoint: is the module wired up, and can it reach its federation?
 *
 * The second question is the one that matters in operation. Discovery caches
 * aggressively and fails quietly by design, so a picker can keep serving a
 * federation that has been unreachable for an hour. This says so plainly.
 */
class Status
{
    protected ModuleConfig $moduleConfig;

    protected FederationFactory $federationFactory;


    public function __construct(
        protected Configuration $config,
        protected Session $session,
    ) {
        $this->moduleConfig = new ModuleConfig();
        $this->federationFactory = new FederationFactory($this->moduleConfig);
    }


    public function status(): JsonResponse
    {
        return new JsonResponse([
            'module' => ModuleConfig::MODULE_NAME,
            'module_enabled' => Module::isModuleEnabled(ModuleConfig::MODULE_NAME),
            'ssp_version' => $this->config->getVersion(),
            'php_version' => phpversion(),
            'openid_library' => class_exists(Federation::class) ? 'loaded' : 'missing',
            'trust_anchor_id' => $this->moduleConfig->getTrustAnchorId(),
            'use_mock_data' => $this->moduleConfig->useMockData(),
            'collection_endpoint_config' => $this->moduleConfig->getCollectionEndpoint(),
            'trust_anchor' => $this->trustAnchorStatus(),
        ]);
    }


    /**
     * Fetch the Trust Anchor's Entity Configuration and report what it says.
     *
     * Served from the artifact cache when it is warm, so this is not a fresh
     * probe of the endpoint on every call -- it answers "can discovery work
     * right now", which is the question being asked.
     *
     * @return array<string, mixed>
     */
    protected function trustAnchorStatus(): array
    {
        if ($this->moduleConfig->useMockData()) {
            return ['checked' => false, 'reason' => 'Mock data is in use, so no federation is contacted.'];
        }

        try {
            $entityConfiguration = $this->federationFactory->build()->entityStatementFetcher()
                ->fromCacheOrWellKnownEndpoint($this->moduleConfig->getTrustAnchorId());
        } catch (Throwable $throwable) {
            return [
                'checked' => true,
                'reachable' => false,
                'error' => $this->moduleConfig->exposeErrorDetails()
                    ? ErrorDetail::shorten($throwable->getMessage())
                    : 'unavailable',
            ];
        }

        return [
            'checked' => true,
            'reachable' => true,
            'expires_at' => $entityConfiguration->getExpirationTime(),
            'federation_list_endpoint' => $entityConfiguration->getFederationListEndpoint(),
            'federation_collection_endpoint' => $entityConfiguration->getFederationCollectionEndpoint(),
        ];
    }
}
