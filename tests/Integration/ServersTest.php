<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use RedisSentinel;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * The preparation every integration test gets from setUp(), run within a test after leaving keys behind or the servers
 * failed over: it brings back the canonical state, and a reset from inside the test container leaves it connected.
 */
final class ServersTest extends IntegrationTestCase
{
    public function test_keys_a_test_left_are_gone_from_every_node_before_the_next(): void
    {
        $master = Servers::node(Servers::MASTER);
        $master->set('left-behind', '1');
        $this->assertSame(count(Servers::REPLICAS), $master->wait(count(Servers::REPLICAS), 1000));

        $this->ensureCanonicalServers();

        foreach ([Servers::MASTER, ...Servers::REPLICAS] as $port) {
            $this->assertSame(0, Servers::node($port)->dbSize(), "Node {$port} still holds keys.");
        }
    }

    public function test_servers_left_failed_over_are_reset_to_the_canonical_state(): void
    {
        $sentinel = Servers::sentinel(Servers::SENTINELS[0]);
        $this->assertTrue($sentinel->failover(Servers::SERVICE));

        $until = hrtime(true) + 10_000_000_000;

        while ($this->namedMaster($sentinel) === Servers::MASTER && hrtime(true) < $until) {
            usleep(100_000);
        }

        $this->assertContains($this->namedMaster($sentinel), Servers::REPLICAS, 'The failover did not happen within 10 s.');
        $this->assertNotSame([], Servers::differences());

        $this->ensureCanonicalServers();

        $this->assertSame([], Servers::differences());
    }

    /**
     * The port the sentinel currently names as the master's.
     */
    private function namedMaster(RedisSentinel $sentinel): int
    {
        $address = $sentinel->getMasterAddrByName(Servers::SERVICE);

        return is_array($address) ? (int) $address[1] : 0;
    }
}
