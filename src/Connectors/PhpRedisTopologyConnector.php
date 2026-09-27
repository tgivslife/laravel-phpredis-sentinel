<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Connectors;

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Support\ConnectionSettings;

/**
 * The `phpredis` client's connector: a connection with `sentinel_hosts` goes to the Sentinel connector, every other
 * connection and every Cluster to Laravel's own, held by composition rather than reached through the manager.
 *
 * `sentinel_hosts` selects Sentinel even when null or empty, so a bad value fails as a configuration error instead of opening a standalone connection.
 * A `sentinel_` key wherever the package does not read it is refused, so a misspelled or differently cased `sentinel_hosts` cannot open one either.
 * The unprefixed `retry_*` keys are left alone: a body shared with a Sentinel connection may carry them.
 *
 * @internal
 */
final class PhpRedisTopologyConnector implements Connector
{
    /**
     * @param  Connector  $sentinel  The connector for connections with `sentinel_hosts`.
     * @param  Connector  $standalone  Laravel's phpredis connector, for every other connection and every Cluster.
     */
    public function __construct(
        private readonly Connector $sentinel,
        private readonly Connector $standalone = new PhpRedisConnector,
    ) {}

    /**
     * Connect to a named connection, through Sentinel when it has `sentinel_hosts`.
     *
     * @param  array<array-key, mixed>  $config  The connection configuration (a `database.redis.*` entry).
     * @param  array<array-key, mixed>  $options  The `database.redis.options` array.
     *
     * @throws SentinelConfigurationException When a connection without `sentinel_hosts` carries a Sentinel setting,
     *                                        or its options or the global ones do.
     */
    public function connect(array $config, array $options): Connection
    {
        if (array_key_exists('sentinel_hosts', $config)) {
            return $this->sentinel->connect($config, $options);
        }

        $key = ConnectionSettings::firstSentinelKey($config);

        if ($key !== null) {
            // Otherwise `SENTINEL_HOSTS is set on a connection without sentinel_hosts` would read as a contradiction.
            $hint = strtolower($key) === 'sentinel_hosts' ? ' (keys are read with the exact case)' : '';

            throw new SentinelConfigurationException(
                "{$key} is set on a connection without sentinel_hosts{$hint}: add sentinel_hosts to make it a Sentinel"
                .' connection, or remove the key.'
            );
        }

        if (is_array($config['options'] ?? null)) {
            ConnectionSettings::refuseSentinelKeysIn("the connection's options", $config['options']);
        }

        ConnectionSettings::refuseSentinelKeysIn('the global Redis options', $options);

        return $this->standalone->connect($config, $options);
    }

    /**
     * Connect to a Redis Cluster, through Laravel's connector: a Cluster is never a Sentinel connection.
     *
     * @param  array<array-key, mixed>  $config  The cluster's nodes, and its own `options` among them.
     * @param  array<array-key, mixed>  $clusterOptions  The `database.redis.clusters.options` array.
     * @param  array<array-key, mixed>  $options  The `database.redis.options` array.
     *
     * @throws SentinelConfigurationException When a Sentinel setting is set anywhere in the configuration.
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): Connection
    {
        foreach ([...array_filter($config, is_array(...)), $clusterOptions] as $settings) {
            $key = ConnectionSettings::firstSentinelKey($settings);

            if ($key !== null) {
                throw new SentinelConfigurationException(
                    "{$key} is set inside a Redis Cluster configuration: a Cluster is never a Sentinel connection."
                );
            }
        }

        ConnectionSettings::refuseSentinelKeysIn('the global Redis options', $options);

        return $this->standalone->connectToCluster($config, $clusterOptions, $options);
    }
}
