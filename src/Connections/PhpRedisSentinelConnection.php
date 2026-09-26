<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Connections;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;
use Tgi\LaravelPhpRedisSentinel\Recovery\RecoveryDeadline;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Support\ReadTimeout;
use Throwable;

/**
 * A phpredis connection that survives a Sentinel failover inside the failing command.
 *
 * Laravel's own recovery reconnects to the address it already has, which after a failover is the old master.
 * Here each method that talks to the client runs in the retry policy's loop, and a retry rediscovers the master first.
 * The rest (`__call`, `eval`, `flushdb`, …) already goes through command(), and wrapping it again would nest two loops.
 * A blocking pop waits out its own timeout (without one, in slices of the read timeout) instead of failing at the read timeout.
 *
 * A retry may repeat work: a write whose reply was lost, a whole pipeline, or the keys a scan already returned.
 *
 * @internal
 */
final class PhpRedisSentinelConnection extends PhpRedisConnection
{
    /**
     * The blocking pops, and the argument each takes its timeout in: an index, or -1 for the last argument.
     *
     * @var array<string, int>
     */
    private const array BLOCKING_TIMEOUT_POSITIONS = [
        'blmove' => 4,
        'blmpop' => 0,
        'blpop' => -1,
        'brpop' => -1,
        'brpoplpush' => 2,
        'bzmpop' => 0,
        'bzpopmax' => -1,
        'bzpopmin' => -1,
    ];

    /**
     * What a blocking pop uses for a read timeout that has no limit, as headroom and as a slice's length, in seconds.
     */
    private const float NO_LIMIT_STANDIN_SECONDS = 2.0;

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
        $timeout = $this->blockingTimeout($method, $parameters);

        if ($timeout === null || $timeout[1] < 0) {
            return $this->retryOnFailure(fn () => Connection::command($method, $parameters));
        }

        [$position, $wait] = $timeout;

        if ($wait > 0) {
            return $this->retryOnFailure(fn () => $this->pop($method, $parameters), wait: $wait);
        }

