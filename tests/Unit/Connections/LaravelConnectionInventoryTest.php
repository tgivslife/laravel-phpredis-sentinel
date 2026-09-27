<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit\Connections;

use Illuminate\Redis\Connections\PhpRedisConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The inventory of Laravel's PhpRedisConnection as reviewed for 13.33: every public and protected method, trait
 * methods included, with what its own body does with the client, read from its source.
 *
 * A release that adds, removes or renames a method, changes its visibility, or changes what it does with the client
 * fails here, which forces a review of section 5's contract before the release is declared supported.
 *
 * Each method is classified by its own body; a `parent::` call is followed, since an override that delegates does
 * what its parent does. Other calls are not, or `connector` would swallow most methods: a change in a callee shows on
 * the callee's own entry. The first category a body shows, in this order, is its category:
 *
 * - `connector`: touches `$this->connector`, the closure a rebuild reconnects through;
 * - `network`: calls the client with network I/O, a dynamic call such as `->{$method}` included;
 * - `command`: goes through `$this->command()`;
 * - `client-other`: hands the client to a function, returns it or wraps it, so calls made through it skip the loop;
 * - `client-local`: uses the client for options, its local `_` helpers or `close()` only;
 * - `none`: does not use the client.
 */
final class LaravelConnectionInventoryTest extends TestCase
{
    /**
     * As of Laravel 13.33.
     *
     * @var array<string, string>
     */
    private const array REVIEWED = [
        '__call' => 'public command',
        '__callStatic' => 'public none',
        '__construct' => 'public connector',
        'blpop' => 'public command',
        'brpop' => 'public command',
        'causedByLostConnection' => 'protected none',
        'client' => 'public client-other',
        'command' => 'public network',
        'compressed' => 'public client-local',
        'createSubscription' => 'public none',
        'disconnect' => 'public client-local',
        'eval' => 'public command',
        'evalsha' => 'public command',
        'event' => 'protected none',
        'executeRaw' => 'public command',
        'flushMacros' => 'public none',
        'flushdb' => 'public command',
        'funnel' => 'public none',
        'get' => 'public command',
        'getEventDispatcher' => 'public none',
        'getName' => 'public none',
        'hasHashTag' => 'public none',
        'hasMacro' => 'public none',
        'hmget' => 'public command',
        'hmset' => 'public command',
        'hscan' => 'public network',
        'hsetnx' => 'public command',
        'isCluster' => 'public none',
        'isRetryable' => 'protected none',
        'listen' => 'public none',
        'listenForFailures' => 'public none',
        'lrem' => 'public command',
        'lz4Compressed' => 'public client-local',
        'lzfCompressed' => 'public client-local',
        'macro' => 'public none',
        'macroCall' => 'public none',
        'mget' => 'public command',
        'mixin' => 'public none',
        'pack' => 'public client-local',
        'parseParametersForEvent' => 'protected none',
        'phpRedisVersionAtLeast' => 'protected none',
        'pipeline' => 'public network',
        'psubscribe' => 'public network',
        'rebuildClient' => 'protected connector',
        'rebuildClientOnLostConnection' => 'protected none',
        'retryOnceOnLostConnection' => 'protected none',
        'scan' => 'public network',
        'serialized' => 'public client-local',
        'set' => 'public command',
        'setEventDispatcher' => 'public none',
        'setName' => 'public none',
        'setnx' => 'public command',
        'spop' => 'public command',
        'sscan' => 'public network',
        'subscribe' => 'public network',
        'supportsLzf' => 'protected none',
        'supportsPacking' => 'protected none',
        'supportsZstd' => 'protected none',
        'throttle' => 'public none',
        'transaction' => 'public network',
        'unsetEventDispatcher' => 'public none',
        'withoutSerializationOrCompression' => 'public client-local',
        'zadd' => 'public command',
        'zinterstore' => 'public command',
        'zrangebyscore' => 'public command',
        'zrevrangebyscore' => 'public command',
        'zscan' => 'public network',
        'zstdCompressed' => 'public client-local',
        'zunionstore' => 'public command',
    ];

