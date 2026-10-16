<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            EnsureAccountIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// The portable launcher keeps secrets and writable data outside replaceable app files.
if (filter_var(getenv('STOCKNEST_PORTABLE') ?: ($_SERVER['STOCKNEST_PORTABLE'] ?? false), FILTER_VALIDATE_BOOLEAN)) {
    $dataDirectory = rtrim((string) (getenv('STOCKNEST_DATA_DIR') ?: ($_SERVER['STOCKNEST_DATA_DIR'] ?? '')), '/\\');

    if ($dataDirectory === '' || ! preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $dataDirectory)) {
        throw new RuntimeException('The portable launcher must provide an absolute StockNest data directory.');
    }

    $app->useEnvironmentPath($dataDirectory);
    $app->useStoragePath($dataDirectory.DIRECTORY_SEPARATOR.'storage');

    // Laravel's default cache resolver recognizes / paths, but Windows uses drive roots.
    if (preg_match('~^[A-Za-z]:[/\\\\]~', $dataDirectory)) {
        $app->addAbsoluteCachePathPrefix(substr($dataDirectory, 0, 2).'\\');
        $app->addAbsoluteCachePathPrefix(substr($dataDirectory, 0, 2).'/');
    }
}

return $app;
