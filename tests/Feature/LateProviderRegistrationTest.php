<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Feature;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use Orchestra\Testbench\TestCase;
use Redis;
use ReflectionProperty;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisTopologyConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\RedisSentinelServiceProvider;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RedisManagerConnector;

/**
 * The provider registered after the application booted, as a package registered late, or a test, would be.
 */
final class LateProviderRegistrationTest extends TestCase
{
    public function test_a_manager_resolved_before_the_provider_gets_the_connector_and_keeps_its_connections(): void
    {
        $app = $this->app ?? $this->fail('The application is not booted.');
        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        // A connection the application already holds, standing in for one it opened.
        $opened = new PhpRedisConnection(new Redis);
        (new ReflectionProperty($redis, 'connections'))->setValue($redis, ['cache' => $opened]);

        $this->assertInstanceOf(PhpRedisConnector::class, RedisManagerConnector::of($redis));
        $this->assertNotInstanceOf(PhpRedisTopologyConnector::class, RedisManagerConnector::of($redis));

        $app->register(RedisSentinelServiceProvider::class);

        $this->assertInstanceOf(PhpRedisTopologyConnector::class, RedisManagerConnector::of($redis));
        $this->assertSame(['cache' => $opened], $redis->connections(), 'no connection is purged');
    }

    public function test_registration_reads_no_configuration_until_redis_is_resolved(): void
    {
        // What config:cache relies on: a configuration the check refuses passes registration untouched.
        $app = $this->app ?? $this->fail('The application is not booted.');
        $this->assertFalse($app->resolved('redis'), 'the manager must not be resolved yet for this test to mean anything');

        $app['config']->set('database.redis', ['client' => 'predis', 'default' => ['sentinel_hosts' => 's1:26379']]);

        $app->register(RedisSentinelServiceProvider::class);

        $this->expectExceptionObject(new SentinelConfigurationException(
            'connection [default] has sentinel_hosts, but database.redis.client is predis: set it to phpredis.'
        ));

        $app->make('redis');
    }
}
