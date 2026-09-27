<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Feature;

use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Tests\TestCase;

/**
 * A connection whose sentinel_hosts is unusable, or that carries Sentinel settings without it, opened through the
 * real provider and connectors: each fails with a message that says what to fix, and none opens as a standalone one.
 *
 * Every refusal here comes before any socket, so no server is needed. The block also carries the host and port of
 * Laravel's stock configuration, which a standalone fallback would connect to.
 */
final class InvalidSentinelHostsTest extends TestCase
{
    private const array STOCK_ADDRESS = ['host' => '127.0.0.1', 'port' => 6379];

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('invalidSentinelConnections')]
    public function test_an_invalid_sentinel_connection_fails_clearly_and_never_opens_as_a_standalone_one(array $settings, string $message): void
    {
        $redis = $this->redis(['cache' => self::STOCK_ADDRESS + $settings]);

        // A standalone fallback would connect and reach fail(), or throw a RedisException this catch does not take.
        try {
            $redis->connection('cache');

            $this->fail('Expected the connection to be refused.');
        } catch (SentinelConfigurationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSentinelConnections(): array
    {
        $none = 'No Redis sentinel hosts configured: set sentinel_hosts.';

        return [
            'null, as an unset env() gives' => [['sentinel_hosts' => null], $none],
            'empty' => [['sentinel_hosts' => ''], $none],
            'only separators' => [['sentinel_hosts' => ' , '], $none],
            'an empty list' => [['sentinel_hosts' => []], $none],
            'an illegal port' => [['sentinel_hosts' => 's1:26379,s2:abc'], 'Invalid port [abc] in sentinel_hosts entry [s2:abc].'],
            'an unclosed bracket' => [
                ['sentinel_hosts' => '[fd00::1'],
                'Malformed host [[fd00::1] in sentinel_hosts - a bracketed IPv6 literal needs its closing bracket.',
            ],
            'an empty host' => [['sentinel_hosts' => ':26379'], 'Empty host in sentinel_hosts entry [:26379].'],
            'not a string or a list' => [['sentinel_hosts' => 26379], 'sentinel_hosts must be a string or a list, int given.'],
            'an entry that is not a string' => [['sentinel_hosts' => ['s1', ['s2']]], 'sentinel_hosts entries must be strings, array given.'],
            'another Sentinel setting without sentinel_hosts' => [
                ['sentinel_service' => 'mymaster'],
                'sentinel_service is set on a connection without sentinel_hosts: add sentinel_hosts to make it a Sentinel connection, or remove the key.',
            ],
            'a misspelled sentinel_hosts' => [
                ['sentinel_host' => 's1:26379'],
                'sentinel_host is set on a connection without sentinel_hosts: add sentinel_hosts to make it a Sentinel connection, or remove the key.',
            ],
            'sentinel_hosts in another case' => [
                ['SENTINEL_HOSTS' => 's1:26379'],
                'SENTINEL_HOSTS is set on a connection without sentinel_hosts (keys are read with the exact case): add sentinel_hosts'
                .' to make it a Sentinel connection, or remove the key.',
            ],
        ];
    }

    /**
     * The manager, built from the given connections under the phpredis client.
     *
     * @param  array<string, mixed>  $connections
     */
    private function redis(array $connections): RedisManager
    {
        $app = $this->app ?? $this->fail('The application is not booted.');

        $app['config']->set('database.redis', ['client' => 'phpredis', 'options' => ['prefix' => 'app:']] + $connections);
        $app->forgetInstance('redis');

        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        return $redis;
    }
}
