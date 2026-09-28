<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

/**
 * Part of a test run in a forked child, so a wait with no limit of its own cannot hang the test: the parent waits on
 * it with a limit, and kills it and fails with the child's last progress line when the limit passes.
 *
 * The child opens its own connections: it shares the parent's socket descriptors, and using or closing one would
 * corrupt the parent's stream. It reports over a socket pair, one JSON line per event: progress lines while it runs,
 * then its result or the exception that ended it. Assertions belong in the parent, since one that fails in the child
 * never reaches PHPUnit. The child ends by SIGKILL, not exit(), which would run PHPUnit's shutdown functions and the
 * destructors that close the shared sockets.
 */
final class Child
{
    private string $buffer = '';

    /** @var list<string> */
    private array $progress = [];

    private bool $running = true;

    /**
     * @param  resource  $channel
     */
    private function __construct(private readonly int $pid, private $channel) {}

    /**
     * Fork, and run the body in the child. It gets a function that reports a progress line, and returns its result,
     * which must survive a JSON round trip.
     *
     * @param  Closure(Closure(string): void): mixed  $body
     */
    public static function run(Closure $body): self
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('Could not create the socket pair for a child.');
        }

        [$parentEnd, $childEnd] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork a child.');
        }

        if ($pid === 0) {
            fclose($parentEnd);
            $report = static function (array $event) use ($childEnd): void {
                fwrite($childEnd, json_encode($event, JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
            };

            // Whatever escapes, the child ends here: one that returned into PHPUnit would run the rest of the suite.
            try {
                try {
                    $report(['result' => $body(static fn (string $line) => $report(['progress' => $line]))]);
                } catch (Throwable $exception) {
                    $report(['error' => $exception::class.': '.$exception->getMessage()]);
                }
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        fclose($childEnd);
        stream_set_blocking($parentEnd, false);

        return new self($pid, $parentEnd);
    }

    /**
     * Wait until the child reports the given progress line.
     */
    public function waitFor(string $progress, float $seconds): void
    {
        $this->read($seconds, "progress [{$progress}]", fn (array $event): bool => ($event['progress'] ?? null) === $progress);
    }

    /**
     * Wait for the child's result.
     */
    public function result(float $seconds): mixed
    {
        $result = null;

        $this->read($seconds, 'the result', function (array $event) use (&$result): bool {
            if (! array_key_exists('result', $event)) {
                return false;
            }

            $result = $event['result'];

            return true;
        });

        $this->kill();

        return $result;
    }

    /**
     * The progress lines the child reported so far, in order.
     *
     * @return list<string>
     */
    public function progress(): array
    {
        return $this->progress;
    }

    /**
     * Kill the child unless it has ended, and reap it.
     */
    public function kill(): void
    {
        if (! $this->running) {
            return;
        }

        $this->running = false;
        posix_kill($this->pid, SIGKILL);
        pcntl_waitpid($this->pid, $status);
        fclose($this->channel);
    }

    /**
     * Read events until one matches, failing the test on the child's error, its end, or the limit.
     *
     * @param  Closure(array<string, mixed>): bool  $matches
     */
    private function read(float $seconds, string $waitingFor, Closure $matches): void
    {
        $until = hrtime(true) + (int) ($seconds * 1e9);

        while (true) {
            while (($newline = strpos($this->buffer, "\n")) !== false) {
                $event = json_decode(substr($this->buffer, 0, $newline), true, flags: JSON_THROW_ON_ERROR);
                $this->buffer = substr($this->buffer, $newline + 1);
                assert(is_array($event));

                if (isset($event['error'])) {
                    $this->fail("The child failed: {$event['error']}");
                }

                if (isset($event['progress']) && is_string($event['progress'])) {
                    $this->progress[] = $event['progress'];
                }

                if ($matches($event)) {
                    return;
                }
            }

            $left = ($until - hrtime(true)) / 1e9;

            if ($left <= 0) {
                $this->fail(sprintf('The child did not report %s within %.1f s', $waitingFor, $seconds));
            }

            $read = [$this->channel];
            $write = $except = null;

            if (stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6)) === 0) {
                continue;
            }

            $chunk = fread($this->channel, 65536);

            if ($chunk === '' || $chunk === false) {
                if (feof($this->channel)) {
                    $this->fail("The child ended before it reported {$waitingFor}");
                }

                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    private function fail(string $message): never
    {
        $this->kill();
        $last = $this->progress === [] ? 'none' : end($this->progress);

        Assert::fail("{$message}; its last progress: {$last}.");
    }
}
