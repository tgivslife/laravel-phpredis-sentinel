<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Exceptions;

use InvalidArgumentException;

/**
 * A Sentinel connection's configuration cannot be used: a setting is missing or has the wrong type, a host entry
 * is malformed, or the connection is configured as something a Sentinel connection cannot be.
 *
 * An InvalidArgumentException, as Laravel's RedisManager throws for a connection that is not configured.
 * Never a RedisException or an ErrorException: the retry policy retries those when the message holds a failover
 * fragment, and a message that quotes the offending value, such as a host named `socket.internal`, could hold one.
 *
 * @api
 */
final class SentinelConfigurationException extends InvalidArgumentException {}
