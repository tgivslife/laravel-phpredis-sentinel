<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * Run against a runtime-only graph, as the "dependencies" CI job does: remove
 * require-dev and autoload-dev from composer.json, then `composer update`.
 * `composer install --no-dev` is not enough: Testbench's laravel/framework still
 * wins resolution and replaces the illuminate/* components, and --no-dev keeps it
 * because it satisfies illuminate/support, so every Illuminate class is reported
 * as a laravel/framework shadow dependency.
 */
return (new Configuration)
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    // Declared up front by the plan; the extracted connector will use \Redis directly.
    ->ignoreErrorsOnExtension('ext-redis', [ErrorType::UNUSED_DEPENDENCY]);
