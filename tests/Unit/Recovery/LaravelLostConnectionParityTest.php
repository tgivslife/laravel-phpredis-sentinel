<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Recovery;

use Composer\InstalledVersions;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedisException;
use Tgi\LaravelPhpRedisSentinel\Recovery\SentinelRetryPolicy;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\LaravelLostConnectionMessages;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\RecordingLogger;
use Throwable;

/**
 * Every failure Laravel's PhpRedisConnection::causedByLostConnection() recognizes is retryable in the package too.
 *
 * The connection bypasses Laravel's loop, so a message Laravel would recover from and the package would not is a failure the package lets through.
 * The fragments are read from Laravel's source, so one a release adds is checked here without an edit.
 * Laravel matches them case-sensitively and the package without regard to case, so a match in Laravel is always one here.
 */
final class LaravelLostConnectionParityTest extends TestCase
{
    /**
     * Fragments deliberately not retried, why, and the first supported Laravel release that lists them.
     *
     * @var array<string, array{reason: string, since: string}>
     */
    private const array EXCLUDED = [
        'Error processing response from Redis node' => [
            'reason' => 'raised by RedisCluster only; a Sentinel connection never is one',
            'since' => '13.33.0',
        ],
        "Can't communicate with any node in the cluster" => [
            'reason' => 'raised by RedisCluster only; a Sentinel connection never is one',
            'since' => '13.36.0',
        ],
    ];

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function laravelLostConnections(): iterable
    {
        foreach (LaravelLostConnectionMessages::fragments() as $fragment) {
            if (array_key_exists($fragment, self::EXCLUDED)) {
                continue;
            }

            // Laravel accepts both, and phpredis reports one failure as either, depending on the platform.
            foreach ([RedisException::class, ErrorException::class] as $type) {
                yield "{$type}: {$fragment}" => [new $type($fragment)];
            }
        }
    }

    #[DataProvider('laravelLostConnections')]
    public function test_a_failure_laravel_recovers_from_is_retryable_in_the_package(Throwable $failure): void
    {
        $this->assertTrue((new SentinelRetryPolicy(new RecordingLogger))->isRetryable($failure));
    }

    public function test_every_exclusion_is_still_one_laravel_recognises(): void
    {
        // An exclusion Laravel dropped is a dead entry, and one it renamed would hide the new wording from the test.
        // One newer than the installed release is not listed yet: the lowest-dependency jobs run the Laravel floor.
        $installed = ltrim((string) InstalledVersions::getPrettyVersion('laravel/framework'), 'v');
        $listed = array_filter(self::EXCLUDED, static fn (array $exclusion): bool => version_compare($installed, $exclusion['since'], '>='));

        $this->assertSame([], array_diff(array_keys($listed), LaravelLostConnectionMessages::fragments()));
    }

    public function test_an_excluded_fragment_is_not_retried(): void
    {
        foreach (array_keys(self::EXCLUDED) as $fragment) {
            $this->assertFalse((new SentinelRetryPolicy(new RecordingLogger))->isRetryable(new RedisException($fragment)), $fragment);
        }
    }
}
