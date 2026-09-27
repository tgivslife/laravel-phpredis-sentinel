<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Support;

use Illuminate\Redis\Connections\PhpRedisConnection;
use ReflectionMethod;
use RuntimeException;

/**
 * The message fragments Laravel's PhpRedisConnection::causedByLostConnection() recognizes, read from its source.
 *
 * Read rather than copied, so a fragment a Laravel release adds reaches the tests that use it without an edit.
 */
final class LaravelLostConnectionMessages
{
    /**
     * @return list<string>
     */
    public static function fragments(): array
    {
        $method = new ReflectionMethod(PhpRedisConnection::class, 'causedByLostConnection');
        $lines = file((string) $method->getFileName()) ?: [];
        $body = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

        if (preg_match('/Str::contains\(\s*\$e->getMessage\(\),\s*\[(?<list>.*?)\]\s*\)/s', $body, $match) !== 1) {
            throw new RuntimeException('causedByLostConnection() no longer has the shape this reader expects; review it.');
        }

        $fragments = [];

        // Anything but a single-quoted literal (a double-quoted string, a constant, a concatenation) is refused,
        // not skipped: a fragment the reader cannot see would quietly drop out of the tests.
        foreach (token_get_all('<?php '.$match['list']) as $token) {
            if ($token === ',' || (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true))) {
                continue;
            }

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || $token[1][0] !== "'") {
                throw new RuntimeException('causedByLostConnection() lists something other than single-quoted strings; review it.');
            }

            // A single-quoted literal knows two escapes only.
            $fragments[] = strtr(substr($token[1], 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
        }

        if ($fragments === []) {
            throw new RuntimeException('causedByLostConnection() lists no fragment; review it.');
        }

        return $fragments;
    }
}
