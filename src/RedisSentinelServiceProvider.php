<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisSentinelConnector;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisTopologyConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Support\ConnectionSettings;

/**
 * Puts the topology connector behind Laravel's `phpredis` client, lazily: nothing is read or opened until the Redis
 * manager is first resolved, and connections open only when used.
 *
 * An `extend('phpredis', ...)` registered later, by the application or another package, replaces this one.
 *
 * @api
 */
final class RedisSentinelServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        // An extender, not a resolving callback: it runs before the manager is cached, so a refusal holds on every resolution;
        // it never matches phpredis's own Redis class; and it applies at once to a resolved manager.
        $this->app->extend('redis', static function (RedisManager $redis, Container $app): RedisManager {
            self::configure($redis, $app);

            return $redis;
        });
    }

    /**
     * Refuse Sentinel settings another client would ignore, then register the topology connector.
     *
     * @throws SentinelConfigurationException When another client is configured with a `sentinel_` key.
     */
    private static function configure(RedisManager $redis, Container $app): void
    {
        self::refuseAnotherClient($app->make(Repository::class));

        // The manager binds the creator to itself; it uses only the captured $app, never $this.
        $redis->extend('phpredis', fn (): PhpRedisTopologyConnector => new PhpRedisTopologyConnector(
            new PhpRedisSentinelConnector($app->make(LoggerInterface::class)),
        ));
    }

    /**
     * Refuse a `sentinel_` key anywhere in `database.redis` when the client is not `phpredis`: the package's
     * connector never runs then, and Predis would open a standalone connection to the block's host and port without
     * a word.
     *
     * Unlike the `phpredis` path, which Laravel never tells the connection's name, the messages name the place, and
     * the client wherever changing it is part of the fix, so one attempt is enough to set both right.
     * Read the way Laravel reads it: a missing `client` means `phpredis`, a null one does not.
     *
     * @throws SentinelConfigurationException When a `sentinel_` key is set under another client.
     */
    private static function refuseAnotherClient(Repository $config): void
    {
        $redis = $config->get('database.redis', []);

        if (! is_array($redis)) {
            return;
        }

        $client = array_key_exists('client', $redis) ? $redis['client'] : 'phpredis';

        if ($client === 'phpredis') {
            return;
        }

        $client = is_string($client) ? $client : get_debug_type($client);
        $wrongClient = ", but database.redis.client is {$client}: set it to phpredis";

        foreach ($redis as $name => $entry) {
            if ($name === 'client' || ! is_array($entry)) {
                continue;
            }

            if ($name === 'options') {
                self::refuse('the global Redis options have ', $entry, "{$wrongClient}, and move the key onto the Sentinel connection itself.");
            } elseif ($name === 'clusters') {
                self::refuseSentinelKeysInClusters($entry);
            } else {
                self::refuse("connection [{$name}] has ", $entry, "{$wrongClient}.");

                if (is_array($entry['options'] ?? null)) {
                    self::refuse("connection [{$name}] has ", $entry['options'], " in its options{$wrongClient}, and move the key onto the connection itself.");
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $clusters  The `database.redis.clusters` array: each cluster's nodes and its
     *                                             own options, and the options shared by all.
     *
     * @throws SentinelConfigurationException When a node or an options array carries a `sentinel_` key.
     */
    private static function refuseSentinelKeysInClusters(array $clusters): void
    {
        foreach ($clusters as $name => $cluster) {
            if (! is_array($cluster)) {
                continue;
            }

            // Whatever the client, a Cluster is never a Sentinel connection: changing the client would not help.
            $place = $name === 'options' ? 'the options shared by the Redis Clusters have ' : "Redis Cluster [{$name}] has ";

            foreach ($name === 'options' ? [$cluster] : array_filter($cluster, is_array(...)) as $settings) {
                self::refuse($place, $settings, ', but a Cluster is never a Sentinel connection.');
            }
        }
    }

    /**
     * Refuse the first `sentinel_` key of an array, with the message the key completes: `{before}{key}{after}`.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @throws SentinelConfigurationException When the array holds a key starting with `sentinel_`.
     */
    private static function refuse(string $before, array $settings, string $after): void
    {
        $key = ConnectionSettings::firstSentinelKey($settings);

        if ($key !== null) {
            throw new SentinelConfigurationException($before.$key.$after);
        }
    }
}
