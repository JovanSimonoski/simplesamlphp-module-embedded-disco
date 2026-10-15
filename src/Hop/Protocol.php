<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Hop;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Input and URL handling shared by the independently deployable HOP and RP. */
class Protocol
{
    /** @return ($required is true ? string : string|null) */
    public static function parameter(Request $request, string $name, bool $required = false): ?string
    {
        $values = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $value = $values[$name] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new BadRequestHttpException('Invalid or missing ' . $name . '.');
        }
        return $value;
    }


    public static function httpsUrl(string $uri, bool $allowQuery = true, bool $allowFragment = false): string
    {
        $parts = parse_url($uri);
        if (
            $parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (!$allowFragment && isset($parts['fragment']))
            || (!$allowQuery && isset($parts['query']))
            || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)
            || preg_match('/%(?:0[ad]|5c)/i', $uri)
        ) {
            throw new BadRequestHttpException('Invalid absolute HTTPS URL.');
        }
        return $uri;
    }


    public static function origin(string $uri): string
    {
        $parts = parse_url(self::httpsUrl($uri, allowFragment: true));
        return 'https://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? 443);
    }


    /** @param array<string, string> $parameters */
    public static function withQuery(string $uri, array $parameters): string
    {
        self::httpsUrl($uri);
        $parts = explode('?', $uri, 2);
        $query = [];
        parse_str($parts[1] ?? '', $query);
        // Never inherit protocol values from an endpoint's fixed query string.
        foreach (['iss', 'entity_id', 'hint', 'login_hint', 'target_link_uri'] as $reserved) {
            unset($query[$reserved]);
        }
        return $parts[0] . '?' . http_build_query([...$query, ...$parameters], '', '&', PHP_QUERY_RFC3986);
    }
}
