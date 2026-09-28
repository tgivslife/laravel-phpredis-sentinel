<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Integration;

use RuntimeException;
use Tgi\LaravelPhpRedisSentinel\Tests\Support\Servers;
use Tgi\LaravelPhpRedisSentinel\Tests\TestCase;

/**
 * A test against the servers of docker/compose.yaml, run in the Linux test container (`composer test:integration`).
 *
 * Every test starts from the canonical state, with no data: servers a previous test left in another state are reset
 * first, and keys it left are flushed. Servers that cannot be reached or reset fail the test instead of skipping it,
 * since a skipped suite would pass CI having shown nothing; phpunit.xml.dist also fails the run on any skipped or
 * incomplete test.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The servers' namespace is where the announced addresses and failure modes are the real ones: through Docker
        // Desktop's port forwarder, for one, a connection to a stopped server is accepted and then dropped.
        if (getenv('INTEGRATION_CONTAINER') !== '1') {
            $this->fail('The integration tests run in the test container: composer test:integration.');
        }

        $this->ensureCanonicalServers();
    }

    /**
     * Empty the servers when their topology is canonical, reset them otherwise, and fail if a reset does not bring them
     * to the canonical state.
     */
    protected function ensureCanonicalServers(): void
    {
        if (Servers::differences() === [] && Servers::flush()) {
            return;
        }

        try {
            Servers::reset();
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
        }

        $differences = Servers::differences();

        if ($differences !== []) {
            $this->fail("The servers are not in their canonical state after a reset:\n- ".implode("\n- ", $differences));
        }
    }
}
