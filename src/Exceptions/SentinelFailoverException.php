<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Exceptions;

use RedisException;

/**
 * The failover retry budget was spent without the operation succeeding.
 *
 * A RedisException rather than a RuntimeException on purpose: Laravel's `PhpRedisConnection::command()` declares
 * `@throws RedisException`, and callers that guard Redis work do so with `catch (RedisException)`.
 * A sentinel deployment must not quietly fall outside those guards just because this driver sits underneath.
 *
 * The last failure is attached as `previous`: this type says "we stopped trying", the previous says what went wrong.
 * A budget spent before any attempt failed, such as one spent rebuilding a stale client, has no previous.
 *
 * @api
 */
final class SentinelFailoverException extends RedisException {}
