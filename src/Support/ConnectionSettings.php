<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Support;

use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;

/**
 * Reads a Sentinel connection's settings: the one place their types and defaults are checked.
 *
 * A scalar is cast as PHP casts it, unless the reader states a stricter rule; anything else is refused, naming the setting.
 * Socket waits are seconds and may be fractional, as phpredis takes them; the recovery budget is whole milliseconds,
 * as phpredis's `retry_interval` and Sentinel's own timing settings are.
 *
 * @internal
 */
final class ConnectionSettings
{
    /**
     * The sentinel probe's connect and read timeout, in seconds, when `sentinel_timeout` is not set.
     */
    private const float DEFAULT_SENTINEL_TIMEOUT = 0.5;

    /**
     * The data node's connect and read timeouts, in seconds, when they are not set.
     */
    private const float DEFAULT_DATA_NODE_TIMEOUT = 2.0;

    /**
     * The `sentinel_timeout` setting, a sentinel probe's connect and read timeout, or its 0.5 s default when not set.
     *
     * Only a positive number is accepted: phpredis reads 0 as `default_socket_timeout`, 60 s by default, so a
     * sentinel that accepts the connection and never answers would stall discovery that long.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelConfigurationException When the setting is not a positive number.
     */
    public static function sentinelTimeout(array $config): float
    {
        return self::positiveSeconds($config, 'sentinel_timeout', self::DEFAULT_SENTINEL_TIMEOUT);
    }

    /**
     * A data-node timeout, `timeout` or `read_timeout`, or its 2.0 s default when it is not set.
     *
     * Only a positive number is accepted: phpredis gives 0 and negative values meanings of its own,
     * `default_socket_timeout` or no limit, and without a recovery deadline nothing else would bound the wait.
     *
     * @param  array<array-key, mixed>  $config  The configuration the client is built from.
     *
     * @throws SentinelConfigurationException When the setting is not a positive number.
     */
    public static function dataNodeTimeout(array $config, string $key): float
    {
        return self::positiveSeconds($config, $key, self::DEFAULT_DATA_NODE_TIMEOUT);
    }

    /**
     * The `sentinel_username` setting, '' when it is not set.
     *
     * Redis refuses a username with whitespace or a null character (`ACL SETUSER`), so such a one can never
     * authenticate; a stray space from an env file is refused here rather than failing every sentinel.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelConfigurationException When the setting is not a scalar, or holds whitespace.
     */
    public static function sentinelUsername(array $config): string
    {
        $username = self::string($config, 'sentinel_username', '');

        if (preg_match('/[\s\x00]/', $username) === 1) {
            throw new SentinelConfigurationException(sprintf(
                'sentinel_username must not contain whitespace, which no Redis username can, %s given.',
                var_export($username, true),
            ));
        }

        return $username;
    }

    /**
     * A count or a number of milliseconds, 0 or more, or the default when it is not set.
     *
     * A fraction is refused rather than cut: `retry_delay => 0.5`, meant as seconds, would otherwise be 0 ms.
     *
     * @param  array<array-key, mixed>  $config
     * @param  string  $unit  What the number counts, for the message, such as `milliseconds`; '' for a plain count.
     *
     * @throws SentinelConfigurationException When the setting is not a whole number of 0 or more.
     */
    public static function wholeNumber(array $config, string $key, int $default, string $unit = ''): int
    {
        $value = $config[$key] ?? $default;
        $number = is_int($value) ? $value : (is_float($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false);

        if ($number === false || $number < 0) {
            throw new SentinelConfigurationException(sprintf(
                '%s must be a whole number%s, 0 or more, %s given.',
                $key,
                $unit === '' ? '' : " of {$unit}",
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ));
        }

        return $number;
    }

    /**
     * The sentinel host list, as a string or a list of scalars.
     *
     * @param  array<array-key, mixed>  $config
     * @return string|array<array-key, scalar|null>
     *
     * @throws SentinelConfigurationException When the setting is neither.
     */
    public static function hostList(array $config): string|array
    {
        $hosts = $config['sentinel_hosts'] ?? '';

        if (is_string($hosts)) {
            return $hosts;
        }

        if (! is_array($hosts)) {
            throw new SentinelConfigurationException(sprintf('sentinel_hosts must be a string or a list, %s given.', get_debug_type($hosts)));
        }

        $list = [];

        foreach ($hosts as $key => $host) {
            if (! is_scalar($host) && $host !== null) {
                throw new SentinelConfigurationException(sprintf('sentinel_hosts entries must be strings, %s given.', get_debug_type($host)));
            }

            $list[$key] = $host;
        }

        return $list;
    }

    /**
     * A string setting, or the default when it is not set.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelConfigurationException When the setting is not a scalar.
     */
    public static function string(array $config, string $key, string $default): string
    {
        return self::stringValue($config[$key] ?? $default, $key);
    }

    /**
     * A value read as a string setting, for values that do not sit under a key of their own, such as each part
     * of a `password` given as a list.
     *
     * @param  string  $key  The setting the value belongs to, for the message.
     *
     * @throws SentinelConfigurationException When the value is not a scalar.
     */
    public static function stringValue(mixed $value, string $key): string
    {
        if (! is_scalar($value)) {
            throw new SentinelConfigurationException(sprintf('%s must be a string, %s given.', $key, get_debug_type($value)));
        }

        return (string) $value;
    }

    /**
     * An integer setting, or the default when it is not set.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelConfigurationException When the setting is not a scalar.
     */
    public static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (! is_scalar($value)) {
            throw new SentinelConfigurationException(sprintf('%s must be a number, %s given.', $key, get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * A timeout in seconds that must be positive and finite, or the default when it is not set.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelConfigurationException When the setting is not a positive number.
     */
    private static function positiveSeconds(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;
        $seconds = is_numeric($value) ? (float) $value : null;

        if ($seconds === null || ! is_finite($seconds) || $seconds <= 0) {
            // -1 is the usual phpredis setting for a subscriber; subscriptions here lift the read timeout themselves.
            throw new SentinelConfigurationException(sprintf(
                '%s must be a positive number of seconds, %s given%s',
                $key,
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
                $key === 'read_timeout' && $seconds !== null && $seconds < 0
                    ? '; subscribe() and psubscribe() already wait without a limit.'
                    : '.',
            ));
        }

        return $seconds;
    }
}
