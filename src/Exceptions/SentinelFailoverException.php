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
 * The original failure is always attached as `previous` - this type says "we stopped trying", the previous says what was actually wrong.
 *
 * @api
 */
final class SentinelFailoverException extends RedisException {}
