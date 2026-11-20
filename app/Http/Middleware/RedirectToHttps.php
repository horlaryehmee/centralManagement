<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the site's address (APP_URL) is https://, send plain http:// page visits there.
 * The login cookie is HTTPS-only, so an http:// visit would otherwise end in "419 Page Expired".
 * Only GET/HEAD are redirected; the installation API rejects plain HTTP on its own (RequireHttps).
 */
class RedirectToHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure()
            && str_starts_with((string) config('app.url'), 'https://')
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && ! $request->is('api/*')) {
            return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
