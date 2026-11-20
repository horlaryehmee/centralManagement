<?php

use App\Http\Middleware\AuthenticateInstallation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Fresh upload, first visit: create .env (new APP_KEY) so the setup wizard at /install can run.
if (PHP_SAPI !== 'cli' && ! is_file(dirname(__DIR__).'/.env')) {
    \App\Support\Installer::prepareEnv(dirname(__DIR__));
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Not installed yet → the setup wizard (/install).
        $middleware->prepend(\App\Http\Middleware\EnsureInstalled::class);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias([
            'central.installation' => AuthenticateInstallation::class,
            'central.https' => RequireHttps::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        // Behind a proxy or load balancer (common on hosting), trust its HTTPS headers.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The installation API always answers in JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
