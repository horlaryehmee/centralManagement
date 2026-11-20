<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** The setup wizard is plain HTML and runs before there is a database. */
    public function handle(Request $request, \Closure $next)
    {
        if ($request->is('install', 'install/*')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app_name' => config('app.name'),
            'auth' => ['user' => fn () => $request->user()?->only('id', 'name', 'email')],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // One-time connection code, shown once right after it is created.
                'enrollment' => fn () => $request->session()->get('enrollment'),
            ],
        ];
    }
}