    /**
     * Client methods that never reach the server.
     *
     * @var list<string>
     */
    private const array LOCAL_CLIENT_METHODS = [
        '_compress', '_pack', '_prefix', '_serialize', '_uncompress', '_unpack', '_unserialize',
        'close', 'getoption', 'setoption',
    ];

    public function test_every_method_matches_the_review_for_laravel_13_33(): void
    {
        $methods = (new ReflectionClass(PhpRedisConnection::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);
        $found = [];

        foreach ($methods as $method) {
            $found[$method->getName()] = ($method->isPublic() ? 'public ' : 'protected ').self::category($method);
        }

        ksort($found);
        $reviewed = self::REVIEWED;
        ksort($reviewed);

        $this->assertSame($reviewed, $found, "PhpRedisConnection changed: review section 5's contract, then this list");
    }

    public function test_the_classifier_sees_each_category_in_a_body_it_is_given(): void
    {
        // Pins the reading itself, on methods whose body is known, so a regex change cannot quietly mark all as none.
        $this->assertSame('connector', self::category(new ReflectionMethod(PhpRedisConnection::class, 'rebuildClient')));
        $this->assertSame('network', self::category(new ReflectionMethod(PhpRedisConnection::class, 'scan')));
        $this->assertSame('command', self::category(new ReflectionMethod(PhpRedisConnection::class, 'get')));
        $this->assertSame('client-other', self::category(new ReflectionMethod(PhpRedisConnection::class, 'client')), 'it hands the client out');
        $this->assertSame('client-local', self::category(new ReflectionMethod(PhpRedisConnection::class, 'disconnect')));
        $this->assertSame('none', self::category(new ReflectionMethod(PhpRedisConnection::class, 'getName')));
        $this->assertSame('command', self::category(new ReflectionMethod(PhpRedisConnection::class, '__call')), 'through parent::__call()');
    }

    /**
     * What a method's body does with the client: the first category it shows, in order of precedence.
     */
    private static function category(ReflectionMethod $method): string
    {
        $signals = self::signals($method);

        foreach (['connector', 'network', 'command', 'client-other', 'client-local'] as $category) {
            if (in_array($category, $signals, true)) {
                return $category;
            }
        }

        return 'none';
    }

    /**
     * Every category a method's body shows, its parent's included where it calls `parent::`.
     *
     * @return list<string>
     */
    private static function signals(ReflectionMethod $method): array
    {
        // A trait method's lines are in the trait's file, and getFileName() points there.
        $lines = file((string) $method->getFileName()) ?: [];
        $body = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
        $signals = [];

        if (preg_match('/\$this->connector\b/', $body) === 1) {
            $signals[] = 'connector';
        }

        preg_match_all('/\$(?:this->client(?:\(\))?|client)->(\{|\w+)/', $body, $calls);

        foreach ($calls[1] as $call) {
            $signals[] = in_array(strtolower($call), self::LOCAL_CLIENT_METHODS, true) ? 'client-local' : 'network';
        }

        if (preg_match('/\$this->command\(/', $body) === 1) {
            $signals[] = 'command';
        }

        // Any other use of the client: handed to a function, returned or wrapped. An alias's source and an
        // assignment's target are not uses, and a call on it is read above; the possessive ?+ keeps
        // `$this->client()->x()` a call.
        $other = preg_replace(['/\$client\s*=\s*\$this->client\b(?!\()/', '/\$(?:this->client|client)\s*=(?!=)/'], '', $body);

        if (preg_match('/\$(?:this->client\b(?:\(\))?+|client\b)(?!\s*->)/', (string) $other) === 1) {
            $signals[] = 'client-other';
        }

        $parent = $method->getDeclaringClass()->getParentClass();
        preg_match_all('/parent::(\w+)\(/', $body, $parentCalls);

        foreach ($parentCalls[1] as $name) {
            if ($parent !== false && $parent->hasMethod($name)) {
                $signals = [...$signals, ...self::signals($parent->getMethod($name))];
            }
        }

        return $signals;
    }
}
