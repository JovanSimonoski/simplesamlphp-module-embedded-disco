<?php

declare(strict_types=1);

namespace SimpleSAML\Module\embeddeddisco\Hop;

use SimpleSAML\Auth\State;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * RP-only continuation. The HOP sees an opaque ID, never serialized SSP state.
 * Each tab has its own session-bound, expiring, single-use continuation.
 */
class Continuation
{
    public const string PENDING = 'embeddeddisco:hop-pending';

    public const string AUTHENTICATING = 'embeddeddisco:hop-authenticating';

    public const string COMPLETE = 'embeddeddisco:hop-complete';

    public const string CREATED = 'embeddeddisco:hop-created';

    public const string ATTRIBUTES = 'embeddeddisco:hop-attributes';

    public const int TTL = 900;


    /** @param array<mixed> $state */
    public function start(array &$state, string $resumeUri): string
    {
        $state[self::CREATED] = time();
        $id = $this->save($state, self::PENDING);
        return Protocol::withQuery($resumeUri, ['request' => $id]);
    }


    public function claim(string $target, string $resumeUri): ?string
    {
        if (explode('?', $target, 2)[0] !== $resumeUri) {
            return null;
        }
        parse_str(parse_url($target, PHP_URL_QUERY) ?? '', $parameters);
        $id = $parameters['request'] ?? null;
        if (!is_string($id) || $target !== Protocol::withQuery($resumeUri, ['request' => $id])) {
            throw new BadRequestHttpException('Invalid HOP continuation target.');
        }
        $state = $this->load($id, self::PENDING);
        $this->save($state, self::AUTHENTICATING);
        return $id;
    }


    /** @param array<string, array<int, mixed>> $attributes */
    public function complete(string $id, array $attributes): void
    {
        $state = $this->load($id, self::AUTHENTICATING);
        $state[self::ATTRIBUTES] = $attributes;
        $this->save($state, self::COMPLETE);
    }


    /** @return array<mixed> */
    public function resume(string $id): array
    {
        $state = $this->load($id, self::COMPLETE);
        if (!is_array($state[self::ATTRIBUTES] ?? null)) {
            throw new BadRequestHttpException('The HOP authentication did not complete.');
        }
        $this->delete($state);
        $state['Attributes'] = $state[self::ATTRIBUTES];
        unset($state[self::CREATED], $state[self::ATTRIBUTES]);
        return $state;
    }


    /** @return array<mixed> */
    protected function load(string $id, string $stage): array
    {
        // Raw state IDs only: never accept SSP's optional restart-URL suffix.
        if (!preg_match('/^_[a-f0-9]{32,128}$/D', $id)) {
            throw new BadRequestHttpException('Invalid HOP continuation identifier.');
        }
        try {
            $state = State::loadState($id, $stage, true);
        } catch (\Exception $exception) {
            throw new BadRequestHttpException('This HOP request cannot be used at this stage.', $exception);
        }
        if ($state === null || !isset($state[self::CREATED]) || time() - $state[self::CREATED] > self::TTL) {
            throw new BadRequestHttpException('This HOP request has expired or belongs to another browser session.');
        }
        return $state;
    }


    /** @param array<mixed> $state */
    protected function save(array &$state, string $stage): string
    {
        return State::saveState($state, $stage, true);
    }


    /** @param array<mixed> $state */
    protected function delete(array &$state): void
    {
        State::deleteState($state);
    }
}
