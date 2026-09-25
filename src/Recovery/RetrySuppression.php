<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Recovery;

use Closure;

/**
 * Turns the package's failover retries off while a callback runs, connecting included, so a health check reports
 * Redis's state instead of repairing it.
 *
 * Process-wide, since connecting is retried before any connection object exists, and restored afterward, even on
 * an exception. phpredis' own socket retries are unaffected.
 *
 * @api
 */
final class RetrySuppression
{
    /**
     * Whether retries are suppressed, process-wide.
     */
    private static bool $active = false;

    private function __construct() {}

    /**
     * Run a callback with retries suppressed and return its result.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function during(Closure $callback): mixed
    {
        $previous = self::$active;

        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }

    /**
     * Whether retries are suppressed right now, anywhere in the process.
     *
     * Lets a caller's tests check that it really runs under suppression: without it, a suppressed check and an
     * unsuppressed one differ only in how long they take to fail.
     */
    public static function active(): bool
    {
        return self::$active;
    }
}
