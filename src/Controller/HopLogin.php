<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Auth\Source\OpenIdFederation;
use SimpleSAML\Module\embeddeddisco\Hop\Continuation;
use SimpleSAML\Module\embeddeddisco\Hop\Protocol;
use SimpleSAML\Module\embeddeddisco\Hop\RelyingParty;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class HopLogin
{
    public function __construct(protected Configuration $config)
    {
    }


    public function initiate(Request $request): Response
    {
        try {
            return $this->start($request);
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception);
        }
    }


    protected function start(Request $request): Response
    {
        $moduleConfig = new ModuleConfig();
        if (!$moduleConfig->isFederationRelyingParty() || $moduleConfig->getInitiateLoginUri() === null) {
            throw new AccessDeniedHttpException('Third-party login is not enabled on this RP.');
        }
        $issuer = Protocol::httpsUrl(Protocol::parameter($request, 'iss', true), false);
        $loginHint = Protocol::parameter($request, 'login_hint');
        $target = (new RelyingParty($moduleConfig))->target(
            Protocol::parameter($request, 'target_link_uri')
                ?? $moduleConfig->getHopDefaultTarget()
                ?? Module::getModuleURL('embeddeddisco/session'),
        );
        $continuation = (new Continuation())->claim($target, Module::getModuleURL('embeddeddisco/hop/resume'));
        // Always start the requested authentication, even if an SSP session
        // exists for another OP. The selected issuer bypasses the picker.
        $response = new RunnableResponse([new Auth\Simple(Login::AUTH_SOURCE_ID), 'login'], [[
            'ReturnTo' => $target,
            'KeepPost' => false,
            OpenIdFederation::SELECTED_ISSUER => $issuer,
            OpenIdFederation::LOGIN_HINT => $loginHint,
            OpenIdFederation::HOP_CONTINUATION => $continuation,
        ]]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        return $response;
    }


    public function resume(Request $request): Response
    {
        try {
            $state = (new Continuation())->resume(Protocol::parameter($request, 'request', true));
            return new RunnableResponse([Auth\Source::class, 'completeAuth'], [&$state]);
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception);
        }
    }


    protected function errorResponse(HttpExceptionInterface $exception): Response
    {
        return new Response($exception->getMessage(), $exception->getStatusCode(), [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