        return $this->popInSlices($method, $parameters, $position);
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function scan($cursor, $options = [])
    {
        return $this->retryScan($cursor, fn ($cursor) => parent::scan($cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function zscan($key, $cursor, $options = [])
    {
        return $this->retryScan($cursor, fn ($cursor) => parent::zscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function hscan($key, $cursor, $options = [])
    {
        return $this->retryScan($cursor, fn ($cursor) => parent::hscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $options
     */
    public function sscan($key, $cursor, $options = [])
    {
        return $this->retryScan($cursor, fn ($cursor) => parent::sscan($key, $cursor, $options));
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
     * Under a deadline, every attempt, the first included, runs with the read timeout cut to what is left.
     * A blocking pop's attempts run with their wait plus headroom instead, the deadline moved later by the wait.
     * A stale client rebuilt on the way in that spent the deadline doing so ends the operation before the command runs.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback  The client operation.
     * @param  SentinelRetryPolicy|null  $policy  Overrides the connection's budget, for blocking operations.
     * @param  float|null  $wait  A blocking pop's timeout in seconds; null for everything else.
     * @return TResult
     *
     * @throws SentinelFailoverException When the retry budget is spent (the last failure as previous, if any).
     * @throws Throwable When the error is not failover-class (propagated untouched).
     */
    private function retryOnFailure(callable $callback, ?SentinelRetryPolicy $policy = null, ?float $wait = null): mixed
    {
        try {
            return ($policy ?? $this->retryPolicy)->run(
                function (?RecoveryDeadline $deadline) use ($callback, $wait) {
                    $this->refreshStaleClient($deadline);

                    $readTimeout = $wait === null ? null : $this->blockingReadTimeout($deadline, $wait);

                    // Not retryable, so the loop lets it through: the budget was spent before the command ran. A pop
                    // whose whole wait no longer fits counts as spent too: even an empty wait would end in an error.
                    if ($deadline?->spent() || ($readTimeout !== null && $readTimeout <= $wait)) {
                        throw new SentinelFailoverException(sprintf(
                            'Redis sentinel connection [%s] gave up: the recovery deadline was spent rebuilding the client',
                            $this->getName() ?? 'unknown',
                        ));
                    }

                    return $readTimeout === null
                        ? $this->withReadTimeoutWithin($deadline, $callback)
                        : $this->withReadTimeout($readTimeout, $callback);
                },
                $this->refreshClient(...),
                sprintf('connection [%s]', $this->getName() ?? 'unknown'),
                max(0, (int) ceil(($wait ?? 0) * 1000)),
            );
        } catch (SentinelFailoverException $exception) {
            // Rebuild lazily: rebuilding now would spend the time the deadline just refused.
            $this->clientIsStale = true;

            throw $exception;
        }
    }

    /**
     * Run one scan page, restarting from the start cursor on any attempt that may reach another server.
     *
     * A cursor is a position in one server's hash table, and each Redis process seeds its hash at random, so after a
     * retry, or a stale client rebuilt on the way in, the scan starts over and the caller's loop carries on from the
     * new cursor. Keys already returned may come back. 0 is left as it is: it ends a scan without asking Redis.
     *
     * @template TResult
     *
     * @param  callable(mixed): TResult  $scan  Runs one page from the given cursor.
     * @return TResult
     */
    private function retryScan(mixed $cursor, callable $scan): mixed
    {
        $client = $this->client;
        $attempts = 0;
        $warned = false;

        // The start value and a finished scan have nothing to restart.
        $midIteration = ! in_array($cursor, [null, 0, '0'], true);

        return $this->retryOnFailure(function () use ($cursor, $scan, $client, $midIteration, &$attempts, &$warned) {
            $restart = $midIteration && ($attempts++ > 0 || $this->client !== $client);

            // Every retry is logged already; the restart once per call.
            if ($restart && ! $warned) {
                $warned = true;
                $this->logger->warning(sprintf(
                    'Redis sentinel connection [%s]: scan restarted from the start cursor, as its server may have changed; keys already returned may come back',
                    $this->getName() ?? 'unknown',
                ));
            }

            return $scan($restart ? null : $cursor);
        });
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
     * Where a blocking pop's timeout sits in its arguments, and its value in seconds; null for any other command.
     *
     * A numeric string counts only where phpredis takes one: brpoplpush, blmove, blmpop and bzmpop wait it out,
     * while the pops that take their timeout last refuse it at once, and are left to do so.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array{array-key, float}|null
     */
    private function blockingTimeout(string $method, array $parameters): ?array
    {
        $position = self::BLOCKING_TIMEOUT_POSITIONS[strtolower($method)] ?? null;

        if ($position === null || $parameters === []) {
            return null;
        }

        $key = $position === -1 ? array_key_last($parameters) : $position;
        $timeout = $parameters[$key] ?? null;

        if (is_int($timeout) || is_float($timeout) || ($position !== -1 && is_numeric($timeout))) {
            return [$key, (float) $timeout];
        }

        return null;
    }

    /**
     * Run a blocking pop that has no timeout as finite waits, one slice each, until a slice is not empty.
     *
     * Lifting the read timeout instead would leave a hung master unnoticed. Each slice is a pop of its own, so it
     * recovers under a deadline of its own. An error reply is `false` too, so an empty reply loops only without one.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    private function popInSlices(string $method, array $parameters, int|string $position): mixed
    {
        while (true) {
            $parameters[$position] = $slice = $this->finiteReadTimeout();

            $result = $this->retryOnFailure(fn () => $this->pop($method, $parameters), wait: $slice);

            if (! self::isEmptyReply($result) || $this->client->getLastError() !== null) {
                return $result;
            }
        }
    }

    /**
     * Run one blocking pop, raising the demotion an empty reply can hide.
     *
     * A master demoted by a plain REPLICAOF (Sentinel drops the clients instead) answers `UNBLOCKED … instance state changed`.
     * phpredis throws it for brpoplpush and blmove; the other six return an empty reply, the text only in getLastError().
     * That text outlives later commands, so it is cleared first.
     *
     * @param  array<array-key, mixed>  $parameters
     *
     * @throws RedisException When the master was demoted during the wait.
     */
    private function pop(string $method, array $parameters): mixed
    {
        $this->client->clearLastError();

        $result = Connection::command($method, $parameters);

        if (self::isEmptyReply($result)) {
            $error = $this->client->getLastError();

            if ($error !== null && str_contains($error, 'instance state changed')) {
                throw new RedisException($error);
            }
        }

        return $result;
    }

    /**
     * Whether a blocking pop came back empty: `[]` from the pops that take their timeout last, `false` from the rest.
     */
    private static function isEmptyReply(mixed $result): bool
    {
        return $result === false || $result === null || $result === [];
    }

    /**
     * A blocking pop's read timeout: its wait plus the socket's own read timeout as headroom, cut to the deadline.
     *
     * The deadline has moved later by the wait, so only the headroom is cut, unless a stale rebuild spent the budget.
     * The result is then at most the wait, and the caller refuses the pop.
     */
    private function blockingReadTimeout(?RecoveryDeadline $deadline, float $wait): float
    {
        $timeout = $wait + $this->finiteReadTimeout();

        return $deadline?->clamp($timeout) ?? $timeout;
    }

    /**
     * The socket's own read timeout, or 2.0 s when it has no limit: a blocking pop's headroom, and a slice's length.
     *
     * Without a limit the headroom would end the read before the wait does, and a slice would never end.
     */
    private function finiteReadTimeout(): float
    {
        $configured = ReadTimeout::effective((float) $this->client->getOption(Redis::OPT_READ_TIMEOUT));

        return $configured > 0 ? $configured : self::NO_LIMIT_STANDIN_SECONDS;
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

        $configured = ReadTimeout::effective((float) $this->client->getOption(Redis::OPT_READ_TIMEOUT));

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
        $previous = ReadTimeout::effective((float) $client->getOption(Redis::OPT_READ_TIMEOUT));

        $client->setOption(Redis::OPT_READ_TIMEOUT, $seconds);

        try {
            return $callback();
        } finally {
            $client->setOption(Redis::OPT_READ_TIMEOUT, $previous);
        }
    }
}
