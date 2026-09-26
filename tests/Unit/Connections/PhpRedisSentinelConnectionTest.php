<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\DataProvider;
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
             * Delivers one message, as phpredis calls the handler: ($redis, $channel, $message).
             *
             * @param  array<array-key, string>  $channels
             */
            public function subscribe(array $channels, callable $callback): void
            {
                $this->establish();

                $callback($this, $channels[0] ?? '', 'message');
            }

            /**
             * Delivers one message, as phpredis calls the handler: ($redis, $pattern, $channel, $message).
             *
             * @param  array<array-key, string>  $patterns
             */
            public function psubscribe(array $patterns, callable $callback): void
            {
                $this->establish();

                $callback($this, $patterns[0] ?? '', 'channel', 'message');
            }

            /**
             * Count the subscription, and fail it while failures are left.
             */
            private function establish(): void
            {
                $this->subscribes++;

                if ($this->failures-- > 0) {
                    throw new RedisException('Connection lost');
                }
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

    /**
     * A client that answers any command (the blocking pops, xread) by following a script, one step per call.
     *
     * Each step may move the clock ('ms'), then throws ('throw'), leaves an error for getLastError() ('error'), and
     * returns ('return'). Every call is recorded with its arguments and the read timeout in force.
     *
     * @param  list<array{ms?: int, throw?: string, error?: string, return?: mixed}>  $script
     */
    private function blockingClient(array $script, float $readTimeout = 2.0, ?string $lastError = null): object
    {
        return new class($script, $readTimeout, $lastError, $this->clock)
        {
            /** @var list<array{string, list<mixed>, float}> Every command: its name, arguments and read timeout. */
            public array $calls = [];

            /** @var list<float> Every value OPT_READ_TIMEOUT was set to, in order. */
            public array $readTimeouts = [];

            /**
             * @param  list<array{ms?: int, throw?: string, error?: string, return?: mixed}>  $script
             */
            public function __construct(
                private array $script,
                private float $readTimeout,
                private ?string $lastError,
                private readonly FakeClock $clock,
            ) {}

            /**
             * @param  list<mixed>  $arguments
             */
            public function __call(string $method, array $arguments): mixed
            {
                $this->calls[] = [$method, $arguments, $this->readTimeout];
                $step = array_shift($this->script) ?? [];

                $this->clock->advance($step['ms'] ?? 0);

                if (isset($step['throw'])) {
                    throw new RedisException($step['throw']);
                }

                if (isset($step['error'])) {
                    $this->lastError = $step['error'];
                }

                return $step['return'] ?? [];
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

            public function clearLastError(): bool
            {
                $this->lastError = null;

                return true;
            }

            public function getLastError(): ?string
            {
                return $this->lastError;
            }
        };
    }

    /**
     * A client whose scans follow a script, one step per call: each step throws ('throw'), or moves the cursor to
     * 'next' and returns 'keys'. Every cursor a scan was called with is recorded.
     *
     * @param  list<array{throw?: string, next?: int, keys?: list<string>}>  $script
     */
    private function scanClient(array $script): object
    {
        return new class($script)
        {
            /** @var list<int|string|null> The cursor of every scan call, in order. */
            public array $cursors = [];

            /**
             * @param  list<array{throw?: string, next?: int, keys?: list<string>}>  $script
             */
            public function __construct(private array $script) {}

            /**
             * @return list<string>
             */
            public function scan(int|string|null &$cursor, ?string $pattern = null, int $count = 0): array
            {
                return $this->page($cursor);
            }

            /**
             * @return list<string>
             */
            public function zscan(string $key, int|string|null &$cursor, ?string $pattern = null, int $count = 0): array
            {
                return $this->page($cursor);
            }

            /**
             * @return list<string>
             */
            public function hscan(string $key, int|string|null &$cursor, ?string $pattern = null, int $count = 0): array
            {
                return $this->page($cursor);
            }

            /**
             * @return list<string>
             */
            public function sscan(string $key, int|string|null &$cursor, ?string $pattern = null, int $count = 0): array
            {
                return $this->page($cursor);
            }

            public function getOption(int $option): float
            {
                return $option === Redis::OPT_READ_TIMEOUT ? 2.0 : 0.0;
            }

            public function setOption(int $option, mixed $value): bool
            {
                return true;
            }

            /**
             * @return list<string>
             */
            private function page(int|string|null &$cursor): array
            {
                $this->cursors[] = $cursor;
                $step = array_shift($this->script) ?? [];

                if (isset($step['throw'])) {
                    throw new RedisException($step['throw']);
                }

                $cursor = $step['next'] ?? 0;

                return $step['keys'] ?? [];
            }
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

    /**
     * A message handler that uses the connection can replace its client mid-subscription; the lifted read timeout
     * still goes back on the subscriber it was lifted on, and the replacement keeps its own.
     */
    public function test_a_read_timeout_is_restored_on_the_client_it_was_changed_on_when_the_client_is_replaced(): void
    {
        $subscriber = $this->subscriberClient(failures: 0);
        $replacement = $this->flakyClient(0, 'unused');
        $refreshes = [];
        $connection = $this->connection($subscriber, $replacement, $refreshes);

        // The subscriber's ping fails, so the command's retry rebuilds the connection onto the replacement.
        $connection->subscribe(['events'], static function () use ($connection): void {
            $connection->command('ping');
        });

        $this->assertSame($replacement, $connection->client());
        $this->assertSame([-1.0, 2.0], $subscriber->readTimeouts, 'lifted and restored on the subscriber itself');
        $this->assertSame([], $replacement->readTimeouts, 'the replacement keeps its own read timeout');
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

    /**
     * A healthy wait longer than the read timeout is not a failover: the pop reads under its wait plus the configured
     * read timeout as headroom, and the configured value comes back afterwards.
     */
    public function test_a_blocking_pop_waits_its_timeout_plus_headroom_without_a_rediscovery(): void
    {
        $client = $this->blockingClient([['ms' => 5000, 'return' => ['q', 'job']]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertSame(['q', 'job'], $connection->blpop(['q'], 5));
        $this->assertSame([7.0, 2.0], $client->readTimeouts);
        $this->assertSame([], $refreshes);
    }

    /**
     * @return array<string, array{string, list<mixed>, float, mixed, mixed}>
     */
    public static function blockingPops(): array
    {
        return [
            // method, arguments, read timeout at the call, reply when empty, what the caller gets back
            'blpop' => ['blpop', [['q'], 3], 5.0, [], null],
            'brpop' => ['brpop', [['q'], 3], 5.0, [], null],
            'bzpopmin' => ['bzpopmin', [['z'], 3], 5.0, [], []],
            'bzpopmax' => ['bzpopmax', [['z'], 3], 5.0, [], []],
            'brpoplpush' => ['brpoplpush', ['src', 'dst', 3], 5.0, false, false],
            'blmove' => ['blmove', ['src', 'dst', 'LEFT', 'RIGHT', 3], 5.0, false, false],
            'blmpop' => ['blmpop', [3, ['q'], 'LEFT', 1], 5.0, false, false],
            'bzmpop' => ['bzmpop', [3, ['z'], 'MIN', 1], 5.0, false, false],
            'a float timeout' => ['blpop', [['q'], 0.5], 2.5, [], null],
            'a numeric-string timeout' => ['brpoplpush', ['src', 'dst', '3'], 5.0, false, false],
        ];
    }

    /**
     * Each pop in the table, called the way an application would (Laravel's blpop()/brpop(), __call for the rest):
     * its timeout is found in its own position, and an empty reply comes back as a normal result.
     *
     * @param  list<mixed>  $arguments
     */
    #[DataProvider('blockingPops')]
    public function test_every_blocking_pop_reads_under_its_own_timeout(
        string $method,
        array $arguments,
        float $readTimeout,
        mixed $emptyReply,
        mixed $result,
    ): void {
        $client = $this->blockingClient([['return' => $emptyReply]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertSame($result, $connection->{$method}(...$arguments));
        $this->assertSame([$method, $arguments, $readTimeout], $client->calls[0]);
        $this->assertSame([], $refreshes, 'an empty wait is not a failover');
    }

    /**
     * Laravel lowercases what goes through `__call`, and PHP resolves `bLPop()` to `blpop()` by itself, but
     * `command()` passes a name on as given: `Redis::command('bRPopLPush', …)`, in phpredis' own spelling.
     */
    public function test_a_pop_named_as_phpredis_spells_it_through_command_is_found(): void
    {
        $client = $this->blockingClient([['return' => false]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertFalse($connection->command('bRPopLPush', ['src', 'dst', 3]));
        $this->assertSame(['bRPopLPush', ['src', 'dst', 3], 5.0], $client->calls[0]);
    }

    /**
     * @return array<string, array{string, list<mixed>, array{throw?: string, error?: string, return?: mixed}, mixed}>
     */
    public static function unblockedPops(): array
    {
        $unblocked = 'UNBLOCKED force unblock from blocking operation, instance state changed (master -> replica?)';

        return [
            // method, arguments, the demoted master's answer, the element popped after the rediscovery
            'an empty reply, the error left behind (six pops)' => ['blpop', [['q'], 5], ['error' => $unblocked, 'return' => false], ['q', 'job']],
            'an exception (brpoplpush and blmove)' => ['brpoplpush', ['src', 'dst', 5], ['throw' => $unblocked], 'job'],
        ];
    }

    /**
     * A master demoted mid-wait by a plain REPLICAOF, which unlike Sentinel leaves its clients connected, answers
     * UNBLOCKED in one of two shapes (measured with phpredis 6.3.0 and Redis 7.4): pop() turns the empty reply into
     * an exception, and the policy's fragment makes either one a failover.
     *
     * @param  list<mixed>  $arguments
     * @param  array{throw?: string, error?: string, return?: mixed}  $answer
     */
    #[DataProvider('unblockedPops')]
    public function test_a_pop_unblocked_by_its_master_being_demoted_is_a_failover(
        string $method,
        array $arguments,
        array $answer,
        mixed $element,
    ): void {
        $client = $this->blockingClient([$answer, ['return' => $element]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertSame($element, $connection->{$method}(...$arguments));
        $this->assertSame([true], $refreshes, 'the demotion ended the wait: rediscover and pop again');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staleErrors(): array
    {
        return [
            'an earlier WRONGTYPE' => ['WRONGTYPE Operation against a key holding the wrong kind of value'],
            'an earlier UNBLOCKED' => ['UNBLOCKED force unblock from blocking operation, instance state changed (master -> replica?)'],
        ];
    }

    /**
     * phpredis keeps the last error text after later commands succeed, so only an error the pop itself left counts.
     */
    #[DataProvider('staleErrors')]
    public function test_an_old_error_left_on_the_client_does_not_turn_an_empty_wait_into_a_failover(string $lastError): void
    {
        $client = $this->blockingClient([['return' => []]], lastError: $lastError);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertNull($connection->blpop(['q'], 5));
        $this->assertSame([], $refreshes);
        $this->assertCount(1, $client->calls);
    }

    /**
     * The demotion Sentinel makes: when it reconfigures the old master it kills that master's clients, so a pop
     * waiting there gets a read error (measured: about 11 s after SENTINEL FAILOVER), not UNBLOCKED. The pop is
     * retried after a rediscovery, again with its whole wait plus headroom: the time already waited is not held
     * against it.
     * Each client gets its own read timeout back.
     */
    public function test_a_pop_whose_connection_is_closed_mid_wait_retries_with_its_whole_wait(): void
    {
        $client = $this->blockingClient([['ms' => 4500, 'throw' => 'read error on connection to 10.0.0.9:6380']]);
        $replacement = $this->blockingClient([['return' => ['q', 'job']]]);
        $refreshes = [];
        $connection = $this->connection($client, $replacement, $refreshes, deadlineMs: 5000);

        $this->assertSame(['q', 'job'], $connection->blpop(['q'], 5));
        $this->assertSame([true], $refreshes);
        $this->assertSame([7.0, 2.0], $client->readTimeouts);
        $this->assertSame([7.0, 2.0], $replacement->readTimeouts);
    }

    public function test_a_negative_timeout_takes_the_ordinary_path_and_its_false_comes_back_untouched(): void
    {
        $client = $this->blockingClient([['error' => 'ERR timeout is negative', 'return' => false]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertFalse($connection->bzmpop(-1, ['z'], 'MIN', 1));
        $this->assertSame(2.0, $client->calls[0][2]);
        $this->assertSame([], $refreshes);
    }

    public function test_the_read_timeout_goes_back_after_a_blocking_pop_that_throws(): void
    {
        $client = $this->blockingClient([['throw' => 'WRONGTYPE Operation against a key holding the wrong kind of value']]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        try {
            $connection->blpop(['q'], 5);
            $this->fail('Expected the application error to propagate.');
        } catch (RedisException $exception) {
            $this->assertStringContainsString('WRONGTYPE', $exception->getMessage());
            $this->assertSame([7.0, 2.0], $client->readTimeouts);
        }
    }

    public function test_no_pop_starts_once_the_deadline_is_spent(): void
    {
        // 1 s of wait plus 1 s of budget: the read timeout runs out at 2 s, and only the wait is credited.
        $client = $this->blockingClient([['ms' => 2000, 'throw' => 'read error on connection to 10.0.0.9:6380']]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 1000);

        try {
            $connection->blpop(['q'], 1);
            $this->fail('Expected the spent deadline to end the pop.');
        } catch (SentinelFailoverException) {
            $this->assertSame(2.0, $client->calls[0][2], 'the wait plus the 1 s left, not the whole headroom');
            $this->assertCount(1, $client->calls, 'no second pop on a spent deadline');
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function rebuildsThatSpendTheBudget(): array
    {
        return ['past the budget' => [6000], 'ending exactly at it' => [5000]];
    }

    /**
     * A pop's attempt deadline is moved later by its wait, so a stale rebuild can spend the budget and still leave
     * that deadline unspent. The pop would then read under at most its own wait, and even an empty queue would end
     * in a read error: it is refused like any command whose budget the rebuild spent.
     */
    #[DataProvider('rebuildsThatSpendTheBudget')]
    public function test_a_stale_rebuild_that_spends_the_budget_ends_a_pop_before_it_is_sent(int $rebuildMs): void
    {
        $dead = $this->blockingClient([['throw' => 'Connection refused']]);
        $healthy = $this->blockingClient([['return' => []]]);
        $handOut = $dead;

        $connector = function (bool $refresh = false) use (&$handOut, $rebuildMs): object {
            $this->clock->advance($rebuildMs);

            return $handOut;
        };

        $connection = new PhpRedisSentinelConnection($dead, $connector, [], $this->policy(0, 0, 5000), $this->logger);

        try {
            $connection->command('ping');
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException) {
            // Flagged stale on the way out.
        }

        $handOut = $healthy;

        try {
            $connection->blpop(['q'], 5);
            $this->fail('Expected the spent rebuild to end the pop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertStringContainsString('rebuilding the client', $exception->getMessage());
            $this->assertSame([], $healthy->calls, 'no pop on a budget the rebuild spent');
        }
    }

    public function test_xread_takes_the_ordinary_path(): void
    {
        // Its block is an option in milliseconds, not a timeout the table knows.
        $client = $this->blockingClient([['return' => []]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $connection->xread(['s' => '$'], 1, 5000);

        $this->assertSame(2.0, $client->calls[0][2]);
    }

    public function test_an_unset_read_timeout_is_cut_to_the_deadline_from_what_the_socket_waits(): void
    {
        // A client reporting 0 waits default_socket_timeout, so a longer deadline must not lengthen that wait.
        $client = $this->blockingClient([['return' => false]], readTimeout: 0.0);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 100_000);

        $connection->get('k');

        $this->assertSame((float) ini_get('default_socket_timeout'), $client->calls[0][2]);
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function readTimeoutsThatAreNotPositive(): array
    {
        $default = (float) ini_get('default_socket_timeout');

        return [
            // reported read timeout, the pop's read timeout, what is put back
            // Without one the socket waits default_socket_timeout; 0 set on a live socket fails every read.
            '0 (unset)' => [0.0, 5.0 + $default, $default],
            'negative (no limit)' => [-1.0, 7.0, -1.0],
        ];
    }

    /**
     * The headroom is the read timeout the socket actually has, the same for every pop: `default_socket_timeout` when
     * none was set. Nothing validates the read timeout yet, and with no limit the headroom would end the read before
     * the end of the wait, so it is then 2.0 s. Without a deadline, as here, the pop is the only thing that changes
     * the read timeout, so what it puts back is what the next command gets.
     */
    #[DataProvider('readTimeoutsThatAreNotPositive')]
    public function test_a_read_timeout_that_is_not_positive_gives_the_headroom_the_socket_has(
        float $readTimeout,
        float $popReadTimeout,
        float $restored,
    ): void {
        $client = $this->blockingClient([['return' => ['q', 'job']], ['return' => ['q', 'job']]], readTimeout: $readTimeout);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $connection->blpop(['q'], 5);
        $connection->blpop(['q'], 5);

        $this->assertSame([$popReadTimeout, $restored, $popReadTimeout, $restored], $client->readTimeouts);
    }

    /**
     * A timeout of 0 waits for ever, which a read timeout would cut short and lifting it would leave a hung master
     * unnoticed: the pop waits in slices of the read timeout instead, each a pop of its own, until one is not empty.
     */
    public function test_a_pop_without_a_timeout_waits_in_slices_until_an_element_arrives(): void
    {
        $client = $this->blockingClient([
            ['ms' => 2000, 'return' => []],
            ['ms' => 2000, 'return' => []],
            ['return' => ['q', 'job']],
        ]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertSame(['q', 'job'], $connection->blpop(['q'], 0));
        $this->assertSame(array_fill(0, 3, ['blpop', [['q'], 2.0], 4.0]), $client->calls);
        $this->assertSame([], $refreshes, 'an empty slice is not a failover');
    }

    /**
     * @return array<string, array{string, list<mixed>, list<mixed>, mixed}>
     */
    public static function popsWithoutATimeout(): array
    {
        return [
            // method, arguments, the arguments of each slice, reply when empty
            'blpop' => ['blpop', [['q'], 0], [['q'], 2.0], []],
            'brpop' => ['brpop', [['q'], 0], [['q'], 2.0], []],
            'bzpopmin' => ['bzpopmin', [['z'], 0], [['z'], 2.0], []],
            'bzpopmax' => ['bzpopmax', [['z'], 0], [['z'], 2.0], []],
            'brpoplpush' => ['brpoplpush', ['src', 'dst', 0], ['src', 'dst', 2.0], false],
            'blmove' => ['blmove', ['src', 'dst', 'LEFT', 'RIGHT', 0], ['src', 'dst', 'LEFT', 'RIGHT', 2.0], false],
            'blmpop' => ['blmpop', [0, ['q'], 'LEFT', 1], [2.0, ['q'], 'LEFT', 1], false],
            'bzmpop' => ['bzmpop', [0, ['z'], 'MIN', 1], [2.0, ['z'], 'MIN', 1], false],
            'a float 0' => ['blpop', [['q'], 0.0], [['q'], 2.0], []],
            'a numeric-string 0, where phpredis takes one' => ['brpoplpush', ['src', 'dst', '0'], ['src', 'dst', 2.0], false],
        ];
    }

    /**
     * Each pop in the table gets its slice in its own timeout position, and loops on its own empty reply.
     *
     * @param  list<mixed>  $arguments
     * @param  list<mixed>  $slice
     */
    #[DataProvider('popsWithoutATimeout')]
    public function test_every_blocking_pop_without_a_timeout_waits_in_slices(
        string $method,
        array $arguments,
        array $slice,
        mixed $emptyReply,
    ): void {
        $client = $this->blockingClient([['return' => $emptyReply], ['return' => 'element']]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertSame('element', $connection->{$method}(...$arguments));
        $this->assertSame([[$method, $slice, 4.0], [$method, $slice, 4.0]], $client->calls);
    }

    /**
     * Each slice recovers under a deadline of its own: the time the empty slices before it took is not recovery.
     */
    public function test_a_failover_between_slices_recovers_under_a_fresh_deadline(): void
    {
        $client = $this->blockingClient([
            ['ms' => 2000, 'return' => []],
            ['ms' => 2000, 'return' => []],
            ['ms' => 2000, 'return' => []],
            ['throw' => 'read error on connection to 10.0.0.9:6380'],
            ['return' => ['q', 'job']],
        ]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 1000);

        $this->assertSame(['q', 'job'], $connection->blpop(['q'], 0));
        $this->assertSame([true], $refreshes);
    }

    public function test_the_budget_can_run_out_inside_one_slice(): void
    {
        $client = $this->blockingClient([
            ['return' => []],
            ['throw' => 'read error on connection to 10.0.0.9:6380'],
            ['throw' => 'read error on connection to 10.0.0.9:6380'],
            ['return' => ['q', 'job']],
        ]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 1, deadlineMs: 5000);

        try {
            $connection->blpop(['q'], 0);
            $this->fail('Expected the slice to give up.');
        } catch (SentinelFailoverException $exception) {
            $this->assertStringContainsString('gave up after 1 retry', $exception->getMessage());
            $this->assertCount(3, $client->calls, 'no slice after the one that gave up');
        }
    }

    public function test_an_error_reply_ends_a_pop_without_a_timeout_instead_of_looping(): void
    {
        // phpredis returns an error reply as false, the text in getLastError(): that is not an empty slice.
        $client = $this->blockingClient([
            ['error' => 'WRONGTYPE Operation against a key holding the wrong kind of value', 'return' => false],
            ['return' => ['q', 'job']],
        ]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertFalse($connection->brpoplpush('src', 'dst', 0));
        $this->assertCount(1, $client->calls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stringTimeouts(): array
    {
        return ['0' => ['0'], '5' => ['5']];
    }

    /**
     * phpredis refuses a string timeout in the pops that take it last, at once and without an error to go by, so
     * the call goes to it untouched: a slice would pass a float and turn the refusal into a wait.
     */
    #[DataProvider('stringTimeouts')]
    public function test_a_string_timeout_in_a_pop_that_takes_it_last_is_left_to_phpredis(string $timeout): void
    {
        $client = $this->blockingClient([['return' => false], ['return' => ['q', 'job']]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, deadlineMs: 5000);

        $this->assertNull($connection->blpop(['q'], $timeout));
        $this->assertSame([['blpop', [['q'], $timeout], 2.0]], $client->calls, 'one ordinary call');
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function sliceLengths(): array
    {
        $default = (float) ini_get('default_socket_timeout');

        return [
            // reported read timeout, the slice
            '0 (unset)' => [0.0, $default],
            'negative (no limit)' => [-1.0, 2.0],
        ];
    }

    /**
     * A slice is as long as the headroom: the socket's own read timeout, or 2.0 s when it has no limit.
     */
    #[DataProvider('sliceLengths')]
    public function test_a_read_timeout_that_is_not_positive_gives_the_slice_the_socket_has(float $readTimeout, float $slice): void
    {
        $client = $this->blockingClient([['return' => []], ['return' => ['q', 'job']]], readTimeout: $readTimeout);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $connection->blpop(['q'], 0);

        $this->assertSame([['q'], $slice], $client->calls[0][1]);
        $this->assertSame($slice + $slice, $client->calls[0][2], 'the slice plus the same headroom');
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function scans(): array
    {
        return [
            // method, the arguments before the cursor
            'scan' => ['scan', []],
            'zscan' => ['zscan', ['z']],
            'hscan' => ['hscan', ['h']],
            'sscan' => ['sscan', ['s']],
        ];
    }

    /**
     * @param  list<mixed>  $before
     */
    #[DataProvider('scans')]
    public function test_a_scan_that_fails_at_the_start_cursor_retries_it(string $method, array $before): void
    {
        $client = $this->scanClient([['throw' => 'Connection lost'], ['next' => 5, 'keys' => ['a']]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame([5, ['a']], $connection->{$method}(...[...$before, null]));
        $this->assertSame([null, null], $client->cursors);
        $this->assertSame([true], $refreshes);
        $this->assertCount(1, $this->logger->warnings(), 'the retry only: nothing was restarted');
    }

    /**
     * A cursor is a position in one server's hash table, and each Redis process seeds its hash function at random:
     * the old master's cursor means nothing on the new one, so the retry starts the scan over, and the caller's loop
     * carries on from the new cursor.
     *
     * @param  list<mixed>  $before
     */
    #[DataProvider('scans')]
    public function test_a_scan_that_fails_mid_iteration_restarts_from_the_start_cursor(string $method, array $before): void
    {
        $client = $this->scanClient([['throw' => 'Connection lost'], ['next' => 9, 'keys' => ['b']]]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame([9, ['b']], $connection->{$method}(...[...$before, 17]));
        $this->assertSame([17, null], $client->cursors);
        $this->assertCount(2, $this->logger->warnings());
        $this->assertStringContainsString('restarted', $this->logger->warnings()[1]);
    }

    /**
     * Every retry restarts, even on the old client when rediscovery failed: the server behind it may be a restarted process.
     * The retries are logged each time already, so the restart is logged once per call.
     */
    public function test_a_scan_retried_twice_is_restarted_each_time_and_logged_once(): void
    {
        $client = $this->scanClient([
            ['throw' => 'Connection lost'],
            ['throw' => 'Connection lost'],
            ['next' => 9, 'keys' => ['b']],
        ]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame([9, ['b']], $connection->scan(17));
        $this->assertSame([17, null, null], $client->cursors);

        $restarts = array_filter($this->logger->warnings(), static fn (string $warning): bool => str_contains($warning, 'restarted'));
        $this->assertCount(1, $restarts);
        $this->assertStringNotContainsString('new master', (string) reset($restarts));
    }

    public function test_a_restart_spends_retry_budget_like_any_other_attempt(): void
    {
        $client = $this->scanClient([['throw' => 'Connection lost'], ['throw' => 'Connection lost']]);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 1);

        try {
            $connection->scan(17);
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException $exception) {
            $this->assertStringContainsString('gave up after 1 retry', $exception->getMessage());
            $this->assertSame([17, null], $client->cursors);
        }
    }

    /**
     * @return array<string, array{int|string, int|string|null}>
     */
    public static function cursorsOnARebuiltClient(): array
    {
        return [
            // the caller's cursor, the cursor the rebuilt client gets
            'mid-iteration' => [17, null],
            'finished (0 ends a scan without asking Redis)' => [0, 0],
            'finished, as a string' => ['0', '0'],
        ];
    }

    /**
     * A stale client rebuilt on the way into the scan puts even its first attempt on a new master.
     */
    #[DataProvider('cursorsOnARebuiltClient')]
    public function test_a_scan_on_a_client_rebuilt_on_the_way_in_restarts_an_unfinished_cursor(
        int|string $cursor,
        int|string|null $sent,
    ): void {
        $dead = $this->scanClient([['throw' => 'Connection refused']]);
        $healthy = $this->scanClient([['next' => 0, 'keys' => []]]);
        $handOut = $dead;

        $connector = function (bool $refresh = false) use (&$handOut): object {
            return $handOut;
        };

        $connection = new PhpRedisSentinelConnection($dead, $connector, [], $this->policy(0), $this->logger);

        try {
            $connection->scan(null);
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException) {
            // Flagged stale on the way out.
        }

        $handOut = $healthy;
        $connection->scan($cursor);

        $this->assertSame([$sent], $healthy->cursors);
    }
}
