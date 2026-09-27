<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;

/**
 * A connector that records each call and answers with one connection that opens no socket.
 */
final class RecordingConnector implements Connector
{
    /**
     * Every call, in order: its method name, then its arguments.
     *
     * @var list<list<mixed>>
     */
    public array $calls = [];

    public readonly PhpRedisConnection $connection;

    public function __construct()
    {
        $this->connection = new PhpRedisConnection(new Redis);
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $options
     */
    public function connect(array $config, array $options): Connection
    {
        $this->calls[] = ['connect', $config, $options];

        return $this->connection;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $clusterOptions
     * @param  array<array-key, mixed>  $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): Connection
    {
        $this->calls[] = ['connectToCluster', $config, $clusterOptions, $options];

        return $this->connection;
    }
}
