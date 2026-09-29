<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Redis;
use RedisException;
use ReflectionClass;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * Passwords and ACL users, set at runtime, on the data nodes and on the sentinels separately; and a client built by
 * the package compared with one Laravel builds for the same settings.
 *
 * Each test undoes what it set when it ends, as differences() cannot see an ACL user: one that added a user deletes
 * it, in milliseconds, and one that set a password resets the servers.
 */
final class AuthenticationTest extends IntegrationTestCase
{
    private const string PASSWORD = 'data-secret';

    private const string SENTINEL_PASSWORD = 'sentinel-secret';

    private const array APP_USER = ['on', '>app-secret', '~*', '&*', '+@all'];

    /**
     * The connection settings both parity clients get.
     */
    private const array PARITY_SETTINGS = [
        'username' => 'app',
        'password' => 'app-secret',
        'database' => 2,
        'name' => 'parity',
        'timeout' => 1.5,
        'read_timeout' => 1.5,
    ];

    public function test_a_data_node_password_is_sent_and_a_wrong_one_is_refused_without_a_retry(): void
    {
        try {
            $this->requireADataNodePassword();

            $connection = $this->sentinelConnection(['password' => self::PASSWORD]);
            $this->assertTrue($connection->set('key', 'value'));
            $this->assertSame('value', $connection->get('key'));

            $this->assertOpeningFails(['password' => 'wrong'], 'WRONGPASS');
            $this->assertSame([], $this->logger->warnings(), 'a configuration error is not retried');
        } finally {
            Servers::reset();
        }
    }

    public function test_a_data_node_acl_user_is_sent_and_a_wrong_password_is_refused_without_a_retry(): void
    {
        $this->withAclUser([Servers::MASTER, ...Servers::REPLICAS], 'app', self::APP_USER, function (): void {
            $connection = $this->sentinelConnection(['username' => 'app', 'password' => 'app-secret']);
            $this->assertSame('app', $connection->command('acl', ['whoami']));

            $this->assertOpeningFails(['username' => 'app', 'password' => 'wrong'], 'WRONGPASS');
            $this->assertSame([], $this->logger->warnings());
        });
    }

    public function test_a_sentinel_password_is_sent_and_a_wrong_one_fails_discovery_at_once_naming_every_sentinel(): void
    {
        try {
            foreach (Servers::SENTINELS as $port) {
                $sentinel = Servers::node($port);
                // The sentinels talk to each other too, so each gets the password to present first.
                $this->assertTrue($sentinel->rawCommand('SENTINEL', 'CONFIG', 'SET', 'sentinel-pass', self::SENTINEL_PASSWORD));
                $this->assertTrue($sentinel->rawCommand('ACL', 'SETUSER', 'default', 'resetpass', '>'.self::SENTINEL_PASSWORD));
            }

            $this->assertSame(Servers::MASTER, $this->sentinelConnection(['sentinel_password' => self::SENTINEL_PASSWORD])->client()->getPort());

            $this->assertDiscoveryFails(['sentinel_password' => 'wrong']);
        } finally {
            Servers::reset();
        }
    }

    /**
     * A sentinel user needs one permission: SENTINEL GET-MASTER-ADDR-BY-NAME. AUTH is always allowed.
     */
    public function test_a_sentinel_acl_user_with_only_the_discovery_command_is_enough(): void
    {
        $rules = ['on', '>discovery-secret', '+sentinel|get-master-addr-by-name'];

        $this->withAclUser(Servers::SENTINELS, 'discovery', $rules, function (): void {
            $settings = ['sentinel_username' => 'discovery', 'sentinel_password' => 'discovery-secret'];
            $this->assertSame(Servers::MASTER, $this->sentinelConnection($settings)->client()->getPort());

            $this->assertDiscoveryFails(['sentinel_password' => 'wrong'] + $settings);
        });
    }

    /**
     * The package builds its data-node client with Laravel's own createClient() and sends AUTH, SELECT and CLIENT
     * SETNAME itself, with Laravel's rules: a client it builds is set up as Laravel's is, but for max_retries, which
     * it overrides to 0 on purpose.
     */
    public function test_a_client_the_package_builds_is_set_up_as_laravels_own_but_for_max_retries(): void
    {
        $this->withAclUser([Servers::MASTER], 'app', self::APP_USER, function (): void {
            $this->assertTheClientsMatch(...$this->bothClients(self::PARITY_SETTINGS));
        });
    }

    /**
     * The same with persistent connections, which Laravel's createClient() opens with pconnect() and the persistent ID.
     *
     * Each client gets an ID of its own, unique to the run: with the same one they would share one socket, and a later
     * pconnect() could pick up its database and login. Deleting the user afterwards makes Redis close both sockets.
     */
    public function test_a_persistent_client_the_package_builds_is_set_up_as_laravels_own(): void
    {
        $run = uniqid('parity-', true);

        $this->withAclUser([Servers::MASTER], 'app', self::APP_USER, function () use ($run): void {
            [$laravels, $packages] = $this->bothClients(
                self::PARITY_SETTINGS + ['persistent' => true],
                ['persistent_id' => "{$run}-laravel"],
                ['persistent_id' => "{$run}-package"],
            );

            $this->assertTheClientsMatch($laravels, $packages);
            $this->assertSame("{$run}-laravel", $laravels->getPersistentID());
            $this->assertSame("{$run}-package", $packages->getPersistentID());
        });

        $this->assertStringNotContainsString('name=parity', (string) Servers::node(Servers::MASTER)->rawCommand('CLIENT', 'LIST'), 'both sockets closed');
    }

