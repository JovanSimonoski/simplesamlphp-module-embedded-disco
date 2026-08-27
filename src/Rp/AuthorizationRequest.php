<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Rp;

use function is_array;
use function is_string;

/**
 * One authorization request in flight.
 *
 * Everything here has to survive the round trip to the provider and be checked
 * when the user comes back: at that point the provider's answer is untrusted
 * input, and these are what make it checkable rather than merely plausible.
 *
 * The state parameter is not part of this. It is the identifier SimpleSAMLphp
 * gives the saved authentication state, which only exists once this has been
 * stored in it -- see toStateArray().
 */
class AuthorizationRequest
{
    /**
     * @param string[] $scopes
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly string $nonce,
        public readonly string $codeVerifier,
    ) {
    }


    /**
     * @return array<string, mixed>
     */
    public function toStateArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'clientId' => $this->clientId,
            'redirectUri' => $this->redirectUri,
            'nonce' => $this->nonce,
            'codeVerifier' => $this->codeVerifier,
        ];
    }


    /**
     * @param mixed $state Whatever was left in the authentication state; treated as untrusted.
     * @return ?array{
     *     issuer: non-empty-string,
     *     clientId: non-empty-string,
     *     redirectUri: non-empty-string,
     *     nonce: non-empty-string,
     *     codeVerifier: non-empty-string
     * }
     */
    public static function fromStateArray(mixed $state): ?array
    {
        if (!is_array($state)) {
            return null;
        }

        $pending = [];

        foreach (['issuer', 'clientId', 'redirectUri', 'nonce', 'codeVerifier'] as $key) {
            $value = $state[$key] ?? null;

            if (!is_string($value) || $value === '') {
                return null;
            }

            $pending[$key] = $value;
        }

        return $pending;
    }
}
