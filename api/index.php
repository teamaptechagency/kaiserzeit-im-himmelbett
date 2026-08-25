<?php

/**
 * The front controller Vercel runs.
 *
 * Vercel serves PHP as a function, not from a document root, so it cannot use
 * public/index.php directly — every request is routed here by vercel.json and
 * handed on from this file.
 *
 * The one thing that has to happen before Laravel boots is storage. Vercel's
 * filesystem is read-only apart from /tmp, and Laravel writes compiled Blade
 * views, its cache and its logs under storage/ on the first request. Pointed
 * at the project it would die on boot; pointed at /tmp it works, at the cost
 * of that being wiped between cold starts — which is fine for compiled views
 * and fatal for anything else, hence the notes in LARAVEL.md about the
 * database and photo storage having to live elsewhere.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/* Only /tmp is writable here. Create the tree Laravel expects inside it. */
$storage = '/tmp/storage';

foreach ([
    $storage,
    $storage . '/app',
    $storage . '/app/public',
    $storage . '/framework',
    $storage . '/framework/cache',
    $storage . '/framework/cache/data',
    $storage . '/framework/sessions',
    $storage . '/framework/views',
    $storage . '/logs',
] as $directory) {
    if (! is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
}

require __DIR__ . '/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->useStoragePath($storage);

/*
 * Warm the config cache into /tmp on a cold start.
 *
 * Without it every request re-reads and re-parses every file in config/,
 * which on a serverless function is paid for on each invocation rather than
 * once at boot.
 */
if (! is_file($storage . '/framework/cache/config.php')) {
    try {
        $app->make(ConsoleKernel::class)->call('config:cache');
    } catch (Throwable $error) {
        /* A cold start that cannot cache is slower, not broken. */
    }
}

$kernel   = $app->make(Kernel::class);
$request  = Request::capture();
$response = $kernel->handle($request);

$response->send();

$kernel->terminate($request, $response);
