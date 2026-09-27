<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connections;

use Closure;
use ErrorException;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedisException;
use ReflectionClass;
use ReflectionMethod;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\LaravelLostConnectionMessages;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\LosingClient;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;
use Throwable;

/**
 * The contract with Laravel's reconnect logic, by behavior: every public method of the connection, against a client
 * whose first network call fails with each lost-connection message Laravel recognizes.
 *
 * Only the package's loop may call the connector, and always with an explicit `true`, a forced rediscovery; Laravel's
 * own rebuilds call it with no argument, which would reconnect to the cached address. A failure the package does not
 * retry must come back untouched, with no connector call at all. A method Laravel adds fails the table test until it
 * is driven here or skipped for a reason.
 */
final class LaravelReconnectContractTest extends TestCase
{
    /**
     * Public methods not driven, and why.
     *
     * @var array<string, string>
     */
    private const array SKIPPED = [
        '__construct' => 'builds the connection',
        '__callStatic' => 'Macroable plumbing: a macro reaches the client through the methods driven here',
        'flushMacros' => 'Macroable plumbing',
        'hasMacro' => 'Macroable plumbing',
        'macro' => 'Macroable plumbing',
        'macroCall' => 'Macroable plumbing',
        'mixin' => 'Macroable plumbing',
    ];

    /**
     * The methods that talk to the server, each with a call that makes it do so.
     *
     * @return array<string, Closure(PhpRedisSentinelConnection): mixed>
     */
    private static function networkCalls(): array
    {
        return [
            '__call' => static fn (PhpRedisSentinelConnection $c): mixed => $c->__call('incr', ['k']),
            'blpop' => static fn (PhpRedisSentinelConnection $c): mixed => $c->blpop('q', 1),
            'brpop' => static fn (PhpRedisSentinelConnection $c): mixed => $c->brpop('q', 1),
            'command' => static fn (PhpRedisSentinelConnection $c): mixed => $c->command('get', ['k']),
            'eval' => static fn (PhpRedisSentinelConnection $c): mixed => $c->eval('return 1', 0),
            'evalsha' => static fn (PhpRedisSentinelConnection $c): mixed => $c->evalsha('return 1', 0),
            'executeRaw' => static fn (PhpRedisSentinelConnection $c): mixed => $c->executeRaw(['PING']),
            'flushdb' => static fn (PhpRedisSentinelConnection $c): mixed => $c->flushdb(),
            'get' => static fn (PhpRedisSentinelConnection $c): mixed => $c->get('k'),
            'hmget' => static fn (PhpRedisSentinelConnection $c): mixed => $c->hmget('h', 'f'),
            'hmset' => static fn (PhpRedisSentinelConnection $c): mixed => $c->hmset('h', ['f' => 'v']),
            'hscan' => static fn (PhpRedisSentinelConnection $c): mixed => $c->hscan('h', 0),
            'hsetnx' => static fn (PhpRedisSentinelConnection $c): mixed => $c->hsetnx('h', 'f', 'v'),
            'lrem' => static fn (PhpRedisSentinelConnection $c): mixed => $c->lrem('l', 1, 'v'),
            'mget' => static fn (PhpRedisSentinelConnection $c): mixed => $c->mget(['a', 'b']),
            'pipeline' => static fn (PhpRedisSentinelConnection $c): mixed => $c->pipeline(static fn (object $p): mixed => $p->set('k', 'v')),
            'psubscribe' => static fn (PhpRedisSentinelConnection $c): mixed => $c->psubscribe(['c*'], static function (): void {}),
            'scan' => static fn (PhpRedisSentinelConnection $c): mixed => $c->scan(0),
            'set' => static fn (PhpRedisSentinelConnection $c): mixed => $c->set('k', 'v'),
            'setnx' => static fn (PhpRedisSentinelConnection $c): mixed => $c->setnx('k', 'v'),
            'spop' => static fn (PhpRedisSentinelConnection $c): mixed => $c->spop('s'),
            'sscan' => static fn (PhpRedisSentinelConnection $c): mixed => $c->sscan('s', 0),
            'subscribe' => static fn (PhpRedisSentinelConnection $c): mixed => $c->subscribe(['c'], static function (): void {}),
            'transaction' => static fn (PhpRedisSentinelConnection $c): mixed => $c->transaction(static fn (object $t): mixed => $t->set('k', 'v')),
            'withoutSerializationOrCompression' => static fn (PhpRedisSentinelConnection $c): mixed => $c->withoutSerializationOrCompression(static fn (): mixed => $c->get('k')),
            'zadd' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zadd('z', 1, 'm'),
            'zinterstore' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zinterstore('out', ['a', 'b']),
            'zrangebyscore' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zrangebyscore('z', 0, 1),
            'zrevrangebyscore' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zrevrangebyscore('z', 1, 0),
            'zscan' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zscan('z', 0),
            'zunionstore' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zunionstore('out', ['a', 'b']),
        ];
    }

