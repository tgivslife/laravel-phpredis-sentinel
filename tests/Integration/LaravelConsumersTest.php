<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Redis;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * Laravel's cache, rate limiter, session and queue on a Sentinel connection, across SENTINEL FAILOVER.
 *
 * They reach Redis only through the connection, so each is checked the way it is used: the cache, the rate limiter and
 * the queue held across the failover, as a long-running process holds them; the session by a later request in the same
 * long-lived worker, such as Octane's, which opens a new connection on the master the process cached (under php-fpm
 * the cache lasts one request). A worker's blocking pop runs in a forked child.
 */
final class LaravelConsumersTest extends IntegrationTestCase
{
    /**
     * @param  Application  $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.prefix', 'cache:');
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default']);
        $app['config']->set('session.connection', 'default');
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
        ]);
    }

    /**
     * Two caches, each on a connection of its own, as two processes would hold them: one reads first after the
     * failover, the other writes several entries, through a MULTI that RedisStore opens by hand.
     */
    public function test_the_cache_held_across_a_failover_keeps_its_entries_and_locks(): void
    {
        $reading = $this->request();
        $cache = $this->cache();
        $writing = $this->request();
        $writingCache = $this->cache();

        $this->assertTrue($cache->put('before', 'kept', 60));
        $this->assertSame(1, $cache->increment('counter'));
        $lock = $cache->lock('report', 60);
        $this->assertTrue($lock->get());
        $this->assertTrue($writingCache->has('before'), 'held: open on the old master');
        $this->assertSame(2, $reading->client()->wait(2, 1000), 'on both replicas before the failover');

        $master = $this->failOverAndWaitForTheDemotion();

        $this->assertSame('kept', $cache->get('before'));
        $this->assertTrue($writingCache->putMany(['one' => 'first', 'two' => 'second'], 60));
        $this->assertCount(2, $this->logger->warnings(), 'one for each connection');

        $this->assertSame(['one' => 'first', 'two' => 'second'], $cache->many(['one', 'two']));
        $this->assertTrue($cache->add('added', 'first', 60));
        $this->assertFalse($cache->add('added', 'second', 60));
        $this->assertSame(2, $cache->increment('counter'));
        $this->assertFalse($cache->lock('report', 60)->get(), 'still held');
        $this->assertTrue($lock->release());
        $this->assertTrue($cache->forget('before'));

        $this->assertSame([$master, $master], [$reading->client()->getPort(), $writing->client()->getPort()]);
        $this->assertCount(2, $this->logger->warnings(), implode("\n", $this->logger->warnings()));
    }

    /**
     * The rate limiter keeps its counter unserialized, turning a configured serializer off around its calls: the
     * counter must stay a plain integer across the failover, and the serializer be back on the client afterwards.
     */
    public function test_the_rate_limiter_held_across_a_failover_keeps_counting_with_a_serializer(): void
    {
        $connection = $this->request(['serializer' => Redis::SERIALIZER_PHP]);
        $limiter = new RateLimiter($this->cache());

        $this->assertSame(1, $limiter->hit('login'));
        $this->assertSame(2, $limiter->hit('login'));
        $this->assertSame(2, $connection->client()->wait(2, 1000), 'on both replicas before the failover');

        $master = $this->failOverAndWaitForTheDemotion();

        $this->assertSame('2', $limiter->attempts('login'));
        $this->assertSame(3, $limiter->hit('login'));
        $this->assertSame('3', Servers::node($master)->get('cache:login'), 'a plain integer');
        $this->assertSame(Redis::SERIALIZER_PHP, $connection->client()->getOption(Redis::OPT_SERIALIZER));

        $this->assertSame($master, $connection->client()->getPort());
        $this->assertCount(1, $this->logger->warnings(), implode("\n", $this->logger->warnings()));
    }

    public function test_a_session_saved_before_a_failover_is_read_and_written_by_a_later_request_in_the_same_worker(): void
    {
        $connection = $this->request();
        $session = $this->redisSession();
        $session->put('user', 42);
        $session->save();
        $this->assertSame(2, $connection->client()->wait(2, 1000), 'on both replicas before the failover');

        $master = $this->failOverAndWaitForTheDemotion();

        // The next request opens its connection on the master this process cached, now a replica, with no role check.
        $connection = $this->request();
        $this->assertSame(Servers::MASTER, $connection->client()->getPort(), 'on the stale cached address');
        $next = $this->redisSession($session->getId());
        $this->assertSame(42, $next->get('user'), 'read from the demoted node');
        $next->put('visits', 2);
        $next->save();

        $this->assertSame($master, $connection->client()->getPort());
        $this->assertCount(1, $this->logger->warnings(), implode("\n", $this->logger->warnings()));
        $this->assertStringContainsString('READONLY', $this->logger->warnings()[0]);

        $this->request();
        $last = $this->redisSession($session->getId());
        $this->assertSame([42, 2], [$last->get('user'), $last->get('visits')]);
    }

