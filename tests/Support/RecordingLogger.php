<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * A logger that keeps every record, so a test can assert what was logged instead of only silencing it.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * Messages by level, each in the order it was logged.
     *
     * @var array<string, list<string>>
     */
    private array $messages = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->messages[(string) $level][] = (string) $message;
    }

    /**
     * The messages logged as warnings, in order.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->messages[LogLevel::WARNING] ?? [];
    }
}
