<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Recovery\SystemClock;

final class SystemClockTest extends TestCase
{
    /**
     * Checks the units (milliseconds in, nanoseconds out), not precision: half the sleep is enough, because on Windows
     * usleep() can return up to about a millisecond early when another process has raised the timer resolution.
     */
    public function test_sleeping_moves_the_clock_forward_in_nanoseconds(): void
    {
        $clock = new SystemClock;
        $before = $clock->now();

        $clock->sleep(20);

        $this->assertGreaterThanOrEqual(10_000_000, $clock->now() - $before);
    }
}
