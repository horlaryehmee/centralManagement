<?php

namespace App\Http\Middleware;

use App\Services\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The installation API only works over HTTPS (plain HTTP only when explicitly allowed for local testing). */
class RequireHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure() && ! config('central.allow_insecure_api')) {
            throw new ApiException('https_required', 'The update API only accepts HTTPS.', 403);
        }

        return $next($request);
    }
}
