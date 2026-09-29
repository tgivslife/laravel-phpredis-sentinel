<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Symfony\Component\Process\Process;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * A connection reset in the middle of a write, on Linux: the master's `proto-max-bulk-len`, lowered to 1 MB, makes it
 * reject an 8 MB SET and close the connection while phpredis is still sending. phpredis then raises no exception: it
 * returns `false` with an E_NOTICE, "Send of N bytes failed with errno=104 Connection reset by peer".
 *
 * Only an error handler that turns the notice into an exception, as Laravel's does, lets the package see the failure.
 */
final class MidWriteResetTest extends IntegrationTestCase
{
    /**
     * Large enough that the reset comes mid-send. Measured over 30 runs each: at most about a sixth of an 8 MB SET got
     * through first, but all but 34 KB of a 2 MB one, which would then fail on the read instead.
     */
    private const int VALUE_BYTES = 8 * 1024 * 1024;

    public function test_under_laravels_error_handler_a_write_reset_mid_send_is_retried_and_lands(): void
    {
        $this->withTheBulkLimitLowered(function (): void {
            $connection = $this->sentinelConnection();

            // Lifts the limit once the master has rejected the first attempt, within the retry delay (500 ms).
            // Started after the connection opens, so opening adds nothing to the error count it waits on.
            $lifter = $this->liftTheLimitOnceAWriteIsRejected();

            $this->assertTrue($connection->set('big', str_repeat('x', self::VALUE_BYTES)));

            $this->assertBackgroundSucceeded($lifter);
            $this->assertSame(self::VALUE_BYTES, Servers::node(Servers::MASTER)->strlen('big'));
            $this->assertCount(1, $this->logger->warnings(), implode("\n", $this->logger->warnings()));
            $this->assertStringContainsString('errno=104 Connection reset by peer', $this->logger->warnings()[0]);
        });
    }

    /**
     * The limit the package cannot cover: under an error handler that only records the notice, the write returns
     * `false`, nothing is retried, and the value is lost without an exception.
     */
    public function test_without_laravels_error_handler_the_write_is_lost_without_an_exception(): void
    {
        $this->withTheBulkLimitLowered(function (): void {
            $connection = $this->sentinelConnection();
            $notices = [];

            set_error_handler(function (int $level, string $message) use (&$notices): bool {
                $notices[] = [$level, $message];

                return true;
            });

            try {
                $written = $connection->set('big', str_repeat('x', self::VALUE_BYTES));
            } finally {
                restore_error_handler();
            }

            $this->assertFalse($written);
            $this->assertCount(1, $notices);
            $this->assertSame(E_NOTICE, $notices[0][0]);
            $this->assertStringContainsString('errno=104 Connection reset by peer', $notices[0][1]);
            $this->assertSame([], $this->logger->warnings(), 'nothing retried');
            $this->assertSame(0, Servers::node(Servers::MASTER)->exists('big'), 'the write is lost');
        });
    }

    /**
     * Run the test with the master's bulk limit at 1 MB, and put back the value it had afterwards.
     */
    private function withTheBulkLimitLowered(Closure $test): void
    {
        $master = Servers::node(Servers::MASTER);
        $limit = $master->config('GET', 'proto-max-bulk-len');
        $this->assertIsArray($limit);
        $this->assertTrue($master->config('SET', 'proto-max-bulk-len', '1mb'));

        try {
            $test();
        } finally {
            Servers::node(Servers::MASTER)->config('SET', 'proto-max-bulk-len', (string) $limit['proto-max-bulk-len']);
        }
    }

    /**
     * A process that waits until the master has rejected a write, then lifts the limit.
     *
     * The rejection shows as the master's error replies going up. They count for the server's lifetime, over tests,
     * so the baseline is read here, before the write: the child could start after it and miss the rejection.
     */
    private function liftTheLimitOnceAWriteIsRejected(): Process
    {
        $stats = Servers::node(Servers::MASTER)->info('stats');
        $this->assertIsArray($stats);

        return $this->inBackground(sprintf(<<<'PHP'
            $rejected = fn (): bool => $redis->info('stats')['total_error_replies'] > %d;
            $until = microtime(true) + 10;
            while (! $rejected() && microtime(true) < $until) {
                usleep(10_000);
            }
            $redis->config('SET', 'proto-max-bulk-len', '512mb');
            if (! $rejected()) {
                fwrite(STDERR, 'no write was rejected within 10 s');
                exit(1);
            }
            PHP, (int) $stats['total_error_replies']));
    }
}
