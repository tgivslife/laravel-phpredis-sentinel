<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit;

use Composer\InstalledVersions;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The Laravel code the package depends on, fingerprinted as reviewed: any change to it fails here until reviewed.
 *
 * The package overrides protected methods of PhpRedisConnection, builds its data-node clients through
 * PhpRedisConnector::createClient(), and registers with the RedisManager, none of which Laravel documents. Every
 * public and protected method of PhpRedisConnection, of its parent Connection (those it overrides included) and of
 * PhpRedisConnector, traits' included, and the RedisManager methods the provider relies on, is hashed over its tokens
 * without whitespace or comments, so a reformat passes and any other change, a refactor included, fails with the
 * method it touched.
 *
 * Before updating a fingerprint, read the diff the failure names and check:
 *
 * - no PhpRedisConnection method reconnects through `$this->connector`, or calls a hook that does, unless the
 *   package's connection overrides or neutralises it: a rebuild from the cached address after a failover reaches the
 *   demoted master;
 * - no PhpRedisConnection method uses the client other than through command() unless the package's connection wraps
 *   it, or a failover there is not recovered;
 * - the hooks the connection overrides (retryOnceOnLostConnection(), rebuildClient()) are still what the methods
 *   around them call, and Connection::command(), which it calls directly to skip Laravel's retry, still only runs
 *   the command and dispatches its events; and every caller of retryOnceOnLostConnection() runs inside the package's
 *   retry loop, which is the only place that unwraps the UnretriedFailure it may throw;
 * - createClient() and establishConnection() still send nothing to the server before the connector's own AUTH,
 *   SELECT and CLIENT SETNAME (it strips password, database and name first), and createClient() still applies
 *   max_retries right after connecting, which the connector's zero relies on;
 * - RedisManager::connector() still consults a custom creator first, and connect() still gets the parsed connection
 *   block plus the merged options.
 */
final class LaravelFingerprintTest extends TestCase
{
    /**
     * The laravel/framework release the fingerprints were reviewed against.
     */
    private const string REVIEWED_LARAVEL = '13.33.0';

    /**
     * The RedisManager methods the provider relies on.
     *
     * @var list<string>
     */
    private const array MANAGER_METHODS = ['connector', 'extend', 'parseConnectionConfiguration', 'resolve', 'resolveCluster'];

