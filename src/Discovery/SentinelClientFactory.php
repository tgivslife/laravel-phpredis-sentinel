<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Discovery;

use RedisSentinel;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelConfigurationException;
use Tgi\LaravelPhpRedisSentinel\Support\ConnectionSettings;
use Tgi\LaravelPhpRedisSentinel\Support\HostListParser;

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
     */
    public function make(string $host, int $port, array $config): RedisSentinel
    {
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
     * @param  array<string, mixed>  $config
     * @return array{host: string, port: int, connectTimeout: float, readTimeout: float, auth?: string|array{0: string, 1: string}}
     *
     * @throws SentinelConfigurationException When only one half of the sentinel credentials is configured, a
     *                                        setting is not a scalar, the username holds whitespace, or the timeout
     *                                        is not a positive number.
     */
    public function options(string $host, int $port, array $config): array
    {
        $timeout = ConnectionSettings::sentinelTimeout($config);

        $options = [
            'host' => $host,
            'port' => $port,
            'connectTimeout' => $timeout,
            'readTimeout' => $timeout,
        ];

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
}
