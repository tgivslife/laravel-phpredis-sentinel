<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Support;

use RuntimeException;

/**
 * The phpredis a Sentinel connection needs, checked when one opens rather than left to Composer.
 *
 * Composer enforces `ext-redis` only when it installs, not at all after `--ignore-platform-reqs` or in an image whose
 * extensions differ from the one that installed the dependencies, and its runtime check covers the PHP version alone
 * by default (`platform-check: php-only`). The package is written and tested against the minimum and later only.
 *
 * @internal
 */
final class PhpRedisVersion
{
    /**
     * The lowest phpredis release supported: the lower bound of `ext-redis` in composer.json.
     */
    public const string MINIMUM = '6.3.0';

    /**
     * Refuse a phpredis older than the minimum, or none.
     *
     * A plain RuntimeException, as Composer's own platform check throws: never a RedisException, so no retry.
     *
     * @param  string|false  $installed  The loaded version, as `phpversion('redis')` gives it; false when not loaded.
     *
     * @throws RuntimeException When phpredis is missing or older than the minimum.
     */
    public static function refuseOlder(string|false $installed): void
    {
        if ($installed === false) {
            throw new RuntimeException(sprintf(
                'Redis Sentinel connections need the phpredis extension %s or later, and it is not loaded.',
                self::MINIMUM,
            ));
        }

        if (version_compare($installed, self::MINIMUM, '<')) {
            throw new RuntimeException(sprintf(
                'Redis Sentinel connections need phpredis %s or later; %s is loaded.',
                self::MINIMUM,
                $installed,
            ));
        }
    }
}
