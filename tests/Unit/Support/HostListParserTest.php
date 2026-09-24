<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    public function test_an_unbracketed_entry_with_several_colons_is_a_bare_address_on_the_default_port(): void
    {
        // Deliberate: without brackets, more than one colon can only be read safely as a bare IPv6 literal.
        // An IPv4 typo such as a doubled port is therefore not caught here, only when connecting.
        $this->assertSame(
            [['10.0.0.1:26379:26380', 26379]],
            $this->sentinelHosts()->parseHosts('10.0.0.1:26379:26380'),
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

        $this->expectExceptionObject(new RuntimeException('Invalid port [abc] in other_hosts entry [host:abc].'));

        $otherList->parseHosts('host:abc');
    }

    #[DataProvider('unusablePorts')]
    public function test_it_refuses_a_port_it_cannot_use_instead_of_defaulting(string $entry, string $port): void
    {
        // Silently falling back to the default turns "wrong port" into "mysteriously unreachable host" later.
        $this->expectExceptionObject(new RuntimeException("Invalid port [{$port}] in sentinel_hosts entry [{$entry}]."));

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
        $this->expectExceptionObject(new RuntimeException('Malformed host [[fd00::1] in sentinel_hosts - a bracketed IPv6 literal needs its closing bracket.'));

        $this->sentinelHosts()->parseHosts('[fd00::1');
    }

    private function sentinelHosts(): HostListParser
    {
        return new HostListParser('sentinel_hosts', 26379);
    }
}
