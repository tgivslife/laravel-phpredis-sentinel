<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use RedisException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;
use Throwable;

/**
 * A transaction, pipeline or WATCH opened by hand does not survive the client, so a failure inside one is raised as it
 * came: a retry would run on a new client, outside it. Nothing is half-applied, and the next operation rebuilds the
 * client, with no warning.
 */
#[Group('failover')]
final class TransactionsOpenedByHandTest extends IntegrationTestCase
{
    /**
     * @param  Application  $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.prefix', 'cache:');
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default']);
    }

    public function test_a_transaction_opened_by_hand_across_a_failover_is_raised_and_nothing_is_written(): void
    {
        $connection = $this->sentinelConnection();
        $connection->multi();
        $connection->setex('one', 60, '1');

        $master = $this->failOverAndWaitForTheDemotion();

        $thrown = $this->thrownBy(fn () => $connection->setex('two', 60, '2'));
        $this->assertInstanceOf(RedisException::class, $thrown);
        $this->assertSame('Connection lost and socket is in MULTI/watching mode', $thrown->getMessage());
        $this->assertSame([false, false], Servers::node($master)->mget(['one', 'two']), 'nothing written');

        $this->assertTrue($connection->set('next', '1'));
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_a_pipeline_opened_by_hand_across_a_failover_is_raised_and_nothing_is_written(): void
    {
        $connection = $this->sentinelConnection();
        $connection->pipeline();
        $connection->set('one', '1');
        $connection->set('two', '2');

        $master = $this->failOverAndWaitForTheDemotion();

        $thrown = $this->thrownBy(fn () => $connection->exec());
        $this->assertInstanceOf(RedisException::class, $thrown);
        $this->assertSame('Connection lost', $thrown->getMessage());
        $this->assertSame([false, false], Servers::node($master)->mget(['one', 'two']), 'nothing written');

        $this->assertTrue($connection->set('next', '1'));
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertSame([], $this->logger->warnings());
    }

    /**
     * An optimistic update: WATCH, read, then MULTI and EXEC. The read fails on a hung master. A retry would read again
     * on a client that watches nothing, so a change made after the read would go undetected, and EXEC overwrite it.
     */
    public function test_a_read_under_watch_on_a_hung_master_is_raised_and_the_update_never_runs(): void
    {
        $connection = $this->sentinelConnection(['read_timeout' => 0.5]);
        $this->assertTrue($connection->set('balance', '10'));
        $this->assertTrue($connection->watch('balance'));

        Servers::pause(Servers::MASTER);

        try {
            $thrown = $this->thrownBy(function () use ($connection): void {
                $balance = (int) $connection->get('balance');
                $connection->multi();
                $connection->set('balance', (string) ($balance - 1));
                $connection->exec();
            });
        } finally {
            Servers::unpause(Servers::MASTER);
        }

        $this->assertInstanceOf(RedisException::class, $thrown);
        $this->assertMatchesRegularExpression('/read error on connection|socket error on read socket/', $thrown->getMessage());
        $this->assertSame([], $this->logger->warnings(), 'not retried');

        $this->assertSame('10', $connection->get('balance'), 'the next read works on the rebuilt client');
        $this->assertSame([], $this->logger->warnings());
    }

    /**
     * A later request in the same long-lived worker opens on the master this process cached, now a replica, where
     * RedisStore's putMany() gets READONLY inside its MULTI: raised, with the transaction discarded on the replica, and
     * the next cache call rebuilds the client on the new master.
     */
    public function test_put_many_on_a_stale_cached_master_is_raised_and_the_transaction_discarded(): void
    {
        // Caches 6390 as the service's master for this process.
        $this->sentinelConnection();
        $master = $this->failOverAndWaitForTheDemotion();

        $connection = $this->sentinelConnection();
        $this->assertSame(Servers::MASTER, $connection->client()->getPort(), 'on the stale cached address');
        $cache = $this->cache();

        $thrown = $this->thrownBy(fn () => $cache->putMany(['one' => 'first', 'two' => 'second'], 60));
        $this->assertInstanceOf(RedisException::class, $thrown);
        $this->assertStringContainsString('READONLY', $thrown->getMessage());
        $this->assertDoesNotMatchRegularExpression('/flags=\S*x/', (string) Servers::node(Servers::MASTER)->rawCommand('CLIENT', 'LIST'), 'no transaction left open');
        $this->assertSame([], $this->logger->warnings());

        $this->assertTrue($cache->put('one', 'first', 60));
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertSame('first', $cache->get('one'));
        $this->assertSame([], $this->logger->warnings());
    }

    private function cache(): Repository
    {
        $cache = ($this->app ?? $this->fail('The application is not booted.'))['cache']->store('redis');
        $this->assertInstanceOf(Repository::class, $cache);

        return $cache;
    }

    /**
     * What the operation threw, or null.
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
}
