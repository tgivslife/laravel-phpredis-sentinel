<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Connections;

use RuntimeException;
use Throwable;

/**
 * Carries a failover-class failure out of the retry loop without a retry: the loop lets through anything it does not
 * classify as failover-class, and the connection then throws the failure it carries, as phpredis raised it.
 *
 * @internal
 */
final class UnretriedFailure extends RuntimeException
{
    public function __construct(public readonly Throwable $failure)
    {
        parent::__construct('A failure let through without a retry', 0, $failure);
    }
}
