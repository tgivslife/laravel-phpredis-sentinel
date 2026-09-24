<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use RedisException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;

final class SentinelDiscoveryExceptionTest extends TestCase
{
    public function test_an_unreachable_fleet_is_the_default(): void
    {
        $exception = new SentinelDiscoveryException('no sentinel answered: s1:26379 (Connection refused)');

        $this->assertFalse($exception->anySentinelAnswered);
        $this->assertSame('no sentinel answered: s1:26379 (Connection refused)', $exception->getMessage());
    }

    public function test_it_records_that_a_sentinel_answered(): void
    {
        $this->assertTrue((new SentinelDiscoveryException('no master yet', anySentinelAnswered: true))->anySentinelAnswered);
    }

    public function test_callers_guarding_redis_work_catch_it(): void
    {
        $this->assertInstanceOf(RedisException::class, new SentinelDiscoveryException('no master yet'));
    }
}
