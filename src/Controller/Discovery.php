<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\Session;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Embedded discovery for a SimpleSAMLphp relying party.
 *
 * Renders a picker of OpenID Providers discovered beneath a Trust Anchor, and
 * exposes the same result set as an OpenID Federation entity collection
 * response for embedding elsewhere.
 */
class Discovery
{
    public const string ROUTE_DISCOVERY = 'embeddeddisco/disco';

    public const string ROUTE_SELECT = 'embeddeddisco/select';

    public const string ROUTE_ENTITIES = 'embeddeddisco/entities';


    protected ModuleConfig $moduleConfig;

    protected DiscoveryService $discoveryService;


    public function __construct(
        protected Configuration $config,
        protected Session $session,
    ) {
        $this->moduleConfig = new ModuleConfig();
        $this->discoveryService = new DiscoveryService($this->moduleConfig);
    }


    /**
     * The picker itself.
     */
    public function main(Request $request): Template
    {
        $discoveryQuery = DiscoveryQuery::fromRequest($request, $this->moduleConfig);
        $result = $this->discoveryService->discover($discoveryQuery);

        $template = new Template($this->config, 'embeddeddisco:discovery.twig');

        $template->data['entities'] = $result['entities'];
        $template->data['total'] = $result['total'];
        $template->data['pageSize'] = $discoveryQuery->limit;
        $template->data['nextPageToken'] = $result['nextPageToken'];
        $template->data['lastUpdated'] = $result['lastUpdated'];
        $template->data['trustAnchorId'] = $result['trustAnchorId'];
        $template->data['query'] = $discoveryQuery->query;
        $template->data['sortOrder'] = $discoveryQuery->sortOrder;
        $template->data['entityTypes'] = $discoveryQuery->entityTypes;
        $template->data['availableEntityTypes'] = [
            EntityTypesEnum::OpenIdProvider->value,
            EntityTypesEnum::OpenIdRelyingParty->value,
            EntityTypesEnum::FederationEntity->value,
        ];
        $template->data['usingMockData'] = $this->moduleConfig->useMockData();
        $template->data['returnTo'] = $this->returnTo($request);
        $template->data['formUrl'] = Module::getModuleURL(self::ROUTE_DISCOVERY);
        $template->data['selectUrl'] = Module::getModuleURL(self::ROUTE_SELECT);
        $template->data['entitiesUrl'] = Module::getModuleURL(self::ROUTE_ENTITIES);
        $template->data['nextPageUrl'] = $result['nextPageToken'] === null
            ? null
            : $this->discoveryUrl($discoveryQuery, $result['nextPageToken'], $template->data['returnTo']);
        $template->data['resetUrl'] = $this->discoveryUrl(
            new DiscoveryQuery(limit: $discoveryQuery->limit),
            null,
            $template->data['returnTo'],
        );

        return $template;
    }


    /**
     * Rebuild the picker URL, carrying the current criteria and an optional
     * pagination cursor.
     */
    protected function discoveryUrl(DiscoveryQuery $discoveryQuery, ?string $from, ?string $returnTo): string
    {
        $parameters = [];

        if ($discoveryQuery->query !== '') {
            $parameters['query'] = $discoveryQuery->query;
        }

        if ($discoveryQuery->entityTypes !== []) {
            $parameters['entity_type'] = $discoveryQuery->entityTypes;
        }

        if ($discoveryQuery->sortOrder !== 'asc') {
            $parameters['sort_dir'] = $discoveryQuery->sortOrder;
        }

        if ($from !== null) {
            $parameters['from'] = $from;
        }

        if ($returnTo !== null) {
            $parameters['ReturnTo'] = $returnTo;
        }

        return Module::getModuleURL(self::ROUTE_DISCOVERY, $parameters);
    }


    /**
     * The same result set, serialized as an OpenID Federation entity collection
     * response. This is what an embedded widget on another page would consume.
     */
    public function entities(Request $request): JsonResponse
    {
        $discoveryQuery = DiscoveryQuery::fromRequest($request, $this->moduleConfig);
        $result = $this->discoveryService->discover($discoveryQuery);

        return new JsonResponse(
            $this->discoveryService->toCollectionEndpointResponse($result['collection']),
        );
    }


    /**
     * Where the RP hand-off will happen.
     *
     * The POC stops at selection: it echoes the chosen provider back rather than
     * starting an authentication request, because this SimpleSAMLphp instance is
     * not yet configured as an OpenID Connect relying party.
     */
    public function select(Request $request): Template
    {
        $entityId = $request->query->get('entity_id');
        $entityId = is_string($entityId) ? $entityId : '';

        $template = new Template($this->config, 'embeddeddisco:selected.twig');
        $template->data['entityId'] = $entityId;
        $template->data['returnTo'] = $this->returnTo($request);
        $template->data['trustAnchorId'] = $this->moduleConfig->getTrustAnchorId();

        return $template;
    }


    protected function returnTo(Request $request): ?string
    {
        $returnTo = $request->query->get('ReturnTo');

        return is_string($returnTo) && $returnTo !== '' ? $returnTo : null;
    }
}
