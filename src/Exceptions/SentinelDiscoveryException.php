<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Exceptions;

use RedisException;

/**
 * Finding the master failed: no sentinel named a usable one, the named node could not be verified as master,
 * or the recovery deadline ran out before one of its setup stages could start.
 *
 * Whether that is worth retrying depends entirely on *why*, which is what `$anySentinelAnswered` records:
 *
 *  - no sentinel answered at all - the fleet is unreachable. Retrying changes nothing, so this fails fast,
 *    with every host it tried named in the message.
 *  - a sentinel answered, and there is no usable master yet: an election is in flight, a replica is still being
 *    promoted, or the budget ran out mid-setup. The retry policy treats it like any other failover-class error.
 *
 * A RedisException like everything else this driver throws at the application; see
 * {@see SentinelFailoverException} for why.
 *
 * @api
 */
final class SentinelDiscoveryException extends RedisException
{
    /**
     * @param  string  $message  Names the sentinels tried, or the node, and why no usable master came of it.
     * @param  bool  $anySentinelAnswered  Whether a sentinel responded, making it retryable (not an outage).
     */
    public function __construct(string $message, public readonly bool $anySentinelAnswered = false)
    {
        parent::__construct($message);
    }
}
