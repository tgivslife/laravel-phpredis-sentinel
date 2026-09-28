<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Redis;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Child;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * A connection held across SENTINEL FAILOVER. The waits without a limit of their own, a subscription and a blocking
 * pop with timeout 0, run in a forked child that the test waits on with a limit.
 *
 * The sentinels promote a replica at once but demote the old master, dropping its clients, only on their next check
 * of it, about 10 s later; until then a held client keeps working on the old master.
 */
final class ControlledFailoverTest extends IntegrationTestCase
{
    /**
     * Seconds to wait for a held client to reach the new master: the demotion, then the retry.
     */
    private const float RECOVERY_SECONDS = 30;

    public function test_a_held_subscriber_moves_to_the_new_master(): void
    {
        $child = $this->subscriber('after');
        $this->waitForSubscriber(Servers::MASTER);
        Servers::node(Servers::MASTER)->publish('news', 'before');
        $child->waitFor('received before', 5);

        $master = Servers::failover();
        $this->waitForSubscriber($master);
        Servers::node($master)->publish('news', 'after');
        $result = $child->result(5);

        $this->assertSame(['before', 'after'], $result['received']);
        $this->assertSame($master, $result['port']);
        $this->assertCount(1, $result['warnings'], implode("\n", $result['warnings']));
        $this->assertSame(1.0, $result['read_timeout']);
    }

    public function test_a_held_subscriber_survives_two_separate_failovers(): void
    {
        $child = $this->subscriber('after second');
        $this->waitForSubscriber(Servers::MASTER);

        $first = Servers::failover();
        $this->waitForSubscriber($first);
        Servers::node($first)->publish('news', 'after first');
        $child->waitFor('received after first', 5);

        // The subscription on the first new master lives until the second demotion, about 10 s: longer than
        // max(retry_deadline, 1 s), so the attempt count starts over for the second incident.
        Servers::waitUntil(Servers::readyForFailover(...), self::RECOVERY_SECONDS, 'the servers to be ready for another failover');
        $second = Servers::failover();
        $this->waitForSubscriber($second);
        Servers::node($second)->publish('news', 'after second');
        $result = $child->result(5);

        $this->assertNotSame($first, $second);
        $this->assertSame(['after first', 'after second'], $result['received']);
        $this->assertCount(2, $result['warnings'], implode("\n", $result['warnings']));
        $this->assertStringContainsString('(attempt 1/3,', $result['warnings'][1], 'a new incident, not a second attempt');
    }

    public function test_a_held_pop_without_a_timeout_moves_to_the_new_master(): void
    {
        $child = $this->fork(function (Closure $progress): array {
            $connection = $this->sentinelConnection(['read_timeout' => 1.0]);
            $progress('popping');
            $popped = $connection->blpop('jobs', 0);

            return [
                'popped' => $popped,
                'port' => $connection->client()->getPort(),
                'warnings' => $this->logger->warnings(),
            ];
        });
        Servers::waitUntil(fn (): bool => Servers::blockedClients(Servers::MASTER) > 0, 5, 'the pop to block on the master');

        $master = Servers::failover();
        Servers::waitUntil(fn (): bool => Servers::blockedClients($master) > 0, self::RECOVERY_SECONDS, 'the pop to block on the new master');
        Servers::node($master)->rpush('jobs', 'job');
        $result = $child->result(5);

        $this->assertSame(['jobs', 'job'], $result['popped']);
        $this->assertSame($master, $result['port']);
        $this->assertCount(1, $result['warnings'], implode("\n", $result['warnings']));
    }

    /**
     * A child holding a subscription to `news`, with a 1 s read timeout, until the given message arrives. Its result:
     * the messages received, the port it ended on, the warnings logged and the read timeout afterward.
     */
    private function subscriber(string $last): Child
    {
        return $this->fork(function (Closure $progress) use ($last): array {
            $connection = $this->sentinelConnection(['read_timeout' => 1.0]);
            $received = [];

            $connection->subscribe('news', function (string $message) use ($connection, $progress, $last, &$received): void {
                $received[] = $message;
                $progress("received {$message}");

                if ($message === $last) {
                    $connection->client()->unsubscribe(['news']);
                }
            });

            return [
                'received' => $received,
                'port' => $connection->client()->getPort(),
                'warnings' => $this->logger->warnings(),
                'read_timeout' => $connection->client()->getOption(Redis::OPT_READ_TIMEOUT),
            ];
        });
    }

    /**
     * Wait until the data node on the given port has a subscriber to `news`.
     */
    private function waitForSubscriber(int $port): void
    {
        Servers::waitUntil(fn (): bool => Servers::subscribers($port, 'news') > 0, self::RECOVERY_SECONDS, "a subscriber on {$port}");
    }
}
