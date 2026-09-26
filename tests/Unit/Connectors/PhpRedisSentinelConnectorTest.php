<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connectors;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RedisSentinel;
use ReflectionProperty;
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
     * @param  array<string, int>  $buildMs  host => how far building its client moves the clock.
     * @param  array<string, int>  $commandMs  auth, select, client or info => how far each call moves the clock.
     */
    private function connector(
        array $sentinels,
        array $deadMasters = [],
        array $replicaHosts = [],
        int $deadConnectMs = 0,
        array $noInfoHosts = [],
        array $infoErrorHosts = [],
        array $buildMs = [],
        array $commandMs = [],
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
            $deadMasters, $replicaHosts, $deadConnectMs, $noInfoHosts, $infoErrorHosts, $buildMs, $commandMs,
        ): Redis {
            $this->clientHosts[] = "{$config['host']}:{$config['port']}";
            $this->clientConfigs[] = $config;
            $this->clock->advance($buildMs[$config['host']] ?? 0);

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

            return $this->clients[] = new class($role, $this->clock, $commandMs) extends Redis
            {
                /** @var list<float> Every value OPT_READ_TIMEOUT was set to, in order. */
                public array $readTimeouts = [];

                public int $infoCalls = 0;

                /** @var list<list<mixed>> Every setup command and INFO sent, in order, with its arguments. */
                public array $calls = [];

                /**
                 * @param  array<string, int>  $commandMs
                 */
                public function __construct(
                    private readonly ?string $role,
                    private readonly FakeClock $clock,
                    private readonly array $commandMs,
                ) {}

                public function auth(mixed $credentials): Redis|bool
                {
                    $this->sent('auth', $credentials);

                    return true;
                }

                public function select(int $db): Redis|bool
                {
                    $this->sent('select', $db);

                    return true;
                }

                public function client(string $opt, mixed ...$args): mixed
                {
                    $this->sent('client', $opt, ...$args);

                    return true;
                }

                /**
                 * @return array<string, string>|false
                 */
                public function info(string ...$sections): array|false
                {
                    $this->infoCalls++;
                    $this->sent('info', ...$sections);

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

                /**
                 * Record a command, then let it take its scripted time.
                 */
                private function sent(string $command, mixed ...$arguments): void
                {
                    $this->calls[] = [$command, ...$arguments];
                    $this->clock->advance($this->commandMs[$command] ?? 0);
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
            'database' => [['database' => []], 'database must be a number, array given.'],
            'password entry' => [['password' => [['ops']]], 'password must be a string, array given.'],
            'username' => [['username' => ['ops'], 'password' => 'secret'], 'username must be a string, array given.'],
            // Read for the master cache key before any sentinel is asked, so refused before it is serialized.
            'sentinel_password' => [['sentinel_password' => new class {}], 'sentinel_password must be a string, class@anonymous given.'],
            'sentinel_username' => [['sentinel_username' => ['ops']], 'sentinel_username must be a string, array given.'],
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
            'backoff_cap' => 1000,
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

        // The data-node credentials and database are not discovery keys: the connector sends them itself.
        $this->assertArrayNotHasKey('password', $client);
        $this->assertArrayNotHasKey('database', $client);
        $this->assertSame([['auth', 'secret'], ['select', 2]], $this->clients[0]->calls);
        $this->assertSame(1000, $client['backoff_cap']);
    }

    /**
     * Laravel's createClient() still connects the client and applies every local option, but gets no password,
     * database or name: the connector sends AUTH, SELECT and CLIENT SETNAME itself, after the options.
     */
    public function test_setup_commands_leave_createclient_and_run_in_laravels_order(): void
    {
        $config = $this->config('s1:26379', [
            'password' => 'secret', 'database' => '2', 'name' => 'worker', 'username' => 'ops', 'prefix' => 'app:',
        ]);

        // A connection before the failover caches 10.0.0.7, which then dies: the next connect rediscovers.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);
        $this->forgetRecordings();

        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']], deadMasters: ['10.0.0.7'])
            ->connect($config, ['serializer' => 1]);

        foreach ($this->clientConfigs as $built) {
            foreach (['password', 'database', 'name'] as $sent) {
                $this->assertArrayNotHasKey($sent, $built, "[{$sent}] is sent by the connector, not createClient()");
            }

            $this->assertSame('ops', $built['username']);
            $this->assertSame('app:', $built['prefix']);
            $this->assertSame(1, $built['serializer']);
        }

        $this->assertSame(
            [['auth', ['ops', 'secret']], ['select', 2], ['client', 'SETNAME', 'worker'], ['info', 'replication']],
            $this->clients[0]->calls,
            'AUTH, SELECT and SETNAME in that order, then the role check of a rediscovery',
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function maxRetriesSettings(): array
    {
        return [
            // the connection's own settings, the global options
            'unset' => [[], []],
            "Laravel's stock 3, on the connection" => [['max_retries' => 3], []],
            'in the connection options' => [['options' => ['max_retries' => 1]], []],
            'in the global options' => [[], ['max_retries' => 10]],
        ];
    }

    /**
     * phpredis reconnects a socket closed while idle to the same address by itself, up to `max_retries` times: after
     * a graceful failover, the demoted master. With none, the close fails the next command, which the policy moves.
     * Laravel's stock config sets 3, which cannot be told from a deliberate 3, so any value is overridden.
     * createClient() applies `max_retries` right after connecting, so it is in force before AUTH.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('maxRetriesSettings')]
    public function test_the_data_node_client_gets_no_reconnects_of_its_own(array $extra, array $options): void
    {
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])
            ->connect($this->config('s1:26379', $extra), $options);

        $this->assertSame(0, $this->clientConfigs[0]['max_retries']);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<list<mixed>>}>
     */
    public static function setupCommands(): array
    {
        return [
            'empty password' => [['password' => ''], []],
            'password "0"' => [['password' => '0'], []],
            'password alone' => [['password' => 'secret'], [['auth', 'secret']]],
            'username and password' => [['username' => 'ops', 'password' => 'secret'], [['auth', ['ops', 'secret']]]],
            'empty username' => [['username' => '', 'password' => 'secret'], [['auth', 'secret']]],
            // phpredis turns a scalar into a string itself, so '1234' sends the bytes Laravel's 1234 sends.
            'int password with a username' => [['username' => 'ops', 'password' => 1234], [['auth', '1234']]],
            'array password' => [['password' => ['ops', 'secret']], [['auth', ['ops', 'secret']]]],
            'database 0' => [['database' => 0], [['select', 0]]],
            'database as a string' => [['database' => '3'], [['select', 3]]],
            'no database' => [[], []],
            'empty name' => [['name' => ''], []],
            'name' => [['name' => 'worker'], [['client', 'SETNAME', 'worker']]],
        ];
    }

    /**
     * Laravel 13.33's rules, from PhpRedisConnector::createClient().
     *
     * @param  array<string, mixed>  $settings
     * @param  list<list<mixed>>  $calls
     */
    #[DataProvider('setupCommands')]
    public function test_setup_commands_follow_laravels_rules(array $settings, array $calls): void
    {
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])
            ->connect($this->config('s1:26379', $settings), []);

        $this->assertSame($calls, $this->clients[0]->calls);
    }

    public function test_connection_options_override_the_global_ones_and_prefix_joins_them(): void
    {
        $connector = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']]);

        // No deadline, so the recorded read_timeout is the merged value, not one cut by the clamp.
        $connector->connect(
            $this->config('s1:26379', [
                'prefix' => 'app:', 'retry_deadline' => 0, 'options' => ['serializer' => 1, 'read_timeout' => 3.0],
            ]),
            ['read_timeout' => 1.5, 'serializer' => 2, 'scan' => 1, 'prefix' => 'global:'],
        );

        $client = $this->clientConfigs[0];

        $this->assertSame(1, $client['serializer']);
        $this->assertSame(3.0, $client['read_timeout'], 'the connection options override the global ones');
        $this->assertSame(1, $client['scan'], 'a global option nothing overrides still reaches the client');
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

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function roleCheckRefusals(): array
    {
        return [
            // how the rediscovered 10.0.0.9 is refused, extra connection settings
            'a replica' => [['replicaHosts' => ['10.0.0.9']], []],
            'an INFO that throws' => [['infoErrorHosts' => ['10.0.0.9']], []],
            'an INFO that is not an array' => [['noInfoHosts' => ['10.0.0.9']], []],
            'the deadline spent before its role check' => [['buildMs' => ['10.0.0.9' => 200]], ['retry_deadline' => 100]],
        ];
    }

    /**
     * A cache hit skips the role check, so an address may be cached only once its client was built and, on a
     * rediscovery, passed that check: otherwise the next connect() in the process is served the refused node.
     *
     * @param  array<string, mixed>  $refusal
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('roleCheckRefusals')]
    public function test_a_node_the_role_check_refuses_is_never_served_from_the_cache(array $refusal, array $extra): void
    {
        $config = $this->config('s1:26379', ['retry_attempts' => 1] + $extra);

        // A connection before the failover caches 10.0.0.7.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);

        // 10.0.0.7 dies, and the sentinel names 10.0.0.9 before its promotion has landed: the budget runs out.
        try {
            $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']], ...$refusal, deadMasters: ['10.0.0.7'])
                ->connect($config, []);
            $this->fail('Expected the budget to run out on the refused node.');
        } catch (SentinelFailoverException) {
            // As expected.
        }

        $this->forgetRecordings();

        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.2', '6381']])->connect($config, []);

        $this->assertSame(['10.0.0.2:6381'], $this->clientHosts, 'the next connect() discovers instead of taking 10.0.0.9');
        $this->assertSame(1, $this->discoveries);
    }

    public function test_a_cached_master_whose_client_fails_is_evicted(): void
    {
        $config = $this->config('s1:26379');

        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);

        // 10.0.0.7 dies while no sentinel answers either: the connect fails, and the dead address must not stay.
        try {
            $this->connector([], deadMasters: ['10.0.0.7'])->connect($config, []);
            $this->fail('Expected the connect to fail.');
        } catch (SentinelDiscoveryException) {
            // As expected: no sentinel answered.
        }

        $this->forgetRecordings();

        // 10.0.0.7 is back, now as a replica the sentinels no longer name.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.2', '6381']])->connect($config, []);

        $this->assertSame(['10.0.0.2:6381'], $this->clientHosts, 'the next connect() discovers at once');
        $this->assertSame(1, $this->discoveries);
    }

    /**
     * Only the address whose client failed is evicted: a rediscovered node the role check refuses leaves the
     * entry for the master that still works.
     */
    public function test_a_refused_rediscovery_keeps_the_cached_master_it_did_not_replace(): void
    {
        $answers = [['10.0.0.7', '6380'], ['10.0.0.9', '6380']];
        $config = $this->config('s1:26379');

        $connection = $this->connector(['s1:26379' => static function () use (&$answers): mixed {
            return array_shift($answers);
        }], replicaHosts: ['10.0.0.9'])->connect($config, []);

        $rebuild = (new ReflectionProperty(PhpRedisConnection::class, 'connector'))->getValue($connection);
        $this->assertInstanceOf(Closure::class, $rebuild);

        try {
            $rebuild();
            $this->fail('Expected the role check to refuse 10.0.0.9.');
        } catch (SentinelDiscoveryException) {
            // As expected.
        }

        $this->forgetRecordings();
        $this->connector([])->connect($config, []);

        $this->assertSame(['10.0.0.7:6380'], $this->clientHosts, 'still served from the cache');
        $this->assertSame(0, $this->discoveries);
    }

    /**
     * A cache hit asks no sentinel, so connections that differ only in their sentinel credentials must not share an
     * entry: one with wrong credentials would work until its first rediscovery, during a failover.
     */
    public function test_the_master_cache_is_kept_apart_by_sentinel_credentials(): void
    {
        $connector = $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']]);
        $config = $this->config('s1:26379');

        $connector->connect($config + ['sentinel_username' => 'ops', 'sentinel_password' => 'sentinel-secret'], []);
        $connector->connect($config + ['sentinel_username' => 'ops', 'sentinel_password' => 'another-secret'], []);
        $this->assertSame(2, $this->discoveries, 'a different password discovers');

        // ACL users made from one template often share a password.
        $connector->connect($config + ['sentinel_username' => 'other', 'sentinel_password' => 'sentinel-secret'], []);
        $this->assertSame(3, $this->discoveries, 'a different username discovers too');

        $connector->connect($config + ['sentinel_username' => 'ops', 'sentinel_password' => 'sentinel-secret'], []);
        $this->assertSame(3, $this->discoveries, 'identical credentials share the entry');

        $keys = implode("\n", array_keys((new ReflectionProperty(PhpRedisSentinelConnector::class, 'resolvedMasters'))->getValue()));
        $this->assertStringNotContainsString('secret', $keys, 'no password in the cache key');
        $this->assertStringNotContainsString('ops', $keys, 'nor a username');
        $this->assertStringNotContainsString('other', $keys);
    }

    /**
     * Laravel's own rebuild paths call the connector with no argument; a future one would reconnect to the cached,
     * possibly demoted, master. Called that way the connector rediscovers instead, with the role check.
     */
    public function test_an_argument_less_connector_call_rediscovers_instead_of_reusing_the_cached_master(): void
    {
        $answers = [['10.0.0.9', '6380'], ['10.0.0.2', '6381']];

        $connection = $this->connector(['s1:26379' => static function () use (&$answers): mixed {
            return array_shift($answers);
        }])->connect($this->config('s1:26379'), []);

        $connector = (new ReflectionProperty(PhpRedisConnection::class, 'connector'))->getValue($connection);
        $this->assertInstanceOf(Closure::class, $connector);

        $connector();

        $this->assertSame(2, $this->discoveries, 'the sentinels are asked again, not the cache');
        $this->assertSame(['10.0.0.9:6380', '10.0.0.2:6381'], $this->clientHosts);
        $this->assertSame(1, $this->clients[1]->infoCalls, 'a rediscovered node gets the role check');
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

    /**
     * A rediscovery whose client setup spends the rest of the budget starts no role check: INFO is one more round
     * trip the deadline cannot cover, and an unverified node must not be handed out as the master either.
     */
    public function test_a_rediscovery_whose_setup_spends_the_budget_starts_no_role_check(): void
    {
        $config = $this->config('s1:26379', ['retry_deadline' => 100]);

        // A connection before the failover caches 10.0.0.7; it then dies, and setting up 10.0.0.9 takes 150 ms.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);
        $this->forgetRecordings();

        $connector = $this->connector(
            ['s1:26379' => static fn (): array => ['10.0.0.9', '6380']],
            deadMasters: ['10.0.0.7'],
            buildMs: ['10.0.0.9' => 150],
        );

        try {
            $connector->connect($config, []);
            $this->fail('Expected the spent budget to end the connect.');
        } catch (SentinelFailoverException $exception) {
            $this->assertSame(['10.0.0.7:6380', '10.0.0.9:6380'], $this->clientHosts);
            $this->assertSame(0, $this->clients[0]->infoCalls, 'no role check may start on a spent budget');
            $this->assertStringContainsString(
                'spent before the role check of [10.0.0.9:6380]',
                $exception->getPrevious()?->getMessage() ?? '',
            );
        }
    }

    /**
     * @return array<string, array{array<string, int>, array<string, int>, list<string>, string}>
     */
    public static function stagesAfterASpentBudget(): array
    {
        return [
            'AUTH' => [['10.0.0.9' => 150], [], [], 'before AUTH on [10.0.0.9:6380]'],
            'SELECT' => [[], ['auth' => 150], ['auth'], 'before SELECT on [10.0.0.9:6380]'],
            'CLIENT SETNAME' => [[], ['select' => 150], ['auth', 'select'], 'before CLIENT SETNAME on [10.0.0.9:6380]'],
            'role check' => [[], ['client' => 150], ['auth', 'select', 'client'], 'before the role check of [10.0.0.9:6380]'],
        ];
    }

    /**
     * Each setup stage is a round trip of its own: once the stages before it have spent the budget it does not
     * start, the error names it, and the client gets its configured read timeout back.
     *
     * @param  array<string, int>  $buildMs
     * @param  array<string, int>  $commandMs
     * @param  list<string>  $sent
     */
    #[DataProvider('stagesAfterASpentBudget')]
    public function test_no_setup_stage_starts_once_the_budget_is_spent(
        array $buildMs,
        array $commandMs,
        array $sent,
        string $stage,
    ): void {
        $config = $this->config('s1:26379', [
            'password' => 'secret', 'database' => 2, 'name' => 'worker', 'read_timeout' => 2.0, 'retry_deadline' => 100,
        ]);

        // A connection before the failover caches 10.0.0.7, which then dies: the next connect rediscovers.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);
        $this->forgetRecordings();

        $connector = $this->connector(
            ['s1:26379' => static fn (): array => ['10.0.0.9', '6380']],
            deadMasters: ['10.0.0.7'],
            buildMs: $buildMs,
            commandMs: $commandMs,
        );

        try {
            $connector->connect($config, []);
            $this->fail('Expected the spent budget to end the connect.');
        } catch (SentinelFailoverException $exception) {
            $this->assertSame($sent, array_column($this->clients[0]->calls, 0));
            $this->assertStringContainsString(
                "The recovery deadline was spent {$stage}",
                $exception->getPrevious()?->getMessage() ?? '',
            );
            $this->assertSame(2.0, end($this->clients[0]->readTimeouts), 'the configured read timeout comes back');
        }
    }

    public function test_a_configured_read_timeout_shorter_than_what_is_left_is_kept_for_each_stage(): void
    {
        // The default 5 s deadline leaves far more than 0.5 s: every stage waits the configured 0.5 s, never longer.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])
            ->connect($this->config('s1:26379', ['password' => 'secret', 'database' => 2, 'read_timeout' => 0.5]), []);

        $this->assertSame([0.5, 0.5, 0.5], $this->clients[0]->readTimeouts, 'AUTH, SELECT, then the restore');
    }

    public function test_an_unset_read_timeout_comes_back_as_the_default_socket_timeout(): void
    {
        // A client connected without one reads under default_socket_timeout; 0 set on a live socket fails every read.
        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])
            ->connect($this->config('s1:26379', ['password' => 'secret']), []);

        $this->assertSame([5.0, (float) ini_get('default_socket_timeout')], $this->clients[0]->readTimeouts, 'AUTH, then the restore');
    }

    public function test_an_unset_read_timeout_is_cut_to_the_deadline_from_what_the_socket_waits(): void
    {
        // A deadline longer than default_socket_timeout must not lengthen the connect's or a stage's read.
        $default = (float) ini_get('default_socket_timeout');

        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.9', '6380']])
            ->connect($this->config('s1:26379', ['password' => 'secret', 'retry_deadline' => 100_000]), []);

        $this->assertSame($default, $this->clientConfigs[0]['read_timeout'], 'the client is connected under it');
        $this->assertSame([$default, $default], $this->clients[0]->readTimeouts, 'AUTH, then the restore');
    }

    public function test_each_setup_stage_waits_at_most_what_the_stages_before_it_left(): void
    {
        $config = $this->config('s1:26379', [
            'password' => 'secret', 'database' => 2, 'name' => 'worker', 'read_timeout' => 2.0, 'retry_deadline' => 100,
        ]);

        $this->connector(['s1:26379' => static fn (): array => ['10.0.0.7', '6380']])->connect($config, []);
        $this->forgetRecordings();

        $this->connector(
            ['s1:26379' => static fn (): array => ['10.0.0.9', '6380']],
            deadMasters: ['10.0.0.7'],
            commandMs: ['auth' => 30, 'select' => 20, 'client' => 10],
        )->connect($config, []);

        $this->assertSame(
            [0.1, 0.07, 0.05, 0.04, 2.0],
            $this->clients[0]->readTimeouts,
            'AUTH, SELECT, SETNAME and the role check each get what is left, then the configured value comes back',
        );
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

    /**
     * A sentinel that answers after the deadline has passed may name a perfectly good master, but building a client
     * from it could only overrun: nothing is connected to, and the late answer is not cached.
     */
    public function test_a_sentinel_answer_that_arrives_after_the_deadline_builds_no_client(): void
    {
        $config = $this->config('late:26379,next:26379', ['retry_deadline' => 40]);

        $connector = $this->connector([
            'late:26379' => function (): array {
                $this->clock->advance(60);

                return ['10.0.0.9', '6380'];
            },
            'next:26379' => static fn (): array => ['10.0.0.2', '6381'],
        ]);

        try {
            $connector->connect($config, []);
            $this->fail('Expected the late answer to end the connect.');
        } catch (SentinelFailoverException $exception) {
            $discovery = $exception->getPrevious()?->getMessage() ?? '';

            $this->assertSame([], $this->clientHosts, 'no data-node client may be built from a late answer');
            $this->assertSame(1, $this->discoveries, 'no probe starts after a late answer');
            $this->assertStringContainsString('late:26379 (answered after the recovery deadline was spent)', $discovery);
            $this->assertStringContainsString('next:26379 (not tried', $discovery);
        }

        // The late answer was not cached: the next connect asks the sentinels again.
        $this->forgetRecordings();
        $this->connector(['late:26379' => static fn (): array => ['10.0.0.2', '6381']])->connect($config, []);

        $this->assertSame(1, $this->discoveries);
        $this->assertSame(['10.0.0.2:6381'], $this->clientHosts);
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
        $this->assertSame([0.07, 2.0], $this->clients[0]->readTimeouts, 'clamped for the role check, then restored');
    }

    public function test_cluster_connections_are_refused_with_a_named_error(): void
    {
        $this->expectExceptionObject(new RuntimeException(
            'A Sentinel connection cannot be a Redis Cluster: define the cluster under `clusters`, without sentinel_hosts.'
        ));

        $this->connector([])->connectToCluster([], [], []);
    }
}
