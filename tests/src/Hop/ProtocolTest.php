<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Hop;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Hop\Protocol;
use SimpleSAML\Module\embeddeddisco\Hop\RelyingParty;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ProtocolTest extends TestCase
{
    public function testGetAndPostUseTheirOwnParameterBags(): void
    {
        $request = Request::create('/initiate?iss=https://wrong.example', 'POST', ['iss' => 'https://op.example']);
        self::assertSame('https://op.example', Protocol::parameter($request, 'iss', true));
        self::assertSame('https://op.example', Protocol::parameter(new Request(['iss' => 'https://op.example']), 'iss'));
        self::assertNull(Protocol::parameter(new Request(), 'login_hint'));
    }


    public static function invalidParameters(): array
    {
        return [[null], [''], [['https://op.example']], ["evil\r\nLocation: other"], [str_repeat('x', 8193)]];
    }


    #[DataProvider('invalidParameters')]
    public function testRejectsMalformedRequiredParameters(mixed $value): void
    {
        $this->expectException(BadRequestHttpException::class);
        Protocol::parameter(new Request(['iss' => $value]), 'iss', true);
    }


    public static function invalidUrls(): array
    {
        return array_map(static fn(string $url): array => [$url], [
            'http://rp.example', '//rp.example', 'https://user@rp.example', 'https://rp.example/#fragment',
            'https://rp.example\\@evil.example', 'https://rp.example/%0d%0aLocation:evil', 'https://rp.example/a b',
        ]);
    }


    #[DataProvider('invalidUrls')]
    public function testRejectsUnsafeEndpointUrls(string $url): void
    {
        $this->expectException(BadRequestHttpException::class);
        Protocol::httpsUrl($url);
    }


    public function testReturnTargetSurvivesEncodingWithoutModification(): void
    {
        $target = 'https://rp.example/app?next=%2Fhome%3Fx%3D1&x=a+b&x=two';
        $url = Protocol::withQuery('https://rp.example/initiate?tenant=one&iss=stale&hint=stale', [
            'iss' => 'https://op.example', 'target_link_uri' => $target,
        ]);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        self::assertSame($target, $parameters['target_link_uri']);
        self::assertSame('https://op.example', $parameters['iss']);
        self::assertSame('one', $parameters['tenant']);
        self::assertArrayNotHasKey('hint', $parameters);
    }


    public function testOutgoingCallUsesRpEntityIdAndSeparateOpHint(): void
    {
        $config = new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_HOP_ENDPOINT => 'https://hop.example/pick',
            ModuleConfig::OPTION_FEDERATION_ENTITY_ID => 'https://rp.example/entity',
            ModuleConfig::OPTION_INITIATE_LOGIN_URI => 'https://rp.example/initiate',
            ModuleConfig::OPTION_HOP_HINT => 'https://op.example',
        ]));
        $url = (new RelyingParty($config))->outgoingUrl('https://rp.example/resume?request=opaque');
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        self::assertSame('https://rp.example/entity', $parameters['entity_id']);
        self::assertSame('https://op.example', $parameters['hint']);
        self::assertSame('https://rp.example/resume?request=opaque', $parameters['target_link_uri']);
        self::assertArrayNotHasKey('login_hint', $parameters);
    }


    public static function targets(): array
    {
        return [
            ['https://rp.example/path?a=%2f+b', true],
            ['https://RP.example:443/path', true],
            ['https://app.example/path', true],
            ['https://rp.example/path#section', true],
            ['https://rp.example:8443/path', false],
            ['https://rp.example.evil.example/path', false],
            ['https://rp.example@evil.example/path', false],
            ['//evil.example/path', false],
        ];
    }


    #[DataProvider('targets')]
    public function testTargetOriginValidation(string $target, bool $accepted): void
    {
        $service = new RelyingParty(new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_INITIATE_LOGIN_URI => 'https://rp.example/initiate',
            ModuleConfig::OPTION_HOP_ALLOWED_TARGET_ORIGINS => ['https://app.example'],
        ])));
        if (!$accepted) {
            $this->expectException(BadRequestHttpException::class);
        }
        self::assertSame($target, $service->target($target));
    }
}
