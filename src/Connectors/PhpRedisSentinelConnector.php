<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Connectors;

use Closure;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use RedisSentinel;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Discovery\SentinelClientFactory;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Recovery\MonotonicClock;
use Tgi\LaravelPhpRedisSentinel\Recovery\RecoveryDeadline;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Recovery\SystemClock;

/**
 * Opens a phpredis connection to whichever node the sentinels currently name as master.
 *
 * Discovery asks each configured sentinel in turn, then an ordinary client is built for the master, so auth,
 * database, prefix and client options behave as on a standalone connection. The master address is cached per
 * process, so the connections of one request pay for one sentinel round trip.
 *
 * Opening the connection runs in the retry budget too: php-fpm reconnects on every request, so a request during
 * an election fails while connecting. Only a fleet where no sentinel answers fails at once, naming every host tried.
 *
 * @internal
 */
final class PhpRedisSentinelConnector extends PhpRedisConnector
{
    /**
     * Configuration keys consumed by discovery, removed before a data-node client is built.
     *
     * `url` is normally gone already (RedisManager parses it into host and port first); it is listed so the
     * connector stays correct when driven directly.
     *
     * @var list<string>
     */
    private const array SENTINEL_CONFIG_KEYS = [
        'url',
        'sentinel_hosts',
        'sentinel_service',
        'sentinel_username',
        'sentinel_password',
        'sentinel_timeout',
        'retry_attempts',
        'retry_delay',
        'retry_deadline',
    ];

    /**
     * Settings removed from the config Laravel's createClient() receives, because the connector sends their commands
     * itself: password (AUTH), database (SELECT) and name (CLIENT SETNAME).
     *
     * @var array<string, true>
     */
    private const array SETUP_COMMAND_KEYS = ['password' => true, 'database' => true, 'name' => true];

    /**
     * Per-process master address cache: discovery cache key => [host, port].
     *
     * A stale entry costs one failed attempt before the retry rediscovers; no cross-process cache by design.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $resolvedMasters = [];

    private readonly SentinelClientFactory $sentinelClients;

    /**
     * @var Closure(string, int, array<string, mixed>): RedisSentinel
     */
    private readonly Closure $sentinels;

    /**
     * @var Closure(array<array-key, mixed>): Redis
     */
    private readonly Closure $clients;

    /**
     * Clients come from SentinelClientFactory::make() and Laravel's createClient() unless a test passes its own.
     *
     * @param  LoggerInterface  $logger  Receives the retry and rediscovery warnings.
     * @param  (Closure(string, int, array<string, mixed>): RedisSentinel)|null  $sentinels  One sentinel's client.
     * @param  (Closure(array<array-key, mixed>): Redis)|null  $clients  A data-node client.
     * @param  MonotonicClock  $clock  Measures and paces the retry budget.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?Closure $sentinels = null,
        ?Closure $clients = null,
        private readonly MonotonicClock $clock = new SystemClock,
    ) {
        $this->sentinelClients = new SentinelClientFactory;
        $this->sentinels = $sentinels ?? $this->sentinelClients->make(...);
        $this->clients = $clients ?? $this->createClient(...);
    }

    /**
     * Create a connection to the current master.
     *
     * The connection keeps a client factory that rediscovers when called with `true`; Laravel's own closure pins the
     * address it started with, which after a failover is the old master.
     *
     * @param  array<string, mixed>  $config  The connection configuration (a `database.redis.*` entry).
     * @param  array<string, mixed>  $options  The `database.redis.options` array.
     *
     * @throws RuntimeException When the configuration is unusable.
     */
    public function connect(array $config, array $options): PhpRedisSentinelConnection
    {
        $formattedOptions = $config['options'] ?? [];
        unset($config['options']);

        if (! is_array($formattedOptions)) {
            throw new RuntimeException(sprintf('options must be an array, %s given.', get_debug_type($formattedOptions)));
        }

        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }

