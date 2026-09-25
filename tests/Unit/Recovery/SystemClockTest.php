<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Recovery\SystemClock;

final class SystemClockTest extends TestCase
{
    /**
     * Only a lower bound: a sleep may overshoot on a busy host, never undershoot.
     */
    public function test_sleeping_moves_the_clock_forward_by_at_least_the_time_slept(): void
    {
        $clock = new SystemClock;
        $before = $clock->now();

        $clock->sleep(1);

        $this->assertGreaterThanOrEqual(1_000_000, $clock->now() - $before);
    }
}
