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
            'scheme' => [['sentinel_scheme' => ['tls']], 'sentinel_scheme must be a string, array given.'],
            'context' => [['sentinel_context' => 'verify'], 'sentinel_context must be an array, string given.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function schemedHosts(): array
    {
        return [
            'a name' => ['s1', 'tls://s1'],
            'an IPv4 address' => ['10.0.0.1', 'tls://10.0.0.1'],
            'an IPv6 address, bracketed so the scheme and the address can be told apart' => ['fd00::1', 'tls://[fd00::1]'],
        ];
    }

    #[DataProvider('schemedHosts')]
    public function test_the_sentinel_scheme_goes_before_the_host(string $host, string $schemed): void
    {
        $this->assertSame($schemed, $this->factory->options($host, 26379, ['sentinel_scheme' => 'tls'])['host']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function schemesPhpDoesNotSupport(): array
    {
        return [
            'a typo' => ['tsl'],
            'another case: transports are case-sensitive' => ['TLS'],
            'a boolean, which would become 1://' => [true],
        ];
    }

    /**
     * With a transport PHP does not have, RedisSentinel fails with the same "went away" as a sentinel that is down.
     */
    #[DataProvider('schemesPhpDoesNotSupport')]
    public function test_a_scheme_php_does_not_support_is_refused(mixed $scheme): void
    {
        try {
            $this->factory->options('s1', 26379, ['sentinel_scheme' => $scheme]);
            $this->fail('Expected the scheme to be refused.');
        } catch (SentinelConfigurationException $exception) {
            // The list that follows is the platform's own.
            $this->assertStringStartsWith(
                sprintf('sentinel_scheme [%s] is not a transport PHP supports; it supports tcp, ', (string) $scheme),
                $exception->getMessage(),
            );
        }
    }

    public function test_the_data_nodes_scheme_and_context_do_not_reach_the_sentinels(): void
    {
        $options = $this->factory->options('s1', 26379, ['scheme' => 'tls', 'context' => ['ssl' => ['verify_peer' => true]]]);

        $this->assertSame('s1', $options['host']);
        $this->assertArrayNotHasKey('ssl', $options);
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function sentinelContexts(): array
    {
        $ssl = ['cafile' => '/certs/ca.pem', 'verify_peer' => true];

        return [
            'under an ssl key' => [['ssl' => $ssl]],
            'under a stream key' => [['stream' => $ssl]],
            'the options themselves' => [$ssl],
        ];
    }

    /**
     * RedisSentinel takes its `ssl` option flat, like a cluster's context, so it is normalised as Laravel normalises
     * one: every form Laravel accepts for a `context` gives the same options.
     *
     * @param  array<array-key, mixed>  $context
     */
    #[DataProvider('sentinelContexts')]
    public function test_the_sentinel_context_becomes_the_flat_ssl_option(array $context): void
    {
        $this->assertSame(
            ['cafile' => '/certs/ca.pem', 'verify_peer' => true],
            $this->factory->options('s1', 26379, ['sentinel_context' => $context])['ssl'] ?? null,
        );
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
