<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Support;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Support\ConnectionSettings;

/**
 * The readers every class reads a connection's settings with. Each caller's own tests cover the settings it reads.
 */
final class ConnectionSettingsTest extends TestCase
{
    public function test_an_unset_setting_reads_as_its_default(): void
    {
        $this->assertSame('mymaster', ConnectionSettings::string([], 'sentinel_service', 'mymaster'));
        $this->assertSame(0, ConnectionSettings::int(['database' => null], 'database', 0));
        $this->assertSame(500, ConnectionSettings::wholeNumber(['retry_delay' => null], 'retry_delay', 500));
        $this->assertSame(2.0, ConnectionSettings::dataNodeTimeout(['read_timeout' => null], 'read_timeout'));
        $this->assertSame('', ConnectionSettings::sentinelUsername([]));
        $this->assertSame('', ConnectionSettings::hostList([]));
    }

    public function test_the_sentinel_timeout_defaults_to_half_a_second(): void
    {
        $this->assertSame(0.5, ConnectionSettings::sentinelTimeout([]));
        $this->assertSame(0.25, ConnectionSettings::sentinelTimeout(['sentinel_timeout' => '0.25']));
    }

    public function test_scalars_are_cast_as_php_casts_them(): void
    {
        $this->assertSame('3', ConnectionSettings::string(['sentinel_service' => 3], 'sentinel_service', 'mymaster'));
        $this->assertSame(7, ConnectionSettings::int(['database' => '7'], 'database', 0));
        $this->assertSame('1', ConnectionSettings::stringValue(true, 'password'));
    }

    public function test_a_whole_number_may_come_as_a_string_or_a_whole_float(): void
    {
        // env() returns a string, and a config file may compute a float.
        $this->assertSame(250, ConnectionSettings::wholeNumber(['retry_delay' => '250'], 'retry_delay', 500));
        $this->assertSame(250, ConnectionSettings::wholeNumber(['retry_delay' => 250.0], 'retry_delay', 500));
        $this->assertSame(0, ConnectionSettings::wholeNumber(['retry_delay' => 0], 'retry_delay', 500));
        $this->assertSame(250, ConnectionSettings::wholeNumber(['retry_delay' => "250 \n"], 'retry_delay', 500), 'whitespace around a number is ignored');
    }

    public function test_a_whole_number_with_a_leading_zero_is_refused(): void
    {
        // FILTER_VALIDATE_INT refuses it, and a count or a number of milliseconds has no use for one.
        $this->expectExceptionObject(new SentinelConfigurationException(
            "retry_delay must be a whole number of milliseconds, 0 or more, '0500' given."
        ));

        ConnectionSettings::wholeNumber(['retry_delay' => '0500'], 'retry_delay', 500, 'milliseconds');
    }

    public function test_the_host_list_keeps_a_string_or_a_list_of_scalars_as_given(): void
    {
        $this->assertSame('s1, s2:26380', ConnectionSettings::hostList(['sentinel_hosts' => 's1, s2:26380']));
        $this->assertSame(['s1', 26380, null], ConnectionSettings::hostList(['sentinel_hosts' => ['s1', 26380, null]]));
    }

    /**
     * @return array<string, array{Closure(): mixed, string}>
     */
    public static function malformedValues(): array
    {
        return [
            'string' => [static fn (): string => ConnectionSettings::string(['sentinel_service' => []], 'sentinel_service', 'mymaster'), 'sentinel_service must be a string, array given.'],
            'string value' => [static fn (): string => ConnectionSettings::stringValue(null, 'password'), 'password must be a string, null given.'],
            'int' => [static fn (): int => ConnectionSettings::int(['database' => [2]], 'database', 0), 'database must be a number, array given.'],
            'whole number' => [static fn (): int => ConnectionSettings::wholeNumber(['retry_delay' => [500]], 'retry_delay', 500, 'milliseconds'), 'retry_delay must be a whole number of milliseconds, 0 or more, array given.'],
            'data-node timeout' => [static fn (): float => ConnectionSettings::dataNodeTimeout(['read_timeout' => new stdClass], 'read_timeout'), 'read_timeout must be a positive number of seconds, stdClass given.'],
            'sentinel timeout' => [static fn (): float => ConnectionSettings::sentinelTimeout(['sentinel_timeout' => [1]]), 'sentinel_timeout must be a positive number of seconds, array given.'],
            'sentinel username' => [static fn (): string => ConnectionSettings::sentinelUsername(['sentinel_username' => ['ops']]), 'sentinel_username must be a string, array given.'],
            'host list' => [static fn (): string|array => ConnectionSettings::hostList(['sentinel_hosts' => 5]), 'sentinel_hosts must be a string or a list, int given.'],
            'host list entry' => [static fn (): string|array => ConnectionSettings::hostList(['sentinel_hosts' => [['s1']]]), 'sentinel_hosts entries must be strings, array given.'],
        ];
    }

    /**
     * @param  Closure(): mixed  $read
     */
    #[DataProvider('malformedValues')]
    public function test_a_value_that_is_not_a_scalar_is_refused_naming_its_setting(Closure $read, string $message): void
    {
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        $read();
    }
}
