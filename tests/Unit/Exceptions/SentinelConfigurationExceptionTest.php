<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Exceptions;

use ErrorException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RedisException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;

final class SentinelConfigurationExceptionTest extends TestCase
{
    public function test_it_is_the_type_laravel_uses_for_an_unusable_redis_configuration(): void
    {
        $this->assertInstanceOf(InvalidArgumentException::class, new SentinelConfigurationException('sentinel_hosts must be a string or a list, int given.'));
    }

    public function test_it_is_none_of_the_types_the_retry_policy_retries(): void
    {
        $exception = new SentinelConfigurationException('Invalid port [abc] in sentinel_hosts entry [socket.internal:abc].');

        $this->assertNotInstanceOf(RedisException::class, $exception);
        $this->assertNotInstanceOf(ErrorException::class, $exception);
    }
}
