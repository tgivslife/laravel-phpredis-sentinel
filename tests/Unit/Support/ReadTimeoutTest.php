<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Support\ReadTimeout;

final class ReadTimeoutTest extends TestCase
{
    public function test_zero_is_the_default_socket_timeout_in_force(): void
    {
        $previous = ini_set('default_socket_timeout', '7');

        try {
            $this->assertSame(7.0, ReadTimeout::effective(0.0));
        } finally {
            ini_set('default_socket_timeout', (string) $previous);
        }
    }

    public function test_any_other_value_is_kept(): void
    {
        $this->assertSame(2.5, ReadTimeout::effective(2.5));
        $this->assertSame(-1.0, ReadTimeout::effective(-1.0), 'phpredis reads -1 as no limit');
    }
}
