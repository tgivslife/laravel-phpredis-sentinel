<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Feature;

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use ReflectionProperty;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisSentinelConnector;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisTopologyConnector;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingConnector;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RedisManagerConnector;
use Tgi\LaravelPhpRedisSentinel\Tests\TestCase;

/**
 * One application with a Sentinel, a standalone and a Cluster connection, each reaching the connector meant for it.
 *
 * Opening a real connection needs a server, so the routing runs through the real manager, with Laravel's own parsing
 * of each block, into a topology connector whose two sides record their calls; the wiring the provider installs is
 * checked separately.
 */
final class MixedTopologiesTest extends TestCase
{
    private const array SENTINEL = ['sentinel_hosts' => 's1:26379,s2:26379', 'sentinel_service' => 'mymaster', 'database' => '1'];

    public function test_the_provider_puts_the_sentinel_connector_and_laravels_own_behind_phpredis(): void
    {
        $connector = RedisManagerConnector::of($this->redis());

        $this->assertInstanceOf(PhpRedisTopologyConnector::class, $connector);
        $this->assertInstanceOf(PhpRedisSentinelConnector::class, self::side($connector, 'sentinel'));
        $this->assertSame(PhpRedisConnector::class, self::side($connector, 'standalone')::class, "Laravel's own, not a subclass");
    }

    public function test_each_connection_of_one_application_reaches_the_connector_meant_for_it(): void
    {
        $redis = $this->redis();
        $sentinel = new RecordingConnector;
        $standalone = new RecordingConnector;

        // The manager binds the creator to itself, so the stand-ins are captured, not read through $this.
        $redis->extend('phpredis', fn (): Connector => new PhpRedisTopologyConnector($sentinel, $standalone));

        $redis->connection('cache');
        $redis->connection('default');
        $redis->connection('sessions');

        $this->assertCount(1, $sentinel->calls, 'only the Sentinel connection reaches the Sentinel connector');
        [$method, $config, $options] = $sentinel->calls[0];
        $this->assertSame('connect', $method);
        $this->assertSame(self::SENTINEL, $config, 'the block reaches it as configured');
        $this->assertSame('app:', $options['prefix'] ?? null, 'with the global options');

        $this->assertCount(2, $standalone->calls);
        [$method, $config] = $standalone->calls[0];
        $this->assertSame('connect', $method);
        $this->assertSame(['127.0.0.1', 6380], [$config['host'] ?? null, $config['port'] ?? null], 'its url parsed by Laravel');

        [$method, $nodes, $clusterOptions] = $standalone->calls[1];
        $this->assertSame('connectToCluster', $method);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], array_column($nodes, 'host'));
        $this->assertSame(['cluster' => 'redis'], $clusterOptions);
    }

    /**
     * The manager, built from a configuration that holds all three topologies.
     */
    private function redis(): RedisManager
    {
        $app = $this->app ?? $this->fail('The application is not booted.');

        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => 'app:'],
            'default' => ['url' => 'redis://127.0.0.1:6380', 'database' => '0'],
            'cache' => self::SENTINEL,
            'clusters' => [
                'options' => ['cluster' => 'redis'],
                'sessions' => [['host' => '10.0.0.1', 'port' => 7000], ['host' => '10.0.0.2', 'port' => 7000]],
            ],
        ]);
        $app->forgetInstance('redis');

        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        return $redis;
    }

    /**
     * One of the topology connector's two connectors.
     */
    private static function side(mixed $connector, string $property): object
    {
        $side = (new ReflectionProperty(PhpRedisTopologyConnector::class, $property))->getValue($connector);
        self::assertIsObject($side);

        return $side;
    }
}
