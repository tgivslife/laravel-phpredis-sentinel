<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Support;

use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;

/**
 * Parses a comma-separated host list (`host`, `host:port`, `[v6]`, `[v6]:port`) into [host, port] pairs.
 *
 * Built with the name of the setting it parses, which its errors name, and that list's default port.
 * No container, facades or network: it runs while a connection is being opened.
 *
 * @internal
 */
final readonly class HostListParser
{
    public function __construct(
        private string $source,
        private int $defaultPort,
    ) {}

    /**
     * Parse the comma-separated host list into [host, port] pairs.
     *
     * @param  string|array<array-key, scalar|null>  $hosts
     * @return list<array{0: string, 1: int}>
     */
    public function parseHosts(string|array $hosts): array
    {
        $segments = is_array($hosts) ? $hosts : explode(',', $hosts);

        $parsed = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);

            if ($segment === '') {
                continue;
            }

            $parsed[] = $this->parseHost($segment);
        }

        return $parsed;
    }

    /**
     * Split one `host`, `host:port`, `[v6]` or `[v6]:port` entry.
     *
     * An IPv6 literal holds colons of its own, so only brackets (RFC 3986) can set a port after one.
     * Unbracketed, an entry with more than one colon must be an IPv6 address, on the default port; anything else,
     * such as a doubled port, is a typo. Split on its last colon, `fd00::1` would become host `fd00:` on port 1.
     * Bracketed content with a colon must be an IPv6 address too, so a port put inside the brackets is refused;
     * without one it is a host name, as in `[redis-sentinel]`.
     * The brackets are stripped, since phpredis adds them back to a host that contains a colon.
     *
     * @return array{0: string, 1: int}
     *
     * @throws SentinelConfigurationException When the host is empty, the entry is malformed or the port is illegal.
     */
    private function parseHost(string $segment): array
    {
        if (str_starts_with($segment, '[')) {
            $close = strpos($segment, ']');

            if ($close === false) {
                throw new SentinelConfigurationException(
                    "Malformed host [{$segment}] in {$this->source} - a bracketed IPv6 literal needs its closing bracket."
                );
            }

            $host = $this->nonEmptyHost(substr($segment, 1, $close - 1), $segment);

            if (str_contains($host, ':') && ! self::isIpv6Address($host)) {
                throw new SentinelConfigurationException(
                    "Malformed host [{$segment}] in {$this->source} - brackets hold an IPv6 address; the port goes after them, as in [fd00::1]:{$this->defaultPort}."
                );
            }

            $rest = substr($segment, $close + 1);

            if ($rest !== '' && ! str_starts_with($rest, ':')) {
                throw new SentinelConfigurationException(
                    "Malformed host [{$segment}] in {$this->source} - a bracketed IPv6 literal can only be followed by :port."
                );
            }

            return [$host, $this->parsePort(substr($rest, 1), $segment)];
        }

        $colon = strrpos($segment, ':');

        if ($colon === false) {
            return [$segment, $this->defaultPort];
        }

        if ($colon !== strpos($segment, ':')) {
            if (! self::isIpv6Address($segment)) {
                throw new SentinelConfigurationException(
                    "Malformed host [{$segment}] in {$this->source} - only an IPv6 address holds several colons,"
                    ." and with a port it needs brackets, as in [fd00::1]:{$this->defaultPort}."
                );
            }

            return [$segment, $this->defaultPort];
        }

        return [$this->nonEmptyHost(substr($segment, 0, $colon), $segment), $this->parsePort(substr($segment, $colon + 1), $segment)];
    }

    /**
     * Whether a host is an IPv6 address, with or without a zone ID (`fe80::1%eth0`).
     *
     * PHP's validation refuses a zone ID, so only the address before it is validated; the zone ID itself must not be
     * empty or hold a colon, or `fe80::1%eth0:26380`, a port without its brackets, would pass as one.
     */
    private static function isIpv6Address(string $host): bool
    {
        [$address, $zone] = explode('%', $host, 2) + [1 => null];

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && ($zone === null || ($zone !== '' && ! str_contains($zone, ':')));
    }

    /**
     * The host half of an entry, refused when empty, as in `[]` or `:26379`.
     *
     * @throws SentinelConfigurationException When the host is empty.
     */
    private function nonEmptyHost(string $host, string $segment): string
    {
        if ($host === '') {
            throw new SentinelConfigurationException("Empty host in {$this->source} entry [{$segment}].");
        }

        return $host;
    }

    /**
     * Validate the port half of a host entry.
     *
     * A typo must not fall back to the default port silently, which turns "wrong port" into "mysteriously unreachable host" much later.
     *
     * @throws SentinelConfigurationException When a port is present but is not a legal TCP port.
     */
    private function parsePort(string $port, string $segment): int
    {
        if ($port === '') {
            return $this->defaultPort;
        }

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new SentinelConfigurationException("Invalid port [{$port}] in {$this->source} entry [{$segment}].");
        }

        return (int) $port;
    }
}
