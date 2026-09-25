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
     * @param  int  $at  Nanoseconds on the hrtime() clock.
     */
    public function __construct(private int $at) {}

    /**
     * A deadline the given number of milliseconds after an hrtime() instant.
     */
    public static function after(int $startedAtNs, int $milliseconds): self
    {
        return new self($startedAtNs + $milliseconds * 1_000_000);
    }

    /**
     * Milliseconds left, never negative.
     *
     * Rounded down, so it reaches 0 up to a millisecond before the deadline passes: use spent() to test expiry,
     * and never hand this to phpredis, which reads 0 as no limit (clamp() is for socket timeouts).
     */
    public function remainingMs(): int
    {
        return max(0, intdiv($this->at - (int) hrtime(true), 1_000_000));
    }

    public function spent(): bool
    {
        return (int) hrtime(true) >= $this->at;
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
        $remainingSeconds = max($this->at - (int) hrtime(true), 0) / 1_000_000_000;

        $clamped = $configuredSeconds > 0 ? min($configuredSeconds, $remainingSeconds) : $remainingSeconds;

        return max($clamped, self::MINIMUM_TIMEOUT_SECONDS);
    }
}
