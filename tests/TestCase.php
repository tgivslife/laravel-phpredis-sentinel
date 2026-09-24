<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tgi\LaravelPhpRedisSentinel\RedisSentinelServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            RedisSentinelServiceProvider::class,
        ];
    }
}
