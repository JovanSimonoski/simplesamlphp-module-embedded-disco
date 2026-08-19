<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\embeddeddisco\Federation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleSAML\Module\embeddeddisco\Federation\FederationFactory;

/**
 * The module's factory, minus SimpleSAMLphp's logger.
 *
 * SimpleSAML\Compat\Logger writes through SimpleSAML\Logger, which expects a
 * configured installation. Everything else about the facade is left as the
 * module builds it, so what the tests exercise is the real composition.
 */
class OfflineFederationFactory extends FederationFactory
{
    protected function buildLogger(): LoggerInterface
    {
        return new NullLogger();
    }
}
