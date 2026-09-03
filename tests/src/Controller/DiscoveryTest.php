<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Controller\Discovery;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryResult;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryService;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoverySourceEnum;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Federation\EntityCollection;
use SimpleSAML\Utils\Auth as AuthUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(Discovery::class)]
class DiscoveryTest extends TestCase
{
    public function testSingleProviderIsSelectedAutomaticallyDuringLogin(): void
    {
        $result = new DiscoveryResult(
            collection: $this->createStub(EntityCollection::class),
            entities: [['entityId' => 'https://op.example.org']],
            total: 1,
            nextPageToken: null,
            lastUpdated: null,
            trustAnchorId: 'https://ta.example.org',
            source: DiscoverySourceEnum::Traversal,
        );

        $discoveryService = $this->createMock(DiscoveryService::class);
        $discoveryService->expects($this->once())
            ->method('discover')
            ->willReturn($result);

        $authUtils = $this->createMock(AuthUtils::class);
        $authUtils->expects($this->once())
            ->method('isAdmin')
            ->willReturn(false);

        $moduleConfig = new ModuleConfig(Configuration::loadFromArray([]));
        $controller = new class ($moduleConfig, $discoveryService, $authUtils) extends Discovery {
            public ?Request $selectionRequest = null;


            public function __construct(
                ModuleConfig $moduleConfig,
                DiscoveryService $discoveryService,
                AuthUtils $authUtils,
            ) {
                $this->moduleConfig = $moduleConfig;
                $this->discoveryService = $discoveryService;
                $this->authUtils = $authUtils;
            }


            public function select(Request $request): Response
            {
                $this->selectionRequest = $request;

                return new Response(status: Response::HTTP_NO_CONTENT);
            }
        };
        $response = $controller->main(new Request(['AuthState' => 'discovery-state']));

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertNotNull($controller->selectionRequest);
        $this->assertSame('https://op.example.org', $controller->selectionRequest->query->get('entity_id'));
        $this->assertSame('discovery-state', $controller->selectionRequest->query->get('AuthState'));
    }
}
