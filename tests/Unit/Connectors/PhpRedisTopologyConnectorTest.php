<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisTopologyConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingConnector;

/**
 * Which connector each configuration reaches, against stand-ins that record their calls.
 */
final class PhpRedisTopologyConnectorTest extends TestCase
{
    private RecordingConnector $sentinel;

    private RecordingConnector $standalone;

    private PhpRedisTopologyConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentinel = new RecordingConnector;
        $this->standalone = new RecordingConnector;
        $this->connector = new PhpRedisTopologyConnector($this->sentinel, $this->standalone);
    }

    public function test_a_connection_with_sentinel_hosts_goes_to_the_sentinel_connector_unchanged(): void
    {
        $config = ['sentinel_hosts' => 's1:26379', 'sentinel_service' => 'mymaster', 'database' => 2];
        $options = ['prefix' => 'app:', 'parameters' => []];

        $connection = $this->connector->connect($config, $options);

        $this->assertSame([['connect', $config, $options]], $this->sentinel->calls);
        $this->assertSame([], $this->standalone->calls);
        $this->assertSame($this->sentinel->connection, $connection);
    }

    #[DataProvider('unusableSentinelHosts')]
    public function test_sentinel_hosts_selects_sentinel_even_when_it_is_unusable(mixed $hosts): void
    {
        // The Sentinel connector refuses it as a configuration error; falling back to standalone would hide it.
        $this->connector->connect(['sentinel_hosts' => $hosts], []);

        $this->assertCount(1, $this->sentinel->calls);
        $this->assertSame([], $this->standalone->calls);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableSentinelHosts(): array
    {
        return ['null' => [null], 'empty' => [''], 'empty list' => [[]]];
    }

    public function test_a_connection_without_sentinel_keys_goes_to_laravels_connector_unchanged(): void
    {
        // The retry settings carry no sentinel_ prefix and are left alone: a shared body may carry them.
        $config = ['host' => '127.0.0.1', 'port' => 6379, 'retry_attempts' => 3, 'retry_delay' => 500, 'options' => ['prefix' => 'a:']];
        $options = ['cluster' => 'redis', 'parameters' => []];

        $connection = $this->connector->connect($config, $options);

        $this->assertSame([['connect', $config, $options]], $this->standalone->calls);
        $this->assertSame([], $this->sentinel->calls);
        $this->assertSame($this->standalone->connection, $connection);
    }

    public function test_a_cluster_goes_to_laravels_connector_unchanged(): void
    {
        $config = [['host' => '10.0.0.1', 'port' => 7000], ['host' => '10.0.0.2', 'port' => 7000], 'options' => ['prefix' => 'c:']];
        $clusterOptions = ['cluster' => 'redis'];
        $options = ['prefix' => 'app:'];

        $connection = $this->connector->connectToCluster($config, $clusterOptions, $options);

        $this->assertSame([['connectToCluster', $config, $clusterOptions, $options]], $this->standalone->calls);
        $this->assertSame([], $this->sentinel->calls);
        $this->assertSame($this->standalone->connection, $connection);
    }

    #[DataProvider('sentinelKeysWithoutSentinelHosts')]
    public function test_a_sentinel_key_on_a_connection_without_sentinel_hosts_is_refused(string $key, string $hint): void
    {
        // Without the refusal the connection would open as a standalone one, against whatever host it carries.
        $this->assertRefused(
            "{$key} is set on a connection without sentinel_hosts{$hint}: add sentinel_hosts to make it a Sentinel connection, or remove the key.",
            fn () => $this->connector->connect(['host' => '127.0.0.1', 'port' => 6379, $key => 'x'], []),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sentinelKeysWithoutSentinelHosts(): array
    {
        // Only a sentinel_hosts in another case gets the hint: the message would otherwise contradict itself.
        return [
            'another Sentinel setting' => ['sentinel_service', ''],
            'a misspelled sentinel_hosts' => ['sentinel_host', ''],
            'sentinel_hosts in upper case' => ['SENTINEL_HOSTS', ' (keys are read with the exact case)'],
            'sentinel_hosts in mixed case' => ['Sentinel_Hosts', ' (keys are read with the exact case)'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('sentinelKeysInTheOptionsOfAStandaloneConnection')]
    public function test_a_sentinel_key_in_the_options_of_a_standalone_connection_is_refused(array $config, array $options, string $place): void
    {
        $this->assertRefused(
            "sentinel_timeout must not be set in {$place}: the package reads Sentinel settings only on the Sentinel connection itself.",
            fn () => $this->connector->connect($config, $options),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function sentinelKeysInTheOptionsOfAStandaloneConnection(): array
    {
        return [
            "the connection's options" => [['host' => '127.0.0.1', 'options' => ['sentinel_timeout' => 0.5]], [], "the connection's options"],
            // Every connection gets the global options, so this one fails even a connection with no Sentinel in it.
            'the global options' => [['host' => '127.0.0.1'], ['sentinel_timeout' => 0.5], 'the global Redis options'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  array<string, mixed>  $clusterOptions
     */
    #[DataProvider('sentinelKeysInsideACluster')]
    public function test_a_sentinel_key_inside_a_cluster_configuration_is_refused(array $config, array $clusterOptions): void
    {
        $this->assertRefused(
            'sentinel_hosts is set inside a Redis Cluster configuration: a Cluster is never a Sentinel connection.',
            fn () => $this->connector->connectToCluster($config, $clusterOptions, []),
        );
    }

    /**
     * @return array<string, array{array<array-key, mixed>, array<string, mixed>}>
     */
    public static function sentinelKeysInsideACluster(): array
    {
        $node = ['host' => '10.0.0.1', 'port' => 7000];

        return [
            'a node' => [[$node, $node + ['sentinel_hosts' => 's1:26379']], []],
            "the cluster's own options" => [[$node, 'options' => ['sentinel_hosts' => 's1:26379']], []],
            'the clusters options' => [[$node], ['sentinel_hosts' => 's1:26379']],
        ];
    }

    public function test_a_sentinel_key_in_the_global_options_is_refused_for_a_cluster_too(): void
    {
        $this->assertRefused(
            'sentinel_timeout must not be set in the global Redis options: the package reads Sentinel settings only on the Sentinel connection itself.',
            fn () => $this->connector->connectToCluster([['host' => '10.0.0.1', 'port' => 7000]], [], ['sentinel_timeout' => 0.5]),
        );
    }

    /**
     * Run a call expected to be refused with the message, and check that neither connector was reached.
     */
    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();

            $this->fail('Expected the configuration to be refused.');
        } catch (SentinelConfigurationException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], $this->sentinel->calls, 'the Sentinel connector is not reached');
            $this->assertSame([], $this->standalone->calls, "Laravel's connector is not reached");
        }
    }
}
