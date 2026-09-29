<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use ErrorException;
use Tgi\LaravelPhpRedisSentinel\Connections\PhpRedisSentinelConnection;
use Tgi\LaravelPhpRedisSentinel\Exceptions\SentinelDiscoveryException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;

/**
 * TLS to the sentinels and to the data nodes, verified against the test CA, on the TLS-only servers of the `tls`
 * profile (their certificates are generated at startup into /certs).
 *
 * The two sides are configured apart: `sentinel_scheme` and `sentinel_context` for the sentinels, Laravel's own
 * `scheme` and `context` for the data nodes. Either setting turns TLS on: phpredis uses TLS whenever SSL options are
 * given, and a scheme alone verifies against the system's CAs.
 */
final class TlsTest extends IntegrationTestCase
{
    private const string TLS_SENTINEL_HOSTS = '127.0.0.1:26490,127.0.0.1:26491,127.0.0.1:26492';

    /**
     * The same sentinels' plaintext ports.
     */
    private const string PLAINTEXT_SENTINEL_HOSTS = '127.0.0.1:26493,127.0.0.1:26494,127.0.0.1:26495';

    private const int TLS_MASTER = 6490;

    /** @var list<int> */
    private const array TLS_SENTINELS = [26490, 26491, 26492];

    protected function setUp(): void
    {
        parent::setUp();

        Servers::startTls();
    }

    public function test_with_verified_tls_to_the_sentinels_and_the_data_nodes_a_connection_works(): void
    {
        $connection = $this->tlsConnection();

        $this->assertSame(['tls://127.0.0.1', self::TLS_MASTER], [$connection->client()->getHost(), $connection->client()->getPort()]);
        $this->assertTrue($connection->set('tls', 'verified'));
        $this->assertSame('verified', $connection->get('tls'));
        $this->assertSame([], $this->logger->warnings());
    }

    public function test_the_sentinel_context_alone_turns_tls_on_for_the_sentinels(): void
    {
        // Not through tlsConnection(), which would put the scheme back.
        $settings = $this->verifiedSettings();
        unset($settings['sentinel_scheme']);

        $this->assertSame(self::TLS_MASTER, $this->sentinelConnection($settings)->client()->getPort());
    }

    /**
     * The two sides configured apart, TLS to the data nodes only: the sentinels' plaintext ports with no sentinel TLS
     * settings. The sentinels' TLS settings would not do on those ports.
     */
    public function test_plaintext_sentinels_and_tls_data_nodes_work_together(): void
    {
        $settings = ['sentinel_hosts' => self::PLAINTEXT_SENTINEL_HOSTS] + $this->verifiedSettings();
        unset($settings['sentinel_scheme'], $settings['sentinel_context']);

        $connection = $this->sentinelConnection($settings);
        $this->assertSame(['tls://127.0.0.1', self::TLS_MASTER], [$connection->client()->getHost(), $connection->client()->getPort()]);
        $this->assertTrue($connection->ping());

        $this->expectException(SentinelDiscoveryException::class);
        $this->tlsConnection(['sentinel_hosts' => self::PLAINTEXT_SENTINEL_HOSTS]);
    }

    public function test_a_sentinel_certificate_from_another_ca_fails_discovery_at_once_naming_every_sentinel(): void
    {
        try {
            $this->tlsConnection(['sentinel_context' => $this->verifiedAgainst('/certs/other-ca.pem')]);
            $this->fail('Expected discovery to fail.');
        } catch (SentinelDiscoveryException $exception) {
            $this->assertFalse($exception->anySentinelAnswered);

            foreach (self::TLS_SENTINELS as $port) {
                $this->assertStringContainsString(Servers::HOST.":{$port} (", $exception->getMessage());
            }

            // Each reads "went away", as a sentinel that is down would, so the message points at TLS.
            $this->assertStringContainsString('with sentinel TLS configured, a failed handshake reads the same way', $exception->getMessage());
        }

        $this->assertSame([], $this->logger->warnings(), 'not retried');
    }

    public function test_a_data_node_certificate_from_another_ca_fails_opening_without_a_retry(): void
    {
        $this->assertOpeningFailsOnTheDataNode(['context' => ['ssl' => $this->verifiedAgainst('/certs/other-ca.pem')]], 'certificate verify failed');
    }

    /**
     * The right CA, but a certificate for another name than the one asked for: the name is checked too, on each side.
     */
    public function test_a_certificate_for_another_name_is_refused_on_each_side(): void
    {
        $elsewhere = ['peer_name' => 'not-127.0.0.1'] + $this->verifiedAgainst('/certs/ca.pem');

        $this->assertOpeningFailsOnTheDataNode(
            ['context' => ['ssl' => $elsewhere]],
            "Peer certificate CN=`127.0.0.1' did not match expected CN=`not-127.0.0.1'",
        );

        $this->expectException(SentinelDiscoveryException::class);
        $this->tlsConnection(['sentinel_context' => $elsewhere]);
    }

    /**
     * Opening fails on the data node's certificate, as an ErrorException from Laravel's error handler, not retried.
     *
     * @param  array<string, mixed>  $settings
     */
    private function assertOpeningFailsOnTheDataNode(array $settings, string $error): void
    {
        try {
            $this->tlsConnection($settings);
            $this->fail('Expected opening to fail.');
        } catch (ErrorException $exception) {
            $this->assertStringContainsString($error, $exception->getMessage());
        }

        $this->assertSame([], $this->logger->warnings(), 'a configuration error is not retried');
    }

    /**
     * A Sentinel connection to the TLS servers: verified TLS on both sides, but for what the given settings replace.
     *
     * @param  array<string, mixed>  $settings
     */
    private function tlsConnection(array $settings = []): PhpRedisSentinelConnection
    {
        return $this->sentinelConnection($settings + $this->verifiedSettings());
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedSettings(): array
    {
        return [
            'sentinel_hosts' => self::TLS_SENTINEL_HOSTS,
            'sentinel_scheme' => 'tls',
            'sentinel_context' => $this->verifiedAgainst('/certs/ca.pem'),
            'scheme' => 'tls',
            'context' => ['ssl' => $this->verifiedAgainst('/certs/ca.pem')],
        ];
    }

    /**
     * SSL options that verify the peer's certificate, and that it is for the host connected to, against the given CA.
     *
     * @return array<string, mixed>
     */
    private function verifiedAgainst(string $ca): array
    {
        return ['cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true];
    }
}
