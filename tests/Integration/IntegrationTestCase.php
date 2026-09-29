<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Illuminate\Redis\RedisManager;
use Psr\Log\LoggerInterface;
use Redis;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisSentinelConnector;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Child;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;
use Tgi\LaravelPhpRedisSentinel\Tests\TestCase;

/**
 * A test against the servers of docker/compose.yaml, run in the Linux test container (`composer test:integration`).
 *
 * Every test starts from the canonical state, with no data: servers a previous test left in another state are reset
 * first, and keys it left are flushed.
 * Servers that cannot be reached or reset fail the test instead of skipping it, since a skipped suite would pass
 * CI having shown nothing; phpunit.xml.dist also fails the run on any skipped or incomplete test.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Seconds to wait for a held client to reach the new master: the demotion, then the retry.
     */
    protected const float RECOVERY_SECONDS = 30;

    protected const string SENTINEL_HOSTS = '127.0.0.1:26390,127.0.0.1:26391,127.0.0.1:26392';

    /**
     * The container's logger: the package's warnings, one per retry among them.
     */
    protected RecordingLogger $logger;

    /** @var list<Child> */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The servers' namespace is where the announced addresses and failure modes are the real ones: through Docker
        // Desktop's port forwarder, for one, a connection to a stopped server is accepted and then dropped.
        if (getenv('INTEGRATION_CONTAINER') !== '1') {
            $this->fail('The integration tests run in the test container: composer test:integration.');
        }

        $this->ensureCanonicalServers();

        // The connector caches each service's master for the process. After a test that failed over, the reset makes
        // the cached node a replica again, and a cache hit runs no role check.
        (new ReflectionProperty(PhpRedisSentinelConnector::class, 'resolvedMasters'))->setValue(null, []);

        $this->logger = new RecordingLogger;
        ($this->app ?? $this->fail('The application is not booted.'))->instance(LoggerInterface::class, $this->logger);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $child->kill();
        }

        parent::tearDown();
    }

    /**
     * A Sentinel connection opened by Laravel's manager with the package's provider, listing all three sentinels.
     *
     * Typed as what it is: the manager returns the base Connection, whose `@mixin Redis` would resolve scan() to
     * phpredis's own signature instead of Laravel's.
     *
     * @param  array<string, mixed>  $settings  Added to the connection's block.
     * @param  array<string, mixed>  $options  The global options.
     */
    protected function sentinelConnection(array $settings = [], array $options = []): PhpRedisSentinelConnection
    {
        $app = $this->app ?? $this->fail('The application is not booted.');

        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => $options,
            'default' => ['sentinel_hosts' => self::SENTINEL_HOSTS, 'sentinel_service' => Servers::SERVICE] + $settings,
        ]);
        $app->forgetInstance('redis');

        $redis = $app->make('redis');
        $this->assertInstanceOf(RedisManager::class, $redis);

        $connection = $redis->connection('default');
        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $connection);

        return $connection;
    }

    /**
     * Fail over, and wait until the old master reports itself a replica, which it does once it has dropped its
     * clients; returns the new master's port.
     */
    protected function failOverAndWaitForTheDemotion(): int
    {
        $master = Servers::failover();
        Servers::waitUntil(fn (): bool => Servers::role(Servers::MASTER) === 'slave', self::RECOVERY_SECONDS, 'the old master to be demoted');

        return $master;
    }

    /**
     * Run PHP code in another process, with `$redis` connected to the master: a publisher or a pusher, say, that waits
     * for the state it needs and then acts. Code that fails should say why on stderr before a non-zero exit.
     */
    protected function inBackground(string $code): Process
    {
        $process = new Process([PHP_BINARY, '-r', sprintf(
            '$redis = new Redis; $redis->connect(%s, %d); %s',
            var_export(Servers::HOST, true),
            Servers::MASTER,
            $code,
        )]);
        $process->setTimeout(20)->start();

        return $process;
    }

    /**
     * Wait for a process from inBackground() to end, and fail with what it wrote unless it exited with 0.
     */
    protected function assertBackgroundSucceeded(Process $process): void
    {
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), trim($process->getErrorOutput().$process->getOutput()));
    }

    /**
     * Run part of the test in a forked child, killed when the test ends if it is still running.
     *
     * @param  Closure(Closure(string): void): mixed  $body
     */
    protected function fork(Closure $body): Child
    {
        return $this->children[] = Child::run($body);
    }

    /**
     * Empty the servers when their topology is canonical, reset them otherwise, and fail if a reset does not bring them
     * to the canonical state.
     */
    protected function ensureCanonicalServers(): void
    {
        if (Servers::differences() === [] && Servers::flush()) {
            return;
        }

        try {
            Servers::reset();
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
        }

        $differences = Servers::differences();

        if ($differences !== []) {
            $this->fail("The servers are not in their canonical state after a reset:\n- ".implode("\n- ", $differences));
        }
    }
}
