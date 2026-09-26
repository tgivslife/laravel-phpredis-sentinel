<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Recovery;

use ErrorException;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RedisException;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Connectors\PhpRedisSentinelConnector;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Throwable;

/**
 * The failover retry budget, and the one definition of what "the master moved" looks like.
 *
 * {@see PhpRedisSentinelConnector} runs opening a connection through it and {@see PhpRedisSentinelConnection} every command.
 * Opening needs it too: php-fpm rebuilds every connection per request, so a request that arrives during an election
 * fails while connecting and never reaches the command loop.
 *
 * `attempts` caps the re-runs and `deadlineMs` the wall clock. The deadline is the bound that matters, since one
 * attempt against a dead master can cost a connect timeout, a read timeout, phpredis' own retries and a sweep of every sentinel.
 * It is one instant ({@see RecoveryDeadline}), moved later only by an operation's expected wait ({@see self::run()}).
 *
 * Once it has passed, no package-controlled work starts: no attempt, rediscovery, sentinel probe or client setup stage.
 * Socket waits are cut to what is left, except in blocking operations ({@see self::forBlockingOperations()}).
 * It cannot cut a wait already under way, or work inside phpredis (DNS, TCP and TLS setup, its own reconnects and backoff),
 * so a recovery can end after the deadline; by how much has not been measured, and no maximum is promised.
 *
 * @internal
 */
final class SentinelRetryPolicy
{
    /**
     * Message fragments that mean "the master is gone: rediscover and retry", matched case-insensitively on a
     * RedisException or an ErrorException.
     *
     * Includes every fragment Laravel's PhpRedisConnection::command() recovers from, since the connection bypasses
     * that loop, except the RedisCluster-only `Error processing response from Redis node`.
     *
     * `NOREPLICAS` is left out on purpose: after a failover the new master has no replica until the old one
     * resyncs, which outlasts any budget, so retrying would only add the deadline to every write.
     *
     * @var list<string>
     */
    private const array RETRYABLE_ERROR_FRAGMENTS = [
        "can't write against a read only replica",
        'broken pipe',
        'connection closed',
        'connection lost',
        'connection refused',
        'connection reset',
        'connection timed out',
        'error while reading',
        'failed while reconnecting',
        'getaddrinfo',
        'is loading the dataset in memory',
        'masterdown',
        'name or service not known',
        'php_network_getaddresses',
        'read error on connection',
        'readonly',
        'socket',
        'went away',
    ];

    /**
     * @param  LoggerInterface  $logger  Receives a warning for every retry.
     * @param  int  $attempts  Retries after the initial failure (`retry_attempts`).
     * @param  int  $delayMs  Wait between attempts in milliseconds (`retry_delay`).
     * @param  int  $deadlineMs  Total wall clock for one recovery (`retry_deadline`); 0 disables the bound.
     * @param  bool  $blocking  Whether the operation blocks indefinitely by design; see forBlockingOperations().
     * @param  MonotonicClock  $clock  Measures the budget and waits out the delay.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $attempts = 3,
        private readonly int $delayMs = 500,
        private readonly int $deadlineMs = 5000,
        private readonly bool $blocking = false,
        private readonly MonotonicClock $clock = new SystemClock,
    ) {}

    /**
     * Build the policy from a `database.redis.*` connection configuration.
     *
     * @param  array<string, mixed>  $config  The connection configuration, discovery keys included.
     *
     * @throws RuntimeException When a retry setting is not a scalar.
     */
    public static function fromConfig(array $config, LoggerInterface $logger, MonotonicClock $clock = new SystemClock): self
    {
        return new self(
            $logger,
            max(self::intSetting($config, 'retry_attempts', 3), 0),
            max(self::intSetting($config, 'retry_delay', 500), 0),
            max(self::intSetting($config, 'retry_deadline', 5000), 0),
            clock: $clock,
        );
    }

    /**
     * A copy for operations that block by design, (p)subscribe so far, keeping the logger and the clock.
     *
     * The budget bounds one recovery, but a subscriber's run() lasts as long as the process. So the copy enforces no
     * deadline, which a long healthy subscription would otherwise have spent before its first failover, and resets
     * the attempt count after an attempt long enough to have been working, or a subscriber would survive only
     * `attempts` failovers in its lifetime.
     */
    public function forBlockingOperations(): self
    {
        return new self($this->logger, $this->attempts, $this->delayMs, $this->deadlineMs, blocking: true, clock: $this->clock);
    }

