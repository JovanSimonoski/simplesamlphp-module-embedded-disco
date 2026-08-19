<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Federation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\Federation\ErrorDetail;
use SimpleSAML\Module\embeddeddisco\Federation\SelectionVerification;
use SimpleSAML\Module\embeddeddisco\Federation\TrustChainService;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;

#[CoversClass(SelectionVerification::class)]
#[CoversClass(TrustChainService::class)]
#[CoversClass(ErrorDetail::class)]
class SelectionVerificationTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected function trustChainService(array $options): TrustChainService
    {
        $moduleConfig = new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_CACHE_DIRECTORY => sys_get_temp_dir() . '/embeddeddisco-tests',
            ...$options,
        ]));

        return new TrustChainService($moduleConfig, new OfflineFederationFactory($moduleConfig));
    }


    public function testFixtureDataIsReportedAsUnverifiedRatherThanVerified(): void
    {
        // Nothing to resolve a fixture entity against, and saying "verified"
        // there would be a lie the picker repeats to the user.
        $verification = $this->trustChainService([ModuleConfig::OPTION_USE_MOCK_DATA => true])
            ->verify('https://op.finki.ukim.mk');

        $this->assertTrue($verification->skipped);
        $this->assertFalse($verification->verified);
        $this->assertNull($verification->error);
        $this->assertSame([], $verification->chain);
    }


    public function testVerificationCanBeTurnedOffWithoutTouchingTheNetwork(): void
    {
        $verification = $this->trustChainService([
            ModuleConfig::OPTION_USE_MOCK_DATA => false,
            ModuleConfig::OPTION_VERIFY_SELECTION => false,
        ])->verify('https://op.example.org');

        $this->assertTrue($verification->skipped);
        $this->assertFalse($verification->verified);
    }


    public function testAnEmptySelectionFailsBeforeAnyResolution(): void
    {
        $verification = $this->trustChainService([ModuleConfig::OPTION_USE_MOCK_DATA => false])->verify('');

        $this->assertFalse($verification->verified);
        $this->assertFalse($verification->skipped);
        $this->assertNotNull($verification->error);
    }


    public function testDisplayNameFallsBackThroughTheMetadataToTheEntityId(): void
    {
        $withDisplayName = new SelectionVerification(
            entityId: 'https://op.example.org',
            trustAnchorId: 'https://ta.example.org',
            verified: true,
            metadata: ['organization_name' => 'Example Org', 'display_name' => 'Example University'],
        );
        $withOrganisation = new SelectionVerification(
            entityId: 'https://op.example.org',
            trustAnchorId: 'https://ta.example.org',
            verified: true,
            metadata: ['organization_name' => 'Example Org'],
        );
        $withNothing = SelectionVerification::failed('https://op.example.org', 'https://ta.example.org', 'nope');

        $this->assertSame('Example University', $withDisplayName->getDisplayName());
        $this->assertSame('Example Org', $withOrganisation->getDisplayName());
        $this->assertSame('https://op.example.org', $withNothing->getDisplayName());
    }


    public function testRemoteErrorPagesAreCutDownBeforeReachingTheUi(): void
    {
        $message = "Server error: `GET https://ta.example.org/.well-known/openid-federation` resulted in a "
            . "`503 Service Unavailable` response:\n<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html>";

        $shortened = ErrorDetail::shorten($message);

        $this->assertStringNotContainsString('DOCTYPE', $shortened);
        $this->assertStringContainsString('503 Service Unavailable', $shortened);
        $this->assertLessThanOrEqual(ErrorDetail::MAX_LENGTH, mb_strlen($shortened));
    }
}
