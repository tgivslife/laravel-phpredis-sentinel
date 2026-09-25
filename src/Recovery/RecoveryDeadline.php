<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Recovery;

/**
 * The instant a failover recovery must be over by, on the monotonic clock.
 *
 * One absolute instant, handed down from {@see SentinelRetryPolicy::run()} through rediscovery and into every
 * socket wait, so each phase measures what is left when it starts rather than inheriting a budget computed
 * earlier. Null in the callbacks' signatures means unbounded: a policy without a deadline, or a blocking operation.
 *
 * @internal
 */
final readonly class RecoveryDeadline
{
    /**
     * The shortest socket timeout ever handed out, in seconds: phpredis reads zero as "wait forever".
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
     * Milliseconds left, never negative.
     *
     * Rounded down, so it reaches 0 up to a millisecond before the deadline passes: use spent() to test expiry,
     * and never hand this to phpredis, which reads 0 as no limit (clamp() is for socket timeouts).
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
     * A configured value of zero or less means unbounded and becomes the remainder outright. Never zero on the way
     * out, since that would mean unbounded again: a spent deadline yields the minimum, and callers that must not
     * start work on a spent deadline check spent() first.
     */
    public function clamp(float $configuredSeconds): float
    {
        $remainingSeconds = max($this->at - $this->clock->now(), 0) / 1_000_000_000;

        $clamped = $configuredSeconds > 0 ? min($configuredSeconds, $remainingSeconds) : $remainingSeconds;

        return max($clamped, self::MINIMUM_TIMEOUT_SECONDS);
    }
}
