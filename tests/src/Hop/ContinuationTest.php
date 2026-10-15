<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Hop;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Auth\State;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Hop\Continuation;
use SimpleSAML\Session;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ContinuationTest extends TestCase
{
    private const string RESUME = 'https://rp.example/hop/resume';


    protected function setUp(): void
    {
        Configuration::setPreLoadedConfig(Configuration::loadFromArray([
            'secretsalt' => 'hop-test-only', 'logging.handler' => 'errorlog',
        ]), 'config.php');
        Session::useTransientSession();
    }


    protected function tearDown(): void
    {
        Session::clearInternalState();
        Configuration::clearInternalState();
    }


    public function testResumesOriginalApplicationStateOnceAfterAuthentication(): void
    {
        $service = new Continuation();
        $state = ['application-return' => '/protected', 'LoginCompletedHandler' => 'original-handler'];
        $target = $service->start($state, self::RESUME);
        $id = $service->claim($target, self::RESUME);
        self::assertNotNull($id);
        $service->complete($id, ['sub' => ['student']]);
        $restored = $service->resume($id);
        self::assertSame('/protected', $restored['application-return']);
        self::assertSame('original-handler', $restored['LoginCompletedHandler']);
        self::assertSame(['sub' => ['student']], $restored['Attributes']);
        $this->expectException(BadRequestHttpException::class);
        $service->resume($id);
    }


    public function testPickerFirstTargetDoesNotRequireAnExistingState(): void
    {
        self::assertNull((new Continuation())->claim('https://rp.example/application', self::RESUME));
    }


    public function testTwoTabsKeepTheirOwnApplicationStateAndAttributes(): void
    {
        $service = new Continuation();
        $first = ['application' => 'first'];
        $second = ['application' => 'second'];
        $firstTarget = $service->start($first, self::RESUME);
        $secondTarget = $service->start($second, self::RESUME);
        $firstId = $service->claim($firstTarget, self::RESUME);
        $secondId = $service->claim($secondTarget, self::RESUME);
        self::assertNotSame($firstId, $secondId);
        $service->complete($secondId, ['sub' => ['second-user']]);
        $service->complete($firstId, ['sub' => ['first-user']]);
        $resumed = $service->resume($firstId);
        self::assertSame('first', $resumed['application']);
        self::assertSame(['first-user'], $resumed['Attributes']['sub']);
        $resumed = $service->resume($secondId);
        self::assertSame('second', $resumed['application']);
        self::assertSame(['second-user'], $resumed['Attributes']['sub']);
    }


    public function testExpiredStateIsRejectedEvenIfSspStillStoresIt(): void
    {
        $state = [Continuation::CREATED => time() - Continuation::TTL - 1];
        $id = State::saveState($state, Continuation::PENDING, true);
        $this->expectException(BadRequestHttpException::class);
        (new Continuation())->claim(self::RESUME . '?request=' . $id, self::RESUME);
    }


    public function testAnotherBrowserSessionCannotClaimTheContinuation(): void
    {
        $service = new Continuation();
        $state = [];
        $target = $service->start($state, self::RESUME);
        Session::clearInternalState();
        Session::useTransientSession();
        $this->expectException(BadRequestHttpException::class);
        $service->claim($target, self::RESUME);
    }


    public function testResumeCannotRunBeforeSuccessfulAuthentication(): void
    {
        $service = new Continuation();
        $state = [];
        $target = $service->start($state, self::RESUME);
        $id = $service->claim($target, self::RESUME);
        $this->expectException(BadRequestHttpException::class);
        $service->resume($id);
    }


    public function testClaimCannotBeReplayed(): void
    {
        $service = new Continuation();
        $state = [];
        $target = $service->start($state, self::RESUME);
        $service->claim($target, self::RESUME);
        $this->expectException(BadRequestHttpException::class);
        $service->claim($target, self::RESUME);
    }
}
