<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Rp;

use Exception;

/**
 * A login could not be started or completed.
 *
 * Separate from the federation exceptions on purpose: this is the protocol half
 * of the flow, where a failure means the user does not get logged in, rather
 * than the discovery half, where a failure means a shorter list.
 */
class RelyingPartyException extends Exception
{
}
