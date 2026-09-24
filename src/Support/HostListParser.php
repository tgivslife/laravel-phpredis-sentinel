<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Support;

use RuntimeException;

/**
 * Parses a comma-separated host list (`host`, `host:port`, `[v6]`, `[v6]:port`) into [host, port] pairs.
 *
 * The one definition the sentinel host list is read with.
 * Instantiated with the name of the setting being parsed so an error names the actual setting to fix, and with that list's conventional default port.
 *
 * Must stay dependency-free (no container, no facades, no network): it runs while a connection is being opened.
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
     * A bare IPv6 literal is nothing but colons, so splitting on the last one would read `fd00::1` as host `fd00:`
     * on port 1 and then fail somewhere far away from the typo.
     * Brackets are the disambiguator RFC 3986 exists for; unbracketed, anything holding more than one colon is taken
     * as a bare address on the default port, which is the only reading that cannot silently connect somewhere unintended.
     * The brackets are then stripped, because phpredis re-adds them itself when it sees a colon in the host.
     *
     * @return array{0: string, 1: int}
     */
    private function parseHost(string $segment): array
    {
        if (str_starts_with($segment, '[')) {
            $close = strpos($segment, ']');

            if ($close === false) {
                throw new RuntimeException(
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
     * @throws RuntimeException When a port is present but is not a legal TCP port.
     */
    private function parsePort(string $port, string $segment): int
    {
        if ($port === '') {
            return $this->defaultPort;
        }

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException("Invalid port [{$port}] in {$this->source} entry [{$segment}].");
        }

        return (int) $port;
    }
}
