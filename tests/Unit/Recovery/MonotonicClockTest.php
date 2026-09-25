<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Recovery\MonotonicClock;
use Tgi\LaravelPhpRedisSentinel\Recovery\SystemClock;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use ValueError;

/**
 * The contract the system clock and the tests' stand-in both answer to, so a test on the stand-in cannot pass
 * where the system clock would fail.
 */
final class MonotonicClockTest extends TestCase
{
    /**
     * @return array<string, array{MonotonicClock}>
     */
    public static function clocks(): array
    {
        return [
            'system clock' => [new SystemClock],
            'fake clock' => [new FakeClock],
        ];
    }

    #[DataProvider('clocks')]
    public function test_a_negative_sleep_is_refused(MonotonicClock $clock): void
    {
        $this->expectException(ValueError::class);

        $clock->sleep(-5);
    }
}
