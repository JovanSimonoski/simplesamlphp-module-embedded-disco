<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Federation;

use function explode;
use function mb_strimwidth;
use function trim;

/**
 * Turns a library exception message into something a page can carry.
 *
 * Federation endpoints answer failures with whatever their web server feels
 * like, and the library quotes the body it got. Left whole, one unreachable
 * Trust Anchor puts an entire Apache error page in the picker.
 */
class ErrorDetail
{
    /**
     * Long enough to name the failing host and the HTTP status, short enough
     * that a remote error page cannot take over the UI.
     */
    public const int MAX_LENGTH = 300;


    public static function shorten(string $message): string
    {
        return mb_strimwidth(trim(explode("\n", $message)[0]), 0, self::MAX_LENGTH, '...');
    }
}
