<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Recovery\RecoveryDeadline;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use ValueError;

final class RecoveryDeadlineTest extends TestCase
{
    public function test_a_timeout_is_cut_down_to_what_is_left_and_an_unbounded_one_becomes_it(): void
    {
        $clock = new FakeClock;
        $deadline = RecoveryDeadline::after($clock, $clock->now(), 50);

        $this->assertSame(0.05, $deadline->clamp(2.0));
        $this->assertSame(0.05, $deadline->clamp(0.0), 'zero means unbounded in phpredis');
        $this->assertSame(0.01, $deadline->clamp(0.01), 'a timeout already inside the budget is kept');
        $this->assertFalse($deadline->spent());

        $clock->advance(30);

        $this->assertSame(0.02, $deadline->clamp(2.0), 'what is left shrinks as the clock moves');
    }

    public function test_a_spent_deadline_never_hands_out_a_zero_timeout(): void
    {
        $clock = new FakeClock;
        $deadline = RecoveryDeadline::after($clock, $clock->now(), 0);

        $clock->advance(1);

        $this->assertTrue($deadline->spent());
        $this->assertSame(0, $deadline->remainingMs());
        $this->assertSame(0.001, $deadline->clamp(2.0), 'zero would mean wait forever');
    }

    public function test_an_extended_deadline_is_a_new_instant_later_by_the_extension(): void
    {
        $clock = new FakeClock;
        $deadline = RecoveryDeadline::after($clock, $clock->now(), 50);

        $this->assertSame(250, $deadline->extendedBy(200)->remainingMs());
        $this->assertSame(50, $deadline->remainingMs(), 'the original deadline does not move');
        $this->assertSame($deadline, $deadline->extendedBy(0), 'no extension, the same instant');
    }

    public function test_a_deadline_is_never_moved_earlier(): void
    {
        $clock = new FakeClock;

        $this->expectExceptionObject(new ValueError('A recovery deadline cannot be moved earlier.'));

        RecoveryDeadline::after($clock, $clock->now(), 50)->extendedBy(-1);
    }

    public function test_remaining_milliseconds_round_down_so_only_spent_decides_expiry(): void
    {
        $clock = new FakeClock;
        $deadline = new RecoveryDeadline($clock, $clock->now() + 900_000);

        $this->assertSame(0, $deadline->remainingMs());
        $this->assertFalse($deadline->spent());
    }
}
