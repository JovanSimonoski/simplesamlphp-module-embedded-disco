<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Error\Exception as SspException;
use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryService;
use SimpleSAML\Module\embeddeddisco\Federation\FederationFactory;
use SimpleSAML\Module\embeddeddisco\Federation\TrustChainService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\Session;
use SimpleSAML\Utils\Auth;
use SimpleSAML\Utils\HTTP;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

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

    /**
     * Re-reads the federation instead of serving the stored entity collection.
     */
    public const string PARAM_REFRESH = 'refresh';


    protected ModuleConfig $moduleConfig;

    protected DiscoveryService $discoveryService;

    protected TrustChainService $trustChainService;

    protected Auth $authUtils;

    protected HTTP $httpUtils;


    public function __construct(
        protected Configuration $config,
        protected Session $session,
    ) {
        $this->moduleConfig = new ModuleConfig();
        // One facade for both services: it holds the HTTP client and the caches
        // that discovery and Trust Chain resolution share.
        $federationFactory = new FederationFactory($this->moduleConfig);
        $this->discoveryService = new DiscoveryService($this->moduleConfig, $federationFactory);
        $this->trustChainService = new TrustChainService($this->moduleConfig, $federationFactory);
        $this->authUtils = new Auth();
        $this->httpUtils = new HTTP();
    }


    /**
     * The picker itself.
     */
    public function main(Request $request): Template
    {
        $discoveryQuery = DiscoveryQuery::fromRequest($request, $this->moduleConfig);
        $isAdmin = $this->authUtils->isAdmin();
        $result = $this->discoveryService->discover($discoveryQuery, $this->wantsRefresh($request) && $isAdmin);

        $returnTo = $this->returnTo($request);

        $template = new Template($this->config, 'embeddeddisco:discovery.twig');

        $template->data['entities'] = $result->entities;
        $template->data['total'] = $result->total;
        $template->data['pageSize'] = $discoveryQuery->limit;
        $template->data['nextPageToken'] = $result->nextPageToken;
        $template->data['lastUpdated'] = $result->lastUpdated;
        $template->data['trustAnchorId'] = $result->trustAnchorId;
        $template->data['query'] = $discoveryQuery->query;
        $template->data['sortOrder'] = $discoveryQuery->sortOrder;
        $template->data['usingMockData'] = $this->moduleConfig->useMockData();
        $template->data['discoverySource'] = $result->source->value;
        $template->data['discoveryFailed'] = $result->hasFailed();
        $template->data['discoveryError'] = $this->moduleConfig->exposeErrorDetails() ? $result->error : null;
        $template->data['returnTo'] = $returnTo;
        $template->data['formUrl'] = Module::getModuleURL(self::ROUTE_DISCOVERY);
        $template->data['selectUrl'] = Module::getModuleURL(self::ROUTE_SELECT);
        $template->data['entitiesUrl'] = Module::getModuleURL(self::ROUTE_ENTITIES);
        $template->data['nextPageUrl'] = $result->nextPageToken === null
            ? null
            : $this->discoveryUrl($discoveryQuery, $result->nextPageToken, $returnTo);
        $template->data['resetUrl'] = $this->discoveryUrl(
            new DiscoveryQuery(limit: $discoveryQuery->limit),
            null,
            $returnTo,
        );
        // A refresh discards the stored collection and traverses the federation
        // again, which is as much work as one visitor can ask a deployment to do.
        // Offered to administrators only, for that reason.
        $template->data['refreshUrl'] = $isAdmin && !$this->moduleConfig->useMockData()
            ? $this->discoveryUrl($discoveryQuery, null, $returnTo, true)
            : null;

        return $template;
    }


    /**
     * The same result set, serialized as an OpenID Federation entity collection
     * response. This is what an embedded widget on another page would consume.
     */
    public function entities(Request $request): JsonResponse
    {
        $discoveryQuery = DiscoveryQuery::fromRequest($request, $this->moduleConfig);
        $result = $this->discoveryService->discover(
            $discoveryQuery,
            $this->wantsRefresh($request) && $this->authUtils->isAdmin(),
        );

        if ($result->hasFailed()) {
            // An empty collection would claim the federation is empty. It is
            // not; it could not be read.
            return new JsonResponse(
                [
                    'error' => 'temporarily_unavailable',
                    'error_description' => $this->moduleConfig->exposeErrorDetails()
                        ? $result->error
                        : 'The federation could not be read.',
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(
            $this->discoveryService->toCollectionEndpointResponse($result->collection),
        );
    }


    /**
     * Where the RP hand-off will happen.
     *
     * Before that, the picked entity is verified: discovery listed it from a
     * self-asserted Entity Configuration, and only a Trust Chain to the Trust
     * Anchor shows it is really part of the federation. The POC stops after
     * that, showing what was established rather than starting an authentication
     * request, because this SimpleSAMLphp instance is not configured as an
     * OpenID Connect relying party.
     */
    public function select(Request $request): Template
    {
        $entityId = $request->query->get('entity_id');
        $entityId = is_string($entityId) ? $entityId : '';

        $verification = $this->trustChainService->verify($entityId);

        $template = new Template($this->config, 'embeddeddisco:selected.twig');
        $template->data['entityId'] = $entityId;
        $template->data['displayName'] = $verification->getDisplayName();
        $template->data['returnTo'] = $this->returnTo($request);
        $template->data['trustAnchorId'] = $verification->trustAnchorId;
        $template->data['verified'] = $verification->verified;
        $template->data['verificationSkipped'] = $verification->skipped;
        $template->data['verificationError'] = $this->moduleConfig->exposeErrorDetails()
            ? $verification->error
            : null;
        $template->data['chain'] = $verification->chain;
        $template->data['expiresAt'] = $verification->expiresAt;
        $template->data['entityType'] = $verification->entityType;
        $template->data['metadata'] = $verification->metadata === []
            ? null
            : json_encode($verification->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $template->data['trustMarks'] = $verification->trustMarks;
        $template->data['usingMockData'] = $this->moduleConfig->useMockData();

        return $template;
    }


    /**
     * Rebuild the picker URL, carrying the current criteria and an optional
     * pagination cursor.
     */
    protected function discoveryUrl(
        DiscoveryQuery $discoveryQuery,
        ?string $from,
        ?string $returnTo,
        bool $refresh = false,
    ): string {
        $parameters = [];

        if ($discoveryQuery->query !== '') {
            $parameters['query'] = $discoveryQuery->query;
        }

        // No entity_type: the picker serves the configured types, so carrying
        // them in the URL would only invite editing them there.
        if ($discoveryQuery->sortOrder !== 'asc') {
            $parameters['sort_dir'] = $discoveryQuery->sortOrder;
        }

        if ($from !== null) {
            $parameters['from'] = $from;
        }

        if ($refresh) {
            $parameters[self::PARAM_REFRESH] = '1';
        }

        if ($returnTo !== null) {
            $parameters['ReturnTo'] = $returnTo;
        }

        return Module::getModuleURL(self::ROUTE_DISCOVERY, $parameters);
    }


    protected function wantsRefresh(Request $request): bool
    {
        return $request->query->get(self::PARAM_REFRESH) === '1';
    }


    /**
     * The URL to send the user back to, if it is one this installation is
     * willing to link to.
     *
     * ReturnTo arrives from whoever linked to the picker, and ends up as an
     * anchor on the page, so it is checked against trusted.url.domains the same
     * way the rest of SimpleSAMLphp checks redirect targets.
     */
    protected function returnTo(Request $request): ?string
    {
        $returnTo = $request->query->get('ReturnTo');

        if (!is_string($returnTo) || $returnTo === '') {
            return null;
        }

        try {
            return $this->httpUtils->checkURLAllowed($returnTo);
        } catch (SspException) {
            return null;
        }
    }
}
