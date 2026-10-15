<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Hop;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Hop\PickerService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Federation\EntityStatement;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class PickerServiceTest extends TestCase
{
    protected function service(EntityStatement $statement): PickerService
    {
        $config = new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_HOP_TRUSTED_RPS => ['https://rp.example' => 'https://rp.example/initiate'],
        ]));
        return new class ($config, $statement) extends PickerService {
            public function __construct(ModuleConfig $config, private EntityStatement $statement)
            {
                parent::__construct($config);
            }


            protected function configuration(string $entityId): EntityStatement
            {
                return $this->statement;
            }
        };
    }


    public function testEnrolledRpMustPublishItsPinnedInitiationUri(): void
    {
        $statement = $this->createMock(EntityStatement::class);
        $statement->expects($this->once())->method('verifyWithKeySet');
        $statement->method('getIssuer')->willReturn('https://rp.example');
        $statement->method('getSubject')->willReturn('https://rp.example');
        $statement->method('getMetadata')->willReturn([
            'openid_relying_party' => ['initiate_login_uri' => 'https://rp.example/initiate'],
        ]);
        self::assertSame('https://rp.example/initiate', $this->service($statement)->initiateLoginUri('https://rp.example'));
    }


    public function testUnenrolledRpIsRejectedBeforeMetadataFetch(): void
    {
        $service = $this->getMockBuilder(PickerService::class)
            ->setConstructorArgs([new ModuleConfig(Configuration::loadFromArray([]))])
            ->onlyMethods(['configuration'])->getMock();
        $service->expects($this->never())->method('configuration');
        $this->expectException(AccessDeniedHttpException::class);
        $service->initiateLoginUri('https://unknown.example');
    }


    public function testMetadataCannotReplaceApprovedReturnEndpoint(): void
    {
        $statement = $this->createMock(EntityStatement::class);
        $statement->expects($this->once())->method('verifyWithKeySet');
        $statement->method('getIssuer')->willReturn('https://rp.example');
        $statement->method('getSubject')->willReturn('https://rp.example');
        $statement->method('getMetadata')->willReturn([
            'openid_relying_party' => ['initiate_login_uri' => 'https://evil.example'],
        ]);
        $this->expectException(BadRequestHttpException::class);
        $this->service($statement)->initiateLoginUri('https://rp.example');
    }


    public function testReturnCallPreservesTargetAndUserHintButNotOpHint(): void
    {
        $target = 'https://rp.example/app?a=%2f&b=one+two';
        $service = new PickerService(new ModuleConfig(Configuration::loadFromArray([])));
        $url = $service->returnUrl('https://rp.example/initiate', 'https://op.example', [
            'target_link_uri' => $target, 'hint' => 'https://op.example', 'login_hint' => 'student@example.org',
        ]);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        self::assertSame([
            'iss' => 'https://op.example', 'target_link_uri' => $target, 'login_hint' => 'student@example.org',
        ], $parameters);
    }
}
