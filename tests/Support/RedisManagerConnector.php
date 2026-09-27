<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Illuminate\Redis\RedisManager;
use ReflectionMethod;

/**
 * Reads which connector a Redis manager would open a connection with, which the manager keeps protected.
 */
final class RedisManagerConnector
{
    public static function of(RedisManager $redis): mixed
    {
        return (new ReflectionMethod($redis, 'connector'))->invoke($redis);
    }
}
