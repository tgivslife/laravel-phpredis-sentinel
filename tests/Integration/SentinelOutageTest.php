<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * Sentinels, and a replica, out of service, in the two ways a server can be: stopped, when its port refuses at once,
 * or paused, when its port accepts and never answers, so every probe waits out `sentinel_timeout` (0.5 s by default).
 */
final class SentinelOutageTest extends IntegrationTestCase
{
    public function test_with_the_first_sentinel_stopped_a_connection_opens_through_the_next_at_once(): void
    {
        Servers::stop(Servers::SENTINELS[0]);

        $seconds = $this->seconds(fn () => $this->assertOpenOnTheMaster());

        $this->assertLessThan(0.5, $seconds);
        $this->assertSame([], $this->logger->warnings(), 'a probe that fails is not a retry');
    }

    public function test_with_the_first_sentinel_paused_a_connection_opens_through_the_next_after_its_timeout(): void
    {
        Servers::pause(Servers::SENTINELS[0]);

        $seconds = $this->seconds(fn () => $this->assertOpenOnTheMaster());

        $this->assertGreaterThanOrEqual(0.5, $seconds, 'the first probe waited out its timeout');
        $this->assertLessThan(1.0, $seconds);
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_with_every_sentinel_stopped_opening_fails_at_once_naming_every_sentinel(): void
    {
        Servers::stop(...Servers::SENTINELS);

        $seconds = $this->seconds(fn () => $this->assertOpeningFailsNamingEverySentinel());

        $this->assertLessThan(0.5, $seconds);
    }

    public function test_with_every_sentinel_paused_opening_fails_once_every_probe_times_out_naming_every_sentinel(): void
    {
        Servers::pause(...Servers::SENTINELS);

        $seconds = $this->seconds(fn () => $this->assertOpeningFailsNamingEverySentinel());

        $this->assertGreaterThanOrEqual(1.5, $seconds, 'three probes of 0.5 s');
        $this->assertLessThan(2.5, $seconds);
    }

    /**
     * A held connection that ran out its budget while its node hung, and so is flagged stale, keeps working on that
     * node once it answers again, although no sentinel does: the rebuild finds none answering, and the old client
     * gets the attempt. Only that operation pays the sentinel round; the next one runs at once.
     */
    public function test_with_every_sentinel_paused_a_held_stale_connection_keeps_working_on_its_node(): void
    {
        $connection = $this->sentinelConnection(['read_timeout' => 0.5, 'retry_attempts' => 1]);
        $this->assertTrue($connection->ping());

        Servers::pause(...Servers::SENTINELS);
        Servers::pause(Servers::MASTER);

        try {
            $connection->ping();
            $this->fail('Expected the budget to run out while the node hangs.');
        } catch (SentinelFailoverException) {
            // Flagged stale on the way out.
        }

        Servers::unpause(Servers::MASTER);
        $warnings = count($this->logger->warnings());

        $rebuilding = $this->seconds(fn () => $this->assertTrue($connection->ping()));
        $next = $this->seconds(fn () => $this->assertTrue($connection->ping()));

        $this->assertGreaterThanOrEqual(1.5, $rebuilding, 'the rebuild waited out every probe');
        $this->assertLessThan(2.5, $rebuilding, 'one sentinel round, then the old client at once');
        $this->assertCount($warnings, $this->logger->warnings(), 'the old client worked on its first attempt');
        $this->assertLessThan(0.2, $next, 'no sentinel round before the next operation');
        $this->assertSame(Servers::MASTER, $connection->client()->getPort());
    }

    /**
     * A sentinel that does not monitor the service answers, with no master: the next one is asked.
     */
    public function test_an_unknown_service_on_the_first_sentinel_does_not_stop_discovery(): void
    {
        // Changes a sentinel's configuration, which differences() sees, so the next test's setUp() resets it.
        $this->assertTrue(Servers::node(Servers::SENTINELS[0])->rawCommand('SENTINEL', 'REMOVE', Servers::SERVICE));

        $this->assertOpenOnTheMaster();
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_with_a_replica_stopped_a_failover_promotes_the_other_and_a_held_connection_follows(): void
    {
        $connection = $this->sentinelConnection();
        $this->assertTrue($connection->ping());

        Servers::stop(Servers::REPLICAS[0]);
        $this->assertTrue($connection->set('before', '1'), 'the master is unaffected');
        $this->assertSame([], $this->logger->warnings());

        $master = $this->failOverAndWaitForTheDemotion();

        $this->assertSame(Servers::REPLICAS[1], $master, 'the replica still up');
        $this->assertTrue($connection->set('after', '1'));
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertCount(1, $this->logger->warnings(), implode("\n", $this->logger->warnings()));
    }

    private function assertOpenOnTheMaster(): void
    {
        $this->assertSame(Servers::MASTER, $this->sentinelConnection()->client()->getPort());
    }

    private function assertOpeningFailsNamingEverySentinel(): void
    {
        try {
            $this->sentinelConnection();
            $this->fail('Expected opening to fail.');
        } catch (SentinelDiscoveryException $exception) {
            $this->assertFalse($exception->anySentinelAnswered);

            foreach (Servers::SENTINELS as $port) {
                $this->assertStringContainsString(Servers::HOST.":{$port} (", $exception->getMessage());
            }
        }

        $this->assertSame([], $this->logger->warnings(), 'not retried');
    }

    /**
     * How long the given code takes to run, in seconds.
     */
    private function seconds(Closure $code): float
    {
        $started = hrtime(true);
        $code();

        return (hrtime(true) - $started) / 1e9;
    }
}
