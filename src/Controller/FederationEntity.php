<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Module\embeddeddisco\Federation\LocalEntityService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function in_array;
use function is_string;

/**
 * Public OpenID Federation endpoints for the local RP and Trust Anchor.
 */
class FederationEntity
{
    protected readonly ModuleConfig $moduleConfig;

    protected readonly LocalEntityService $localEntityService;


    public function __construct()
    {
        $this->moduleConfig = new ModuleConfig();
        $this->localEntityService = new LocalEntityService($this->moduleConfig);
    }


    public function configuration(): Response
    {
        try {
            return $this->entityStatementResponse($this->localEntityService->entityConfiguration());
        } catch (Throwable $throwable) {
            return $this->error('server_error', $throwable->getMessage(), 500);
        }
    }


    public function list(): JsonResponse
    {
        if (!$this->moduleConfig->isFederationTrustAnchor()) {
            return $this->error('not_found', 'This entity does not have a subordinate listing endpoint.', 404);
        }

        return new JsonResponse(
            $this->moduleConfig->getFederationSubordinates(),
            headers: ['Access-Control-Allow-Origin' => '*'],
        );
    }


    public function fetch(Request $request): Response
    {
        $subject = $request->query->get('sub');
        if (!is_string($subject) || $subject === '') {
            return $this->error('invalid_request', 'Missing sub parameter.', 400);
        }

        try {
            return $this->entityStatementResponse($this->localEntityService->subordinateStatement($subject));
        } catch (Throwable $throwable) {
            $status = in_array($subject, $this->moduleConfig->getFederationSubordinates(), true) ? 502 : 404;

            return $this->error(
                $status === 404 ? 'not_found' : 'server_error',
                $throwable->getMessage(),
                $status,
            );
        }
    }


    protected function entityStatementResponse(string $token): Response
    {
        return new Response($token, 200, [
            'Content-Type' => 'application/entity-statement+jwt',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }


    protected function error(string $error, string $description, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $error,
            'error_description' => $description,
        ], $status, ['Access-Control-Allow-Origin' => '*']);
    }
}
