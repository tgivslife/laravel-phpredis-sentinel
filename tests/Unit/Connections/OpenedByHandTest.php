<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connections;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use ReflectionProperty;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\LosingClient;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;
use Throwable;

/**
 * A transaction, pipeline or WATCH opened by hand does not survive the client, so a failure inside one is raised as
 * it came, not retried on a new client outside it; the next operation rebuilds the client, with no warning.
 * transaction() and pipeline() with a callback replay the whole callback instead.
 */
final class OpenedByHandTest extends TestCase
{
    /**
     * phpredis's message for a connection lost with a transaction or a WATCH open on it.
     */
    private const string LOST_WATCH = 'Connection lost and socket is in MULTI/watching mode';

    private RecordingLogger $logger;

    /**
     * The refresh flag of every connector call.
     *
     * @var list<bool>
     */
    private array $refreshes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;
    }

    public function test_a_command_inside_a_transaction_opened_by_hand_is_raised_and_the_next_operation_rebuilds(): void
    {
        $client = new LosingClient($failure = new RedisException('Connection lost'), 'setex', passes: 1);
        $replacement = new LosingClient(null);
        $connection = $this->connection($client, $replacement);

        $connection->multi();
        $connection->setex('one', 60, '1');

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->setex('two', 60, '2')));
        $this->assertSame([], $this->refreshes, 'no rediscovery');
        $this->assertSame([], $this->logger->warnings());
        $this->assertTrue($this->isStale($connection));

        $this->assertTrue($connection->set('next', '1'));
        $this->assertSame([true], $this->refreshes, 'rebuilt on the way in');
        $this->assertSame(['set'], $replacement->sent());
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_a_pipeline_opened_by_hand_whose_exec_fails_is_raised(): void
    {
        $client = new LosingClient($failure = new RedisException('Connection lost'), 'exec');
        $connection = $this->connection($client, new LosingClient(null));

        $connection->pipeline();
        $connection->set('one', '1');

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->exec()));
        $this->assertSame([], $this->refreshes);
        $this->assertSame([], $this->logger->warnings());
        $this->assertTrue($this->isStale($connection));
    }

    /**
     * @return iterable<string, array{Closure(PhpRedisSentinelConnection): mixed, bool}>
     */
    public static function watchStates(): iterable
    {
        yield 'watching' => [static fn (PhpRedisSentinelConnection $c): mixed => $c->watch('k'), false];
        yield 'no WATCH' => [static fn (PhpRedisSentinelConnection $c): mixed => null, true];
        yield 'after UNWATCH' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->unwatch();
        }, true];
        yield 'after a transaction by hand' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->multi();
            $c->set('k', 'v');
            $c->exec();
        }, true];
        yield 'after a transaction discarded by hand' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->multi();
            $c->discard();
        }, true];
        yield 'after disconnect(), whose socket takes the WATCH with it' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->disconnect();
        }, true];
        yield 'after close() on the client itself' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->client()->close();
        }, true];
        yield 'after close() on the client itself and a command, which reconnected' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->client()->close();
            $c->set('k', 'v');
        }, true];
        yield 'after transaction() with a callback' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->transaction(static fn (object $multi): mixed => $multi->set('k', 'v'));
        }, true];
        yield 'after pipeline() with a callback, which sends no EXEC of a transaction' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->pipeline(static fn (object $pipe): mixed => $pipe->set('k', 'v'));
        }, false];
        yield 'after a pipeline by hand' => [static function (PhpRedisSentinelConnection $c): void {
            $c->watch('k');
            $c->pipeline();
            $c->set('k', 'v');
            $c->exec();
        }, false];
        yield 'after a WATCH queued in a pipeline by hand, which the server runs at EXEC' => [static function (PhpRedisSentinelConnection $c): void {
            $c->pipeline();
            $c->watch('k');
            $c->exec();
        }, false];
        yield 'after a WATCH queued in a transaction, which Redis refuses, and the transaction ended' => [static function (PhpRedisSentinelConnection $c): void {
            $c->multi();
            $c->watch('k');
            $c->exec();
        }, true];
    }

    /**
     * @param  Closure(PhpRedisSentinelConnection): mixed  $before
     */
    #[DataProvider('watchStates')]
    public function test_a_command_is_retried_only_when_no_watch_is_in_force($before, bool $retried): void
    {
        $client = new LosingClient($failure = new RedisException('Connection lost'), 'get');
        $connection = $this->connection($client, new LosingClient(null));

        $before($connection);
        $thrown = $this->thrownBy(fn () => $connection->get('k'));

        if ($retried) {
            $this->assertNull($thrown);
            $this->assertSame([true], $this->refreshes);
            $this->assertCount(1, $this->logger->warnings());
        } else {
            $this->assertSame($failure, $thrown);
            $this->assertSame([], $this->refreshes);
            $this->assertSame([], $this->logger->warnings());
            $this->assertTrue($this->isStale($connection));
        }
    }

    public function test_a_watch_given_up_with_its_client_does_not_hold_back_the_next_clients_retries(): void
    {
        $client = new LosingClient(new RedisException('Connection lost'), 'get');
        $replacement = new LosingClient($failure = new RedisException('Connection lost'), 'get');
        $connection = $this->connection($client, $replacement);

        $connection->watch('k');
        $this->thrownBy(fn () => $connection->get('k'));

        // Rebuilt on the way in: the WATCH went with the old client, so the new one's own failure is retried.
        $this->assertNull($this->thrownBy(fn () => $connection->get('k')));
        $this->assertSame([true, true], $this->refreshes);
        $this->assertCount(1, $this->logger->warnings());
        $this->assertStringContainsString($failure->getMessage(), $this->logger->warnings()[0]);
    }

    /**
     * A stale client is rebuilt before the guard looks at it, so what it had open does not count.
     *
     * No path leaves a stale client watching today, since a watching client is given up on and never retried; the
     * rebuilt client forgets a WATCH all the same, as it has none.
     */
    public function test_a_stale_client_is_rebuilt_before_the_guard_looks_at_it(): void
    {
        $client = new LosingClient(null);
        $client->mode = Redis::MULTI;
        $replacement = new LosingClient(new RedisException('Connection lost'), 'get');
        $connection = $this->connection($client, $replacement);
        (new ReflectionProperty($connection, 'clientIsStale'))->setValue($connection, true);
        (new ReflectionProperty($connection, 'watching'))->setValue($connection, true);

        $this->assertSame(true, $connection->get('k'));
        $this->assertSame([true, true], $this->refreshes, 'the rebuild, then the retry');
        $this->assertCount(1, $this->logger->warnings());
    }

    /**
     * phpredis knows of a WATCH sent on the client itself, which the connection does not see, and says so.
     *
     * @return iterable<string, array{Closure(PhpRedisSentinelConnection): mixed, string}>
     */
    public static function lostWatches(): iterable
    {
        yield 'a command' => [static fn (PhpRedisSentinelConnection $c): mixed => $c->get('k'), 'get'];
        yield 'a MULTI opened by hand' => [static fn (PhpRedisSentinelConnection $c): mixed => $c->multi(), 'multi'];
        yield 'transaction() opening its MULTI' => [
            static fn (PhpRedisSentinelConnection $c): mixed => $c->transaction(static fn (object $multi): mixed => $multi->set('k', 'v')),
            'multi',
        ];
        yield 'pipeline() with a callback' => [
            static fn (PhpRedisSentinelConnection $c): mixed => $c->pipeline(static fn (object $pipe): mixed => $pipe->set('k', 'v')),
            'exec',
        ];
    }

    /**
     * @param  Closure(PhpRedisSentinelConnection): mixed  $operation
     */
    #[DataProvider('lostWatches')]
    public function test_a_watch_lost_with_the_connection_is_raised_not_retried($operation, string $failAt): void
    {
        $client = new LosingClient($failure = new RedisException(self::LOST_WATCH), $failAt);
        $connection = $this->connection($client, new LosingClient(null));

        $this->assertSame($failure, $this->thrownBy(fn () => $operation($connection)));
        $this->assertSame([], $this->refreshes);
        $this->assertSame([], $this->logger->warnings());
        $this->assertTrue($this->isStale($connection));
    }

    /**
     * Inside transaction(), every failure after its MULTI opened carries the watching message, WATCH or not; the
     * whole transaction is replayed, whether its callback queues on the client or through the connection.
     */
    public function test_a_transaction_that_fails_after_its_multi_opened_is_replayed(): void
    {
        foreach (['exec' => false, 'set' => true] as $failAt => $throughTheConnection) {
            $this->refreshes = [];
            $logger = $this->logger = new RecordingLogger;
            $client = new LosingClient(new RedisException(self::LOST_WATCH), $failAt);
            $replacement = new LosingClient(null);
            $connection = $this->connection($client, $replacement);
            $runs = 0;

            $connection->transaction(function (object $multi) use ($connection, $throughTheConnection, &$runs): void {
                $runs++;
                $throughTheConnection ? $connection->set('k', 'v') : $multi->set('k', 'v');
            });

            $this->assertSame(2, $runs, "failing at {$failAt}");
            $this->assertSame([true], $this->refreshes);
            $this->assertCount(1, $logger->warnings());
            $this->assertSame(['multi', 'set', 'exec'], $replacement->sent());
        }
    }

    /**
     * @return iterable<string, array{Closure(PhpRedisSentinelConnection): mixed, LosingClient}>
     */
    public static function replays(): iterable
    {
        yield 'transaction()' => [
            static fn (PhpRedisSentinelConnection $c): mixed => $c->transaction(static fn (object $multi): mixed => $multi->set('k', 'v')),
            new LosingClient(new RedisException('Connection lost'), 'exec'),
        ];
        yield 'pipeline()' => [
            static fn (PhpRedisSentinelConnection $c): mixed => $c->pipeline(static fn (object $pipe): mixed => $pipe->set('k', 'v')),
            new LosingClient(new RedisException('Connection lost'), 'exec'),
        ];
        yield "the queue's bulk push, a transaction inside a pipeline, failing at the pipeline's EXEC" => [
            static fn (PhpRedisSentinelConnection $c): mixed => $c->pipeline(
                static fn (): mixed => $c->transaction(static fn (): mixed => $c->eval('return 1', 0)),
            ),
            new LosingClient(new RedisException('Connection lost'), 'exec', passes: 1),
        ];
    }

    /**
     * @param  Closure(PhpRedisSentinelConnection): mixed  $operation
     */
    #[DataProvider('replays')]
    public function test_a_callback_is_still_replayed_after_a_plain_lost_connection($operation, LosingClient $client): void
    {
        $replacement = new LosingClient(null);
        $connection = $this->connection($client, $replacement);

        $this->assertNull($this->thrownBy(fn () => $operation($connection)));
        $this->assertTrue($client->failed);
        $this->assertSame([true], $this->refreshes);
        $this->assertCount(1, $this->logger->warnings());
        $this->assertContains('exec', $replacement->sent());
    }

    public function test_an_error_reply_inside_a_transaction_discards_it_on_the_open_socket(): void
    {
        $client = new LosingClient($failure = new RedisException("READONLY You can't write against a read only replica."), 'setex', errorReply: true);
        $connection = $this->connection($client, new LosingClient(null));

        $connection->multi();

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->setex('k', 60, 'v')));
        $this->assertSame(['multi', 'setex', 'discard'], $client->sent());
        $this->assertSame(Redis::ATOMIC, $client->mode);
        $this->assertTrue($this->isStale($connection));
    }

    /**
     * A WATCH left on an open socket is ended: a persistent socket would carry it into the next connection.
     */
    public function test_an_error_reply_while_watching_ends_the_watch_on_the_open_socket(): void
    {
        $client = new LosingClient($failure = new RedisException("READONLY You can't write against a read only replica."), 'set', errorReply: true);
        $connection = $this->connection($client, new LosingClient(null));

        $connection->watch('k');

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->set('k', 'v')));
        $this->assertSame(['watch', 'set', 'unwatch'], $client->sent());
        $this->assertTrue($this->isStale($connection));
    }

    public function test_a_pipeline_given_up_on_while_watching_is_dropped_and_the_watch_ended(): void
    {
        $client = new LosingClient($failure = new RedisException("READONLY You can't write against a read only replica."), 'set', errorReply: true);
        $connection = $this->connection($client, new LosingClient(null));

        $connection->watch('k');
        $connection->pipeline();

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->set('k', 'v')));
        $this->assertSame(['watch', 'pipeline', 'set', 'discard', 'unwatch'], $client->sent());
        $this->assertSame(Redis::ATOMIC, $client->mode);
        $this->assertTrue($this->isStale($connection));
    }

    public function test_nothing_is_sent_to_a_socket_that_is_gone(): void
    {
        $client = new LosingClient($failure = new RedisException('Connection lost'), 'get');
        $connection = $this->connection($client, new LosingClient(null));

        $connection->watch('k');

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->get('k')));
        $this->assertSame(['watch', 'get'], $client->sent(), 'no UNWATCH');
        $this->assertTrue($this->isStale($connection));
    }

    /**
     * A failure that is not failover-class leaves what was opened alone, for the caller to end.
     */
    public function test_a_failure_that_is_not_failover_class_leaves_the_transaction_open(): void
    {
        $client = new LosingClient($failure = new RedisException('ERR syntax error'), 'setex', errorReply: true);
        $connection = $this->connection($client, new LosingClient(null));

        $connection->multi();

        $this->assertSame($failure, $this->thrownBy(fn () => $connection->setex('k', 60, 'v')));
        $this->assertSame(['multi', 'setex'], $client->sent());
        $this->assertSame(Redis::MULTI, $client->mode);
        $this->assertFalse($this->isStale($connection));
    }

    /**
     * A connection whose connector records its refresh flags and hands back the replacement.
     */
    private function connection(LosingClient $client, LosingClient $replacement): PhpRedisSentinelConnection
    {
        $connector = function (bool $refresh = false) use ($replacement): LosingClient {
            $this->refreshes[] = $refresh;

            return $replacement;
        };

        return new PhpRedisSentinelConnection(
            $client,
            $connector,
            [],
            new SentinelRetryPolicy($this->logger, 3, 0, 0, clock: new FakeClock),
            $this->logger,
        );
    }

    /**
     * What the operation threw, or null.
     *
     * Recorded rather than asserted inside a try, where the catch would take PHPUnit's own failure too.
     */
    private function thrownBy(Closure $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function isStale(PhpRedisSentinelConnection $connection): bool
    {
        return (bool) (new ReflectionProperty($connection, 'clientIsStale'))->getValue($connection);
    }
}
