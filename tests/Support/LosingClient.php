<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Redis;
use Throwable;

/**
 * A client whose first network call throws the given failure and whose later calls all answer, recording each call.
 *
 * Stands in for phpredis without a server: every method arrives through __call. Reading and writing options,
 * closing, the last-error calls and phpredis's local `_` helpers are not network calls and never fail.
 * `pipeline()` and `multi()` hand back the client itself, as phpredis does in those modes.
 */
final class LosingClient
{
    /**
     * Method names that never reach the server.
     *
     * @var list<string>
     */
    private const array LOCAL = ['getoption', 'setoption', 'close', 'getlasterror', 'clearlasterror', 'isconnected'];

    /**
     * Every call, in order, lower-cased.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Whether the failure has been thrown.
     */
    public bool $failed = false;

    /**
     * @param  Throwable|null  $failure  What the first network call throws; null for a client that never fails.
     * @param  string|null  $failAt  Fail the first call to this method instead of the first network call.
     */
    public function __construct(private ?Throwable $failure, private readonly ?string $failAt = null) {}

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $method = strtolower($method);
        $this->calls[] = $method;

        if ($method === 'getoption') {
            return ($arguments[0] ?? null) === Redis::OPT_READ_TIMEOUT ? 2.0 : 0;
        }

        if (in_array($method, self::LOCAL, true) || str_starts_with($method, '_')) {
            return in_array($method, ['getlasterror'], true) ? null : true;
        }

        if ($this->failure !== null && ($this->failAt === null || $this->failAt === $method)) {
            $failure = $this->failure;
            $this->failure = null;
            $this->failed = true;

            throw $failure;
        }

        return match ($method) {
            'pipeline', 'multi' => $this,
            'exec', 'blpop', 'brpop', 'scan', 'zscan', 'hscan', 'sscan', 'mget', 'hmget' => [],
            default => true,
        };
    }
}
