<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connectors;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RedisSentinel;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisSentinelConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\FakeClock;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;

/**
 * Discovery and connection opening against scripted sentinels and data nodes, driven through connect().
 */
final class PhpRedisSentinelConnectorTest extends TestCase
{
    private FakeClock $clock;

    private RecordingLogger $logger;

    /** @var int How many sentinel probes were started. */
    private int $discoveries = 0;

    /** @var list<float|null> The sentinel_timeout each probe was given. */
    private array $sentinelTimeouts = [];

    /** @var list<string> The host:port each data-node client was asked for. */
    private array $clientHosts = [];

    /** @var list<array<array-key, mixed>> The full configuration each data-node client was built from. */
    private array $clientConfigs = [];

    /** @var list<object> Every data-node client handed out. */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FakeClock;
        $this->logger = new RecordingLogger;
    }

    /**
     * A connector whose sentinels and data nodes are scripted.
     *
     * @param  array<string, Closure(string): mixed>  $sentinels  host:port => its getMasterAddrByName() answer.
     * @param  list<string>  $deadMasters  Hosts whose client fails to connect, after $deadConnectMs on the clock.
     * @param  list<string>  $replicaHosts  Hosts that report role:slave.
     * @param  list<string>  $noInfoHosts  Hosts whose INFO returns false instead of an array.
     * @param  list<string>  $infoErrorHosts  Hosts whose INFO throws.
     */
    private function connector(
        array $sentinels,
        array $deadMasters = [],
        array $replicaHosts = [],
        int $deadConnectMs = 0,
        array $noInfoHosts = [],
        array $infoErrorHosts = [],
    ): PhpRedisSentinelConnector {
        $sentinelClient = function (string $host, int $port, array $config) use ($sentinels): RedisSentinel {
            $this->discoveries++;
            $this->sentinelTimeouts[] = isset($config['sentinel_timeout']) && is_float($config['sentinel_timeout'])
                ? $config['sentinel_timeout'] : null;

            $script = $sentinels["{$host}:{$port}"] ?? static fn (): never => throw new RedisException('Connection refused');

            return new class($script) extends RedisSentinel
            {
                public function __construct(private readonly Closure $script) {}

                public function getMasterAddrByName(string $master): mixed
                {
                    return ($this->script)($master);
                }
            };
        };

        $dataClient = function (array $config) use (
            $deadMasters, $replicaHosts, $deadConnectMs, $noInfoHosts, $infoErrorHosts,
        ): Redis {
            $this->clientHosts[] = "{$config['host']}:{$config['port']}";
            $this->clientConfigs[] = $config;

            if (in_array($config['host'], $deadMasters, true)) {
                $this->clock->advance($deadConnectMs);

                throw new RedisException("read error on connection to {$config['host']}:{$config['port']}");
            }

            $role = match (true) {
                in_array($config['host'], $replicaHosts, true) => 'slave',
                in_array($config['host'], $noInfoHosts, true) => null,
                in_array($config['host'], $infoErrorHosts, true) => 'error',
                default => 'master',
            };

            return $this->clients[] = new class($role) extends Redis
            {
                /** @var list<float> Every value OPT_READ_TIMEOUT was set to, in order. */
                public array $readTimeouts = [];

                public function __construct(private readonly ?string $role) {}

                /**
                 * @return array<string, string>|false
                 */
                public function info(string ...$sections): array|false
                {
                    if ($this->role === 'error') {
                        throw new RedisException('ERR the INFO reply could not be read');
                    }

                    return $this->role === null ? false : ['role' => $this->role];
                }

                public function setOption(int $option, mixed $value): bool
                {
                    if ($option === Redis::OPT_READ_TIMEOUT && is_numeric($value)) {
                        $this->readTimeouts[] = (float) $value;
                    }

                    return true;
                }

                public function close(): bool
                {
                    return true;
                }
            };
        };

        return new PhpRedisSentinelConnector($this->logger, $sentinelClient, $dataClient, $this->clock);
    }

    /**
     * A per-test unique service, so the process-wide master cache never carries over between tests.
     *
     * @return array<string, mixed>
     */
    private function config(string $hosts, array $extra = []): array
    {
        return ['sentinel_hosts' => $hosts, 'sentinel_service' => 'svc-'.uniqid(), 'retry_delay' => 0] + $extra;
    }

    private function forgetRecordings(): void
    {
        $this->discoveries = 0;
        $this->sentinelTimeouts = $this->clientHosts = $this->clientConfigs = $this->clients = [];
    }

    public function test_skips_failing_sentinels_and_uses_the_first_that_answers(): void
    {
        $connector = $this->connector([
            'bad:26379' => static fn (): never => throw new RedisException('Connection refused'),
            'unaware:26379' => static fn (): bool => false,
            'good:26379' => static fn (): array => ['10.0.0.9', '6380'],
        ]);

        $connector->connect($this->config('bad:26379,unaware:26379,good:26379'), []);

        $this->assertSame(['10.0.0.9:6380'], $this->clientHosts);
    }

    public function test_names_every_sentinel_tried_when_none_answers(): void
    {
        $connector = $this->connector([
            'down-a:26379' => static fn (): never => throw new RedisException('Connection refused'),
            'down-b:26380' => static fn (): bool => false,
            // An empty host or an unusable port counts as no master known, and never reaches the client.
            'weird:26381' => static fn (): array => ['', '6380'],
            'text-port:26382' => static fn (): array => ['10.0.0.9', '6380abc'],
            'port-zero:26383' => static fn (): array => ['10.0.0.9', '0'],
            'port-over:26384' => static fn (): array => ['10.0.0.9', '65536'],
        ]);

        try {
            $connector->connect($this->config(
                'down-a:26379,down-b:26380,weird:26381,text-port:26382,port-zero:26383,port-over:26384',
                ['retry_attempts' => 0],
            ), []);
            $this->fail('Expected discovery to fail, naming every sentinel tried.');
        } catch (SentinelFailoverException $exception) {
            $discovery = $exception->getPrevious();

            $this->assertInstanceOf(SentinelDiscoveryException::class, $discovery);
            $this->assertStringContainsString('down-a:26379 (Connection refused)', $discovery->getMessage());
            $this->assertStringContainsString('down-b:26380 (no usable master known', $discovery->getMessage());
            $this->assertStringContainsString('weird:26381 (no usable master known', $discovery->getMessage());
            $this->assertStringContainsString('text-port:26382 (no usable master known', $discovery->getMessage());
            $this->assertStringContainsString('port-zero:26383 (no usable master known', $discovery->getMessage());
            $this->assertStringContainsString('port-over:26384 (no usable master known', $discovery->getMessage());
            $this->assertSame([], $this->clientHosts);
        }
    }

    public function test_rejects_an_empty_sentinel_host_list(): void
    {
        $this->expectExceptionObject(new RuntimeException('No Redis sentinel hosts configured: set sentinel_hosts.'));

        $this->connector([])->connect($this->config(''), []);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedSettings(): array
    {
        return [
            'options' => [['options' => 'x'], 'options must be an array, string given.'],
            'hosts type' => [['sentinel_hosts' => 5], 'sentinel_hosts must be a string or a list, int given.'],
            'hosts entry' => [['sentinel_hosts' => [['s1']]], 'sentinel_hosts entries must be strings, array given.'],
            'service' => [['sentinel_service' => []], 'sentinel_service must be a string, array given.'],
            'sentinel_timeout' => [['sentinel_timeout' => []], 'sentinel_timeout must be a number, array given.'],
            'timeout' => [['timeout' => []], 'timeout must be a number, array given.'],
            'read_timeout' => [['read_timeout' => []], 'read_timeout must be a number, array given.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('malformedSettings')]
    public function test_a_malformed_setting_is_refused_naming_it(array $override, string $message): void
    {
        $this->expectExceptionObject(new RuntimeException($message));

        // array_replace, not +: the override must win over the helper's sentinel_hosts and sentinel_service.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])
            ->connect(array_replace($this->config('s1:26379'), $override), []);
    }

    public function test_caches_the_master_per_process(): void
    {
        $connector = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']]);
        $config = $this->config('s1:26379');

        $connector->connect($config, []);
        $connector->connect($config, []);

        $this->assertSame(1, $this->discoveries, 'the second connection must use the cached master');
        $this->assertSame(['10.0.0.9:6380', '10.0.0.9:6380'], $this->clientHosts);
    }

    public function test_rewrites_host_and_port_and_strips_discovery_keys(): void
    {
        $connector = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']]);

        $connector->connect($this->config('s1:26379', [
            'url' => 'redis://stale-host:9999',
            'host' => 'placeholder',
            'port' => '6379',
            'password' => 'secret',
            'database' => '2',
            'max_retries' => 3,
            'sentinel_username' => 'ops',
            'sentinel_password' => 'sentinel-secret',
            'sentinel_timeout' => 0.25,
            'retry_attempts' => 5,
            'retry_deadline' => 4000,
        ]), []);

        $client = $this->clientConfigs[0];

        $this->assertSame('10.0.0.9', $client['host']);
        $this->assertSame(6380, $client['port']);

        // Every discovery key: adding one to the connector's list without adding it here leaves a leak unnoticed.
        foreach ([
            'url', 'sentinel_hosts', 'sentinel_service', 'sentinel_username', 'sentinel_password', 'sentinel_timeout',
            'retry_attempts', 'retry_delay', 'retry_deadline',
        ] as $stripped) {
            $this->assertArrayNotHasKey($stripped, $client, "[{$stripped}] must not reach the data-node client");
        }

        $this->assertSame('secret', $client['password'], 'the data-node credentials are not discovery keys');
        $this->assertSame('2', $client['database']);
        $this->assertSame(3, $client['max_retries']);
    }

    public function test_connection_options_override_the_global_ones_and_prefix_joins_them(): void
    {
        $connector = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']]);

        // No deadline, so the recorded read_timeout is the merged value, not one cut by the clamp.
        $connector->connect(
            $this->config('s1:26379', [
                'prefix' => 'app:', 'retry_deadline' => 0, 'options' => ['serializer' => 1, 'read_timeout' => 3.0],
            ]),
            ['read_timeout' => 1.5, 'serializer' => 2, 'name' => 'worker', 'prefix' => 'global:'],
        );

        $client = $this->clientConfigs[0];

        $this->assertSame(1, $client['serializer']);
        $this->assertSame(3.0, $client['read_timeout'], 'the connection options override the global ones');
        $this->assertSame('worker', $client['name'], 'a global option nothing overrides still reaches the client');
        $this->assertSame('app:', $client['prefix'], "the connection's prefix beats Laravel's default global one");
        $this->assertArrayNotHasKey('options', $client, 'the options are merged in, not passed nested');
    }

    public function test_connect_falls_back_to_fresh_discovery_when_the_cached_master_is_dead(): void
    {
        $config = $this->config('s1:26379');

        // A connection before the failover caches the old master.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])->connect($config, []);
        $this->forgetRecordings();

        // 10.0.0.9 dies, and the sentinel now names the promoted node.
        $connection = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.2', '6381']], deadMasters: ['10.0.0.9'])
            ->connect($config, []);

        $this->assertSame(['10.0.0.9:6380', '10.0.0.2:6381'], $this->clientHosts, 'the cached master first, then a rediscovery');
        $this->assertSame(1, $this->discoveries);
        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $connection);
    }

    public function test_connect_retries_until_the_election_names_a_master(): void
    {
        // The php-fpm case: the sentinels answer but know no master until the promotion lands.
        $answers = [false, false, ['10.0.0.5', '6379']];

        $connector = $this->connector(['s1:26379' => static function () use (&$answers): mixed {
            return array_shift($answers);
        }]);

        $connector->connect($this->config('s1:26379'), []);

        $this->assertSame(['10.0.0.5:6379'], $this->clientHosts, 'only the promoted node is ever connected to');
        $this->assertSame(3, $this->discoveries);
        $this->assertCount(2, $this->logger->warnings(), 'every retry logs a warning');
    }

    public function test_connect_fails_fast_when_no_sentinel_answers_at_all(): void
    {
        // An unreachable fleet does not become reachable by waiting, so no budget is spent on it.
        $connector = $this->connector([]);

        try {
            $connector->connect($this->config('down-a:26379,down-b:26379'), []);
            $this->fail('Expected discovery to fail fast.');
        } catch (SentinelDiscoveryException $exception) {
            $this->assertFalse($exception->anySentinelAnswered);
            $this->assertSame(2, $this->discoveries, 'one sweep of the fleet, no retries');
            $this->assertStringContainsString('down-b:26379', $exception->getMessage());
        }
    }

    public function test_a_rediscovered_node_that_is_still_a_replica_is_rejected_and_retried(): void
    {
        // Sentinels switch before the old master finishes demoting; the role check runs only on rediscovery,
        // so the dead node comes first to put the loop there.
        $addresses = [['10.0.0.7', '6379'], ['10.0.0.9', '6379'], ['10.0.0.2', '6379']];

        $connector = $this->connector(
            ['s1:26379' => static function () use (&$addresses): mixed {
                return array_shift($addresses);
            }],
            deadMasters: ['10.0.0.7'],
            replicaHosts: ['10.0.0.9'],
        );

        $connector->connect($this->config('s1:26379'), []);

        $this->assertSame(
            ['10.0.0.7:6379', '10.0.0.9:6379', '10.0.0.2:6379'],
            $this->clientHosts,
            'the replica must be rejected and rediscovery tried again, not accepted as the master',
        );
    }

    public function test_a_rediscovered_node_whose_info_is_not_an_array_is_rejected_and_retried(): void
    {
        // phpredis returns false for INFO in some failure modes; the role is then unknown, and unknown is not master.
        $addresses = [['10.0.0.7', '6379'], ['10.0.0.8', '6379'], ['10.0.0.2', '6379']];

        $connector = $this->connector(
            ['s1:26379' => static function () use (&$addresses): mixed {
                return array_shift($addresses);
            }],
            deadMasters: ['10.0.0.7'],
            noInfoHosts: ['10.0.0.8'],
        );

        $connector->connect($this->config('s1:26379'), []);

        $this->assertSame(['10.0.0.7:6379', '10.0.0.8:6379', '10.0.0.2:6379'], $this->clientHosts);
        $this->assertStringContainsString('reports role:unknown', $this->logger->warnings()[1] ?? '');
    }

    public function test_a_rediscovered_node_whose_role_cannot_be_read_is_rejected_and_retried(): void
    {
        // The INFO error names no failover fragment, so only the connector's wrapping makes it retryable.
        $addresses = [['10.0.0.7', '6379'], ['10.0.0.8', '6379'], ['10.0.0.2', '6379']];

        $connector = $this->connector(
            ['s1:26379' => static function () use (&$addresses): mixed {
                return array_shift($addresses);
            }],
            deadMasters: ['10.0.0.7'],
            infoErrorHosts: ['10.0.0.8'],
        );

        $connector->connect($this->config('s1:26379'), []);

        $this->assertSame(['10.0.0.7:6379', '10.0.0.8:6379', '10.0.0.2:6379'], $this->clientHosts);
        $this->assertStringContainsString('Could not verify the role', $this->logger->warnings()[1] ?? '');
    }

    public function test_an_unset_service_and_probe_timeout_take_their_defaults(): void
    {
        // Unique hosts instead of a unique service, so the cache entry stays per test with the default service.
        $sentinel = 'defaults-'.uniqid().':26379';
        $asked = [];

        $connector = $this->connector([$sentinel => static function (string $service) use (&$asked): array {
            $asked[] = $service;

            return ['10.0.0.9', '6380'];
        }]);

        $connector->connect(['sentinel_hosts' => $sentinel, 'retry_delay' => 0], []);

        $this->assertSame(['mymaster'], $asked);
        $this->assertSame([0.5], $this->sentinelTimeouts, 'a 0.5 s probe fits the 5 s default deadline unclamped');
    }

    public function test_the_role_check_stays_off_the_happy_path(): void
    {
        // One INFO per rediscovery is cheap; one per connection would be paid on every request.
        $connector = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6379']], replicaHosts: ['10.0.0.9']);

        $connector->connect($this->config('s1:26379'), []);

        $this->assertSame(['10.0.0.9:6379'], $this->clientHosts);
    }

    /**
     * A sentinel probe waits at most what the deadline has left, and once a probe has spent it the sweep stops;
     * the untried sentinels are named as such.
     */
    public function test_a_sentinel_probe_that_spends_the_budget_stops_the_sweep(): void
    {
        $connector = $this->connector([
            'slow:26379' => function (): never {
                $this->clock->advance(60);

                throw new RedisException('read error on connection');
            },
            'good:26379' => static fn (): array => ['10.0.0.9', '6380'],
        ]);

        try {
            $connector->connect($this->config('slow:26379,good:26379', [
                'sentinel_timeout' => 0.5, 'retry_attempts' => 3, 'retry_deadline' => 40,
            ]), []);

            $this->fail('Expected discovery to stop once the budget was spent.');
        } catch (SentinelDiscoveryException $exception) {
            $this->assertSame(1, $this->discoveries, 'the second sentinel must not be probed');
            $this->assertStringContainsString('good:26379 (not tried', $exception->getMessage());
            $this->assertSame([0.04], $this->sentinelTimeouts, 'the probe is clamped to the budget');
        }
    }

    public function test_a_fast_sentinel_failure_still_lets_the_next_sentinel_be_tried_under_a_deadline(): void
    {
        $connector = $this->connector([
            'fast:26379' => static fn (): never => throw new RedisException('Connection refused'),
            'good:26379' => static fn (): array => ['10.0.0.9', '6380'],
        ]);

        $connector->connect($this->config('fast:26379,good:26379', ['retry_deadline' => 40]), []);

        $this->assertSame(2, $this->discoveries);
        $this->assertSame(['10.0.0.9:6380'], $this->clientHosts);
    }

    /**
     * Each client is built with its connect and read timeouts cut to what the deadline has left, the first attempt
     * included, and then gets its configured read timeout back, since it serves every later command.
     */
    public function test_a_client_is_built_under_the_remaining_budget_and_keeps_its_configured_read_timeout(): void
    {
        $config = $this->config('s1:26379', ['timeout' => 2.0, 'read_timeout' => 2.0, 'retry_deadline' => 100]);

        // A connection before the failover caches 10.0.0.7; it then dies, and its connect attempt takes 30 ms.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);
        $this->forgetRecordings();

        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']], deadMasters: ['10.0.0.7'], deadConnectMs: 30)
            ->connect($config, []);

        $this->assertSame(['10.0.0.7:6380', '10.0.0.9:6380'], $this->clientHosts);
        $this->assertSame([0.1, 0.1], [$this->clientConfigs[0]['timeout'], $this->clientConfigs[0]['read_timeout']]);
        $this->assertSame(
            [0.07, 0.07],
            [$this->clientConfigs[1]['timeout'], $this->clientConfigs[1]['read_timeout']],
            'the rediscovery gets what is left, never more',
        );
        $this->assertSame([2.0], $this->clients[0]->readTimeouts, 'restored after the role check');
    }

    public function test_cluster_connections_are_refused_with_a_named_error(): void
    {
        $this->expectExceptionObject(new RuntimeException(
            'A Sentinel connection cannot be a Redis Cluster: define the cluster under `clusters`, without sentinel_hosts.'
        ));

        $this->connector([])->connectToCluster([], [], []);
    }
}
