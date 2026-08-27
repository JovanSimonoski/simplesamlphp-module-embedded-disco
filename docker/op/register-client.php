<?php

declare(strict_types=1);

/**
 * Registers the embedded discovery relying party at this OpenID Provider.
 *
 * The oidc module administers clients through its admin UI only, and a demo
 * environment should come up ready to log in rather than waiting for someone to
 * fill in a form. This does the same thing the UI does, through the module's own
 * repository, so it stays correct if the schema changes.
 *
 * Idempotent: run on every container start, registers only if absent.
 */

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

$clientId = getenv('OP_CLIENT_ID') ?: '';
$clientSecret = getenv('OP_CLIENT_SECRET') ?: '';
$redirectUri = getenv('OP_CLIENT_REDIRECT_URI') ?: '';

if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
    fwrite(STDERR, "[op] OP_CLIENT_ID, OP_CLIENT_SECRET and OP_CLIENT_REDIRECT_URI must all be set\n");
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

    if ($clientRepository->findById($clientId) !== null) {
        echo "[op] client $clientId already registered\n";
        exit(0);
    }

    $client = $clientEntityFactory->fromData(
        id: $clientId,
        secret: $clientSecret,
        name: 'SimpleSAMLphp embedded discovery',
        description: 'Relying party that discovers this provider through OpenID Federation.',
        redirectUri: [$redirectUri],
        scopes: ['openid', 'profile', 'email'],
        isEnabled: true,
        // Confidential: it authenticates at the token endpoint with the secret
        // above, which is what this provider advertises (client_secret_post).
        isConfidential: true,
    );

    $clientRepository->add($client);

    echo "[op] registered client $clientId with redirect URI $redirectUri\n";
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[op] client registration failed: %s: %s\n", $e::class, $e->getMessage()));
    exit(1);
}
