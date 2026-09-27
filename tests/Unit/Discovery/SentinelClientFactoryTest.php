<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedisSentinel;
use Tgi\LaravelPhpRedisSentinel\Discovery\SentinelClientFactory;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;

/**
 * Sentinel options and auth, and the host list the factory reads with.
 *
 * The parser's own edge cases are covered by HostListParserTest; here only what the factory hands it.
 */
final class SentinelClientFactoryTest extends TestCase
{
    private SentinelClientFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SentinelClientFactory;
    }

    public function test_make_builds_a_client_for_one_sentinel_without_connecting(): void
    {
        $this->assertInstanceOf(RedisSentinel::class, $this->factory->make('192.0.2.1', 26379, []));
    }

    public function test_the_host_list_uses_the_sentinel_default_port(): void
    {
        $this->assertSame(
            [['s1', 26379], ['s2', 26380]],
            $this->factory->parseHosts('s1, s2:26380'),
        );
    }

    public function test_host_list_errors_name_the_sentinel_hosts_setting(): void
    {
        $this->expectExceptionObject(new SentinelConfigurationException('Invalid port [abc] in sentinel_hosts entry [s1:abc].'));

        $this->factory->parseHosts('s1:abc');
    }

    public function test_options_default_to_half_a_second_and_no_auth(): void
    {
        $this->assertSame(
            ['host' => 's1', 'port' => 26379, 'connectTimeout' => 0.5, 'readTimeout' => 0.5],
            $this->factory->options('s1', 26379, []),
        );
    }

    public function test_sentinel_auth_shapes_follow_redis_acl_semantics(): void
    {
        $both = $this->factory->options('s1', 26379, [
            'sentinel_username' => 'ops', 'sentinel_password' => 'secret',
        ]);
        $passwordOnly = $this->factory->options('s1', 26379, ['sentinel_password' => 'secret']);
        $anonymous = $this->factory->options('s1', 26379, ['sentinel_timeout' => 0.25]);

        $this->assertSame(['ops', 'secret'], $both['auth'] ?? null);
        $this->assertSame('secret', $passwordOnly['auth'] ?? null);
        $this->assertArrayNotHasKey('auth', $anonymous);
        $this->assertSame(0.25, $anonymous['connectTimeout']);
        $this->assertSame(0.25, $anonymous['readTimeout']);
    }

    public function test_half_configured_credentials_fail_loudly(): void
    {
        // A username alone authenticates nothing; contacting the sentinels anonymously instead would only
        // surface the day an ACL starts being enforced.
        $this->expectExceptionObject(new SentinelConfigurationException('sentinel_username is set without sentinel_password - set both, or neither.'));

        $this->factory->options('s1', 26379, ['sentinel_username' => 'ops']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('nonScalarSettings')]
    public function test_a_setting_that_is_not_a_scalar_is_refused_rather_than_dropped(array $config, string $message): void
    {
        // Falling back instead would turn a malformed password into an anonymous connection.
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        $this->factory->options('s1', 26379, $config);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function nonScalarSettings(): array
    {
        return [
            'password' => [['sentinel_password' => ['secret']], 'sentinel_password must be a string, array given.'],
            'username' => [['sentinel_username' => ['ops'], 'sentinel_password' => 'secret'], 'sentinel_username must be a string, array given.'],
            'timeout' => [['sentinel_timeout' => [1]], 'sentinel_timeout must be a positive number of seconds, array given.'],
        ];
    }

    public function test_the_password_is_sent_as_written(): void
    {
        // Spaces around a Redis password are part of it: ' pw ' authenticates where 'pw' does not.
        $this->assertSame(' pw ', $this->factory->options('s1', 26379, ['sentinel_password' => ' pw '])['auth'] ?? null);
        $this->assertSame(
            ['ops', ' '],
            $this->factory->options('s1', 26379, ['sentinel_username' => 'ops', 'sentinel_password' => ' '])['auth'] ?? null,
            'a password of spaces counts as set',
        );
    }

    #[DataProvider('usernamesWithWhitespace')]
    public function test_a_username_with_whitespace_is_refused(string $username, string $message): void
    {
        // Redis refuses such a username in ACL SETUSER, so it could never authenticate.
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        $this->factory->options('s1', 26379, ['sentinel_username' => $username, 'sentinel_password' => 'secret']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function usernamesWithWhitespace(): array
    {
        return [
            'trailing space, as from an env file' => ['ops ', "sentinel_username must not contain whitespace, which no Redis username can, 'ops ' given."],
            'leading space' => [' ops', "sentinel_username must not contain whitespace, which no Redis username can, ' ops' given."],
            'inner space' => ['o ps', "sentinel_username must not contain whitespace, which no Redis username can, 'o ps' given."],
            'tab' => ["ops\t", "sentinel_username must not contain whitespace, which no Redis username can, 'ops\t' given."],
        ];
    }

    #[DataProvider('unusableSentinelTimeouts')]
    public function test_a_sentinel_timeout_that_is_not_a_positive_number_is_refused(mixed $timeout, string $message): void
    {
        // phpredis reads 0 as default_socket_timeout, so a sentinel that never answers would stall discovery 60 s.
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        $this->factory->options('s1', 26379, ['sentinel_timeout' => $timeout]);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unusableSentinelTimeouts(): array
    {
        return [
            'zero' => [0, 'sentinel_timeout must be a positive number of seconds, 0 given.'],
            'negative' => [-0.5, 'sentinel_timeout must be a positive number of seconds, -0.5 given.'],
            'not a number' => ['abc', "sentinel_timeout must be a positive number of seconds, 'abc' given."],
            'empty, as a blank env()' => ['', "sentinel_timeout must be a positive number of seconds, '' given."],
        ];
    }
}
