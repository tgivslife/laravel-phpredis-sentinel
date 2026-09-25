<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Recovery;

/**
 * The time source a recovery is measured and paced with.
 *
 * Reading the time and waiting are one collaborator on purpose: a stand-in clock whose sleep() moves now() forward
 * keeps the retry delay visible to the deadline, which a separate stand-in sleeper would hide.
 *
 * @internal
 */
interface MonotonicClock
{
    /**
     * Nanoseconds on a monotonic clock; only the difference between two readings means anything.
     */
    public function now(): int;

    /**
     * Wait the given number of milliseconds.
     *
     * @param  int<0, max>  $milliseconds
     *
     * @throws \ValueError When the duration is negative, as usleep() does.
     */
    public function sleep(int $milliseconds): void;
}