        $connector = function (bool $refresh = false, ?RecoveryDeadline $deadline = null) use ($config, $options, $formattedOptions): Redis {
            [$host, $port] = $this->resolveMaster($config, $refresh, $deadline);

            $clientConfig = array_merge(
                self::withoutDiscoveryKeys($config), ['host' => $host, 'port' => $port], $options, $formattedOptions,
            );

            // Setup runs under timeouts cut to the deadline; the configured read timeout returns for later commands.
            $client = ($this->clients)(
                $this->clampClientTimeouts(array_diff_key($clientConfig, self::SETUP_COMMAND_KEYS), $deadline),
            );

            try {
                $this->sendSetupCommands($client, $clientConfig, $deadline, "{$host}:{$port}");

                if ($refresh) {
                    $this->stage($client, $clientConfig, $deadline, "the role check of [{$host}:{$port}]", function () use ($client, $host, $port): void {
                        $this->assertMaster($client, $host, $port);
                    });
                }
            } finally {
                if ($deadline !== null) {
                    $client->setOption(Redis::OPT_READ_TIMEOUT, self::floatSetting($clientConfig, 'read_timeout', 0.0));
                }
            }

            return $client;
        };

        $policy = SentinelRetryPolicy::fromConfig($config, $this->logger, $this->clock);

        // The first attempt may use the cached address; every retry rediscovers, so a stale entry costs one attempt.
        $refresh = false;

        $client = $policy->run(
            function (?RecoveryDeadline $deadline) use ($connector, &$refresh): Redis {
                return $connector($refresh, $deadline);
            },
            function () use (&$refresh): void {
                $refresh = true;
            },
            'connect',
        );

