<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error\Exception as SspException;
use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Auth\Source\OpenIdFederation;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryService;
use SimpleSAML\Module\embeddeddisco\Federation\FederationFactory;
use SimpleSAML\Module\embeddeddisco\Federation\SelectionVerification;
use SimpleSAML\Module\embeddeddisco\Federation\TrustChainService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\Module\embeddeddisco\Rp\AuthorizationRequest;
use SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyException;
use SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyService;
use SimpleSAML\Session;
use SimpleSAML\Utils\Auth as AuthUtils;
use SimpleSAML\Utils\HTTP;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    protected RelyingPartyService $relyingPartyService;

    protected AuthUtils $authUtils;

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
        $this->relyingPartyService = new RelyingPartyService($this->moduleConfig);
        $this->authUtils = new AuthUtils();
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
        // Present when the picker is a step inside a login, and every link out
        // of the page has to carry it or the login is lost.
        $template->data['authState'] = $this->authState($request);
        $template->data['discoverySource'] = $result->source->value;
        $template->data['discoveryFailed'] = $result->hasFailed();
        $template->data['discoveryError'] = $this->moduleConfig->exposeErrorDetails() ? $result->error : null;
        $template->data['returnTo'] = $returnTo;
        $template->data['formUrl'] = Module::getModuleURL(self::ROUTE_DISCOVERY);
        $template->data['selectUrl'] = Module::getModuleURL(self::ROUTE_SELECT);
        $template->data['entitiesUrl'] = Module::getModuleURL(self::ROUTE_ENTITIES);
        $authState = $this->authState($request);
        $template->data['nextPageUrl'] = $result->nextPageToken === null
            ? null
            : $this->discoveryUrl($discoveryQuery, $result->nextPageToken, $returnTo, false, $authState);
        $template->data['resetUrl'] = $this->discoveryUrl(
            new DiscoveryQuery(limit: $discoveryQuery->limit),
            null,
            $returnTo,
            false,
            $authState,
        );
        // A refresh discards the stored collection and traverses the federation
        // again, which is as much work as one visitor can ask a deployment to do.
        // Offered to administrators only, for that reason.
        $template->data['refreshUrl'] = $isAdmin && !$this->moduleConfig->useMockData()
            ? $this->discoveryUrl($discoveryQuery, null, $returnTo, true, $authState)
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
     * The provider the user picked.
     *
     * It is verified first: discovery listed it from a self-asserted Entity
     * Configuration, and only a Trust Chain to the Trust Anchor shows it is
     * really part of the federation. What happens next depends on why the picker
     * was open. Inside a login -- reached through the authentication source --
     * a verified provider is where the user is sent. Opened on its own, the page
     * shows what was established instead.
     */
    public function select(Request $request): Response
    {
        $entityId = $request->query->get('entity_id');
        $entityId = is_string($entityId) ? $entityId : '';

        $verification = $this->trustChainService->verify($entityId);

        $authStateId = $request->query->get('AuthState');

        if (is_string($authStateId) && $authStateId !== '' && $verification->verified) {
            return $this->startLogin($authStateId, $verification);
        }

        return $this->selectionTemplate($verification, $this->returnTo($request));
    }


    /**
     * The page describing a selection: what was verified, and what could not be.
     */
    protected function selectionTemplate(
        SelectionVerification $verification,
        ?string $returnTo,
        ?string $loginError = null,
    ): Template {
        $template = new Template($this->config, 'embeddeddisco:selected.twig');
        $template->data['entityId'] = $verification->entityId;
        $template->data['displayName'] = $verification->getDisplayName();
        $template->data['returnTo'] = $returnTo;
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
        // Set when the provider verified but no login could be started with it.
        $template->data['loginError'] = $loginError;

        return $template;
    }


    /**
     * Send the user to the provider they picked.
     *
     * The endpoint comes from the Trust Chain's resolved metadata, so what the
     * browser is redirected to is what the federation says that provider's
     * authorization endpoint is -- not what the provider claims about itself.
     */
    protected function startLogin(string $authStateId, SelectionVerification $verification): Response
    {
        // Throws if the state is gone, which is the right answer: a login that
        // has expired cannot be resumed from a link.
        $state = Auth\State::loadState($authStateId, OpenIdFederation::STAGE_DISCOVERY);

        try {
            $authorizationRequest = $this->relyingPartyService->prepareAuthorizationRequest($verification);
        } catch (RelyingPartyException $relyingPartyException) {
            // A verified provider we hold no client registration for. Say so on
            // the selection page rather than failing the whole login.
            return $this->selectionTemplate($verification, null, $relyingPartyException->getMessage());
        }

        // Everything the callback has to check is kept here, server side. The
        // state identifier travels as the OAuth state parameter, which is what
        // ties the response to this request and to this session.
        $state[OpenIdFederation::PENDING] = $authorizationRequest->toStateArray();
        $stateId = Auth\State::saveState($state, OpenIdFederation::STAGE_AUTHORIZATION);

        return new RedirectResponse(
            $this->relyingPartyService->authorizationUrl($authorizationRequest, $stateId),
        );
    }


    /**
     * Where the provider sends the user back to.
     */
    public function callback(Request $request): Response
    {
        $stateId = $request->query->get('state');

        if (!is_string($stateId) || $stateId === '') {
            throw new SspException('The provider did not return a state parameter.');
        }

        // Throws if this state is unknown, already used, or belongs to another
        // stage -- which is what makes the state parameter worth checking.
        $state = Auth\State::loadState($stateId, OpenIdFederation::STAGE_AUTHORIZATION);

        // The provider declined, or the user did.
        $error = $request->query->get('error');
        if (is_string($error) && $error !== '') {
            $description = $request->query->get('error_description');

            throw new SspException(sprintf(
                'The provider refused the login: %s%s',
                $error,
                is_string($description) && $description !== '' ? ' (' . $description . ')' : '',
            ));
        }

        $code = $request->query->get('code');
        if (!is_string($code) || $code === '') {
            throw new SspException('The provider returned no authorization code.');
        }

        $pending = AuthorizationRequest::fromStateArray($state[OpenIdFederation::PENDING] ?? null);
        if ($pending === null) {
            throw new SspException('This login is missing the request it belongs to. Please start again.');
        }

        // Verified again on the way back, rather than trusting what was
        // established before the redirect: this is what supplies the keys the
        // ID token is checked against, and a chain can expire or be withdrawn
        // while the user is away.
        $verification = $this->trustChainService->verify($pending['issuer']);

        if (!$verification->verified) {
            throw new SspException(sprintf(
                'The provider could no longer be verified against the Trust Anchor: %s',
                (string) $verification->error,
            ));
        }

        try {
            $claims = $this->relyingPartyService->completeLogin($code, $pending, $verification);
        } catch (RelyingPartyException $relyingPartyException) {
            throw new SspException($relyingPartyException->getMessage(), 0, $relyingPartyException);
        }

        OpenIdFederation::completeWithAttributes($state, $this->attributesFrom($claims, $pending['issuer']));

        // Completing the login resumes whatever was interrupted when it began,
        // so control does not come back here. Reaching this line means it did.
        throw new SspException('The login completed but the original request was not resumed.');
    }


    /**
     * Turn ID token claims into SimpleSAMLphp attributes.
     *
     * Every attribute is a list, which is what the rest of SimpleSAMLphp
     * expects. Structured claims are left out rather than flattened into
     * something misleading.
     *
     * @param array<string, mixed> $claims
     * @return array<string, array<int, mixed>>
     */
    protected function attributesFrom(array $claims, string $issuer): array
    {
        $attributes = [];

        foreach ($claims as $name => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $attributes[(string) $name] = [is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
            } elseif (is_array($value) && array_is_list($value)) {
                $scalars = array_values(array_filter(
                    $value,
                    static fn(mixed $item): bool => is_string($item) || is_int($item) || is_float($item),
                ));

                if ($scalars !== []) {
                    $attributes[(string) $name] = $scalars;
                }
            }
        }

        // Which provider authenticated this user is part of the answer, and it
        // is not a claim the provider gets to make about itself.
        $attributes['embeddeddisco:issuer'] = [$issuer];

        return $attributes;
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
        ?string $authState = null,
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

        if ($authState !== null) {
            $parameters['AuthState'] = $authState;
        }

        return Module::getModuleURL(self::ROUTE_DISCOVERY, $parameters);
    }


    protected function wantsRefresh(Request $request): bool
    {
        return $request->query->get(self::PARAM_REFRESH) === '1';
    }


    /**
     * The identifier of the login this page is a step in, if it is one.
     */
    protected function authState(Request $request): ?string
    {
        $authState = $request->query->get('AuthState');

        return is_string($authState) && $authState !== '' ? $authState : null;
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
