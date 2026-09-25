<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Tgi\LaravelPhpRedisSentinel\Recovery\MonotonicClock;
use ValueError;

/**
 * A clock that only moves when told to, so a test can put a deadline at an exact instant.
 *
 * Sleeping advances it by the time slept and returns at once.
 *
 * Starts an hour in rather than at zero, as hrtime() does (it counts from an arbitrary point, in practice uptime):
 * at zero, code that passes 0 or a duration where a reading belongs would pass every test.
 */
final class FakeClock implements MonotonicClock
{
    /**
     * An hour, in nanoseconds: a plausible reading, far from overflow.
     */
    private const int DEFAULT_START = 3_600_000_000_000;

    public function __construct(private int $now = self::DEFAULT_START) {}

    public function now(): int
    {
        return $this->now;
    }

    public function sleep(int $milliseconds): void
    {
        $this->advance($milliseconds);
    }

    /**
     * Refuses a negative duration, as SystemClock's usleep() does: a monotonic clock never runs backwards.
     */
    public function advance(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new ValueError('A clock cannot move by a negative duration.');
        }

        $this->now += $milliseconds * 1_000_000;
    }
}
