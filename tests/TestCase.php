<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests;

use Illuminate\Contracts\Foundation\Application;
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

    protected function application(): Application
    {
        return $this->app ?? $this->fail('The application is not booted.');
    }
}
