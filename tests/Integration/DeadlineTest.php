<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use Closure;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * A killed master's failover takes the sentinels about 6 s to name a replica (down-after 5 s, then the election).
 * A shorter budget fails the operation visibly and within its bounds, and the operation after the election succeeds.
 */
final class DeadlineTest extends IntegrationTestCase
{
    private const int DEADLINE_MS = 2000;

    private const int RETRY_DELAY_MS = 250;

    /**
     * Attempts to spare, so the deadline is what ends the recovery.
     */
    private const array SHORT_DEADLINE = ['retry_attempts' => 100, 'retry_delay' => self::RETRY_DELAY_MS, 'retry_deadline' => self::DEADLINE_MS];

    /**
     * How far past the deadline a give-up may come: an attempt or a probe under way when it passes is cut to what is
     * left, but not below a millisecond, and the failing work is refused connects, a millisecond each.
     * Measured, it comes before the deadline (1.77 s of 2 s): the loop starts no retry delay that would outlive it.
     */
    private const float OVERSHOOT_SECONDS = 0.5;

    public function test_a_failover_that_outlasts_the_deadline_fails_within_it_and_the_next_operation_recovers(): void
    {
        $connection = $this->sentinelConnection(self::SHORT_DEADLINE);
        $this->assertTrue($connection->ping());

        Servers::kill(Servers::MASTER);
        [$seconds, $failure] = $this->timed(fn () => $connection->set('during', '1'));

        $this->assertNotNull($failure, 'it gave up');
        $this->assertStringContainsString('gave up after', $failure->getMessage());
        $this->assertNotNull($failure->getPrevious(), 'the last failure is attached');
        $this->assertEndedByTheDeadline($seconds);

        $master = $this->waitForTheElection();
        [$seconds, $failure] = $this->timed(fn () => $this->assertTrue($connection->set('after', '1')));

        $this->assertNull($failure);
        $this->assertSame($master, $connection->client()->getPort());
        $this->assertLessThan(self::DEADLINE_MS / 1000, $seconds);
    }

    public function test_a_connection_opened_during_the_outage_fails_within_the_deadline_and_one_opened_after_it_works(): void
    {
        Servers::kill(Servers::MASTER);
        [$seconds, $failure] = $this->timed(fn () => $this->sentinelConnection(self::SHORT_DEADLINE));

        $this->assertNotNull($failure, 'it gave up');
        $this->assertStringContainsString('connect gave up after', $failure->getMessage());
        $this->assertNotNull($failure->getPrevious(), 'the last failure is attached');
        $this->assertEndedByTheDeadline($seconds);

        $master = $this->waitForTheElection();

        $this->assertSame($master, $this->sentinelConnection(self::SHORT_DEADLINE)->client()->getPort());
    }

    /**
     * With the package's defaults, 3 retries 500 ms apart, every operation during the detection fails fast, and the
     * first one after the sentinels name a replica succeeds: the defaults do not ride out a crash, by design.
     */
    public function test_with_the_defaults_operations_fail_fast_until_the_election_and_the_next_one_succeeds(): void
    {
        $connection = $this->sentinelConnection();
        $this->assertTrue($connection->ping());

        Servers::kill(Servers::MASTER);
        $failures = [];
        $until = hrtime(true) + (int) (self::RECOVERY_SECONDS * 1e9);

        do {
            $elected = ! in_array(Servers::namedMaster(), [0, Servers::MASTER], true);
            [$seconds, $failure] = $this->timed(fn () => $connection->set('key', 'value'));

            if ($failure === null) {
                break;
            }

            $failures[] = [$seconds, $failure->getMessage(), $elected];
        } while (hrtime(true) < $until);

        $this->assertNull($failure, 'an operation succeeded after the election');
        $this->assertNotEmpty($failures, 'operations failed during the detection');

        foreach ($failures as [$seconds, $message, $elected]) {
            $this->assertStringContainsString('gave up after 3 retries', $message);
            $this->assertLessThan(3.0, $seconds, 'fails fast: three retries, not the deadline');
            $this->assertFalse($elected, 'none started after the sentinels had named a replica');
        }

        $this->assertContains($connection->client()->getPort(), Servers::REPLICAS);
    }

    /**
     * Fail unless a give-up after the given seconds was the deadline's doing: the loop gives up once the next delay
     * would outlive the deadline, so within about one delay of it, and no later than the overshoot allows.
     */
    private function assertEndedByTheDeadline(float $seconds): void
    {
        $this->assertGreaterThan((self::DEADLINE_MS - 2 * self::RETRY_DELAY_MS) / 1000, $seconds, 'the deadline ended it, not something sooner');
        $this->assertLessThan(self::DEADLINE_MS / 1000 + self::OVERSHOOT_SECONDS, $seconds);
    }

    /**
     * Wait until the sentinels name a replica in place of the killed master; returns its port.
     */
    private function waitForTheElection(): int
    {
        Servers::waitUntil(
            fn (): bool => in_array(Servers::namedMaster(), Servers::REPLICAS, true),
            self::RECOVERY_SECONDS,
            'the sentinels to name a replica',
        );

        return Servers::namedMaster();
    }

    /**
     * Run the code, returning how long it took in seconds and what it threw, or null.
     *
     * @return array{float, ?SentinelFailoverException}
     */
    private function timed(Closure $code): array
    {
        $started = hrtime(true);

        try {
            $code();
            $failure = null;
        } catch (SentinelFailoverException $exception) {
            $failure = $exception;
        }

        return [(hrtime(true) - $started) / 1e9, $failure];
    }
}
