<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Recovery\RecoveryDeadline;

final class RecoveryDeadlineTest extends TestCase
{
    public function test_a_timeout_is_cut_down_to_what_is_left_and_an_unbounded_one_becomes_it(): void
    {
        $deadline = RecoveryDeadline::after((int) hrtime(true), 50);

        $this->assertLessThanOrEqual(0.05, $deadline->clamp(2.0));
        $this->assertGreaterThan(0.0, $deadline->clamp(2.0));
        $this->assertLessThanOrEqual(0.05, $deadline->clamp(0.0), 'zero means unbounded in phpredis');
        $this->assertSame(0.01, $deadline->clamp(0.01), 'a timeout already inside the budget is kept');
        $this->assertFalse($deadline->spent());
    }

    public function test_a_spent_deadline_never_hands_out_a_zero_timeout(): void
    {
        $deadline = RecoveryDeadline::after((int) hrtime(true) - 1_000_000, 0);

        $this->assertTrue($deadline->spent());
        $this->assertSame(0, $deadline->remainingMs());
        $this->assertSame(0.001, $deadline->clamp(2.0), 'zero would mean wait forever');
    }
}
