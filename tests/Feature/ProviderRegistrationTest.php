<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Feature;

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisTopologyConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingConnector;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RedisManagerConnector;
use Tgi\LaravelPhpRedisSentinel\Tests\TestCase;

/**
 * The provider as an application registers it: the connector behind the phpredis client, and the client check.
 */
final class ProviderRegistrationTest extends TestCase
{
    public function test_the_phpredis_client_gets_the_topology_connector_and_no_connection_is_opened(): void
    {
        // 192.0.2.1 is reserved for documentation: nothing answers there, so a connect would hang or fail.
        $redis = $this->resolveRedis(['client' => 'phpredis', 'default' => ['sentinel_hosts' => '192.0.2.1:26379']]);

        $this->assertInstanceOf(PhpRedisTopologyConnector::class, RedisManagerConnector::of($redis));
        $this->assertEmpty($redis->connections(), 'no connection is opened; the manager holds null until the first');
    }

    public function test_an_extend_registered_later_replaces_the_packages(): void
    {
        $redis = $this->resolveRedis(['client' => 'phpredis']);
        $theirs = new RecordingConnector;

        $redis->extend('phpredis', fn (): Connector => $theirs);

        $this->assertSame($theirs, RedisManagerConnector::of($redis));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('anotherClientWithSentinelSettings')]
    public function test_another_client_with_a_sentinel_setting_is_refused_when_redis_is_resolved(array $config, string $message): void
    {
        // Predis would hand the whole block to its client and open a standalone connection to its host and port.
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        $this->resolveRedis($config);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function anotherClientWithSentinelSettings(): array
    {
        $sentinel = ['host' => '127.0.0.1', 'port' => 6379, 'sentinel_hosts' => 's1:26379'];

        return [
            'predis' => [
                ['client' => 'predis', 'default' => ['host' => '127.0.0.1'], 'cache' => $sentinel],
                'connection [cache] has sentinel_hosts, but database.redis.client is predis: set it to phpredis.',
            ],
            'an unknown client, where Laravel fails with a null connector' => [
                ['client' => 'phpredis-sentinel', 'default' => $sentinel],
                'connection [default] has sentinel_hosts, but database.redis.client is phpredis-sentinel: set it to phpredis.',
            ],
            'a null client, which Laravel does not read as phpredis' => [
                ['client' => null, 'default' => ['sentinel_service' => 'mymaster']],
                'connection [default] has sentinel_service, but database.redis.client is null: set it to phpredis.',
            ],
            // The name is known here, unlike on the phpredis path; the client is named where it is part of the fix.
            "predis, in a connection's options" => [
                ['client' => 'predis', 'default' => ['host' => '127.0.0.1', 'options' => ['sentinel_timeout' => 0.5]]],
                'connection [default] has sentinel_timeout in its options, but database.redis.client is predis: set it to phpredis,'
                .' and move the key onto the connection itself.',
            ],
            'predis, in the global options' => [
                ['client' => 'predis', 'options' => ['sentinel_timeout' => 0.5], 'default' => ['host' => '127.0.0.1']],
                'the global Redis options have sentinel_timeout, but database.redis.client is predis: set it to phpredis,'
                .' and move the key onto the Sentinel connection itself.',
            ],
            'predis, in a cluster node' => [
                ['client' => 'predis', 'clusters' => ['default' => [['host' => '10.0.0.1', 'port' => 7000, 'sentinel_hosts' => 's1:26379']]]],
                'Redis Cluster [default] has sentinel_hosts, but a Cluster is never a Sentinel connection.',
            ],
            "predis, in a cluster's own options" => [
                ['client' => 'predis', 'clusters' => ['default' => [['host' => '10.0.0.1', 'port' => 7000], 'options' => ['sentinel_hosts' => 's1:26379']]]],
                'Redis Cluster [default] has sentinel_hosts, but a Cluster is never a Sentinel connection.',
            ],
            'predis, in the clusters options' => [
                ['client' => 'predis', 'clusters' => ['options' => ['sentinel_hosts' => 's1:26379'], 'default' => [['host' => '10.0.0.1', 'port' => 7000]]]],
                'the options shared by the Redis Clusters have sentinel_hosts, but a Cluster is never a Sentinel connection.',
            ],
            'a connection name with a percent sign, taken as it is' => [
                ['client' => 'predis', 'cache%d' => $sentinel],
                'connection [cache%d] has sentinel_hosts, but database.redis.client is predis: set it to phpredis.',
            ],
        ];
    }

    public function test_a_refused_configuration_is_refused_on_every_resolution(): void
    {
        // A worker that catches the first refusal must not get an unchecked manager from the second resolution.
        $app = $this->app ?? $this->fail('The application is not booted.');
        $app['config']->set('database.redis', ['client' => 'predis', 'default' => ['sentinel_hosts' => 's1:26379']]);
        $app->forgetInstance('redis');
        $refusals = 0;

        foreach ([1, 2] as $resolution) {
            try {
                $app->make('redis');
            } catch (SentinelConfigurationException) {
                $refusals++;
            }
        }

        $this->assertSame(2, $refusals);
    }

    public function test_a_phpredis_client_resolved_through_the_container_is_left_alone(): void
    {
        // PHP class names ignore case, so a hook on 'redis' matched by type would also catch phpredis's Redis class.
        $app = $this->app ?? $this->fail('The application is not booted.');

        $this->assertInstanceOf(Redis::class, $app->make(Redis::class));
    }

    public function test_another_client_without_sentinel_settings_is_left_alone(): void
    {
        // Predis's own Sentinel support is configured through its options, with no sentinel_ key.
        $redis = $this->resolveRedis([
            'client' => 'predis',
            'options' => ['replication' => 'sentinel', 'service' => 'mymaster'],
            'default' => ['host' => '127.0.0.1', 'port' => 26379],
        ]);

        $this->assertEmpty($redis->connections());
    }

    public function test_a_missing_client_is_phpredis(): void
    {
        $redis = $this->resolveRedis(['default' => ['sentinel_hosts' => '192.0.2.1:26379']]);

        $this->assertInstanceOf(PhpRedisTopologyConnector::class, RedisManagerConnector::of($redis));
    }

    /**
     * Resolve a new Redis manager built from the given `database.redis` configuration.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveRedis(array $config): RedisManager
    {
        $app = $this->app ?? $this->fail('The application is not booted.');

        $app['config']->set('database.redis', $config);
        $app->forgetInstance('redis');

        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        return $redis;
    }
}
