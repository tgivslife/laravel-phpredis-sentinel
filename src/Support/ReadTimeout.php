<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Support;

/**
 * The read timeout a phpredis socket actually waits.
 *
 * phpredis reports 0 for a client connected without a read timeout, whose socket then waits `default_socket_timeout`.
 * 0 is not usable as it is: set on a live socket it fails every read at once, and clamp() reads it as no limit.
 *
 * @internal
 */
final class ReadTimeout
{
    /**
     * The effective read timeout in seconds: `default_socket_timeout` for 0, any other value as it is.
     */
    public static function effective(float $seconds): float
    {
        return $seconds === 0.0 ? (float) ini_get('default_socket_timeout') : $seconds;
    }
}