    /**
     * @var array<string, string>
     */
    private const array REVIEWED = [
        'Connection::__call' => '74e9e3673a44591c',
        'Connection::__callStatic' => '2c494f1ec0ef48ed',
        'Connection::client' => '437651533b3cdd6d',
        'Connection::command' => '120223354e5bf148',
        'Connection::createSubscription' => '80a5f3398ef02fdd',
        'Connection::event' => '92c95f869addbc7e',
        'Connection::flushMacros' => '20785274255d484c',
        'Connection::funnel' => '7661f2dd9b7840b3',
        'Connection::getEventDispatcher' => '475d715f9b5176c7',
        'Connection::getName' => '9680565bcaf28dc2',
        'Connection::hasHashTag' => '4d80a7c69c2e579b',
        'Connection::hasMacro' => '5b619dc475325843',
        'Connection::isCluster' => 'ec5f6735d3157ac6',
        'Connection::listen' => 'e3b2a83820b5293a',
        'Connection::listenForFailures' => '2e75da245825894e',
        'Connection::macro' => 'd7d30fb2447cfdb6',
        'Connection::macroCall' => '4ca53f9da0a2ccdc',
        'Connection::mixin' => '576acaf47f23a96c',
        'Connection::parseParametersForEvent' => 'abd24082adcd71a2',
        'Connection::psubscribe' => '39483fc140a27f5c',
        'Connection::setEventDispatcher' => '84b21031af6ca47e',
        'Connection::setName' => '75db82a16addf6db',
        'Connection::subscribe' => 'eaf669c3b8b228cb',
        'Connection::throttle' => '0fbba2b3a3e38a5c',
        'Connection::unsetEventDispatcher' => '285969cbe12226ba',
        'PhpRedisConnection::__call' => 'b5bb3d9a4db29f0c',
        'PhpRedisConnection::__construct' => '4236cd156455fd19',
        'PhpRedisConnection::blpop' => 'c993abed8eb72399',
        'PhpRedisConnection::brpop' => 'cf65797d43a8dcd1',
        'PhpRedisConnection::causedByLostConnection' => '663710a59deb45ac',
        'PhpRedisConnection::command' => 'ead97c51e0038037',
        'PhpRedisConnection::compressed' => '9a90190647a999a9',
        'PhpRedisConnection::createSubscription' => '332c7cfb39fd8a7f',
        'PhpRedisConnection::disconnect' => 'd637d2c676a16d87',
        'PhpRedisConnection::eval' => 'e3bfc4ebaebd8fe7',
        'PhpRedisConnection::evalsha' => '620573b0261e1925',
        'PhpRedisConnection::executeRaw' => '400d2183c80b6120',
        'PhpRedisConnection::flushdb' => '327fa0aae3bef2a9',
        'PhpRedisConnection::get' => 'cd9e9f2cb7edd2e8',
        'PhpRedisConnection::hmget' => 'fd21e60475e9e1ac',
        'PhpRedisConnection::hmset' => '2b67007626ebfd87',
        'PhpRedisConnection::hscan' => 'ee50e7ea441b3000',
        'PhpRedisConnection::hsetnx' => '8e97bd4e6ffa7a4d',
        'PhpRedisConnection::isRetryable' => '9fcc92a529c92bd4',
        'PhpRedisConnection::lrem' => '4ab0f2087f04d717',
        'PhpRedisConnection::lz4Compressed' => '20532bf51ae09d81',
        'PhpRedisConnection::lzfCompressed' => '39fecfcf0e814823',
        'PhpRedisConnection::mget' => 'b1e75870cad10839',
        'PhpRedisConnection::pack' => 'd167138a24126a52',
        'PhpRedisConnection::phpRedisVersionAtLeast' => '0ce5c9be5ea7d7c6',
        'PhpRedisConnection::pipeline' => 'b2061084e2be3a2c',
        'PhpRedisConnection::psubscribe' => 'ef2faf907066be2f',
        'PhpRedisConnection::rebuildClient' => 'e05ebcd4b0bed4d4',
        'PhpRedisConnection::rebuildClientOnLostConnection' => '92354281b262e044',
        'PhpRedisConnection::retryOnceOnLostConnection' => '1a6965d03b595691',
        'PhpRedisConnection::scan' => '9be4082e4199c925',
        'PhpRedisConnection::serialized' => '8526513164751559',
        'PhpRedisConnection::set' => 'cc7dc7ff34a8f2cd',
        'PhpRedisConnection::setnx' => '149d8d16f904dd97',
        'PhpRedisConnection::spop' => '86ee119cf6f4ef6e',
        'PhpRedisConnection::sscan' => '6d8eaff0adecbb60',
        'PhpRedisConnection::subscribe' => '58df9610f8a4c73f',
        'PhpRedisConnection::supportsLzf' => '11f3cf4892dbb72c',
        'PhpRedisConnection::supportsPacking' => 'e0186df3adc6197d',
        'PhpRedisConnection::supportsZstd' => 'b5eed46b7edcd1c4',
        'PhpRedisConnection::transaction' => '55590573ba443f0a',
        'PhpRedisConnection::withoutSerializationOrCompression' => '9a5570d5eb490c05',
        'PhpRedisConnection::zadd' => 'c597d1659a795a20',
        'PhpRedisConnection::zinterstore' => 'effcc3d8d02c33e6',
        'PhpRedisConnection::zrangebyscore' => 'a4a732832d48c4ae',
        'PhpRedisConnection::zrevrangebyscore' => '83a9cd7f57200e23',
        'PhpRedisConnection::zscan' => '001070bff9f89d11',
        'PhpRedisConnection::zstdCompressed' => 'f4e78188616cf062',
        'PhpRedisConnection::zunionstore' => '888bf80c701c7211',
        'PhpRedisConnector::buildClusterConnectionString' => '3e7ddc7452bcd7b3',
        'PhpRedisConnector::connect' => '46c2c3ad4f27ef82',
        'PhpRedisConnector::connectToCluster' => 'e4d5bba1e73243e7',
        'PhpRedisConnector::createClient' => '3f882bf44bade55f',
        'PhpRedisConnector::createRedisClusterInstance' => 'e8c8bc900bf606b7',
        'PhpRedisConnector::establishConnection' => '45968cfe494799d5',
        'PhpRedisConnector::formatClusterPassword' => '46b81b4fbfe320ed',
        'PhpRedisConnector::formatHost' => 'd3f49e615d4bd2d6',
        'PhpRedisConnector::normalizeClusterContext' => 'd0270cbfde92fbb3',
        'PhpRedisConnector::normalizeContext' => 'a85990838a70270b',
        'PhpRedisConnector::parseBackoffAlgorithm' => '16786ae81dc875d5',
        'RedisManager::connector' => '4a87bf735ffbd08e',
        'RedisManager::extend' => 'ea3752343b5dc485',
        'RedisManager::parseConnectionConfiguration' => '00bd73e63ee9f195',
        'RedisManager::resolve' => '25f2aed539feaa42',
        'RedisManager::resolveCluster' => '76e4727870f6cc9d',
    ];

