<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Redis;
use RedisSentinel;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The servers of docker/compose.yaml, as the integration tests reach them: from the servers' network namespace.
 *
 * Canonical state: 6390 master, 6391 and 6392 its replicas with their links up, three sentinels that name 6390 and
 * see both replicas and each other, and no data. differences() checks the topology; flush() empties the data.
 *
 * Nothing checks the rest: CONFIG SET on a node, ACL users, loaded scripts (FLUSHALL keeps them) and SENTINEL SET all
 * outlive a test. A test that changes any of them calls reset() when it ends.
 */
final class Servers
{
    public const string HOST = '127.0.0.1';

    public const string SERVICE = 'mymaster';

    public const int MASTER = 6390;

    /** @var list<int> */
    public const array REPLICAS = [6391, 6392];

    /** @var list<int> */
    public const array SENTINELS = [26390, 26391, 26392];

    /**
     * Every service but `network`: recreating it would leave a running test container in a dead namespace.
     */
    private const array SERVICES = ['redis-1', 'redis-2', 'redis-3', 'sentinel-1', 'sentinel-2', 'sentinel-3'];

    private const float TIMEOUT = 0.5;

    /**
     * A client of the sentinel on the given port, with half a second to connect and to read.
     */
    public static function sentinel(int $port): RedisSentinel
    {
        return new RedisSentinel(['host' => self::HOST, 'port' => $port, 'connectTimeout' => self::TIMEOUT, 'readTimeout' => self::TIMEOUT]);
    }

    /**
     * A client connected to the data node on the given port, with half a second to connect and to read.
     */
    public static function node(int $port): Redis
    {
        $node = new Redis;
        $node->connect(self::HOST, $port, self::TIMEOUT, null, 0, self::TIMEOUT);

        return $node;
    }

    /**
     * How the servers' topology differs from the canonical one; empty when it does not. Data is not checked.
     *
     * @return list<string>
     */
    public static function differences(): array
    {
        $differences = [];

        foreach (self::SENTINELS as $port) {
            try {
                $master = self::sentinel($port)->master(self::SERVICE);
            } catch (Throwable $exception) {
                $differences[] = "sentinel {$port}: {$exception->getMessage()}";

                continue;
            }

            $seen = is_array($master)
                ? "{$master['ip']}:{$master['port']} {$master['flags']}, {$master['num-slaves']} replicas, {$master['num-other-sentinels']} other sentinels"
                : 'no master';

            if ($seen !== self::HOST.':'.self::MASTER.' master, 2 replicas, 2 other sentinels') {
                $differences[] = "sentinel {$port}: {$seen}";
            }
        }

        foreach ([self::MASTER, ...self::REPLICAS] as $port) {
            try {
                $node = self::node($port);
                $replication = $node->info('replication');
                $node->close();
            } catch (Throwable $exception) {
                $differences[] = "node {$port}: {$exception->getMessage()}";

                continue;
            }

            $seen = match (true) {
                ! is_array($replication) => 'no replication info',
                $replication['role'] === 'master' => "master of {$replication['connected_slaves']}",
                default => 'replica of '.($replication['master_port'] ?? '?').', link '.($replication['master_link_status'] ?? '?'),
            };

            if ($seen !== ($port === self::MASTER ? 'master of 2' : 'replica of '.self::MASTER.', link up')) {
                $differences[] = "node {$port}: {$seen}";
            }
        }

        return $differences;
    }

    /**
     * Empty the master, and so its replicas: whether both applied it within a second, a test may read a replica.
     */
    public static function flush(): bool
    {
        try {
            $master = self::node(self::MASTER);
            $master->flushAll();
            $acknowledged = $master->wait(count(self::REPLICAS), 1000);
            $master->close();
        } catch (Throwable) {
            return false;
        }

        return $acknowledged === count(self::REPLICAS);
    }

    /**
     * Recreate the six servers, which puts them in the canonical state with no data, and wait until they are healthy.
     *
     * @throws RuntimeException When Docker fails or the servers are not healthy within 60 seconds.
     */
    public static function reset(): void
    {
        // Naming the six is not enough: Compose recreates a dependency whose configuration hash differs, as it can
        // between the Compose that created `network` and the one in the test container. --no-deps leaves it alone.
        $process = new Process([
            'docker', 'compose', '--file', dirname(__DIR__, 2).'/docker/compose.yaml',
            'up', '--detach', '--no-deps', '--force-recreate', '--wait', '--wait-timeout', '60', ...self::SERVICES,
        ]);
        $process->setTimeout(120)->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Resetting the servers failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
