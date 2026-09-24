# Laravel PhpRedis Sentinel

Redis Sentinel support for Laravel using the PhpRedis extension.

> **Status:** pre-release scaffold. The driver is not implemented yet and the package is not published to Packagist. Do not use it in production.

## Scope

- Keeps `database.redis.client = phpredis` for standalone, Redis Cluster and Sentinel connections.
- Selects Sentinel per named connection by the presence of `sentinel_hosts`.
- Delegates standalone and Cluster connections to Laravel's native PhpRedis connector.
- Supports Laravel Horizon.
- Requires `ext-redis`; has no Predis dependency or fallback.

## Requirements

- PHP 8.3+
- Laravel 13
- phpredis 6.3+

Supported version ranges will be set from the tested compatibility matrix before the first release.

## Development

```bash
composer install
composer test        # validate, Pint, PHPStan, Unit + Feature suites
composer lint        # apply Pint formatting
```

Laravel Horizon requires `ext-pcntl` and `ext-posix`. `composer.json` declares both as platform overrides for this package's development install only, so `composer install` also works on Windows.

## License

MIT. See [LICENSE.md](LICENSE.md).
