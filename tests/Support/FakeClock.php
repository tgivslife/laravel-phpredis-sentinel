<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Tgi\LaravelPhpRedisSentinel\Recovery\MonotonicClock;
use ValueError;

/**
 * A clock that only moves when told to, so a test can put a deadline at an exact instant.
 *
 * sleep() returns at once, advancing by the time asked plus any overshoot set, as a real sleep may run long.
 * It starts an hour in, not at zero, so code that passes 0 where a clock reading belongs fails its tests.
 */
final class FakeClock implements MonotonicClock
{
    /**
     * An hour, in nanoseconds: a plausible reading, far from overflow.
     */
    private const int DEFAULT_START = 3_600_000_000_000;

    /**
     * @param  int  $sleepOvershootMs  How much longer than asked every sleep() takes.
     */
    public function __construct(private int $now = self::DEFAULT_START, private readonly int $sleepOvershootMs = 0)
    {
        if ($sleepOvershootMs < 0) {
            throw new ValueError('A sleep cannot overshoot by a negative duration.');
        }
    }

    public function now(): int
    {
        return $this->now;
    }

    public function sleep(int $milliseconds): void
    {
        $this->advance($milliseconds);
        $this->advance($this->sleepOvershootMs);
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
