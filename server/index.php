<?php

/**
 * Vercel serverless entrypoint.
 * Must live outside /api — Vercel reserves that path for serverless functions,
 * which conflicts with Laravel routes prefixed with /api.
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