    public function test_the_laravel_code_the_package_depends_on_is_as_reviewed(): void
    {
        $found = self::fingerprints();
        $added = array_diff_key($found, self::REVIEWED);
        $removed = array_diff_key(self::REVIEWED, $found);
        $changed = array_diff_assoc(array_intersect_key($found, self::REVIEWED), self::REVIEWED);

        if ($added === [] && $removed === [] && $changed === []) {
            $this->addToAssertionCount(1);

            return;
        }

        $installed = ltrim((string) InstalledVersions::getPrettyVersion('laravel/framework'), 'v');
        $lines = static fn (array $methods): string => implode('', array_map(
            static fn (string $method, string $hash): string => "        '{$method}' => '{$hash}',\n",
            array_keys($methods),
            $methods,
        ));

        $this->fail(
            'Laravel code the package depends on differs from what was reviewed for '.self::REVIEWED_LARAVEL.'.'."\n"
            .'Read, in laravel/framework: git diff v'.self::REVIEWED_LARAVEL." v{$installed} -- src/Illuminate/Redis/ src/Illuminate/Macroable/\n"
            ."Check it against the list in this test's docblock; only then update REVIEWED_LARAVEL and REVIEWED.\n"
            .($changed === [] ? '' : "Changed:\n".$lines($changed))
            .($added === [] ? '' : "Added:\n".$lines($added))
            .($removed === [] ? '' : 'Removed: '.implode(', ', array_keys($removed))."\n")
        );
    }

    public function test_a_reformat_leaves_a_fingerprint_unchanged_and_an_edit_does_not(): void
    {
        $method = "public function get(\$key)\n{\n    return \$this->command('get', [\$key]);\n}\n";
        $reformatted = "public function get( \$key ) // a comment\n{\n\n        /* another */ return \$this->command( 'get', [ \$key ] );\n}\n";
        $edited = "public function get(\$key)\n{\n    return \$this->command('getex', [\$key]);\n}\n";

        $this->assertSame(self::hash($method), self::hash($reformatted));
        $this->assertNotSame(self::hash($method), self::hash($edited));
    }

    /**
     * The fingerprint of every method this test watches, keyed by class short name and method.
     *
     * @return array<string, string>
     */
    private static function fingerprints(): array
    {
        $methods = [];

        foreach ([PhpRedisConnection::class, PhpRedisConnector::class] as $class) {
            // Each class up the hierarchy, keyed by the one that declares the method, so a parent method the child
            // overrides is watched too: the package calls Connection::command() directly.
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                    if ($method->class === $reflection->getName()) {
                        $methods[$reflection->getShortName().'::'.$method->getName()] = $method;
                    }
                }
            }
        }

        foreach (self::MANAGER_METHODS as $name) {
            $methods['RedisManager::'.$name] = new ReflectionMethod(RedisManager::class, $name);
        }

        ksort($methods);

        return array_map(static function (ReflectionMethod $method): string {
            // A trait method's lines are in the trait's file, and getFileName() points there.
            $lines = file((string) $method->getFileName()) ?: [];

            return self::hash(implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1)));
        }, $methods);
    }

    /**
     * A source fragment's tokens, whitespace and comments dropped, joined and hashed.
     *
     * The token texts are hashed, not their kinds, so a PHP release that splits the same code into other tokens
     * leaves the fingerprint as it was.
     */
    private static function hash(string $source): string
    {
        $text = '';

        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $text .= is_array($token) ? $token[1] : $token;
        }

        return substr(hash('sha256', $text), 0, 16);
    }
}
