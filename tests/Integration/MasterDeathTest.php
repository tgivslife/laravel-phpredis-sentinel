<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * The master killed outright, as a crash would: no SENTINEL FAILOVER, so the sentinels first have to notice it is down
 * (down-after-milliseconds 5000 in docker/compose.yaml) and then elect a replica. Until then they keep naming the dead
 * master, whose port refuses connections, and every attempt fails fast.
 *
 * The deadline here covers that detection; one shorter than the failover is a separate case.
 */
final class MasterDeathTest extends IntegrationTestCase
{
    private const int DEADLINE_MS = 15_000;

    private const array SETTINGS = ['retry_attempts' => 100, 'retry_delay' => 250, 'retry_deadline' => self::DEADLINE_MS];

    public function test_a_held_connection_recovers_on_the_new_master_within_the_deadline(): void
    {
        $connection = $this->sentinelConnection(self::SETTINGS);
        $this->assertTrue($connection->ping(), 'held: open on the master');

        Servers::kill(Servers::MASTER);
        $started = hrtime(true);
        $this->assertTrue($connection->set('after', 'held'));
        $seconds = (hrtime(true) - $started) / 1e9;

        $master = $this->electedMaster();
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertSame('held', Servers::node($master)->get('after'));
        $this->assertLessThan(self::DEADLINE_MS / 1000, $seconds);
        $this->assertNotSame([], $this->logger->warnings(), 'recovered by retrying, not by chance');
    }

    public function test_a_cold_connection_opened_during_the_outage_opens_on_the_new_master_within_the_deadline(): void
    {
        Servers::kill(Servers::MASTER);

        // Opened after the kill with nothing cached, as a new php-fpm request would be: it recovers while connecting.
        $started = hrtime(true);
        $connection = $this->sentinelConnection(self::SETTINGS);
        $seconds = (hrtime(true) - $started) / 1e9;

        $master = $this->electedMaster();
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertTrue($connection->set('after', 'cold'));
        $this->assertLessThan(self::DEADLINE_MS / 1000, $seconds);
        $this->assertNotSame([], $this->logger->warnings(), 'recovered by retrying, not by chance');
    }

    /**
     * The master the sentinels elected in place of the dead one.
     */
    private function electedMaster(): int
    {
        $master = Servers::namedMaster();
        $this->assertContains($master, Servers::REPLICAS, 'a replica was promoted');

        return $master;
    }
}
