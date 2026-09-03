<?php

/**
 * Enrolls another local OP beneath this OP, which acts as the demo Trust Anchor.
 *
 * The OIDC module builds its federation list and subordinate statements from
 * federated client records. This startup helper reads the subordinate's Entity
 * Configuration and stores its federation keys in such a record.
 */

declare(strict_types=1);

require '/var/simplesamlphp/src/_autoload.php';

use SimpleSAML\Database;
use SimpleSAML\Module\oidc\Bridges\SspBridge;
use SimpleSAML\Module\oidc\Factories\Entities\ClientEntityFactory;
use SimpleSAML\Module\oidc\Helpers;
use SimpleSAML\Module\oidc\ModuleConfig;
use SimpleSAML\Module\oidc\Repositories\ClientRepository;
use SimpleSAML\Module\oidc\Utils\RequestParamsResolver;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Federation;

$subordinateId = getenv('OP_SUBORDINATE_ID') ?: '';
$caBundle = getenv('OP_SUBORDINATE_CA_BUNDLE') ?: '/etc/ssl/certs/op-demo.pem';

if ($subordinateId === '') {
    fwrite(STDERR, "[op] OP_SUBORDINATE_ID must be set\n");
    exit(1);
}

try {
    $moduleConfig = new ModuleConfig();
    $helpers = new Helpers();
    $clientEntityFactory = new ClientEntityFactory(
        new SspBridge(),
        $helpers,
        $moduleConfig,
        new RequestParamsResolver($helpers, new Core(), new Federation()),
    );
    $clientRepository = new ClientRepository(
        $moduleConfig,
        Database::getInstance(),
        null,
        $clientEntityFactory,
    );

    if ($clientRepository->findFederatedByEntityIdentifier($subordinateId) !== null) {
        echo "[op] federation subordinate $subordinateId already enrolled\n";
        exit(0);
    }

    $context = stream_context_create([
        'http' => ['timeout' => 10],
        'ssl' => [
            'cafile' => $caBundle,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $token = file_get_contents(
        rtrim($subordinateId, '/') . '/.well-known/openid-federation',
        false,
        $context,
    );

    if (!is_string($token)) {
        throw new RuntimeException('The subordinate Entity Configuration could not be fetched.');
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new RuntimeException('The subordinate Entity Configuration is not a compact JWT.');
    }

    $encodedPayload = strtr($parts[1], '-_', '+/');
    $encodedPayload .= str_repeat('=', (4 - strlen($encodedPayload) % 4) % 4);
    $payload = json_decode((string) base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);

    if (
        !is_array($payload)
        || ($payload['iss'] ?? null) !== $subordinateId
        || ($payload['sub'] ?? null) !== $subordinateId
    ) {
        throw new RuntimeException('The fetched Entity Configuration belongs to a different entity.');
    }

    $jwks = $payload['jwks'] ?? null;
    if (!is_array($jwks) || !array_key_exists('keys', $jwks) || !is_array($jwks['keys']) || $jwks['keys'] === []) {
        throw new RuntimeException('The subordinate Entity Configuration contains no federation keys.');
    }

    $openidProviderMetadata = $payload['metadata']['openid_provider'] ?? null;
    $name = is_array($openidProviderMetadata) && is_string($openidProviderMetadata['display_name'] ?? null)
        ? $openidProviderMetadata['display_name']
        : $subordinateId;

    $clientRepository->add($clientEntityFactory->fromData(
        id: 'federation-subordinate-' . hash('sha256', $subordinateId),
        secret: '',
        name: $name,
        description: 'Local OpenID Provider enrolled beneath the demo Trust Anchor.',
        redirectUri: [],
        scopes: ['openid'],
        isEnabled: true,
        entityIdentifier: $subordinateId,
        federationJwks: $jwks,
        isFederated: true,
    ));

    echo "[op] enrolled federation subordinate $subordinateId\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, sprintf(
        "[op] subordinate enrollment failed: %s: %s\n",
        $throwable::class,
        $throwable->getMessage(),
    ));
    exit(1);
}
