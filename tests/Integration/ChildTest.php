<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

/**
 * The fork harness the tests of waits without a limit rely on: a child's result comes back, and a child that fails or
 * never ends fails the test with a reason instead of hanging it.
 */
final class ChildTest extends IntegrationTestCase
{
    public function test_the_result_and_the_progress_come_back(): void
    {
        $child = $this->fork(function (Closure $progress): array {
            $progress('started');

            return ['seconds' => 1.0, 'pid' => getmypid()];
        });

        $child->waitFor('started', 5);
        $result = $child->result(5);

        $this->assertIsArray($result);
        $this->assertSame(1.0, $result['seconds']);
        $this->assertNotSame(getmypid(), $result['pid'], 'it ran in another process');
        $this->assertSame(['started'], $child->progress());
    }

    public function test_an_exception_in_the_child_fails_the_test_with_its_message(): void
    {
        $child = $this->fork(function (Closure $progress): never {
            $progress('about to throw');

            throw new RuntimeException('broken');
        });

        $this->assertFailsWith('The child failed: RuntimeException: broken; its last progress: about to throw.', fn () => $child->result(5));
    }

    public function test_an_exception_message_that_is_not_utf8_still_reaches_the_test(): void
    {
        $child = $this->fork(function (): never {
            throw new RuntimeException("value \xff");
        });

        $this->assertFailsWith("The child failed: RuntimeException: value \u{FFFD}; its last progress: none.", fn () => $child->result(5));
    }

    public function test_a_result_that_cannot_be_reported_fails_the_test_with_the_reason(): void
    {
        $child = $this->fork(fn (): float => NAN);

        $this->assertFailsWith('The child failed: JsonException: Inf and NaN cannot be JSON encoded; its last progress: none.', fn () => $child->result(5));
    }

    public function test_a_child_that_never_ends_is_killed_at_the_limit_with_its_last_progress(): void
    {
        $child = $this->fork(function (Closure $progress): never {
            $progress('waiting forever');

            while (true) {
                sleep(60);
            }
        });

        $started = hrtime(true);
        $this->assertFailsWith('The child did not report the result within 0.5 s; its last progress: waiting forever.', fn () => $child->result(0.5));

        $this->assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }

    /**
     * @param  Closure(): mixed  $wait
     */
    private function assertFailsWith(string $message, Closure $wait): void
    {
        try {
            $wait();
        } catch (AssertionFailedError $failure) {
            $this->assertSame($message, $failure->getMessage());

            return;
        }

        $this->fail('The wait did not fail.');
    }
}
