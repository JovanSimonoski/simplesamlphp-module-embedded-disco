<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Controller\Hop;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryQuery;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryResult;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoveryService;
use SimpleSAML\Module\embeddeddisco\Federation\DiscoverySourceEnum;
use SimpleSAML\Module\embeddeddisco\Hop\PickerService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Federation\EntityCollection;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class HopTest extends TestCase
{
    protected function discoveryResult(array $ids, ?string $next = null, ?string $error = null): DiscoveryResult
    {
        return new DiscoveryResult(
            $this->createStub(EntityCollection::class),
            array_map(static fn(string $id): array => ['entityId' => $id], $ids),
            count($ids), $next, null, 'https://ta.example', DiscoverySourceEnum::Traversal, $error,
        );
    }


    protected function controller(DiscoveryResult $result, bool $auto = true): Hop
    {
        $moduleConfig = new ModuleConfig(Configuration::loadFromArray([ModuleConfig::OPTION_HOP_AUTO_SELECT => $auto]));
        $discovery = $this->createStub(DiscoveryService::class);
        $discovery->method('discover')->willReturnCallback(function (DiscoveryQuery $query) use ($result) {
            self::assertSame(['openid_provider'], $query->entityTypes);
            return $result;
        });
        $picker = $this->getMockBuilder(PickerService::class)->setConstructorArgs([$moduleConfig])
            ->onlyMethods(['initiateLoginUri'])->getMock();
        $picker->expects($this->once())->method('initiateLoginUri')->with('https://rp.example')
            ->willReturn('https://rp.example/initiate');
        $template = $this->createStub(Template::class);
        $template->headers = new ResponseHeaderBag();
        return new class ($moduleConfig, $discovery, $picker, $template) extends Hop {
            public function __construct(
                ModuleConfig $config, DiscoveryService $discovery,
                private PickerService $picker, private Template $template,
            ) {
                $this->moduleConfig = $config;
                $this->discoveryService = $discovery;
            }


            protected function pickerService(): PickerService
            {
                return $this->picker;
            }


            protected function hopUrl(): string
            {
                return 'https://hop.example/pick';
            }


            protected function discoveryTemplate(
                Request $request, DiscoveryQuery $query, DiscoveryResult $result, bool $isAdmin,
            ): Template {
                TestCase::assertCount(0, $request->query->all());
                return $this->template;
            }
        };
    }


    public function testSingleOpReturnsToRpWithoutExistingAuthState(): void
    {
        $response = $this->controller($this->discoveryResult(['https://op.example']))->main(new Request([
            'entity_id' => 'https://rp.example', 'target_link_uri' => 'https://rp.example/path?a=%2f+b',
        ]));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringStartsWith('https://rp.example/initiate?', $response->getTargetUrl());
        parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $parameters);
        self::assertSame('https://op.example', $parameters['iss']);
        self::assertSame('https://rp.example/path?a=%2f+b', $parameters['target_link_uri']);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }


    public function testMatchingHintIsFirstAndContextSurvivesSearchAndPaging(): void
    {
        $response = $this->controller($this->discoveryResult(['https://op1.example', 'https://op2.example'], 'page2'))
            ->main(new Request([
                'entity_id' => 'https://rp.example', 'hint' => 'https://op2.example',
                'target_link_uri' => 'https://rp.example/path', 'AuthState' => 'ignored', 'ReturnTo' => 'ignored',
            ]));
        self::assertInstanceOf(Template::class, $response);
        self::assertSame('https://op2.example', $response->data['entities'][0]['entityId']);
        self::assertTrue($response->data['entities'][0]['suggested']);
        self::assertSame('https://op2.example', $response->data['contextParameters']['hint']);
        parse_str(parse_url($response->data['nextPageUrl'], PHP_URL_QUERY), $parameters);
        self::assertSame('https://rp.example', $parameters['entity_id']);
        self::assertSame('https://rp.example/path', $parameters['target_link_uri']);
        self::assertSame('page2', $parameters['from']);
        self::assertArrayNotHasKey('AuthState', $parameters);
    }


    public function testSingleOpCanRemainVisibleByConfiguration(): void
    {
        $response = $this->controller($this->discoveryResult(['https://op.example']), false)
            ->main(new Request(['entity_id' => 'https://rp.example']));
        self::assertInstanceOf(Template::class, $response);
    }


    public function testFirstPageWithOneRowIsNotMistakenForSingleOpFederation(): void
    {
        $response = $this->controller($this->discoveryResult(['https://op.example'], 'page2'))
            ->main(new Request(['entity_id' => 'https://rp.example']));
        self::assertInstanceOf(Template::class, $response);
    }


    public function testUnknownHintIsNotOfferedAsProvider(): void
    {
        $response = $this->controller($this->discoveryResult(['https://op.example']), false)
            ->main(new Request(['entity_id' => 'https://rp.example', 'hint' => 'https://unknown.example']));
        self::assertInstanceOf(Template::class, $response);
        self::assertCount(1, $response->data['entities']);
        self::assertFalse($response->data['entities'][0]['suggested']);
    }
}
