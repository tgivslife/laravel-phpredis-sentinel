<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Recovery;

use Tgi\LaravelPhpRedisSentinel\Support\ReadTimeout;
use ValueError;

/**
 * The instant a failover recovery must be over by, on the monotonic clock.
 *
 * An absolute instant, handed down from {@see SentinelRetryPolicy::run()} through rediscovery and into every
 * socket wait, so each phase measures what is left when it starts rather than inheriting a budget computed earlier.
 * Only an operation's expected wait moves it later ({@see self::extendedBy()}).
 * Null in the callbacks' signatures means unbounded: a policy without a deadline, or a blocking operation.
 *
 * @internal
 */
final readonly class RecoveryDeadline
{
    /**
     * The shortest socket timeout ever handed out, in seconds: phpredis never reads zero as a short wait.
     */
    private const float MINIMUM_TIMEOUT_SECONDS = 0.001;

    /**
     * @param  MonotonicClock  $clock  The clock every reading of what is left is taken from.
     * @param  int  $at  Nanoseconds on that clock.
     */
    public function __construct(private MonotonicClock $clock, private int $at) {}

    /**
     * A deadline the given number of milliseconds after an instant read from the clock.
     *
     * @param  int  $startedAtNs  A reading of $clock itself; an instant from another clock (hrtime() beside a stand-in)
     *                            puts the deadline anywhere.
     */
    public static function after(MonotonicClock $clock, int $startedAtNs, int $milliseconds): self
    {
        return new self($clock, $startedAtNs + $milliseconds * 1_000_000);
    }

    /**
     * The same deadline moved later by the given number of milliseconds; this one when there is nothing to add.
     *
     * @throws ValueError When the extension is negative: a deadline is never moved earlier.
     */
    public function extendedBy(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new ValueError('A recovery deadline cannot be moved earlier.');
        }

        return $milliseconds === 0 ? $this : new self($this->clock, $this->at + $milliseconds * 1_000_000);
    }

    /**
     * Milliseconds left, never negative.
     *
     * Rounded down, so it reaches 0 up to a millisecond before the deadline passes: use spent() to test expiry,
     * and never hand this to phpredis, which gives 0 meanings of its own (clamp() is for socket timeouts).
     */
    public function remainingMs(): int
    {
        return max(0, intdiv($this->at - $this->clock->now(), 1_000_000));
    }

    public function spent(): bool
    {
        return $this->clock->now() >= $this->at;
    }

    /**
     * A socket timeout cut down to what is left, in seconds.
     *
     * A negative value, phpredis's no limit, becomes the remainder outright. Callers do not pass 0, which phpredis
     * gives meanings of its own (`default_socket_timeout` when connecting, failing at once on a live socket): the
     * configured timeouts are positive, and a client's own read timeout goes through {@see ReadTimeout::effective()}.
     * Never zero on the way out, for the same reason: a spent deadline yields the minimum,
     * and callers that must not start work on a spent deadline check spent() first.
     */
    public function clamp(float $configuredSeconds): float
    {
        $remainingSeconds = max($this->at - $this->clock->now(), 0) / 1_000_000_000;

        $clamped = $configuredSeconds > 0 ? min($configuredSeconds, $remainingSeconds) : $remainingSeconds;

        return max($clamped, self::MINIMUM_TIMEOUT_SECONDS);
    }
}
