<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Recovery;

/**
 * The process's own monotonic clock: hrtime() to read it, usleep() to wait on it.
 *
 * @internal
 */
final class SystemClock implements MonotonicClock
{
    #[\Override]
    public function now(): int
    {
        return (int) hrtime(true);
    }

    #[\Override]
    public function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
