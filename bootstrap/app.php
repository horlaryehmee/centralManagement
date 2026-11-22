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
        // Site set up on https:// → always use it (the login cookie is HTTPS-only).
        // Appended so it runs after TrustProxies: behind a proxy, https must be recognised first.
        $middleware->append(\App\Http\Middleware\RedirectToHttps::class);
        // CSRF cookie named after this app, so another site's domain-wide "XSRF-TOKEN" can't break it.
        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\ValidateCsrfToken::class,
        ]);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias([
            'central.installation' => AuthenticateInstallation::class,
            'central.https' => RequireHttps::class,
        ]);
        // During a self-update the app is briefly in maintenance mode. The Updates page (which drives the
        // update) and login stay reachable; the websites' API answers 503 meanwhile and they retry later.
        $middleware->preventRequestsDuringMaintenance(except: ['updates', 'updates/*', 'login', 'build/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        // Behind a proxy or load balancer (common on hosting), trust its HTTPS headers.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The installation API always answers in JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Stale form (page left open, session expired, or cookie lost): go back and ask to retry
        // instead of showing "419 Page Expired".
        $exceptions->respond(function ($response, \Throwable $e, Request $request) {
            if ($response->getStatusCode() === 419 && ! $request->is('api/*')) {
                // 303 for PUT/PATCH/DELETE/POST: browsers repeat a 302 with the same method, which
                // would hit a GET-only page ("405 Method Not Allowed").
                return back(fallback: '/')->withInput($request->except('password', 'password_confirmation', '_token'))
                    ->with('error', 'Your session expired. Please try again.')
                    ->setStatusCode($request->isMethod('GET') ? 302 : 303);
            }

            return $response;
        });
    })->create();
