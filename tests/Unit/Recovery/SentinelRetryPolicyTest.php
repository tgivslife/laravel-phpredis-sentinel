<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use ErrorException;
use PHPUnit\Framework\TestCase;
use RedisException;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Recovery\RecoveryDeadline;
use Tgi\LaravelPhpRedisSentinel\Recovery\RetrySuppression;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;

/**
 * The failover budget as pure logic - no sockets, no clients.
 *
 * Everything the connector and the connection agree on lives here: what counts as "the master moved", how
 * long the loop may spend deciding, and what comes out the other end when it gives up.
 */
final class SentinelRetryPolicyTest extends TestCase
{
    private FakeClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FakeClock;
        $this->logger = new RecordingLogger;
    }

    private function policy(int $attempts = 3, int $delayMs = 0, int $deadlineMs = 0): SentinelRetryPolicy
    {
        return new SentinelRetryPolicy($this->logger, $attempts, $delayMs, $deadlineMs, clock: $this->clock);
    }

    public function test_it_retries_a_failover_class_failure_until_the_operation_succeeds(): void
    {
        $calls = 0;
        $retries = 0;

        $result = $this->policy()->run(
            function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new RedisException("READONLY You can't write against a read only replica.");
                }

                return 'PONG';
            },
            function () use (&$retries): void {
                $retries++;
            },
            'test',
        );

        $this->assertSame('PONG', $result);
        $this->assertSame(2, $retries, 'rediscovery must run once between each pair of attempts');
        $this->assertCount(2, $this->logger->warnings(), 'every retry logs a warning');
    }

    public function test_it_recognises_every_shape_a_failover_produces(): void
    {
        $policy = $this->policy();

        $messages = [
            'demoted master' => "READONLY You can't write against a read only replica.",
            'dead master' => 'Connection refused',
            'just-promoted replica' => 'LOADING Redis is loading the dataset in memory',
            'replica whose master link is down' => "MASTERDOWN Link with MASTER is down and replica-serve-stale-data is set to 'no'",
            'torn-down socket' => 'Connection reset by peer',
            'half-closed socket' => 'Broken pipe',
            'gone' => 'Redis server went away',
            // In the framework's own list but historically absent from ours; the connection bypasses the
            // vendor's handling, so missing it here would be an outright regression rather than a gap.
            'vendor parity' => 'Error while reading line from the server',
            'matched without regard to case' => 'CONNECTION REFUSED by peer',
        ];

        foreach ($messages as $label => $message) {
            $this->assertTrue($policy->isRetryable(new RedisException($message)), $label);
        }
    }

    public function test_a_send_failure_raised_as_a_php_notice_is_retryable(): void
    {
        // phpredis 6.3 on Linux, a reset mid-write: a notice that Laravel's error handler turns into ErrorException.
        $policy = $this->policy();

        $this->assertTrue($policy->isRetryable(
            new ErrorException('Redis::set(): Send of 52223225 bytes failed with errno=104 Connection reset by peer'),
        ));
        $this->assertFalse($policy->isRetryable(new ErrorException('Undefined array key "host"')));
    }

    public function test_application_errors_are_not_retryable(): void
    {
        $policy = $this->policy();

        foreach ([
            'WRONGTYPE Operation against a key holding the wrong kind of value',
            'OOM command not allowed when used memory > maxmemory.',
            'NOAUTH Authentication required.',
        ] as $message) {
            $this->assertFalse($policy->isRetryable(new RedisException($message)), $message);
        }
    }

    public function test_noreplicas_is_deliberately_not_retryable(): void
    {
        // No good replica lasts a whole resync, longer than any budget: retrying would only delay the failure.
        $this->assertFalse(
            $this->policy()->isRetryable(new RedisException('NOREPLICAS Not enough good replicas to write.')),
        );
    }

    public function test_a_discovery_failure_is_retryable_only_while_sentinels_are_answering(): void
    {
        $policy = $this->policy();

        $this->assertTrue(
            $policy->isRetryable(new SentinelDiscoveryException('no master yet', anySentinelAnswered: true)),
            'sentinels answering but naming no master is an election in flight',
        );

        $this->assertFalse(
            $policy->isRetryable(new SentinelDiscoveryException('fleet unreachable', anySentinelAnswered: false)),
            'an unreachable fleet does not become reachable by waiting',
        );
    }

    public function test_a_spent_budget_is_never_retried_again(): void
    {
        // Guards against two policies nesting: the give-up message quotes the original, retryable, error.
        $this->assertFalse($this->policy()->isRetryable(
            new SentinelFailoverException('gave up after 3 retries: Connection refused'),
        ));
    }

    public function test_the_deadline_ends_the_loop_even_with_attempts_to_spare(): void
    {
        $calls = 0;

        try {
            $this->policy(50, 0, 150)->run(
                function () use (&$calls): void {
                    $calls++;
                    $this->clock->advance(80);

                    throw new RedisException('Connection refused');
                },
                static fn () => null,
                'test',
            );

            $this->fail('Expected the deadline to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertSame(2, $calls, 'the second attempt ends at 160ms, past the 150ms deadline');
            $this->assertStringContainsString('gave up', $exception->getMessage());
        }
    }

    public function test_the_retry_delay_is_waited_on_the_clock(): void
    {
        $startedAt = $this->clock->now();
        $calls = 0;

        $this->policy(3, 100, 0)->run(
            function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new RedisException('Connection refused');
                }

                return 'PONG';
            },
            static fn () => null,
            'test',
        );

        $this->assertSame($startedAt + 200_000_000, $this->clock->now(), 'two retries, one delay before each');
    }

    /**
     * Rediscovery runs on its own clocks, and the deadline used to be checked only before it: a rediscovery that
     * outlived the budget was followed by another attempt anyway.
     * It now ends the loop, with the attempt it was preparing never started.
     */
    public function test_a_rediscovery_that_outlives_the_deadline_ends_the_loop_without_another_attempt(): void
    {
        $operations = 0;

        try {
            $this->policy(50, 0, 50)->run(
                function () use (&$operations): void {
                    $operations++;

                    throw new RedisException('Connection refused');
                },
                fn () => $this->clock->advance(60),
                'test',
            );

            $this->fail('Expected the spent rediscovery to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertSame(1, $operations, 'no attempt may start on a spent budget');
            $this->assertStringContainsString('gave up after 1 retry', $exception->getMessage());
        }
    }

    /**
     * The operation and the rediscovery receive the same absolute deadline, so each phase measures what is left
     * when it starts; an unbounded budget, or a blocking one, hands them null.
     */
    public function test_the_deadline_reaches_the_operation_and_the_rediscovery_as_one_instant(): void
    {
        $seen = [];
        $calls = 0;

        $this->policy(1, 0, 1000)->run(
            function (?RecoveryDeadline $deadline) use (&$seen, &$calls): string {
                $seen[] = $deadline;

                if (++$calls === 1) {
                    throw new RedisException('Connection refused');
                }

                return 'PONG';
            },
            function (?RecoveryDeadline $deadline) use (&$seen): void {
                $seen[] = $deadline;
            },
            'test',
        );

        $this->assertCount(3, $seen);
        $this->assertInstanceOf(RecoveryDeadline::class, $seen[0]);
        $this->assertSame($seen[0], $seen[1]);
        $this->assertSame($seen[0], $seen[2]);
        $this->assertSame(1000, $seen[0]->remainingMs(), 'the clock has not moved since the run began');

        foreach ([$this->policy(1, 0, 0), $this->policy(1, 0, 1000)->forBlockingOperations()] as $unbounded) {
            $unbounded->run(
                function (?RecoveryDeadline $deadline): string {
                    $this->assertNull($deadline);

                    return 'PONG';
                },
                static fn () => null,
                'test',
            );
        }
    }

    public function test_exhaustion_throws_a_redis_exception_carrying_the_original(): void
    {
        $original = new RedisException('Connection lost');

        try {
            $this->policy(2)->run(
                static fn () => throw $original,
                static fn () => null,
                'connection [cache]',
            );

            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException $exception) {
            // A RedisException subclass, so `catch (RedisException)` around Redis work still catches it.
            $this->assertInstanceOf(RedisException::class, $exception);
            $this->assertSame($original, $exception->getPrevious());
            $this->assertStringContainsString('connection [cache]', $exception->getMessage());
            $this->assertStringContainsString('gave up after 2 retries', $exception->getMessage());
        }
    }

    public function test_non_redis_failures_propagate_untouched(): void
    {
        $this->expectExceptionObject(new RuntimeException('not a redis problem'));

        $this->policy()->run(
            static fn () => throw new RuntimeException('not a redis problem'),
            static fn () => null,
            'test',
        );
    }

    public function test_a_retry_setting_that_is_not_a_scalar_is_refused(): void
    {
        $this->expectExceptionObject(new RuntimeException('retry_attempts must be a number, array given.'));

        SentinelRetryPolicy::fromConfig(['retry_attempts' => [3]], $this->logger);
    }

    public function test_a_subscription_older_than_the_deadline_still_gets_its_retries(): void
    {
        // A subscriber's elapsed time is healthy blocking: a deadline on it leaves no retries for its first failover.
        $attempts = 0;

        $result = $this->policy(3, 0, 1000)->forBlockingOperations()->run(
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    $this->clock->advance(1050); // a subscription that outlived the deadline doing its job

                    throw new RedisException('Connection lost');
                }

                return 'resubscribed';
            },
            static fn () => null,
            'connection [broadcast]',
        );

        $this->assertSame('resubscribed', $result);
        $this->assertSame(2, $attempts);
        $this->assertCount(1, $this->logger->warnings(), 'the blocking copy keeps the logger');
    }

    public function test_a_blocking_budget_does_not_accumulate_across_separate_incidents(): void
    {
        // A subscriber's run() lasts the whole process: one retry allowed, yet two separate incidents survived.
        $incidents = 0;

        $result = $this->policy(1, 0, 1000)->forBlockingOperations()->run(
            function () use (&$incidents): string {
                $incidents++;

                if ($incidents > 2) {
                    return 'clean unsubscribe';
                }

                $this->clock->advance(1050); // established, worked, then a failover ended it

                throw new RedisException('Connection lost');
            },
            static fn () => null,
            'connection [broadcast]',
        );

        $this->assertSame('clean unsubscribe', $result);
        $this->assertSame(3, $incidents, 'a re-subscription that lasted proves the previous incident is over');
    }

    public function test_a_subscription_that_never_establishes_still_gives_up(): void
    {
        // The reset is earned by doing work. Failing immediately, over and over, must not loop forever.
        $attempts = 0;

        try {
            $this->policy(2, 0, 1000)->forBlockingOperations()->run(
                function () use (&$attempts): void {
                    $attempts++;

                    throw new RedisException('Connection refused');
                },
                static fn () => null,
                'connection [broadcast]',
            );

            $this->fail('Expected an unestablishable subscription to give up.');
        } catch (SentinelFailoverException) {
            $this->assertSame(3, $attempts, 'the initial attempt plus its two retries, then done');
        }
    }

    public function test_an_ordinary_command_is_not_granted_blocking_semantics(): void
    {
        // A command that runs this long and then fails is what the deadline stops, not work earning a fresh budget.
        $attempts = 0;

        try {
            $this->policy(3, 0, 1000)->run(
                function () use (&$attempts): void {
                    $attempts++;
                    $this->clock->advance(1050);

                    throw new RedisException('Connection lost');
                },
                static fn () => null,
                'connection [cache]',
            );

            $this->fail('Expected the deadline to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertSame(1, $attempts);
            $this->assertStringContainsString('gave up after 0 retries', $exception->getMessage());
        }
    }

    public function test_suppression_makes_the_first_failure_final(): void
    {
        // Process-wide, since connecting is retried before any Connection exists to configure.
        $calls = 0;

        try {
            // Not an arrow function: it copies $calls, so the count stays 0 and the test fails for the wrong reason.
            RetrySuppression::during(function () use (&$calls): void {
                $this->policy()->run(
                    function () use (&$calls): void {
                        $calls++;

                        throw new RedisException('Connection refused');
                    },
                    static fn () => null,
                    'probe',
                );
            });

            $this->fail('Expected the first failure to be final.');
        } catch (SentinelFailoverException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_suppression_does_not_leak_past_the_callback(): void
    {
        // A leaked flag would silently disable failover recovery for the rest of the process.
        try {
            RetrySuppression::during(static fn () => throw new RuntimeException('probe blew up'));
        } catch (RuntimeException) {
            // Expected.
        }

        $calls = 0;

        $this->policy()->run(
            function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new RedisException('Connection refused');
                }

                return 'PONG';
            },
            static fn () => null,
            'connection [cache]',
        );

        $this->assertSame(2, $calls, 'retries must be back on once the probe returns');
    }

    public function test_suppression_nests_without_clobbering_the_outer_scope(): void
    {
        RetrySuppression::during(function (): void {
            RetrySuppression::during(static fn () => null);

            $calls = 0;

            try {
                $this->policy()->run(
                    function () use (&$calls): void {
                        $calls++;

                        throw new RedisException('Connection refused');
                    },
                    static fn () => null,
                    'probe',
                );

                $this->fail('The inner scope returning must not re-enable retries.');
            } catch (SentinelFailoverException) {
                $this->assertSame(1, $calls);
            }
        });
    }
}
