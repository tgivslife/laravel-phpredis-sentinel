<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Connections;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Psr\Log\LoggerInterface;
use Redis;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Recovery\RecoveryDeadline;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Throwable;

/**
 * A phpredis connection that survives a Sentinel failover inside the failing command.
 *
 * Laravel's own recovery rebuilds the client from the address it already has, which after a failover is the old master.
 * Here every entry point that talks to the client directly runs in the retry policy's loop instead, and a retry rebuilds
 * the client through the connector with a forced rediscovery.
 * Every other command - `__call`, `eval`, `flushdb` and the rest - already goes through command(), and wrapping it again would nest two loops.
 *
 * A retried write whose reply was lost may run twice, and a retried pipeline replays the whole batch.
 *
 * @internal
 */
final class PhpRedisSentinelConnection extends PhpRedisConnection
{
    /**
     * Whether an exhausted budget left the client dead, to be rebuilt before the next operation.
     */
    private bool $clientIsStale = false;

    /**
     * The connector, kept with its real signature: Laravel's `$connector` property is documented as a bare callable.
     *
     * @var (Closure(bool=, ?RecoveryDeadline=): Redis)|null
     */
    private readonly ?Closure $rediscover;

    /**
     * The connector takes a refresh flag, `true` forcing a fresh discovery, and the deadline that clamps its waits.
     *
     * @param  Redis  $client  The connected phpredis client.
     * @param  (callable(bool=, ?RecoveryDeadline=): Redis)|null  $connector  Builds a client.
     * @param  array<string, mixed>  $config  The connection configuration, discovery keys already stripped.
     * @param  SentinelRetryPolicy  $retryPolicy  The failover budget shared with the connector.
     * @param  LoggerInterface  $logger  Receives a warning when a rediscovery fails.
     */
    public function __construct(
        $client,
        ?callable $connector,
        array $config,
        private readonly SentinelRetryPolicy $retryPolicy,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($client, $connector, $config);

        $this->rediscover = $connector === null ? null : Closure::fromCallable($connector);
    }