    /**
     * Two queues, each on a connection of its own: one pops first after the failover, the other pushes in bulk, a
     * transaction inside a pipeline.
     */
    public function test_the_queue_held_across_a_failover_keeps_its_jobs(): void
    {
        $popping = $this->request();
        $queue = $this->queue();
        $pushing = $this->request();
        $bulkQueue = $this->queue();

        $queue->push('Handler@handle', ['job' => 'immediate']);
        $queue->later(1, 'Handler@handle', ['job' => 'delayed']);
        $this->assertSame(2, $bulkQueue->size(), 'held: open on the old master');
        $this->assertSame(2, $popping->client()->wait(2, 1000), 'on both replicas before the failover');

        $master = $this->failOverAndWaitForTheDemotion();

        $bulkQueue->bulk(['Handler@handle', 'Handler@handle'], ['job' => 'bulk'], 'bulk');

        // The delayed job is due by now: the first pop moves it to the queue, behind the other.
        $immediate = $this->pop($queue);
        $this->assertCount(2, $this->logger->warnings(), 'one for each connection');
        $this->assertSame(['immediate', 1], [$immediate->payload()['data']['job'], $immediate->attempts()]);
        $immediate->release();

        $delayed = $this->pop($queue);
        $this->assertSame('delayed', $delayed->payload()['data']['job']);
        $delayed->delete();

        $released = $this->pop($queue);
        $this->assertSame(['immediate', 2], [$released->payload()['data']['job'], $released->attempts()]);
        $released->delete();

        $this->assertSame([0, 0, 0], [$queue->pendingSize(), $queue->delayedSize(), $queue->reservedSize()]);
        $this->assertSame(2, $queue->pendingSize('bulk'), 'pushed once');

        $this->assertSame([$master, $master], [$popping->client()->getPort(), $pushing->client()->getPort()]);
        $this->assertCount(2, $this->logger->warnings(), implode("\n", $this->logger->warnings()));
    }

    /**
     * A worker waiting with `block_for` for a job's notification: its blocking pop moves to the new master, and gets
     * the job pushed there.
     */
    public function test_a_worker_waiting_for_a_job_across_a_failover_gets_it_from_the_new_master(): void
    {
        $child = $this->fork(function (Closure $progress): array {
            $connection = $this->request(settings: ['read_timeout' => 1.0]);
            $this->application()['config']->set('queue.connections.redis.block_for', 30);
            $progress('waiting');
            $job = $this->queue()->pop();

            return [
                'job' => $job?->payload()['data']['job'],
                'port' => $connection->client()->getPort(),
                'warnings' => $this->logger->warnings(),
            ];
        });
        Servers::waitUntil(fn (): bool => Servers::blockedClients(Servers::MASTER) > 0, 5, 'the worker to block on the master');

        $master = Servers::failover();
        Servers::waitUntil(fn (): bool => Servers::blockedClients($master) > 0, self::RECOVERY_SECONDS, 'the worker to block on the new master');
        $this->request();
        $this->queue()->push('Handler@handle', ['job' => 'after']);
        $result = $child->result(5);

        $this->assertSame('after', $result['job']);
        $this->assertSame($master, $result['port']);
        $this->assertCount(1, $result['warnings'], implode("\n", $result['warnings']));
    }

    /**
     * What a new request or process does first: open the Sentinel connection, and forget the cache, session and queue
     * managers, which hold the Redis manager they were built with.
     *
     * @param  array<string, mixed>  $options  The global Redis options.
     * @param  array<string, mixed>  $settings  The connection's block.
     */
    private function request(array $options = [], array $settings = []): PhpRedisSentinelConnection
    {
        $connection = $this->sentinelConnection($settings, $options);

        foreach (['cache', 'cache.store', 'session', 'session.store', 'queue', 'queue.connection'] as $service) {
            $this->application()->forgetInstance($service);
        }

        return $connection;
    }

    private function cache(): Repository
    {
        $cache = $this->application()['cache']->store('redis');
        $this->assertInstanceOf(Repository::class, $cache);

        return $cache;
    }

    /**
     * The redis session driver's session, started: a new one, or the one with the given ID.
     */
    private function redisSession(?string $id = null): Session
    {
        $session = $this->application()['session']->driver('redis');
        $this->assertInstanceOf(Session::class, $session);

        if ($id !== null) {
            $session->setId($id);
        }

        $session->start();

        return $session;
    }

    private function queue(): RedisQueue
    {
        $queue = $this->application()['queue']->connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);

        return $queue;
    }

    private function pop(RedisQueue $queue): RedisJob
    {
        $job = $queue->pop();
        $this->assertInstanceOf(RedisJob::class, $job);

        return $job;
    }

    private function application(): Application
    {
        return $this->app ?? $this->fail('The application is not booted.');
    }
}
