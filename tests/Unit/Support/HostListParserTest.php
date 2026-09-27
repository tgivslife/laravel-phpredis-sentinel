<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Support\HostListParser;

final class HostListParserTest extends TestCase
{
    public function test_it_parses_comma_separated_hosts_with_a_default_port(): void
    {
        $this->assertSame(
            [['10.0.0.1', 26379], ['10.0.0.2', 26380], ['10.0.0.3', 26379]],
            $this->sentinelHosts()->parseHosts(' 10.0.0.1 , 10.0.0.2:26380 ,, 10.0.0.3, '),
        );
    }

    public function test_an_empty_list_parses_to_nothing(): void
    {
        $this->assertSame([], $this->sentinelHosts()->parseHosts(''));
        $this->assertSame([], $this->sentinelHosts()->parseHosts(' , '));
        $this->assertSame([], $this->sentinelHosts()->parseHosts([]));
    }

    public function test_it_accepts_an_array_of_entries(): void
    {
        $this->assertSame(
            [['redis-sentinel', 26379], ['10.0.0.2', 26380]],
            $this->sentinelHosts()->parseHosts(['redis-sentinel', ' 10.0.0.2:26380 ', '']),
        );
    }

    public function test_it_reads_ipv6_literals_bracketed_and_bare(): void
    {
        // Splitting on the last colon would read a bare literal as host `fd00:` on port 1. The brackets come
        // back off because phpredis re-adds them itself the moment it sees a colon in the host.
        $this->assertSame(
            [['fd00::1', 26379], ['fd00::1', 26380], ['::1', 26379], ['::1', 26379]],
            $this->sentinelHosts()->parseHosts('[fd00::1], [fd00::1]:26380, ::1, [::1]'),
        );
    }

