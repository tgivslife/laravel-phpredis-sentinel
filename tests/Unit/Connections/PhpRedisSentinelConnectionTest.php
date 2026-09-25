<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connections;

use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;

/**
 * The connection's retry loop against scripted clients, driven through its public methods.
 */
final class PhpRedisSentinelConnectionTest extends TestCase
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

    /**
     * A connection whose client is scripted and whose connector records its refresh flags.
     *
     * @param  object  $client  The initial, possibly failing, client.
     * @param  object|null  $replacement  What the connector hands back on a rebuild; the initial client when null.
     * @param  list<bool>  $refreshes  Filled with the refresh flag of every connector call.
     */
    private function connection(
        object $client,
        ?object $replacement,
        array &$refreshes,
        int $attempts = 3,
        int $deadlineMs = 0,
        int $delayMs = 0,
    ): PhpRedisSentinelConnection {
        $connector = function (bool $refresh = false) use (&$refreshes, $replacement, $client): object {
            $refreshes[] = $refresh;

            return $replacement ?? $client;
        };

        return new PhpRedisSentinelConnection(
            $client, $connector, [], $this->policy($attempts, $delayMs, $deadlineMs), $this->logger,
        );
    }

    /**
     * A client whose ping() fails a given number of times before succeeding, recording every read-timeout change.
     *
     * @param  RedisException|string  $failure  What each failing ping throws; a string becomes a RedisException.
     * @param  int  $pingMs  How far each ping moves the clock.
     */
    private function flakyClient(int $failures, RedisException|string $failure, int $pingMs = 0): object
    {
        return new class($failures, $failure, $this->clock, $pingMs)
        {
            /** @var list<float> Every value OPT_READ_TIMEOUT was set to, in order. */
            public array $readTimeouts = [];

            public int $pings = 0;

            private float $readTimeout = 2.0;

            public function __construct(
                private int $failures,
                private readonly RedisException|string $failure,
                private readonly FakeClock $clock,
                private readonly int $pingMs,
            ) {}

            public function ping(): string
            {
                $this->pings++;
                $this->clock->advance($this->pingMs);

                if ($this->failures-- > 0) {
                    throw is_string($this->failure) ? new RedisException($this->failure) : $this->failure;
                }

                return 'PONG';
            }

            public function getOption(int $option): float
            {
                return $option === Redis::OPT_READ_TIMEOUT ? $this->readTimeout : 0.0;
            }

            public function setOption(int $option, mixed $value): bool
            {
                if ($option === Redis::OPT_READ_TIMEOUT) {
                    $this->readTimeout = (float) $value;
                    $this->readTimeouts[] = (float) $value;
                }

                return true;
            }
        };
    }

    /**
     * A client that fails the first $failures subscriptions and every ping(), recording every read-timeout change.
     */
    private function subscriberClient(int $failures): object
    {
        return new class($failures)
        {
            /** @var list<float> Every value OPT_READ_TIMEOUT was set to, in order. */
            public array $readTimeouts = [];

            public int $subscribes = 0;

            private float $readTimeout = 2.0;

            public function __construct(private int $failures) {}

            public function ping(): never
            {
                throw new RedisException('Connection lost');
            }

            public function getOption(int $option): float
            {
                return $option === Redis::OPT_READ_TIMEOUT ? $this->readTimeout : 0.0;
            }

            public function setOption(int $option, mixed $value): bool
            {
                if ($option === Redis::OPT_READ_TIMEOUT) {
                    $this->readTimeout = (float) $value;
                    $this->readTimeouts[] = (float) $value;
                }

                return true;
            }

            /**
             * @param  array<array-key, string>  $channels
             */
            public function subscribe(array $channels, callable $callback): void
            {
                $this->subscribes++;

                if ($this->failures-- > 0) {
                    throw new RedisException('Connection lost');
                }
            }

            /**
             * @param  array<array-key, string>  $patterns
             */
            public function psubscribe(array $patterns, callable $callback): void
            {
                $this->subscribe($patterns, $callback);
            }
        };
    }

    /**
     * A client whose pipeline() hands out a pipeline, or fails: while opening it, or at exec().
     */
    private function pipelineClient(string $failAt = ''): object
    {
        return new class($failAt)
        {
            public int $opens = 0;

            public function __construct(private readonly string $failAt) {}

            public function pipeline(): object
            {
                $this->opens++;

                if ($this->failAt === 'open') {
                    throw new RedisException('Redis server went away');
                }

                return new class($this->failAt === 'exec')
                {
                    public function __construct(private readonly bool $fails) {}

                    /**
                     * @return list<int>
                     */
                    public function exec(): array
                    {
                        if ($this->fails) {
                            throw new RedisException('Redis server went away');
                        }

                        return [1, 1];
                    }
                };
            }

            public function discard(): void {}
        };
    }

    public function test_subscriptions_get_blocking_retry_semantics(): void
    {
        // A 1 ms deadline against a 10 ms delay: a command gets no retries, a subscription is not charged for it.
        $client = $this->subscriberClient(failures: 2);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 1, delayMs: 10);

        $connection->subscribe(['events'], static fn () => null);

        $this->assertSame(3, $client->subscribes, 'the deadline must not be charged against a subscription');
        $this->assertSame([true, true], $refreshes);
    }

    public function test_a_command_on_the_same_connection_still_honours_the_deadline(): void
    {
        // The blocking policy is per call: a subscription, retried under it, must not leak it into later commands.
        $client = $this->subscriberClient(failures: 1);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 1, delayMs: 10);

        $connection->subscribe(['events'], static fn () => null);
        $this->assertSame(2, $client->subscribes, 'the subscription was retried under the blocking policy');

        try {
            $connection->command('ping');
            $this->fail('Expected the deadline to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertStringContainsString('gave up after 0 retries', $exception->getMessage());
        }
    }

    public function test_a_subscription_runs_with_the_read_timeout_lifted_and_puts_it_back(): void
    {
        // Under the bounded read timeout an idle subscriber would hit a failover-class read error every few seconds.
        $client = $this->subscriberClient(failures: 0);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $connection->subscribe(['events'], static fn () => null);

        $this->assertSame([-1.0, 2.0], $client->readTimeouts, 'lifted for the subscription, restored after it');
    }

    public function test_the_read_timeout_is_restored_even_when_the_subscription_dies(): void
    {
        // Left unbounded, the next ordinary command against a dying master would hang.
        $client = $this->subscriberClient(failures: PHP_INT_MAX);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 1);

        try {
            $connection->subscribe(['events'], static fn () => null);
            $this->fail('Expected the subscription to give up.');
        } catch (SentinelFailoverException) {
            $this->assertSame([-1.0, 2.0, -1.0, 2.0], $client->readTimeouts);
        }
    }

    public function test_psubscribe_behaves_like_subscribe(): void
    {
        $client = $this->subscriberClient(failures: 1);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 1, delayMs: 10);

        $connection->psubscribe(['events.*'], static fn () => null);

        $this->assertSame(2, $client->subscribes);
        $this->assertSame([-1.0, 2.0, -1.0, 2.0], $client->readTimeouts);
    }

    public function test_retries_after_a_retryable_failure_with_forced_rediscovery(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(1, "READONLY You can't write against a read only replica.");
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame('PONG', $connection->command('ping'));
        $this->assertSame([true], $refreshes, 'the rebuild must force fresh sentinel discovery');
    }

    public function test_the_retry_predicate_is_case_insensitive(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(1, 'CONNECTION REFUSED by peer');
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame('PONG', $connection->command('ping'));
    }

    public function test_discovery_failures_mid_command_spend_retry_budget_instead_of_escaping(): void
    {
        // Sentinels answering but naming no master is an election in flight: spend budget, do not escape.
        $refreshes = [];
        $client = $this->flakyClient(1, new SentinelDiscoveryException(
            'Unable to resolve the Redis master for service [mymaster]',
            anySentinelAnswered: true,
        ));
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame('PONG', $connection->command('ping'));
        $this->assertSame([true], $refreshes, 'a discovery failure must force refreshed rediscovery');
    }

    public function test_non_retryable_errors_propagate_immediately_without_rediscovery(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(1, 'WRONGTYPE Operation against a key holding the wrong kind of value');
        $connection = $this->connection($client, null, $refreshes);

        try {
            $connection->command('ping');
            $this->fail('Expected the application error to propagate untouched.');
        } catch (RedisException $exception) {
            $this->assertStringContainsString('WRONGTYPE', $exception->getMessage());
            $this->assertSame([], $refreshes, 'application errors must never trigger rediscovery');
        }
    }

    public function test_exhaustion_wraps_the_original_error_after_the_configured_retries(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(PHP_INT_MAX, 'Connection lost');
        $connection = $this->connection($client, null, $refreshes, attempts: 2);

        try {
            $connection->command('ping');
            $this->fail('Expected the retry budget to exhaust.');
        } catch (SentinelFailoverException $exception) {
            // A RedisException subclass, so `catch (RedisException)` around Redis work still holds.
            $this->assertInstanceOf(RedisException::class, $exception);
            $this->assertStringContainsString('gave up after 2 retries', $exception->getMessage());
            $this->assertInstanceOf(RedisException::class, $exception->getPrevious());
            $this->assertCount(2, $refreshes);
        }
    }

    public function test_pipelines_retry_like_single_commands(): void
    {
        // pipeline() talks to the client directly, bypassing command(), and must heal the same way.
        $refreshes = [];
        $failing = $this->pipelineClient(failAt: 'open');
        $connection = $this->connection($failing, $this->pipelineClient(), $refreshes);

        $this->assertSame([1, 1], $connection->pipeline(static function (): void {}));
        $this->assertSame([true], $refreshes);
        $this->assertSame(1, $failing->opens, 'the dead client is opened once: no retry outside the budget');
    }

    public function test_a_pipeline_lost_mid_exec_is_rebuilt_only_by_rediscovery(): void
    {
        // Laravel rebuilds from the cached address when exec() loses the connection; only rediscovery may here.
        $refreshes = [];
        $connection = $this->connection($this->pipelineClient(failAt: 'exec'), $this->pipelineClient(), $refreshes);

        $this->assertSame([1, 1], $connection->pipeline(static function (): void {}));
        $this->assertSame([true], $refreshes);
    }

    public function test_an_exhausted_budget_leaves_the_connection_usable_for_the_next_command(): void
    {
        // The connection stays pooled: it is flagged stale on the way out and rebuilt by the next operation.
        $dead = $this->flakyClient(PHP_INT_MAX, 'Connection refused');
        $healthy = $this->flakyClient(0, 'unused');
        $handOut = $dead;
        $refreshes = [];

        $connector = function (bool $refresh = false) use (&$handOut, &$refreshes): object {
            $refreshes[] = $refresh;

            return $handOut;
        };

        $connection = new PhpRedisSentinelConnection($dead, $connector, [], $this->policy(1), $this->logger);

        try {
            $connection->command('ping');
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException) {
            // Expected: nothing is reachable yet.
        }

        // The master comes back. The next command must rebuild before running anything, decided by the flag.
        $handOut = $healthy;
        $refreshes = [];

        $this->assertSame('PONG', $connection->command('ping'));
        $this->assertSame([true], $refreshes, 'the stale client must be rebuilt before the operation runs');
    }

    /**
     * Every attempt, the first included, runs under a read timeout cut to what the deadline has left, and gets the
     * configured one back afterwards.
     */
    public function test_every_attempt_runs_under_a_read_timeout_cut_to_the_remaining_budget(): void
    {
        $client = $this->flakyClient(1, 'Connection lost', pingMs: 20);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 50);

        $this->assertSame('PONG', $connection->command('ping'));
        $this->assertSame([0.05, 2.0, 0.03, 2.0], $client->readTimeouts, 'the retry gets what is left, never more');
    }

    public function test_the_read_timeout_is_restored_after_an_attempt_that_throws(): void
    {
        $client = $this->flakyClient(PHP_INT_MAX, 'Connection lost');
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 1, deadlineMs: 50);

        try {
            $connection->command('ping');
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException) {
            // Every clamp is paired with a restore, the last one included.
            $this->assertSame([0.05, 2.0, 0.05, 2.0], $client->readTimeouts);
        }
    }

    /**
     * A stale client is rebuilt on the way into the next operation, under the deadline. When the rebuild alone
     * spends it, the command must not run on the fresh client: the deadline covers the whole operation.
     */
    public function test_a_stale_rebuild_that_spends_the_deadline_ends_the_operation_before_the_command(): void
    {
        $dead = $this->flakyClient(PHP_INT_MAX, 'Connection refused');
        $healthy = $this->flakyClient(0, 'unused');
        $handOut = $dead;

        $connector = function (bool $refresh = false) use (&$handOut): object {
            $this->clock->advance(40);

            return $handOut;
        };

        $connection = new PhpRedisSentinelConnection($dead, $connector, [], $this->policy(1, 0, 30), $this->logger);

        try {
            $connection->command('ping');
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException) {
            // Flagged stale on the way out.
        }

        $handOut = $healthy;

        try {
            $connection->command('ping');
            $this->fail('Expected the spent rebuild to end the operation.');
        } catch (SentinelFailoverException $exception) {
            $this->assertStringContainsString('rebuilding the client', $exception->getMessage());
            $this->assertSame(0, $healthy->pings, 'the command must not run on a budget the rebuild spent');
        }
    }

    public function test_a_failed_rediscovery_keeps_the_previous_client_instead_of_a_closed_one(): void
    {
        $client = $this->flakyClient(1, 'Connection lost');
        $rebuilds = 0;

        $connector = function (bool $refresh = false) use (&$rebuilds): never {
            $rebuilds++;

            throw new SentinelDiscoveryException('no master yet', anySentinelAnswered: true);
        };

        $connection = new PhpRedisSentinelConnection($client, $connector, [], $this->policy(), $this->logger);

        $this->assertSame('PONG', $connection->command('ping'));
        $this->assertSame(1, $rebuilds);
        $this->assertSame($client, $connection->client(), 'the original client must survive a failed rebuild');
        $this->assertStringContainsString('master rediscovery failed', $this->logger->warnings()[1] ?? '');
    }

    public function test_a_failed_rediscovery_is_survivable_within_the_budget(): void
    {
        $callCount = 0;
        $client = $this->flakyClient(2, 'Connection lost');

        $connector = function (bool $refresh = false) use (&$callCount, $client): object {
            if (++$callCount === 1) {
                // Mid-failover: sentinels reachable but no master elected yet.
                throw new RuntimeException('Unable to resolve the Redis master');
            }

            return $client;
        };

        $connection = new PhpRedisSentinelConnection($client, $connector, [], $this->policy(), $this->logger);

        $this->assertSame('PONG', $connection->command('ping'));
        $this->assertSame(2, $callCount, 'the second attempt must rediscover again after the first failed');
    }
}
