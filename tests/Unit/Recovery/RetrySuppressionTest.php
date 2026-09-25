<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Recovery\RetrySuppression;

/**
 * The public suppression switch on its own; the retry policy under it is covered by SentinelRetryPolicyTest.
 */
final class RetrySuppressionTest extends TestCase
{
    public function test_retries_are_suppressed_only_inside_the_callback(): void
    {
        $this->assertFalse(RetrySuppression::active());

        $inside = RetrySuppression::during(static fn (): bool => RetrySuppression::active());

        $this->assertTrue($inside);
        $this->assertFalse(RetrySuppression::active());
    }

    public function test_the_callback_result_is_returned(): void
    {
        $this->assertSame('PONG', RetrySuppression::during(static fn (): string => 'PONG'));
    }

    public function test_an_exception_does_not_leave_retries_suppressed(): void
    {
        try {
            RetrySuppression::during(static fn () => throw new RuntimeException('check blew up'));
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertFalse(RetrySuppression::active());
    }

    public function test_an_inner_scope_returning_leaves_the_outer_scope_suppressed(): void
    {
        $afterInner = RetrySuppression::during(static function (): bool {
            RetrySuppression::during(static fn () => null);

            return RetrySuppression::active();
        });

        $this->assertTrue($afterInner);
        $this->assertFalse(RetrySuppression::active());
    }
}
