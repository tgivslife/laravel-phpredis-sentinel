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

        // Anything but a string literal (a constant, a concatenation, an interpolated string) is refused, not
        // skipped: a fragment the reader cannot see would quietly drop out of the tests.
        foreach (token_get_all('<?php '.$match['list']) as $token) {
            if ($token === ',' || (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true))) {
                continue;
            }

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || ! in_array($token[1][0], ["'", '"'], true)) {
                throw new RuntimeException('causedByLostConnection() lists something other than string literals; review it.');
            }

            $text = substr($token[1], 1, -1);

            // A double-quoted literal that interpolates is split into other tokens, so one without a backslash is
            // its own text. Its escapes follow rules of their own, so one with a backslash is refused, not decoded.
            if ($token[1][0] === '"' && str_contains($text, '\\')) {
                throw new RuntimeException('causedByLostConnection() lists a double-quoted string with an escape; review it.');
            }

            // A single-quoted literal knows two escapes only.
            $fragments[] = $token[1][0] === "'" ? strtr($text, ['\\\\' => '\\', "\\'" => "'"]) : $text;
        }

        if ($fragments === []) {
            throw new RuntimeException('causedByLostConnection() lists no fragment; review it.');
        }

        return $fragments;
    }
}