    /**
     * Run an operation, re-running it while its failure looks like a failover.
     *
     * Both callables get the recovery deadline (null when unbounded) to clamp their socket waits to; a rediscovery
     * that spends it ends the loop. An operation that waits by design (a blocking pop) passes its expected wait:
     * each attempt's deadline moves later by it, and what an attempt spent, up to that wait, is not recovery time.
     *
     * @template TResult
     *
     * @param  callable(?RecoveryDeadline): TResult  $operation  The client operation.
     * @param  callable(?RecoveryDeadline): void  $onRetry  Runs between attempts; forced rediscovery lives here.
     * @param  string  $context  Names the caller in the log lines and the give-up message.
     * @param  int<0, max>  $expectedWaitMs  How long each attempt is expected to wait, in milliseconds.
     * @return TResult The first successful result.
     *
     * @throws SentinelFailoverException When the attempt budget or the deadline is spent.
     * @throws Throwable Anything that is not failover-class, propagated untouched.
     */
    public function run(callable $operation, callable $onRetry, string $context, int $expectedWaitMs = 0): mixed
    {
        $attempts = 0;
        $startedAt = $this->clock->now();
        $deadline = $this->deadlineFrom($startedAt);

        while (true) {
            $attemptStartedAt = $this->clock->now();

            try {
                // The attempt may wait its whole expected wait, so it gets that much more for its own socket waits.
                return $operation($deadline?->extendedBy($expectedWaitMs));
            } catch (Throwable $exception) {
                if (! $this->isRetryable($exception)) {
                    throw $exception;
                }

                // Only the time the attempt actually took, up to its expected wait, is kept off the recovery.
                $deadline = $deadline?->extendedBy(max(0, min($expectedWaitMs, $this->elapsedMs($attemptStartedAt))));

                if ($this->attemptDidWork($attemptStartedAt)) {
                    $attempts = 0;
                    $startedAt = $this->clock->now();
                }

                $elapsedMs = $this->elapsedMs($startedAt);

                if (RetrySuppression::active() || $attempts >= $this->attempts || $this->deadlineWouldPass($deadline)) {
                    throw $this->exhausted($context, $attempts, $elapsedMs, $exception);
                }

                $attempts++;

                $this->logger->warning(sprintf(
                    'Redis sentinel %s: retryable failure (attempt %d/%d, %dms elapsed), rediscovering master: %s',
                    $context,
                    $attempts,
                    $this->attempts,
                    $elapsedMs,
                    $exception->getMessage(),
                ));

                if ($this->delayMs > 0) {
                    $this->clock->sleep($this->delayMs);
                }

                // A sleep only promises a minimum: one that ran past the deadline starts no rediscovery.
                if ($deadline?->spent()) {
                    throw $this->exhausted($context, $attempts, $this->elapsedMs($startedAt), $exception);
                }

                $onRetry($deadline);

                if ($deadline?->spent()) {
                    throw $this->exhausted($context, $attempts, $this->elapsedMs($startedAt), $exception);
                }
            }
        }
    }

    /**
     * Whether the failure signals master loss or demotion rather than an application error.
     */
    public function isRetryable(Throwable $exception): bool
    {
        // A budget that is already spent cannot be spent again; guards against nesting two policies.
        if ($exception instanceof SentinelFailoverException) {
            return false;
        }

        if ($exception instanceof SentinelDiscoveryException) {
            return $exception->anySentinelAnswered;
        }

        // phpredis on Linux reports a failed mid-write send as a notice, which Laravel turns into an ErrorException.
        if (! $exception instanceof RedisException && ! $exception instanceof ErrorException) {
            return false;
        }

        return Str::contains($exception->getMessage(), self::RETRYABLE_ERROR_FRAGMENTS, ignoreCase: true);
    }

    /**
     * The deadline for a run starting at the given instant; null when there is no bound or the operation blocks.
     */
    private function deadlineFrom(int $startedAtNs): ?RecoveryDeadline
    {
        return ! $this->blocking && $this->deadlineMs > 0
            ? RecoveryDeadline::after($this->clock, $startedAtNs, $this->deadlineMs)
            : null;
    }

    private function exhausted(string $context, int $attempts, int $elapsedMs, Throwable $exception): SentinelFailoverException
    {
        return new SentinelFailoverException(sprintf(
            'Redis sentinel %s gave up after %d %s and %dms: %s',
            $context,
            $attempts,
            $attempts === 1 ? 'retry' : 'retries',
            $elapsedMs,
            $exception->getMessage(),
        ), 0, $exception);
    }

    /**
     * Whether another attempt, counting the delay before it, would outlive the deadline.
     * Never without one: blocking operations, whose elapsed time is healthy subscription time, and a budget with no wall-clock bound.
     */
    private function deadlineWouldPass(?RecoveryDeadline $deadline): bool
    {
        return $deadline !== null && $this->delayMs >= $deadline->remainingMs();
    }

    /**
     * Whether a failed blocking attempt had been working rather than failing to start: it outlived the whole
     * recovery budget (at least 1 s), so it cannot have been recovery. A misjudgement costs one retry at most.
     *
     * Ordinary commands never qualify: one that runs this long and then fails is what the deadline exists to stop.
     */
    private function attemptDidWork(int $attemptStartedAt): bool
    {
        return $this->blocking && $this->elapsedMs($attemptStartedAt) >= max($this->deadlineMs, 1000);
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv($this->clock->now() - $startedAt, 1_000_000);
    }

    /**
     * An integer setting, cast as before; anything that is not a scalar is refused rather than cast.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException When the setting is not a scalar.
     */
    private static function intSetting(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (! is_scalar($value)) {
            throw new RuntimeException(sprintf('%s must be a number, %s given.', $key, get_debug_type($value)));
        }

        return (int) $value;
    }
}