    /**
     * The methods that never talk to the server, each with a call.
     *
     * @return array<string, Closure(PhpRedisSentinelConnection): mixed>
     */
    private static function localCalls(): array
    {
        return [
            'client' => static fn (PhpRedisSentinelConnection $c): mixed => $c->client(),
            'compressed' => static fn (PhpRedisSentinelConnection $c): mixed => $c->compressed(),
            'createSubscription' => static fn (PhpRedisSentinelConnection $c): mixed => $c->createSubscription(['c'], static function (): void {}),
            'disconnect' => static fn (PhpRedisSentinelConnection $c): mixed => $c->disconnect(),
            'funnel' => static fn (PhpRedisSentinelConnection $c): mixed => $c->funnel('n'),
            'getEventDispatcher' => static fn (PhpRedisSentinelConnection $c): mixed => $c->getEventDispatcher(),
            'getName' => static fn (PhpRedisSentinelConnection $c): mixed => $c->getName(),
            'hasHashTag' => static fn (PhpRedisSentinelConnection $c): mixed => $c->hasHashTag('k'),
            'isCluster' => static fn (PhpRedisSentinelConnection $c): mixed => $c->isCluster(),
            'listen' => static fn (PhpRedisSentinelConnection $c): mixed => $c->listen(static function (): void {}),
            'listenForFailures' => static fn (PhpRedisSentinelConnection $c): mixed => $c->listenForFailures(static function (): void {}),
            'lz4Compressed' => static fn (PhpRedisSentinelConnection $c): mixed => $c->lz4Compressed(),
            'lzfCompressed' => static fn (PhpRedisSentinelConnection $c): mixed => $c->lzfCompressed(),
            'pack' => static fn (PhpRedisSentinelConnection $c): mixed => $c->pack(['v']),
            'serialized' => static fn (PhpRedisSentinelConnection $c): mixed => $c->serialized(),
            'setEventDispatcher' => static fn (PhpRedisSentinelConnection $c): mixed => $c->setEventDispatcher(new Dispatcher),
            'setName' => static fn (PhpRedisSentinelConnection $c): mixed => $c->setName('x'),
            'throttle' => static fn (PhpRedisSentinelConnection $c): mixed => $c->throttle('n'),
            'unsetEventDispatcher' => static fn (PhpRedisSentinelConnection $c): mixed => $c->unsetEventDispatcher(),
            'zstdCompressed' => static fn (PhpRedisSentinelConnection $c): mixed => $c->zstdCompressed(),
        ];
    }

    public function test_every_public_method_is_driven_or_skipped_for_a_reason(): void
    {
        $public = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(PhpRedisSentinelConnection::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        $listed = [...array_keys(self::networkCalls()), ...array_keys(self::localCalls()), ...array_keys(self::SKIPPED)];

        sort($public);
        sort($listed);

        $this->assertSame($public, $listed, 'a method was added, removed or renamed: drive it here or skip it for a reason');
    }

    /**
     * @return iterable<string, array{string, ?string, Throwable}>
     */
    public static function lostConnections(): iterable
    {
        foreach (array_keys(self::networkCalls()) as $method) {
            $failAts = in_array($method, ['pipeline', 'transaction'], true) ? [null, 'exec'] : [null];

            foreach ($failAts as $failAt) {
                foreach (LaravelLostConnectionMessages::fragments() as $fragment) {
                    foreach ([RedisException::class, ErrorException::class] as $type) {
                        $where = $failAt === null ? '' : " at {$failAt}";

                        yield "{$method}{$where}, {$type}: {$fragment}" => [$method, $failAt, new $type($fragment)];
                    }
                }
            }
        }
    }

    #[DataProvider('lostConnections')]
    public function test_a_lost_connection_is_recovered_only_by_the_packages_loop(string $method, ?string $failAt, Throwable $failure): void
    {
        $client = new LosingClient($failure, $failAt);
        $calls = [];
        $connection = $this->connection($client, $calls);
        $retryable = $this->policy()->isRetryable($failure);

        // Recorded rather than asserted inside the try, where the catch would take PHPUnit's own failure too.
        $thrown = null;

        try {
            self::networkCalls()[$method]($connection);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        if ($retryable) {
            $this->assertNull($thrown, "the package retries this failure, yet {$method} let it through");
        } else {
            $this->assertSame($failure, $thrown, 'a failure the package does not retry comes back untouched');
        }

        $this->assertTrue($client->failed, 'the failure was reached, so the case tests something');

        foreach ($calls as $arguments) {
            $this->assertNotSame([], $arguments, "the connector was called without arguments: Laravel's rebuild, not the package's loop");
            $this->assertTrue($arguments[0], 'every call from the loop is a forced rediscovery');
        }

        $this->assertCount($retryable ? 1 : 0, $calls, 'one rediscovery for a retried failure, none otherwise');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localMethods(): iterable
    {
        foreach (array_keys(self::localCalls()) as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('localMethods')]
    public function test_a_method_without_network_io_never_reaches_the_server_or_the_connector(string $method): void
    {
        $client = new LosingClient(new RedisException('Connection lost'));
        $calls = [];

        self::localCalls()[$method]($this->connection($client, $calls));

        $this->assertFalse($client->failed, 'no network call was made');
        $this->assertSame([], $calls);
    }

    /**
     * A connection whose connector records the arguments of every call and hands back the same, now healthy, client.
     *
     * @param  list<list<mixed>>  $calls
     */
    private function connection(LosingClient $client, array &$calls): PhpRedisSentinelConnection
    {
        $connector = static function () use ($client, &$calls): LosingClient {
            $calls[] = func_get_args();

            return $client;
        };

        return new PhpRedisSentinelConnection($client, $connector, [], $this->policy(), new RecordingLogger);
    }

    private function policy(): SentinelRetryPolicy
    {
        return new SentinelRetryPolicy(new RecordingLogger, 3, 0, 0, clock: new FakeClock);
    }
}
