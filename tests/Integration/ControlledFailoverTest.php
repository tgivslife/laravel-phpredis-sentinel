<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Redis;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Child;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * A connection held across SENTINEL FAILOVER. The waits without a limit of their own, a subscription and a blocking
 * pop with timeout 0, run in a forked child that the test waits on with a limit; the bounded operations run here.
 *
 * The sentinels promote a replica at once but demote the old master, dropping its clients, only on their next check
 * of it, about 10 s later; until then a held client keeps working on the old master. The bounded operations therefore
 * run once the old master reports itself a replica.
 */
final class ControlledFailoverTest extends IntegrationTestCase
{
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

    public function test_a_held_pop_with_a_timeout_moves_to_the_new_master_within_its_wait(): void
    {
        $child = $this->fork(function (Closure $progress): array {
            $connection = $this->sentinelConnection(['read_timeout' => 1.0]);
            $started = hrtime(true);
            $popped = $connection->blpop('jobs', 30);

            return [
                'popped' => $popped,
                'seconds' => (hrtime(true) - $started) / 1e9,
                'port' => $connection->client()->getPort(),
                'warnings' => $this->logger->warnings(),
            ];
        });
        Servers::waitUntil(fn (): bool => Servers::blockedClients(Servers::MASTER) > 0, 5, 'the pop to block on the master');

        $master = Servers::failover();
        Servers::waitUntil(fn (): bool => Servers::blockedClients($master) > 0, self::RECOVERY_SECONDS, 'the pop to block on the new master');
        Servers::node($master)->rpush('jobs', 'job');
        $result = $child->result(5);

        // About 11 s spent waiting on the old master are credited back, so the recovery still has its deadline.
        $this->assertSame(['jobs', 'job'], $result['popped']);
        $this->assertLessThan(30, $result['seconds']);
        $this->assertSame($master, $result['port']);
        $this->assertCount(1, $result['warnings'], implode("\n", $result['warnings']));
    }

    public function test_a_pop_on_a_stale_cached_master_is_retried_on_the_new_master(): void
    {
        // Caches 6390 as the service's master for this process.
        $this->sentinelConnection();
        $master = $this->failOverAndWaitForTheDemotion();
        Servers::node($master)->rpush('jobs', 'job');

        // Opened after the demotion, before anything refreshed the cache: the cached address, now a replica, with no
        // role check. Its pop gets READONLY, which phpredis leaves behind an empty reply. A held connection gets
        // `Connection lost` instead: with max_retries 0, phpredis does not reconnect it to the demoted node by itself.
        $connection = $this->sentinelConnection();
        $this->assertSame(Servers::MASTER, $connection->client()->getPort(), 'on the stale cached address');

        $this->assertSame(['jobs', 'job'], $connection->blpop('jobs', 5));
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertCount(1, $this->logger->warnings());
        $this->assertStringContainsString('READONLY', $this->logger->warnings()[0]);
    }

    public function test_held_commands_a_pipeline_and_a_transaction_heal_on_the_new_master(): void
    {
        $command = $this->sentinelConnection();
        $pipeline = $this->sentinelConnection();
        $transaction = $this->sentinelConnection();

        foreach ([$command, $pipeline, $transaction] as $connection) {
            $this->assertTrue($connection->ping(), 'held: open on the old master');
        }

        $master = $this->failOverAndWaitForTheDemotion();

        $this->assertTrue($command->set('after', 'command'));
        $this->assertCount(1, $this->logger->warnings());

        $this->assertSame([true, 'pipeline'], $pipeline->pipeline(function (Redis $pipe): void {
            $pipe->set('piped', 'pipeline');
            $pipe->get('piped');
        }));
        $this->assertCount(2, $this->logger->warnings());

        $this->assertSame([true, 'transaction'], $transaction->transaction(function (Redis $multi): void {
            $multi->set('transacted', 'transaction');
            $multi->get('transacted');
        }));
        $this->assertCount(3, $this->logger->warnings());

        foreach ([$command, $pipeline, $transaction] as $connection) {
            $this->assertSame($master, $connection->client()->getPort());
        }

        // The count alone would pass a reconnect to the demoted node too: one READONLY there, then the same retry.
        foreach ($this->logger->warnings() as $warning) {
            $this->assertStringContainsString('Connection lost', $warning, 'the held socket failed; phpredis did not reconnect it to the demoted node');
        }

        $this->assertSame(['command', 'pipeline', 'transaction'], Servers::node($master)->mget(['after', 'piped', 'transacted']));
    }

    public function test_scans_interrupted_by_a_failover_return_every_key_at_least_once(): void
    {
        $scanning = $this->sentinelConnection();
        $zscanning = $this->sentinelConnection();

        $keys = array_map(fn (int $index): string => "scanned:{$index}", range(1, 2000));
        $scanning->mset(array_fill_keys($keys, '1'));
        $members = array_map(fn (int $index): string => "member:{$index}", range(1, 2000));
        $scanning->zadd('ranked', array_fill_keys($members, 1));
        $this->assertSame(2, $scanning->client()->wait(2, 1000), 'on both replicas before the failover');

        // Two pages each on the old master, then the rest after the failover.
        $scan = fn (mixed $cursor) => $scanning->scan($cursor, ['match' => 'scanned:*', 'count' => 50]);
        $zscan = fn (mixed $cursor) => $zscanning->zscan('ranked', $cursor, ['count' => 50]);
        [$scanCursor, $scanned] = $this->pages($scan, null, 2);
        [$zscanCursor, $zscanned] = $this->pages($zscan, null, 2);
        $this->assertNotSame(0, (int) $scanCursor, 'mid-iteration');
        $this->assertNotSame(0, (int) $zscanCursor, 'mid-iteration');

        $master = $this->failOverAndWaitForTheDemotion();

        // The restart returns some keys again; each must be there at least once.
        [, $rest] = $this->pages($scan, $scanCursor);
        $this->assertEqualsCanonicalizing($keys, array_values(array_unique([...$scanned, ...$rest])));
        [, $rest] = $this->pages($zscan, $zscanCursor);
        $this->assertEqualsCanonicalizing($members, array_values(array_unique([...array_keys($zscanned), ...array_keys($rest)])));

        $this->assertSame($master, $scanning->client()->getPort());
        $this->assertSame($master, $zscanning->client()->getPort());
        $restarts = array_filter($this->logger->warnings(), fn (string $warning): bool => str_contains($warning, 'scan restarted'));
        $this->assertCount(2, $restarts, implode("\n", $this->logger->warnings()));
    }

    /**
     * Scan pages from the cursor, Laravel's way (RedisStore::tags()), until the scan ends or the given number of pages
     * is read; returns the cursor it stopped at and everything the pages returned, merged.
     *
     * @param  Closure(mixed): mixed  $scan  Reads one page from a cursor.
     * @return array{mixed, array<array-key, mixed>}
     */
    private function pages(Closure $scan, mixed $cursor, int $limit = PHP_INT_MAX): array
    {
        $found = [];

        for ($page = 0; $page < $limit; $page++) {
            $result = $scan($cursor);

            if (! is_array($result)) {
                break;
            }

            [$cursor, $items] = $result;
            $found = array_is_list($items) ? [...$found, ...$items] : $found + $items;

            if ((int) $cursor === 0) {
                break;
            }
        }

        return [$cursor, $found];
    }

    /**
     * A child holding a subscription to `news`, with a 1 s read timeout, until the given message arrives.
     * Its result: the messages received, the port it ended on, the warnings logged and the read timeout afterward.
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
