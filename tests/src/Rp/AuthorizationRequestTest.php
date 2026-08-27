<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Rp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\Module\embeddeddisco\Rp\AuthorizationRequest;

#[CoversClass(AuthorizationRequest::class)]
#[CoversClass(ModuleConfig::class)]
class AuthorizationRequestTest extends TestCase
{
    protected function authorizationRequest(): AuthorizationRequest
    {
        return new AuthorizationRequest(
            issuer: 'https://op.example.org',
            authorizationEndpoint: 'https://op.example.org/authorize',
            clientId: 'rp-client',
            redirectUri: 'https://rp.example.org/callback',
            scopes: ['openid'],
            nonce: 'nonce-value',
            codeVerifier: 'verifier-value',
        );
    }


    public function testWhatTravelsInTheStateIsWhatTheCallbackHasToCheck(): void
    {
        $pending = $this->authorizationRequest()->toStateArray();

        $this->assertSame('https://op.example.org', $pending['issuer']);
        $this->assertSame('nonce-value', $pending['nonce']);
        $this->assertSame('verifier-value', $pending['codeVerifier']);
        $this->assertSame('rp-client', $pending['clientId']);
        $this->assertSame('https://rp.example.org/callback', $pending['redirectUri']);
    }


    public function testStateSurvivesTheRoundTrip(): void
    {
        $restored = AuthorizationRequest::fromStateArray($this->authorizationRequest()->toStateArray());

        $this->assertNotNull($restored);
        $this->assertSame('https://op.example.org', $restored['issuer']);
        $this->assertSame('nonce-value', $restored['nonce']);
    }


    public function testAnythingElseInTheStateIsRefused(): void
    {
        // Whatever comes back from the provider is untrusted, and so is a state
        // array that does not hold a complete request.
        $this->assertNull(AuthorizationRequest::fromStateArray(null));
        $this->assertNull(AuthorizationRequest::fromStateArray('not an array'));
        $this->assertNull(AuthorizationRequest::fromStateArray([]));
        $this->assertNull(AuthorizationRequest::fromStateArray([
            'issuer' => 'https://op.example.org',
            'clientId' => 'rp-client',
            'redirectUri' => 'https://rp.example.org/callback',
            'nonce' => '',
            'codeVerifier' => 'verifier-value',
        ]));
    }


    public function testAProviderWithoutClientCredentialsCannotStartALogin(): void
    {
        $moduleConfig = new ModuleConfig(Configuration::loadFromArray([
            ModuleConfig::OPTION_CLIENTS => [
                'https://op.example.org' => [
                    'client_id' => 'rp-client',
                    'client_secret' => 'rp-secret',
                ],
                // Half an entry is no entry: starting a login with it would fail
                // at the provider instead of on our side.
                'https://broken.example.org' => ['client_id' => 'rp-client'],
            ],
        ]));

        $this->assertNotNull($moduleConfig->getClientForIssuer('https://op.example.org'));
        $this->assertNull($moduleConfig->getClientForIssuer('https://broken.example.org'));
        $this->assertNull($moduleConfig->getClientForIssuer('https://unknown.example.org'));
    }
}
