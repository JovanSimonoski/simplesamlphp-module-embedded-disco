<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Module;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

use function class_exists;
use function phpversion;

/**
 * Smoke-test endpoint confirming the module is enabled and that the
 * simplesamlphp/openid library is autoloadable inside the SSP installation.
 */
class Status
{
    public function __construct(
        protected Configuration $config,
        protected Session $session,
    ) {
    }


    public function status(): JsonResponse
    {
        return new JsonResponse([
            'module' => 'embeddeddisco',
            'module_enabled' => Module::isModuleEnabled('embeddeddisco'),
            'ssp_version' => $this->config->getVersion(),
            'php_version' => phpversion(),
            'openid_library' => class_exists(Federation::class) ? 'loaded' : 'missing',
        ]);
    }
}
