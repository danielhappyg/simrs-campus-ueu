<?php

declare(strict_types=1);

/**
 * Vercel PHP function entry point for the Laravel application.
 *
 * Vercel functions have a read-only application filesystem. Point every
 * Laravel runtime path at /tmp before the framework is bootstrapped.
 */
$runtimeRoot = '/tmp/simrs-campus-ueu';

foreach ([
    $runtimeRoot,
    $runtimeRoot.'/framework/cache/data',
    $runtimeRoot.'/framework/sessions',
    $runtimeRoot.'/framework/views',
    $runtimeRoot.'/logs',
] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

$setDefaultEnvironment = static function (string $key, string $value): void {
    if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
        return;
    }

    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

$setDefaultEnvironment('LARAVEL_STORAGE_PATH', $runtimeRoot);
$setDefaultEnvironment('APP_CONFIG_CACHE', $runtimeRoot.'/config.php');
$setDefaultEnvironment('APP_EVENTS_CACHE', $runtimeRoot.'/events.php');
$setDefaultEnvironment('APP_PACKAGES_CACHE', $runtimeRoot.'/packages.php');
$setDefaultEnvironment('APP_ROUTES_CACHE', $runtimeRoot.'/routes.php');
$setDefaultEnvironment('APP_SERVICES_CACHE', $runtimeRoot.'/services.php');
$setDefaultEnvironment('VIEW_COMPILED_PATH', $runtimeRoot.'/framework/views');
$setDefaultEnvironment('LOG_CHANNEL', 'stderr');
$setDefaultEnvironment('APP_ENV', 'production');
$setDefaultEnvironment('APP_DEBUG', 'false');
$setDefaultEnvironment('APP_MAINTENANCE_DRIVER', 'cache');
$setDefaultEnvironment('APP_MAINTENANCE_STORE', 'database');
$setDefaultEnvironment('SESSION_SECURE_COOKIE', 'true');

if (getenv('APP_URL') === false) {
    $vercelHost = getenv('VERCEL_PROJECT_PRODUCTION_URL') ?: getenv('VERCEL_URL');

    if (is_string($vercelHost) && $vercelHost !== '') {
        $setDefaultEnvironment('APP_URL', 'https://'.$vercelHost);
    }
}

require __DIR__.'/../public/index.php';