        return new PhpRedisSentinelConnection(
            $client, $connector, self::withoutDiscoveryKeys($config), $policy, $this->logger,
        );
    }

    /**
     * The configuration without the keys only discovery reads.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withoutDiscoveryKeys(array $config): array
    {
        return array_diff_key($config, array_flip(self::SENTINEL_CONFIG_KEYS));
    }

    /**
     * Sentinel manages a master and its replicas, never a Redis Cluster.
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $clusterOptions
     * @param  array<array-key, mixed>  $options
     *
     * @throws RuntimeException Always.
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): never
    {
        throw new RuntimeException(
            'A Sentinel connection cannot be a Redis Cluster: define the cluster under `clusters`, without sentinel_hosts.'
        );
    }

    /**
     * AUTH, SELECT and CLIENT SETNAME, with rules copied from Laravel 13.33's PhpRedisConnector::createClient().
     *
     * Replies are ignored, as in Laravel. The commands follow the local options rather than running among them,
     * which changes nothing: their arguments are never serialized, compressed or prefixed.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelDiscoveryException Retryable: the deadline was spent before a stage started.
     * @throws RuntimeException When a credential or the database is not a scalar.
     */
    private function sendSetupCommands(Redis $client, array $config, ?RecoveryDeadline $deadline, string $node): void
    {
        if (! empty($config['password'])) {
            $credentials = self::credentials($config);

            $this->stage($client, $config, $deadline, "AUTH on [{$node}]", static function () use ($client, $credentials): void {
                $client->auth($credentials);
            });
        }

        if (isset($config['database'])) {
            $database = self::intSetting($config, 'database');

            $this->stage($client, $config, $deadline, "SELECT on [{$node}]", static function () use ($client, $database): void {
                $client->select($database);
            });
        }

        if (! empty($config['name'])) {
            $name = $config['name'];

            $this->stage($client, $config, $deadline, "CLIENT SETNAME on [{$node}]", static function () use ($client, $name): void {
                $client->client('SETNAME', $name);
            });
        }
    }

    /**
     * One setup round trip: refused once the deadline is spent, otherwise its read waits at most what is left.
     *
     * @param  array<array-key, mixed>  $config
     * @param  Closure(): void  $run
     *
     * @throws SentinelDiscoveryException Retryable, so the retry policy decides; on a spent budget it gives up.
     */
    private function stage(Redis $client, array $config, ?RecoveryDeadline $deadline, string $name, Closure $run): void
    {
        if ($deadline !== null) {
            if ($deadline->spent()) {
                throw new SentinelDiscoveryException(
                    "The recovery deadline was spent before {$name}",
                    anySentinelAnswered: true,
                );
            }

            $client->setOption(Redis::OPT_READ_TIMEOUT, $deadline->clamp(self::floatSetting($config, 'read_timeout', 0.0)));
        }

        $run();
    }

    /**
     * The AUTH argument: [username, password], or the password alone; phpredis reads an array one as [user, pass].
     *
     * Scalars are cast to strings exactly as phpredis would convert them, so the bytes sent are unchanged.
     *
     * @param  array<array-key, mixed>  $config
     * @return string|array<array-key, string>
     *
     * @throws RuntimeException When a credential is not a scalar.
     */
    private static function credentials(array $config): string|array
    {
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        if ($username !== null && $username !== '' && is_string($password)) {
            return [self::credential($username, 'username'), $password];
        }

        if (is_array($password)) {
            return array_map(static fn (mixed $part): string => self::credential($part, 'password'), $password);
        }

        return self::credential($password, 'password');
    }

    /**
     * @throws RuntimeException When the value is not a scalar.
     */
    private static function credential(mixed $value, string $key): string
    {
        if (! is_scalar($value)) {
            throw new RuntimeException(sprintf('%s must be a string, %s given.', $key, get_debug_type($value)));
        }

        return (string) $value;
    }

    /**
     * Reject a rediscovered node that is not the master yet.
     *
     * Only on rediscovery, so the happy path costs nothing: sentinels switch before the old master finishes demoting,
     * and a replica answers reads, so the mistake would otherwise stay hidden until the next write.
     *
     * @throws SentinelDiscoveryException Retryable: the promotion has not landed yet.
     */
    private function assertMaster(Redis $client, string $host, int $port): void
    {
        $node = "{$host}:{$port}";

        try {
            $info = $client->info('replication');
        } catch (RedisException $exception) {
            throw new SentinelDiscoveryException(
                "Could not verify the role of the discovered Redis master [{$node}]: {$exception->getMessage()}",
                anySentinelAnswered: true,
            );
        }

        $role = is_array($info) ? ($info['role'] ?? null) : null;

        if ($role !== 'master') {
            throw new SentinelDiscoveryException(
                "The node the sentinels named as master [{$node}] reports role:".(is_string($role) ? $role : 'unknown')
                .' - the promotion has not landed yet.',
                anySentinelAnswered: true,
            );
        }
    }

    /**
     * Ask the sentinels for the master address, trying each configured host in order.
     *
     * Unreachable and unaware sentinels are skipped; when none names a master, the exception names every host tried
     * and records whether any answered, which separates an election from an outage.
     * Under a deadline each probe waits at most what is left, and once nothing is left the rest are named as not tried.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: int}
     *
     * @throws SentinelDiscoveryException When no sentinel names a usable master.
     * @throws RuntimeException When no sentinel host is configured, or a setting is malformed.
     */
    private function resolveMaster(array $config, bool $refresh, ?RecoveryDeadline $deadline): array
    {
        $service = self::stringSetting($config, 'sentinel_service', 'mymaster');
        $hosts = $this->sentinelClients->parseHosts(self::hostList($config));
        $cacheKey = $service.'|'.implode(',', array_map(static fn (array $host): string => "{$host[0]}:{$host[1]}", $hosts));

        if (! $refresh && isset(self::$resolvedMasters[$cacheKey])) {
            return self::$resolvedMasters[$cacheKey];
        }

        if ($hosts === []) {
            throw new RuntimeException('No Redis sentinel hosts configured: set sentinel_hosts.');
        }

        $failures = [];
        $answered = false;

        foreach ($hosts as [$host, $port]) {
            if ($deadline?->spent()) {
                $failures[] = "{$host}:{$port} (not tried: the recovery deadline was spent)";

                continue;
            }

            try {
                $address = ($this->sentinels)($host, $port, $this->clampSentinelTimeout($config, $deadline))
                    ->getMasterAddrByName($service);
            } catch (RedisException $exception) {
                $failures[] = "{$host}:{$port} ({$exception->getMessage()})";

                continue;
            }

            $answered = true;

            // A late answer may name a good master, but a client built from it could only overrun the deadline.
            if ($deadline?->spent()) {
                $failures[] = "{$host}:{$port} (answered after the recovery deadline was spent)";

                continue;
            }

            $master = self::usableAddress($address);

            if ($master === null) {
                $failures[] = "{$host}:{$port} (no usable master known for service [{$service}])";

                continue;
            }

            return self::$resolvedMasters[$cacheKey] = $master;
        }

        throw new SentinelDiscoveryException(sprintf(
            'Unable to resolve the Redis master for service [%s] from any configured sentinel: %s',
            $service,
            implode('; ', $failures),
        ), anySentinelAnswered: $answered);
    }

    /**
     * A sentinel's answer as [host, port], or null when it names no usable master.
     *
     * An empty host or an illegal port would only fail later, inside the client, so it counts as no master known.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function usableAddress(mixed $address): ?array
    {
        if (! is_array($address) || ! isset($address[0], $address[1])
            || ! is_scalar($address[0]) || ! is_numeric($address[1])) {
            return null;
        }

        $host = (string) $address[0];
        $port = (int) $address[1];

        return $host === '' || $port < 1 || $port > 65535 ? null : [$host, $port];
    }

    /**
     * The sentinel probe timeout cut to what the deadline has left.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function clampSentinelTimeout(array $config, ?RecoveryDeadline $deadline): array
    {
        if ($deadline !== null) {
            $config['sentinel_timeout'] = $deadline->clamp(self::floatSetting($config, 'sentinel_timeout', 0.5));
        }

        return $config;
    }

    /**
     * The data node's connect and read timeouts cut to what the deadline has left.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    private function clampClientTimeouts(array $config, ?RecoveryDeadline $deadline): array
    {
        if ($deadline !== null) {
            $config['timeout'] = $deadline->clamp(self::floatSetting($config, 'timeout', 0.0));
            $config['read_timeout'] = $deadline->clamp(self::floatSetting($config, 'read_timeout', 0.0));
        }

        return $config;
    }

    /**
     * The sentinel host list, as a string or a list of scalars.
     *
     * @param  array<string, mixed>  $config
     * @return string|array<array-key, scalar|null>
     *
     * @throws RuntimeException When the setting is neither.
     */
    private static function hostList(array $config): string|array
    {
        $hosts = $config['sentinel_hosts'] ?? '';

        if (is_string($hosts)) {
            return $hosts;
        }

        if (! is_array($hosts)) {
            throw new RuntimeException(sprintf('sentinel_hosts must be a string or a list, %s given.', get_debug_type($hosts)));
        }

        $list = [];

        foreach ($hosts as $key => $host) {
            if (! is_scalar($host) && $host !== null) {
                throw new RuntimeException(sprintf('sentinel_hosts entries must be strings, %s given.', get_debug_type($host)));
            }

            $list[$key] = $host;
        }

        return $list;
    }

    /**
     * A string setting, cast as before; anything that is not a scalar is refused rather than cast.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException When the setting is not a scalar.
     */
    private static function stringSetting(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        if (! is_scalar($value)) {
            throw new RuntimeException(sprintf('%s must be a string, %s given.', $key, get_debug_type($value)));
        }

        return (string) $value;
    }

    /**
     * An integer setting, cast as before; anything that is not a scalar is refused rather than cast.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws RuntimeException When the setting is not a scalar.
     */
    private static function intSetting(array $config, string $key): int
    {
        $value = $config[$key] ?? 0;

        if (! is_scalar($value)) {
            throw new RuntimeException(sprintf('%s must be a number, %s given.', $key, get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * A number setting, cast as before; anything that is not a scalar is refused rather than cast.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws RuntimeException When the setting is not a scalar.
     */
    private static function floatSetting(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;

        if (! is_scalar($value)) {
            throw new RuntimeException(sprintf('%s must be a number, %s given.', $key, get_debug_type($value)));
        }

        return (float) $value;
    }
}
