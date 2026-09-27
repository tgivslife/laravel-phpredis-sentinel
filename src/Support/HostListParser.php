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
     * Unbracketed, an entry with more than one colon is a bare address on the default port: split on its last colon,
     * `fd00::1` would become host `fd00:` on port 1.
     * The brackets are stripped, since phpredis adds them back to a host that contains a colon.
     *
     * @return array{0: string, 1: int}
     *
     * @throws SentinelConfigurationException When a bracket is not closed or the port is illegal.
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

            return [
                substr($segment, 1, $close - 1),
                $this->parsePort(ltrim(substr($segment, $close + 1), ':'), $segment),
            ];
        }

        $colon = strrpos($segment, ':');

        if ($colon === false || $colon !== strpos($segment, ':')) {
            return [$segment, $this->defaultPort];
        }

        return [substr($segment, 0, $colon), $this->parsePort(substr($segment, $colon + 1), $segment)];
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
