<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Redis;
use Throwable;

/**
 * A client whose first network call throws the given failure and whose later calls all answer, recording each call.
 *
 * Stands in for phpredis without a server: every method arrives through __call. Reading and writing options,
 * closing, the mode, the connection state, the last-error calls and phpredis's local `_` helpers are not network
 * calls and never fail.
 *
 * It keeps phpredis's mode: `multi()` and `pipeline()` hand back the client itself and change the mode, a `multi()`
 * inside a pipeline leaves it a pipeline, and `exec()` and `discard()` end the innermost. Queued commands hand back
 * the client too. The failure closes the socket and ends the mode, as a lost connection does, unless it is an error
 * reply (`$errorReply`), which leaves both as they were.
 */
final class LosingClient
{
    /**
     * Method names that never reach the server.
     *
     * @var list<string>
     */
    private const array LOCAL = ['getoption', 'setoption', 'close', 'getlasterror', 'clearlasterror', 'isconnected', 'getmode'];

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
     * Whether the socket is open.
     */
    public bool $connected = true;

    /**
     * phpredis's mode: Redis::ATOMIC, MULTI or PIPELINE.
     */
    public int $mode = Redis::ATOMIC;

    /**
     * How many transactions are open inside the pipeline.
     */
    private int $nestedTransactions = 0;

    /**
     * @param  Throwable|null  $failure  What the first network call throws; null for a client that never fails.
     * @param  string|null  $failAt  Fail the first call to this method instead of the first network call.
     * @param  bool  $errorReply  Whether the failure is an error reply, leaving the socket open and the mode as it was.
     * @param  int  $passes  How many calls to $failAt answer before the one that fails.
     */
    public function __construct(
        private ?Throwable $failure,
        private readonly ?string $failAt = null,
        private readonly bool $errorReply = false,
        private int $passes = 0,
    ) {}

    /**
     * The calls that reach the server, in order, lower-cased.
     *
     * @return list<string>
     */
    public function sent(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (string $method): bool => ! in_array($method, self::LOCAL, true) && ! str_starts_with($method, '_'),
        ));
    }

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

        if ($method === 'getmode') {
            return $this->mode;
        }

        if ($method === 'isconnected') {
            return $this->connected;
        }

        if (in_array($method, self::LOCAL, true) || str_starts_with($method, '_')) {
            return in_array($method, ['getlasterror'], true) ? null : true;
        }

        if ($this->failure !== null && ($this->failAt === null || $this->failAt === $method) && $this->passes-- <= 0) {
            $failure = $this->failure;
            $this->failure = null;
            $this->failed = true;

            if (! $this->errorReply) {
                $this->connected = false;
                $this->mode = Redis::ATOMIC;
                $this->nestedTransactions = 0;
            }

            throw $failure;
        }

        return match ($method) {
            'pipeline', 'multi' => $this->open($method),
            'exec', 'discard' => $this->end($method),
            default => $this->mode === Redis::ATOMIC ? $this->answer($method) : $this,
        };
    }

    private function open(string $method): self
    {
        if ($this->mode === Redis::PIPELINE && $method === 'multi') {
            $this->nestedTransactions++;
        } else {
            $this->mode = $method === 'multi' ? Redis::MULTI : Redis::PIPELINE;
        }

        return $this;
    }

    /**
     * @return list<mixed>|bool|self
     */
    private function end(string $method): array|bool|self
    {
        if ($this->nestedTransactions > 0) {
            $this->nestedTransactions--;

            return $this;
        }

        $this->mode = Redis::ATOMIC;

        return $method === 'exec' ? [] : true;
    }

    private function answer(string $method): mixed
    {
        return match ($method) {
            'blpop', 'brpop', 'scan', 'zscan', 'hscan', 'sscan', 'mget', 'hmget' => [],
            default => true,
        };
    }
}
