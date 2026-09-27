<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Support\PhpRedisVersion;

final class PhpRedisVersionTest extends TestCase
{
    #[DataProvider('supportedVersions')]
    public function test_the_minimum_and_later_releases_pass(string $version): void
    {
        PhpRedisVersion::refuseOlder($version);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedVersions(): array
    {
        return [
            'the minimum' => ['6.3.0'],
            'a patch release' => ['6.3.1'],
            'a two-digit minor release, compared as a number' => ['6.10.0'],
            'a major release' => ['7.0.0'],
        ];
    }

    #[DataProvider('olderVersions')]
    public function test_an_older_release_is_refused_naming_both_versions(string $version): void
    {
        $this->expectExceptionObject(new RuntimeException(
            "Redis Sentinel connections need phpredis 6.3.0 or later; {$version} is loaded."
        ));

        PhpRedisVersion::refuseOlder($version);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function olderVersions(): array
    {
        return [
            'the previous minor release' => ['6.2.0'],
            'the previous major release' => ['5.3.7'],
            'a release candidate of the minimum' => ['6.3.0RC1'],
        ];
    }

    public function test_a_missing_extension_is_refused(): void
    {
        $this->expectExceptionObject(new RuntimeException(
            'Redis Sentinel connections need the phpredis extension 6.3.0 or later, and it is not loaded.'
        ));

        PhpRedisVersion::refuseOlder(false);
    }

    public function test_the_minimum_is_the_lower_bound_composer_declares(): void
    {
        /** @var array{require: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        [$major, $minor, $patch] = explode('.', PhpRedisVersion::MINIMUM);

        $this->assertSame('0', $patch, 'a caret constraint on major.minor starts at patch 0');
        $this->assertSame("^{$major}.{$minor}", $composer['require']['ext-redis']);
    }

    public function test_the_loaded_extension_passes(): void
    {
        // The environment the suite runs in is one the package supports.
        PhpRedisVersion::refuseOlder(phpversion('redis'));

        $this->addToAssertionCount(1);
    }
}
