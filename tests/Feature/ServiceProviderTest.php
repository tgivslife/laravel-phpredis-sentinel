<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Feature;

use Tgi\LaravelPhpRedisSentinel\RedisSentinelServiceProvider;
use Tgi\LaravelPhpRedisSentinel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_the_provider_is_registered(): void
    {
        $this->assertNotNull($this->app?->getProvider(RedisSentinelServiceProvider::class));
    }

    public function test_the_provider_is_discoverable_through_composer_metadata(): void
    {
        /** @var array{extra: array{laravel: array{providers: list<string>}}} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains(RedisSentinelServiceProvider::class, $composer['extra']['laravel']['providers']);
    }
}
