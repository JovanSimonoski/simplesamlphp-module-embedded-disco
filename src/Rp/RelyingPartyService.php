<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Rp;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use SimpleSAML\Module;
use SimpleSAML\Module\embeddeddisco\Federation\ErrorDetail;
use SimpleSAML\Module\embeddeddisco\Federation\LocalEntityService;
use SimpleSAML\Module\embeddeddisco\Federation\SelectionVerification;
use SimpleSAML\Module\embeddeddisco\ModuleConfig;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Core;
use Throwable;

use function base64_encode;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function is_string;
use function json_decode;
use function random_bytes;
use function rtrim;
use function strtr;
use function time;

/**
 * The relying party half of the flow: turn a verified provider into a login.
 *
 * The endpoints used here come from the Trust Chain, not from the provider's
 * own published metadata. That is the point of doing discovery this way -- by
 * the time a user is sent anywhere, the federation has already said where that
 * provider's authorization endpoint is and which keys sign its tokens, so a
 * provider cannot talk itself into a different set of endpoints after the fact.
 */
class RelyingPartyService
{
    public const string ROUTE_CALLBACK = 'embeddeddisco/callback';

    /**
     * Tolerance for clock differences when checking token timestamps.
     */
    protected const int LEEWAY_SECONDS = 60;


    protected ?Client $httpClient = null;

    protected ?Core $core = null;

