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
    public function now(): int
    {
        return (int) hrtime(true);
    }

    public function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