    /**
     * {@inheritdoc}
     *
     * Calls Connection::command() rather than PhpRedisConnection::command(): Laravel's loop rebuilds the client
     * from the cached address and retries there, which would charge extra connects to the deadline without ever
     * rediscovering the master.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     */
    public function command($method, array $parameters = [])
    {
        return $this->retryOnFailure(fn () => Connection::command($method, $parameters));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function scan($cursor, $options = [])
    {
        return $this->retryOnFailure(fn () => parent::scan($cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function zscan($key, $cursor, $options = [])
    {
        return $this->retryOnFailure(fn () => parent::zscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function hscan($key, $cursor, $options = [])
    {
        return $this->retryOnFailure(fn () => parent::hscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function sscan($key, $cursor, $options = [])
    {
        return $this->retryOnFailure(fn () => parent::sscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * A retry re-runs the whole pipeline.
     *
     * @return Redis|array<array-key, mixed>
     */
    public function pipeline(?callable $callback = null)
    {
        return $this->retryOnFailure(fn () => parent::pipeline($callback));
    }

    /**
     * {@inheritdoc}
     *
     * @return Redis|array<array-key, mixed>
     */
    public function transaction(?callable $callback = null)
    {
        return $this->retryOnFailure(fn () => parent::transaction($callback));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<array-key, string>|string  $channels
     */
    public function subscribe($channels, Closure $callback)
    {
        $this->retryOnFailure(
            function () use ($channels, $callback): void {
                $this->withoutReadTimeout(fn () => parent::subscribe($channels, $callback));
            },
            $this->retryPolicy->forBlockingOperations(),
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<array-key, string>|string  $channels
     */
    public function psubscribe($channels, Closure $callback)
    {
        $this->retryOnFailure(
            function () use ($channels, $callback): void {
                $this->withoutReadTimeout(fn () => parent::psubscribe($channels, $callback));
            },
            $this->retryPolicy->forBlockingOperations(),
        );
    }

    /**
     * {@inheritdoc}
     *
     * Runs the callback once. Laravel retries opening a pipeline or transaction on a client rebuilt from the cached
     * address; inside retryOnFailure() that would nest a second loop that never rediscovers the master.
     */
    protected function retryOnceOnLostConnection(Closure $callback)
    {
        return $callback();
    }

    /**
     * {@inheritdoc}
     *
     * Does nothing: Laravel rebuilds from the cached address; here only refreshClient() replaces the client.
     */
    protected function rebuildClient()
    {
        //
    }

    /**
     * Run the operation, rediscovering the master and retrying on failover-class errors.
     *
     * Every attempt, the first included, runs with the read timeout cut to what the deadline has left.
     * A stale client rebuilt on the way in that spent the deadline doing so ends the operation before the command runs.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback  The client operation.
     * @param  SentinelRetryPolicy|null  $policy  Overrides the connection's budget, for blocking operations.
     * @return TResult
     *
     * @throws SentinelFailoverException When the retry budget is spent (the original error as previous).
     * @throws Throwable When the error is not failover-class (propagated untouched).
     */
    private function retryOnFailure(callable $callback, ?SentinelRetryPolicy $policy = null): mixed
    {
        try {
            return ($policy ?? $this->retryPolicy)->run(
                function (?RecoveryDeadline $deadline) use ($callback) {
                    $this->refreshStaleClient($deadline);

                    // Not retryable, so the loop lets it through: the budget was spent before the command ran.
                    if ($deadline?->spent()) {
                        throw new SentinelFailoverException(sprintf(
                            'Redis sentinel connection [%s] gave up: the recovery deadline was spent rebuilding the client',
                            $this->getName() ?? 'unknown',
                        ));
                    }

                    return $this->withReadTimeoutWithin($deadline, $callback);
                },
                $this->refreshClient(...),
                sprintf('connection [%s]', $this->getName() ?? 'unknown'),
            );
        } catch (SentinelFailoverException $exception) {
            // Rebuild lazily: rebuilding now would spend the time the deadline just refused.
            $this->clientIsStale = true;

            throw $exception;
        }
    }

    /**
     * Rebuild a client that an exhausted budget left for dead, once; the loop owns everything after that.
     *
     * Runs inside the retry loop, so a rediscovery that fails here becomes an ordinary retryable attempt.
     */
    private function refreshStaleClient(?RecoveryDeadline $deadline): void
    {
        if (! $this->clientIsStale) {
            return;
        }

        $this->clientIsStale = false;

        $this->refreshClient($deadline);
    }

    /**
     * Replace the client with one for the freshly discovered master, its waits clamped to the deadline.
     *
     * A failed rediscovery keeps the old client, which may still serve reads. The old client is never closed
     * explicitly: its socket may be a persistent one another connection shares, and dropping it is enough.
     */
    private function refreshClient(?RecoveryDeadline $deadline = null): void
    {
        if ($this->rediscover === null) {
            return;
        }

        try {
            $this->client = ($this->rediscover)(true, $deadline);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf(
                'Redis sentinel connection [%s]: master rediscovery failed, keeping the previous client for the next attempt: %s',
                $this->getName() ?? 'unknown',
                $exception->getMessage(),
            ));
        }
    }

    /**
     * Run a subscription with the read timeout lifted, restored afterward.
     *
     * An idle subscriber would otherwise hit the read timeout every few seconds with a failover-class read error.
     */
    private function withoutReadTimeout(Closure $callback): mixed
    {
        return $this->withReadTimeout(-1.0, $callback);
    }

    /**
     * Run one attempt with the read timeout cut to what the deadline has left; unchanged when there is no deadline.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withReadTimeoutWithin(?RecoveryDeadline $deadline, callable $callback): mixed
    {
        if ($deadline === null) {
            return $callback();
        }

        $configured = (float) $this->client->getOption(Redis::OPT_READ_TIMEOUT);

        return $this->withReadTimeout($deadline->clamp($configured), $callback);
    }

    /**
     * Run a callback under a temporary read timeout, restored in a finally on the client it was set on: the callback
     * may replace the connection's client, and the replacement keeps its own.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withReadTimeout(float $seconds, callable $callback): mixed
    {
        $client = $this->client;
        $previous = $client->getOption(Redis::OPT_READ_TIMEOUT);

        $client->setOption(Redis::OPT_READ_TIMEOUT, $seconds);

        try {
            return $callback();
        } finally {
            $client->setOption(Redis::OPT_READ_TIMEOUT, $previous);
        }
    }
}