    /**
     * Every phpredis option, the database, credentials, timeouts and client name match, but for max_retries.
     */
    private function assertTheClientsMatch(Redis $laravels, Redis $packages): void
    {
        $this->assertSame(0, $this->clientOptions($packages)['OPT_MAX_RETRIES']);
        $this->assertSame(
            array_diff_key($this->clientOptions($laravels), ['OPT_MAX_RETRIES' => true]),
            array_diff_key($this->clientOptions($packages), ['OPT_MAX_RETRIES' => true]),
        );

        foreach (['getDBNum', 'getAuth', 'getTimeout', 'getReadTimeout'] as $getter) {
            $this->assertSame($laravels->{$getter}(), $packages->{$getter}(), $getter);
        }

        $this->assertSame(2, $packages->getDBNum());
        $this->assertSame(['app', 'app-secret'], $packages->getAuth());
        $this->assertSame('parity', $laravels->client('getname'));
        $this->assertSame('parity', $packages->client('getname'));
    }

    /**
     * Run the test with an ACL user on the servers on the given ports, and delete it afterwards.
     *
     * @param  list<int>  $ports
     * @param  list<string>  $rules  The user's ACL SETUSER rules.
     */
    private function withAclUser(array $ports, string $user, array $rules, Closure $test): void
    {
        // Inside the try, so a user created on some servers before one refused it is deleted too.
        try {
            foreach ($ports as $port) {
                $this->assertTrue(Servers::node($port)->rawCommand('ACL', 'SETUSER', $user, ...$rules));
            }

            $test();
        } finally {
            foreach ($ports as $port) {
                Servers::node($port)->rawCommand('ACL', 'DELUSER', $user);
            }
        }
    }

    /**
     * Make the data nodes require a password, and tell the replicas and the sentinels, which connect to them too.
     */
    private function requireADataNodePassword(): void
    {
        foreach ([Servers::MASTER, ...Servers::REPLICAS] as $port) {
            $node = Servers::node($port);
            $this->assertTrue($node->config('SET', 'masterauth', self::PASSWORD));
            $this->assertTrue($node->config('SET', 'requirepass', self::PASSWORD));
        }

        // So a sentinel that reconnects is not refused: its open links stay authenticated, but a new one would fail its
        // checks, and the master could be failed over.
        foreach (Servers::SENTINELS as $port) {
            $this->assertTrue(Servers::node($port)->rawCommand('SENTINEL', 'SET', Servers::SERVICE, 'auth-pass', self::PASSWORD));
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function assertOpeningFails(array $settings, string $error): void
    {
        try {
            $this->sentinelConnection($settings);
            $this->fail('Expected opening to fail.');
        } catch (RedisException $exception) {
            $this->assertStringContainsString($error, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function assertDiscoveryFails(array $settings): void
    {
        try {
            $this->sentinelConnection($settings);
            $this->fail('Expected discovery to fail.');
        } catch (SentinelDiscoveryException $exception) {
            foreach (Servers::SENTINELS as $port) {
                $this->assertStringContainsString(Servers::HOST.":{$port} (", $exception->getMessage());
            }

            $this->assertStringContainsString('WRONGPASS', $exception->getMessage());
        }

        $this->assertSame([], $this->logger->warnings(), 'not retried');
    }

    /**
     * A client Laravel builds for a plain connection to the master, and one the package builds for a Sentinel
     * connection, from the same settings and the parity options, through one manager with the package's provider.
     *
     * @param  array<string, mixed>  $settings  Both connections'.
     * @param  array<string, mixed>  $plainOnly  Added to the plain connection's.
     * @param  array<string, mixed>  $sentinelOnly  Added to the Sentinel connection's.
     * @return array{Redis, Redis}
     */
    private function bothClients(array $settings, array $plainOnly = [], array $sentinelOnly = []): array
    {
        $app = $this->app ?? $this->fail('The application is not booted.');

        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => 'parity:', 'serializer' => Redis::SERIALIZER_PHP]
                + (defined('Redis::COMPRESSION_LZF') ? ['compression' => Redis::COMPRESSION_LZF] : []),
            'plain' => ['host' => Servers::HOST, 'port' => Servers::MASTER] + $plainOnly + $settings,
            'sentinel' => [
                'sentinel_hosts' => self::SENTINEL_HOSTS,
                'sentinel_service' => Servers::SERVICE,
            ] + $sentinelOnly + $settings,
        ]);
        $app->forgetInstance('redis');

        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        $plain = $redis->connection('plain');
        $sentinel = $redis->connection('sentinel');
        $this->assertSame(PhpRedisConnection::class, $plain::class, "Laravel's own");
        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $sentinel);

        return [$plain->client(), $sentinel->client()];
    }

    /**
     * Every OPT_* option of the client, by name.
     *
     * @return array<string, mixed>
     */
    private function clientOptions(Redis $client): array
    {
        $options = [];

        foreach ((new ReflectionClass(Redis::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'OPT_') && is_int($value)) {
                $options[$name] = $client->getOption($value);
            }
        }

        ksort($options);

        return $options;
    }
}
