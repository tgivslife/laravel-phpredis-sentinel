<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use RedisException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelFailoverException;

final class SentinelFailoverExceptionTest extends TestCase
{
    public function test_callers_guarding_redis_work_catch_it_and_see_the_original_failure(): void
    {
        $original = new RedisException('Connection refused');
        $exception = new SentinelFailoverException('gave up after 3 retries: Connection refused', previous: $original);

        $this->assertInstanceOf(RedisException::class, $exception);
        $this->assertSame($original, $exception->getPrevious());
    }
}
