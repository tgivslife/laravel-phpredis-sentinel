<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Illuminate\Redis\RedisManager;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\Process\Process;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * A Sentinel connection opened by Laravel through the package, on healthy servers: it finds the master, every kind of
 * operation works on it, and nothing is retried, so no warning is logged.
 */
final class HealthyPathTest extends IntegrationTestCase
{
    private const string SENTINEL_HOSTS = '127.0.0.1:26390,127.0.0.1:26391,127.0.0.1:26392';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;
        ($this->app ?? $this->fail('The application is not booted.'))->instance(LoggerInterface::class, $this->logger);
    }

    public function test_the_connection_opens_on_the_master_the_sentinels_name(): void
    {
        $connection = $this->connection();

        $this->assertSame([Servers::HOST, Servers::MASTER], [$connection->client()->getHost(), $connection->client()->getPort()]);
        $this->assertSame('master', $this->role($connection));
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_commands_run_on_the_master_with_the_connections_database_name_and_prefix(): void
    {
        $connection = $this->connection(['database' => 1, 'name' => 'healthy-path'], ['prefix' => 'app:']);

        $this->assertTrue($connection->set('greeting', 'hello'));
        $this->assertSame('hello', $connection->get('greeting'));
        $this->assertSame(3, $connection->incrby('counter', 3));
        $this->assertSame(1, $connection->del('counter'));
        $this->assertSame('healthy-path', $connection->client()->client('getname'));

        $master = Servers::node(Servers::MASTER);
        $master->select(1);
        $this->assertSame('hello', $master->get('app:greeting'), 'stored on the master, in database 1, prefixed');
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_a_pipeline_and_a_transaction_return_every_reply(): void
    {
        $connection = $this->connection();

        $this->assertSame([true, 2, '2'], $connection->pipeline(function (Redis $pipe): void {
            $pipe->set('piped', '1');
            $pipe->incr('piped');
            $pipe->get('piped');
        }));

        $this->assertSame([true, 2, '2'], $connection->transaction(function (Redis $transaction): void {
            $transaction->set('transacted', '1');
            $transaction->incr('transacted');
            $transaction->get('transacted');
        }));

        $this->assertSame([], $this->logger->warnings());
    }

    public function test_a_scan_returns_every_key(): void
    {
        $connection = $this->connection();
        $written = array_map(fn (int $index): string => "scanned:{$index}", range(1, 250));
        $connection->mset(array_fill_keys($written, '1'));
        $connection->set('elsewhere', '1');

        // Laravel's own loop (RedisStore::tags()): from null, until a page is not an array.
        $found = [];
        $cursor = null;

        do {
            $page = $connection->scan($cursor, ['match' => 'scanned:*', 'count' => 50]);

            if (! is_array($page)) {
                break;
            }

            [$cursor, $keys] = $page;
            array_push($found, ...$keys);
        } while ((int) $cursor !== 0);

        $this->assertEqualsCanonicalizing($written, array_unique($found));
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_an_idle_subscriber_outlives_the_read_timeout_and_gets_it_back(): void
    {
        $connection = $this->connection(['read_timeout' => 1.0]);

        // Publishes 1.5 s after the subscription exists: past the read timeout, which the subscription lifts.
        $publisher = $this->inBackground(<<<'PHP'
            $until = microtime(true) + 10;
            while (($redis->pubsub('numsub', ['news'])['news'] ?? 0) < 1 && microtime(true) < $until) {
                usleep(10_000);
            }
            usleep(1_500_000);
            $redis->publish('news', 'hello');
            PHP);

        $received = [];
        $connection->subscribe('news', function (string $message, string $channel) use ($connection, &$received): void {
            $received[] = [$message, $channel];
            $connection->client()->unsubscribe([$channel]);
        });

        $this->assertSame([['hello', 'news']], $received);
        $this->assertSame(1.0, $connection->client()->getOption(Redis::OPT_READ_TIMEOUT), 'lifted during the subscription only');
        $this->assertTrue($connection->ping());
        $this->assertBackgroundSucceeded($publisher);
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_a_blocking_pop_waits_past_the_read_timeout_for_an_element(): void
    {
        $connection = $this->connection(['read_timeout' => 1.0]);
        $client = $connection->client()->client('id');

        // Pushes 1.5 s after the pop blocks: past the read timeout, within the pop's own.
        $pusher = $this->inBackground(<<<'PHP'
            $until = microtime(true) + 10;
            while ($redis->info('clients')['blocked_clients'] < 1 && microtime(true) < $until) {
                usleep(10_000);
            }
            usleep(1_500_000);
            $redis->rpush('jobs', 'job');
            PHP);

        $started = hrtime(true);
        $popped = $connection->blpop('jobs', 5);
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertSame(['jobs', 'job'], $popped);
        $this->assertGreaterThan(1.5, $elapsed, 'the element came after the read timeout');
        $this->assertSame($client, $connection->client()->client('id'), 'on the same client: nothing was retried');
        $this->assertSame(1.0, $connection->client()->getOption(Redis::OPT_READ_TIMEOUT));
        $this->assertBackgroundSucceeded($pusher);
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_an_empty_blocking_pop_waits_its_whole_timeout_without_a_retry(): void
    {
        $connection = $this->connection(['read_timeout' => 1.0]);
        $client = $connection->client()->client('id');

        $started = hrtime(true);
        $popped = $connection->blpop('nothing', 2);
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($popped);
        $this->assertGreaterThanOrEqual(2.0, $elapsed);
        $this->assertLessThan(3.0, $elapsed, 'within the wait plus the read timeout as headroom');
        $this->assertSame($client, $connection->client()->client('id'));
        $this->assertSame([], $this->logger->warnings());
    }

    /**
     * A Sentinel connection opened by Laravel's manager with the package's provider, listing all three sentinels.
     *
     * Typed as what it is: the manager returns the base Connection, whose `@mixin \Redis` would resolve scan() to
     * phpredis's own signature instead of Laravel's.
     *
     * @param  array<string, mixed>  $settings  Added to the connection's block.
     * @param  array<string, mixed>  $options  The global options.
     */
    private function connection(array $settings = [], array $options = []): PhpRedisSentinelConnection
    {
        $app = $this->app ?? $this->fail('The application is not booted.');

        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => $options,
            'default' => ['sentinel_hosts' => self::SENTINEL_HOSTS, 'sentinel_service' => Servers::SERVICE] + $settings,
        ]);
        $app->forgetInstance('redis');

        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        $connection = $redis->connection('default');
        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $connection);

        return $connection;
    }

    /**
     * The role the connection's server reports for itself.
     */
    private function role(PhpRedisSentinelConnection $connection): mixed
    {
        $role = $connection->command('role');

        return is_array($role) ? $role[0] : null;
    }

    /**
     * Run PHP code in another process, with `$redis` connected to the master.
     */
    private function inBackground(string $code): Process
    {
        $process = new Process([PHP_BINARY, '-r', sprintf(
            '$redis = new Redis; $redis->connect(%s, %d); %s',
            var_export(Servers::HOST, true),
            Servers::MASTER,
            $code,
        )]);
        $process->setTimeout(20)->start();

        return $process;
    }

    private function assertBackgroundSucceeded(Process $process): void
    {
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), trim($process->getErrorOutput().$process->getOutput()));
    }
}