    protected ?LocalEntityService $localEntityService = null;


    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
    ) {
    }


    /**
     * Is a login possible for the provider that was just verified?
     */
    public function canLogIn(SelectionVerification $selectionVerification): bool
    {
        return $this->issuerOf($selectionVerification) !== null
            && $this->authorizationEndpointOf($selectionVerification) !== null
            && ($this->moduleConfig->isFederationRelyingParty()
                || $this->moduleConfig->getClientForIssuer((string) $this->issuerOf($selectionVerification)) !== null);
    }


    /**
     * Everything needed to send a user to a verified provider, except the state
     * parameter -- that is the identifier of the saved authentication state,
     * which only exists once this has been stored in it.
     *
     * @throws \SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyException
     */
    public function prepareAuthorizationRequest(
        SelectionVerification $selectionVerification,
    ): AuthorizationRequest {
        if (!$selectionVerification->verified) {
            // Refusing here as well as in the UI: an unverified provider is one
            // the federation would not vouch for, and a redirect is exactly the
            // thing that must not happen.
            throw new RelyingPartyException('Refusing to start a login with a provider that did not verify.');
        }

        $issuer = $this->issuerOf($selectionVerification)
            ?? throw new RelyingPartyException('The resolved metadata has no issuer.');
        $authorizationEndpoint = $this->authorizationEndpointOf($selectionVerification)
            ?? throw new RelyingPartyException('The resolved metadata has no authorization endpoint.');

        if ($this->moduleConfig->isFederationRelyingParty()) {
            $clientId = (string) $this->moduleConfig->getFederationEntityId();
            $scopes = $this->moduleConfig->getScopes();
        } else {
            $client = $this->moduleConfig->getClientForIssuer($issuer)
                ?? throw new RelyingPartyException(
                    'No client is registered for this provider, so a login cannot be started. Add one under the ' .
                    '"clients" option, keyed by the issuer.',
                );

            $clientId = $client['client_id'];
            /** @var string[] $scopes */
            $scopes = $client['scopes'] ?? $this->moduleConfig->getScopes();
        }

        return new AuthorizationRequest(
            issuer: $issuer,
            authorizationEndpoint: $authorizationEndpoint,
            clientId: $clientId,
            redirectUri: Module::getModuleURL(self::ROUTE_CALLBACK),
            scopes: $scopes,
            nonce: $this->randomString(),
            codeVerifier: $this->randomString(),
        );
    }


    /**
     * Where to send the user, once the state has an identifier to travel under.
     */
    public function authorizationUrl(AuthorizationRequest $authorizationRequest, string $stateId): string
    {
        $parameters = [
            'response_type' => 'code',
            'client_id' => $authorizationRequest->clientId,
            'redirect_uri' => $authorizationRequest->redirectUri,
            'scope' => implode(' ', $authorizationRequest->scopes),
            'state' => $stateId,
            'nonce' => $authorizationRequest->nonce,
            // S256, so the verifier itself never travels in the front channel.
            'code_challenge' => $this->urlSafe(hash('sha256', $authorizationRequest->codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ];

        if ($this->isFederationClient($authorizationRequest->clientId)) {
            try {
                $requestObject = $this->localEntityService()->requestObject(
                    $authorizationRequest->issuer,
                    $parameters,
                );
            } catch (Throwable $throwable) {
                throw new RelyingPartyException(
                    'Could not sign the automatic-registration request: ' .
                    ErrorDetail::shorten($throwable->getMessage()),
                    previous: $throwable,
                );
            }

            // Keep only the client identifier outside the signed Request Object.
            // The provider obtains every security-sensitive authorization
            // parameter from the verified JWT.
            $parameters = [
                'client_id' => $authorizationRequest->clientId,
                'request' => $requestObject,
            ];
        }

        $endpoint = $authorizationRequest->authorizationEndpoint;

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($parameters);
    }


    /**
     * Exchange the authorization code and return the ID token's claims.
     *
     * @param array<string, mixed> $pending What buildAuthorizationRequest() produced, as stored in the state.
     * @return array<string, mixed> The validated ID token claims.
     * @throws \SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyException
     */
    public function completeLogin(string $code, array $pending, SelectionVerification $selectionVerification): array
    {
        $issuer = (string) ($pending['issuer'] ?? '');
        $clientId = (string) ($pending['clientId'] ?? '');

        $tokenEndpoint = $this->claim($selectionVerification, ClaimsEnum::TokenEndpoint->value)
            ?? throw new RelyingPartyException('The resolved metadata has no token endpoint.');

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) ($pending['redirectUri'] ?? ''),
            'client_id' => $clientId,
            'code_verifier' => (string) ($pending['codeVerifier'] ?? ''),
        ];

        if (!$this->isFederationClient($clientId)) {
            $client = $this->moduleConfig->getClientForIssuer($issuer)
                ?? throw new RelyingPartyException('No client is registered for this provider any more.');
            $form['client_secret'] = $client['client_secret'];
        }

        $response = $this->postForm($tokenEndpoint, $form);

        $idToken = $response['id_token'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new RelyingPartyException('The provider returned no ID token.');
        }

        return $this->validateIdToken(
            $idToken,
            $selectionVerification,
            $issuer,
            $clientId,
            (string) ($pending['nonce'] ?? ''),
        );
    }


    /**
     * Check an ID token against the keys the federation published for this
     * provider, and against what we asked for.
     *
     * @return array<string, mixed>
     * @throws \SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyException
     */
    protected function validateIdToken(
        string $idToken,
        SelectionVerification $selectionVerification,
        string $issuer,
        string $clientId,
        string $nonce,
    ): array {
        try {
            $parsed = $this->core()->idTokenFactory()->fromToken($idToken);
            $parsed->verifyWithKeySet($this->fetchJwks($selectionVerification));
        } catch (Throwable $throwable) {
            throw new RelyingPartyException(
                'The ID token could not be verified: ' . ErrorDetail::shorten($throwable->getMessage()),
                previous: $throwable,
            );
        }

        if ($parsed->getIssuer() !== $issuer) {
            throw new RelyingPartyException('The ID token was issued by a different provider than the one selected.');
        }

        if (!in_array($clientId, $parsed->getAudience(), true)) {
            throw new RelyingPartyException('The ID token was not issued for this relying party.');
        }

        if ($parsed->getExpirationTime() < time() - self::LEEWAY_SECONDS) {
            throw new RelyingPartyException('The ID token has expired.');
        }

        if ($nonce !== '' && $parsed->getNonce() !== $nonce) {
            // Without this, an ID token captured from an earlier login would do.
            throw new RelyingPartyException('The ID token does not match this login request.');
        }

        return $parsed->getPayload();
    }


    /**
     * The provider's signing keys, from the jwks_uri the Trust Chain resolved.
     *
     * Taken from a Trust Chain resolved at callback time, not carried through
     * the redirect: everything coming back from the provider is untrusted, and
     * the keys that decide whether its token is genuine are the last thing to
     * take from it.
     *
     * @return array<string, mixed>
     * @throws \SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyException
     */
    protected function fetchJwks(SelectionVerification $selectionVerification): array
    {
        $jwksUri = $this->claim($selectionVerification, ClaimsEnum::JwksUri->value)
            ?? throw new RelyingPartyException('The resolved metadata has no jwks_uri.');

        try {
            $body = (string) $this->httpClient()->get($jwksUri)->getBody();
            /** @var array<string, mixed> $jwks */
            $jwks = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RelyingPartyException(
                'Could not read the provider keys: ' . ErrorDetail::shorten($throwable->getMessage()),
                previous: $throwable,
            );
        }

        return $jwks;
    }


    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     * @throws \SimpleSAML\Module\embeddeddisco\Rp\RelyingPartyException
     */
    protected function postForm(string $uri, array $form): array
    {
        try {
            $response = $this->httpClient()->post($uri, [RequestOptions::FORM_PARAMS => $form]);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RelyingPartyException(
                'The token request failed: ' . ErrorDetail::shorten($throwable->getMessage()),
                previous: $throwable,
            );
        }

        if (isset($decoded['error'])) {
            throw new RelyingPartyException(sprintf(
                'The provider refused the token request: %s',
                ErrorDetail::shorten((string) ($decoded['error_description'] ?? $decoded['error'])),
            ));
        }

        return $decoded;
    }


    protected function issuerOf(SelectionVerification $selectionVerification): ?string
    {
        return $this->claim($selectionVerification, ClaimsEnum::Issuer->value)
            // A provider whose metadata omits issuer is identified by its entity ID.
            ?? ($selectionVerification->verified ? $selectionVerification->entityId : null);
    }


    protected function authorizationEndpointOf(SelectionVerification $selectionVerification): ?string
    {
        return $this->claim($selectionVerification, ClaimsEnum::AuthorizationEndpoint->value);
    }


    protected function claim(SelectionVerification $selectionVerification, string $claim): ?string
    {
        $value = $selectionVerification->metadata[$claim] ?? null;

        return (is_string($value) && $value !== '') ? $value : null;
    }


    protected function core(): Core
    {
        return $this->core ??= new Core();
    }


    protected function localEntityService(): LocalEntityService
    {
        return $this->localEntityService ??= new LocalEntityService($this->moduleConfig);
    }


    protected function isFederationClient(string $clientId): bool
    {
        return $this->moduleConfig->isFederationRelyingParty()
            && $clientId !== ''
            && $clientId === $this->moduleConfig->getFederationEntityId();
    }


    protected function httpClient(): Client
    {
        if ($this->httpClient instanceof Client) {
            return $this->httpClient;
        }

        $config = [
            RequestOptions::CONNECT_TIMEOUT => $this->moduleConfig->getHttpConnectTimeout(),
            RequestOptions::TIMEOUT => $this->moduleConfig->getHttpTimeout(),
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::ALLOW_REDIRECTS => false,
        ];

        $caBundle = $this->moduleConfig->getHttpCaBundle();

        if ($caBundle !== null) {
            $config[RequestOptions::VERIFY] = $caBundle;
        }

        return $this->httpClient = new Client($config);
    }


    protected function randomString(): string
    {
        return $this->urlSafe(random_bytes(32));
    }


    protected function urlSafe(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
