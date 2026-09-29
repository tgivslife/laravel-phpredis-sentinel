<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Support;

use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;

/**
 * Reads a Sentinel connection's settings: the one place their types and defaults are checked. It also defines which
 * `sentinel_` keys are the package's, and refuses one wherever the package does not read it.
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
     * The Sentinel settings, read on a Sentinel connection itself with the exact case.
     *
     * @var list<string>
     */
    public const array SENTINEL_SETTINGS = [
        'sentinel_hosts',
        'sentinel_service',
        'sentinel_username',
        'sentinel_password',
        'sentinel_timeout',
        'sentinel_scheme',
        'sentinel_context',
    ];

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
     * Whether a configuration key belongs to the Sentinel settings' namespace, a known setting or a misspelled one.
     *
     * @phpstan-assert-if-true string $key
     */
    public static function isSentinelKey(int|string $key): bool
    {
        return is_string($key) && str_starts_with(strtolower($key), 'sentinel_');
    }

    /**
     * Refuse a Sentinel setting in an options array, where the package never reads it, so it would be ignored.
     *
     * Matched on the `sentinel_` prefix in any case, not on the known keys, so a misspelled one is caught too.
     *
     * @param  string  $place  Which options array, for the message.
     * @param  array<array-key, mixed>  $options
     *
     * @throws SentinelConfigurationException When the array holds a key starting with `sentinel_`.
     */
    public static function refuseSentinelKeysIn(string $place, array $options): void
    {
        $key = self::firstSentinelKey($options);

        if ($key !== null) {
            throw new SentinelConfigurationException(
                "{$key} must not be set in {$place}: the package reads Sentinel settings only on the Sentinel connection itself."
            );
        }
    }

    /**
     * The first key of an array that starts with `sentinel_`, in any case, or null when there is none.
     *
     * @param  array<array-key, mixed>  $settings
     */
    public static function firstSentinelKey(array $settings): ?string
    {
        foreach (array_keys($settings) as $key) {
            if (self::isSentinelKey($key)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Refuse a `sentinel_` key on a Sentinel connection that is not one of its settings, such as a misspelled
     * `sentinel_password`, which would otherwise leave every sentinel probe anonymous without a word.
     *
     * Compared with the exact case, since the settings are read that way: `Sentinel_Password` is refused too.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SentinelConfigurationException When the connection holds a `sentinel_` key that is not a setting.
     */
    public static function refuseUnknownSentinelKeys(array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (self::isSentinelKey($key) && ! in_array($key, self::SENTINEL_SETTINGS, true)) {
                throw new SentinelConfigurationException(sprintf(
                    '%s is not a Sentinel setting; the settings are %s and %s.',
                    $key,
                    implode(', ', array_slice(self::SENTINEL_SETTINGS, 0, -1)),
                    self::SENTINEL_SETTINGS[array_key_last(self::SENTINEL_SETTINGS)],
                ));
            }
        }
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
