<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Discovery;

use RedisSentinel;
use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Support\ConnectionSettings;
use Tgi\LaravelPhpRedisSentinel\Support\HostListParser;
use Tgi\LaravelPhpRedisSentinel\Support\PhpRedisVersion;

/**
 * Builds RedisSentinel clients from a `database.redis.*` connection configuration.
 *
 * Everything that talks to the sentinels goes through here, so host parsing and ACL semantics are defined once.
 * Used by the connector to discover the master, and by applications that inspect the sentinel fleet with the same settings.
 *
 * @api
 */
final class SentinelClientFactory
{
    /**
     * The port a host entry gets when it does not name one.
     */
    private const int DEFAULT_SENTINEL_PORT = 26379;

    public function __construct(
        private readonly HostListParser $hosts = new HostListParser('sentinel_hosts', self::DEFAULT_SENTINEL_PORT),
    ) {}

    /**
     * A client for a single sentinel. It connects lazily, on its first command.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws SentinelConfigurationException When a sentinel setting is unusable; see options().
     * @throws RuntimeException When phpredis is missing or older than the package supports.
     */
    public function make(string $host, int $port, array $config): RedisSentinel
    {
        PhpRedisVersion::refuseOlder(phpversion('redis'));

        return new RedisSentinel($this->options($host, $port, $config));
    }

    /**
     * Parse the comma-separated sentinel host list into [host, port] pairs.
     *
     * @param  string|array<array-key, scalar|null>  $hosts
     * @return list<array{0: string, 1: int}>
     *
     * @throws SentinelConfigurationException When an entry is malformed or names an illegal port.
     */
    public function parseHosts(string|array $hosts): array
    {
        return $this->hosts->parseHosts($hosts);
    }

    /**
     * The RedisSentinel constructor options for one sentinel host.
     *
     * Sentinel auth follows Redis 6.2+ semantics: an ACL [user, password] pair when both are set, the bare requirepass
     * password when only that is, no auth key otherwise.
     * A username without a password is refused rather than quietly downgraded - it authenticates nothing, and silently
     * talking to the sentinels anonymously is the kind of thing that only surfaces when an ACL finally starts being enforced.
     * The password is sent as written: spaces around it are part of it, and one made only of spaces counts as set.
     *
     * TLS to the sentinels is configured apart from the data nodes' `scheme` and `context`, as the two sides often
     * differ: `sentinel_scheme` goes before the host (`tls://`), and `sentinel_context` becomes the `ssl` option.
     *
     * @param  array<string, mixed>  $config
     * @return array{host: string, port: int, connectTimeout: float, readTimeout: float, auth?: string|array{0: string, 1: string}, ssl?: array<array-key, mixed>}
     *
     * @throws SentinelConfigurationException When only one half of the sentinel credentials is configured, a
     *                                        setting is not a scalar, the username holds whitespace, the timeout
     *                                        is not a positive number, the scheme is not a transport PHP supports,
     *                                        or the context is not an array.
     */
    public function options(string $host, int $port, array $config): array
    {
        $timeout = ConnectionSettings::sentinelTimeout($config);

        $options = [
            'host' => self::withScheme($host, ConnectionSettings::string($config, 'sentinel_scheme', '')),
            'port' => $port,
            'connectTimeout' => $timeout,
            'readTimeout' => $timeout,
        ];

        $context = self::sslContext($config);

        if ($context !== null) {
            $options['ssl'] = $context;
        }

        $username = ConnectionSettings::sentinelUsername($config);
        $password = ConnectionSettings::string($config, 'sentinel_password', '');

        if ($username !== '' && $password === '') {
            throw new SentinelConfigurationException(
                'sentinel_username is set without sentinel_password - set both, or neither.'
            );
        }

        if ($username !== '') {
            $options['auth'] = [$username, $password];
        } elseif ($password !== '') {
            $options['auth'] = $password;
        }

        return $options;
    }

    /**
     * The host with the scheme before it, an IPv6 address in brackets so the two can be told apart; unchanged without
     * a scheme.
     *
     * The scheme must be one of PHP's stream transports, as written (they are case-sensitive): with another,
     * RedisSentinel fails with the same "went away" as a sentinel that is down, so a typo would read as an outage.
     *
     * @throws SentinelConfigurationException When the scheme is not a transport PHP supports.
     */
    private static function withScheme(string $host, string $scheme): string
    {
        if ($scheme === '') {
            return $host;
        }

        if (! in_array($scheme, stream_get_transports(), true)) {
            throw new SentinelConfigurationException(sprintf(
                'sentinel_scheme [%s] is not a transport PHP supports; it supports %s.',
                $scheme,
                implode(', ', stream_get_transports()),
            ));
        }

        return $scheme.'://'.(str_contains($host, ':') ? "[{$host}]" : $host);
    }

    /**
     * `sentinel_context` as RedisSentinel's `ssl` option takes it, flat, normalised as Laravel normalises a cluster's
     * `context`: the options under an `ssl` or a `stream` key, or the options themselves; null when unset.
     *
     * @param  array<string, mixed>  $config
     * @return array<array-key, mixed>|null
     *
     * @throws SentinelConfigurationException When the context is not an array.
     */
    private static function sslContext(array $config): ?array
    {
        $context = $config['sentinel_context'] ?? null;

        if ($context === null) {
            return null;
        }

        if (! is_array($context)) {
            throw new SentinelConfigurationException(sprintf('sentinel_context must be an array, %s given.', get_debug_type($context)));
        }

        foreach (['ssl', 'stream'] as $key) {
            if (isset($context[$key]) && is_array($context[$key])) {
                return $context[$key];
            }
        }

        return $context;
    }
}
