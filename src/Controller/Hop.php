<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\Hop\PickerService;
use SimpleSAML\Module\embeddeddisco\Hop\Protocol;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** A HOP picker: returns an issuer to the RP, never starts OP authorization. */
class Hop extends Discovery
{
    public function main(Request $request): Response
    {
        try {
            return $this->pick($request);
        } catch (HttpExceptionInterface $exception) {
            return $this->secureResponse(new Response($exception->getMessage(), $exception->getStatusCode(), [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]));
        }
    }


    protected function pick(Request $request): Response
    {
        $context = [];
        foreach (['entity_id', 'hint', 'target_link_uri', 'login_hint'] as $name) {
            $value = Protocol::parameter($request, $name, $name === 'entity_id');
            if ($value !== null) {
                $context[$name] = $value;
            }
        }
        $picker = $this->pickerService();
        try {
            $initiateUri = $picker->initiateLoginUri($context['entity_id']);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BadRequestHttpException('The RP metadata could not be verified.', $exception);
        }

        // Never interpret an incoming AuthState or ReturnTo at a HOP endpoint.
        $query = DiscoveryQuery::fromRequest($request, $this->moduleConfig);
        $query = new DiscoveryQuery(
            query: $query->query,
            entityTypes: ['openid_provider'],
            trustMarkTypes: $query->trustMarkTypes,
            limit: $query->limit,
            from: $query->from,
            sortOrder: $query->sortOrder,
        );
        $result = $this->discoveryService->discover($query);
        $entities = $result->entities;
        $hint = $context['hint'] ?? null;
        // A hinted entity on a later page can be pinned to the initial page.
        // Look it up through discovery, never by fetching the hint as a URL.
        if ($hint !== null && $query->from === null && $query->query === '' && !$result->hasFailed()) {
            $hintResult = $this->discoveryService->discover(new DiscoveryQuery(
                query: $hint,
                entityTypes: $query->entityTypes,
                trustMarkTypes: $query->trustMarkTypes,
                limit: $query->limit,
                sortOrder: $query->sortOrder,
            ));
            foreach ($hintResult->entities as $entity) {
                if ($entity['entityId'] === $hint && !in_array($hint, array_column($entities, 'entityId'), true)) {
                    array_unshift($entities, $entity);
                    break;
                }
            }
        }
        foreach ($entities as &$entity) {
            $entity['selectUrl'] = $picker->returnUrl($initiateUri, $entity['entityId'], $context);
            $entity['suggested'] = $entity['entityId'] === $hint;
        }
        unset($entity);
        // Stable partition: the matching OP becomes the default choice without
        // changing the remaining ordering or treating an unknown hint as an OP.
        $entities = [
            ...array_filter($entities, static fn(array $entity): bool => $entity['suggested']),
            ...array_filter($entities, static fn(array $entity): bool => !$entity['suggested']),
        ];

        if (
            $this->moduleConfig->hopAutoSelect() && !$result->hasFailed()
            && $query->from === null && count($entities) === 1
            && $result->nextPageToken === null && ($result->total === null || $result->total === 1)
        ) {
            return $this->secureResponse(new RedirectResponse($entities[0]['selectUrl']));
        }

        $template = $this->discoveryTemplate(new Request(), $query, $result, false);
        $formUrl = $this->hopUrl();
        $template->data['entities'] = $entities;
        $template->data['contextParameters'] = $context;
        $template->data['formUrl'] = $formUrl;
        $template->data['resetUrl'] = Protocol::withQuery($formUrl, $context);
        $parameters = [...$context, 'query' => $query->query, 'sort_dir' => $query->sortOrder];
        $template->data['nextPageUrl'] = $result->nextPageToken === null ? null : Protocol::withQuery(
            $formUrl,
            [...$parameters, 'from' => $result->nextPageToken],
        );
        return $this->secureResponse($template);
    }


    protected function pickerService(): PickerService
    {
        return new PickerService($this->moduleConfig);
    }


    protected function hopUrl(): string
    {
        return Module::getModuleURL('embeddeddisco/hop');
    }


    protected function secureResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        return $response;
    }
}