    #[DataProvider('severalColonsButNoIpv6Address')]
    public function test_an_unbracketed_entry_with_several_colons_must_be_an_ipv6_address(string $entry): void
    {
        // A hostname holds no colon, so this is a typo; read as a bare address it would only fail when connecting.
        $this->expectExceptionObject(new SentinelConfigurationException(
            "Malformed host [{$entry}] in sentinel_hosts - only an IPv6 address holds several colons, and with a port it needs brackets, as in [fd00::1]:26379."
        ));

        $this->sentinelHosts()->parseHosts($entry);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function severalColonsButNoIpv6Address(): array
    {
        return [
            'doubled port' => ['10.0.0.1:26379:26380'],
            'IPv6 port without brackets' => ['fd00::1:26380'],
            'hostname' => ['sentinel:a:b'],
            'zone ID with a port' => ['fe80::1%eth0:26380'],
            'empty zone ID' => ['fe80::1%'],
        ];
    }

    public function test_an_unbracketed_ipv6_address_is_read_on_the_default_port(): void
    {
        $this->assertSame(
            [['fd00::1', 26379], ['::', 26379], ['::ffff:10.0.0.1', 26379], ['fe80::1%eth0', 26379]],
            $this->sentinelHosts()->parseHosts('fd00::1, ::, ::ffff:10.0.0.1, fe80::1%eth0'),
        );
    }

    #[DataProvider('emptyHosts')]
    public function test_an_empty_host_is_refused(string $entry): void
    {
        $this->expectExceptionObject(new SentinelConfigurationException("Empty host in sentinel_hosts entry [{$entry}]."));

        $this->sentinelHosts()->parseHosts($entry);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyHosts(): array
    {
        return [
            'brackets' => ['[]'],
            'brackets with a port' => ['[]:26380'],
            'brackets and a colon' => ['[]:'],
            'port only' => [':26380'],
            'colon only' => [':'],
        ];
    }

    #[DataProvider('bracketTails')]
    public function test_a_closing_bracket_can_only_be_followed_by_a_port(string $entry, string $message): void
    {
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        $this->sentinelHosts()->parseHosts($entry);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bracketTails(): array
    {
        return [
            'port without its colon' => ['[fd00::1]26380', 'Malformed host [[fd00::1]26380] in sentinel_hosts - a bracketed IPv6 literal can only be followed by :port.'],
            'two colons' => ['[fd00::1]::26380', 'Invalid port [:26380] in sentinel_hosts entry [[fd00::1]::26380].'],
        ];
    }

    #[DataProvider('bracketsAroundMoreThanAnIpv6Address')]
    public function test_brackets_with_a_colon_inside_must_hold_an_ipv6_address(string $entry): void
    {
        // A port inside the brackets is as easy a mistake as no brackets at all.
        $this->expectExceptionObject(new SentinelConfigurationException(
            "Malformed host [{$entry}] in sentinel_hosts - brackets hold an IPv6 address; the port goes after them, as in [fd00::1]:26379."
        ));

        $this->sentinelHosts()->parseHosts($entry);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bracketsAroundMoreThanAnIpv6Address(): array
    {
        return [
            'IPv6 port inside' => ['[fd00::1:26380]'],
            'IPv4 port inside' => ['[10.0.0.1:26379]'],
            'hostname with colons' => ['[sentinel:a:b]'],
            'zone ID with a port inside' => ['[fe80::1%eth0:26380]'],
        ];
    }

    public function test_brackets_may_hold_a_host_name_or_an_ipv6_address_with_a_zone_id(): void
    {
        $this->assertSame(
            [['redis-sentinel', 26380], ['fe80::1%eth0', 26380], ['::ffff:10.0.0.1', 26379]],
            $this->sentinelHosts()->parseHosts('[redis-sentinel]:26380, [fe80::1%eth0]:26380, [::ffff:10.0.0.1]'),
        );
    }

    public function test_an_empty_port_falls_back_to_the_default(): void
    {
        $this->assertSame(
            [['10.0.0.1', 26379], ['fd00::1', 26379]],
            $this->sentinelHosts()->parseHosts('10.0.0.1:, [fd00::1]:'),
        );
    }

    public function test_it_accepts_the_edges_of_the_port_range(): void
    {
        $this->assertSame(
            [['host', 1], ['host', 65535]],
            $this->sentinelHosts()->parseHosts('host:1, host:65535'),
        );
    }

    public function test_the_default_port_and_the_setting_name_belong_to_each_list(): void
    {
        $otherList = new HostListParser('other_hosts', 6379);

        $this->assertSame(
            [['fd00::1', 7001], ['fd00::2', 6379]],
            $otherList->parseHosts('[fd00::1]:7001,fd00::2'),
        );

        $this->expectExceptionObject(new SentinelConfigurationException('Invalid port [abc] in other_hosts entry [host:abc].'));

        $otherList->parseHosts('host:abc');
    }

    #[DataProvider('bracketAdvice')]
    public function test_the_bracket_example_uses_the_list_default_port(string $entry, string $message): void
    {
        $this->expectExceptionObject(new SentinelConfigurationException($message));

        (new HostListParser('other_hosts', 6379))->parseHosts($entry);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bracketAdvice(): array
    {
        return [
            'several colons' => ['10.0.0.1:7001:7002', 'Malformed host [10.0.0.1:7001:7002] in other_hosts - only an IPv6 address holds several colons, and with a port it needs brackets, as in [fd00::1]:6379.'],
            'port inside the brackets' => ['[10.0.0.1:7001]', 'Malformed host [[10.0.0.1:7001]] in other_hosts - brackets hold an IPv6 address; the port goes after them, as in [fd00::1]:6379.'],
        ];
    }

    #[DataProvider('unusablePorts')]
    public function test_it_refuses_a_port_it_cannot_use_instead_of_defaulting(string $entry, string $port): void
    {
        // Silently falling back to the default turns "wrong port" into "mysteriously unreachable host" later.
        $this->expectExceptionObject(new SentinelConfigurationException("Invalid port [{$port}] in sentinel_hosts entry [{$entry}]."));

        $this->sentinelHosts()->parseHosts($entry);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusablePorts(): array
    {
        return [
            'zero' => ['host:0', '0'],
            'not a number' => ['host:abc', 'abc'],
            'above the range' => ['host:99999', '99999'],
            'negative' => ['host:-1', '-1'],
            'bracketed, not a number' => ['[fd00::1]:abc', 'abc'],
        ];
    }

    public function test_it_refuses_an_unclosed_ipv6_bracket(): void
    {
        $this->expectExceptionObject(new SentinelConfigurationException('Malformed host [[fd00::1] in sentinel_hosts - a bracketed IPv6 literal needs its closing bracket.'));

        $this->sentinelHosts()->parseHosts('[fd00::1');
    }

    private function sentinelHosts(): HostListParser
    {
        return new HostListParser('sentinel_hosts', 26379);
    }
}
