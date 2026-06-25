<?php

/**
 * Vercel serverless entrypoint (must live in api/ — required by the platform).
 * Laravel routes are exposed at /v1/* (not /api/v1/*) to avoid conflicting with
 * Vercel's reserved /api path for serverless functions.
 */
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$storagePath = '/tmp/storage';
$bootstrapCachePath = '/tmp/bootstrap/cache';

foreach ([
    $storagePath.'/framework/cache/data',
    $storagePath.'/framework/sessions',
    $storagePath.'/framework/views',
    $storagePath.'/logs',
    $storagePath.'/app',
    $bootstrapCachePath,
    '/tmp/views',
] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

$maintenance = $storagePath.'/framework/maintenance.php';
if (file_exists($maintenance)) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->useStoragePath($storagePath);

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
