<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Gzip larger JSON answers (e.g. release manifests listing thousands of files) when the installation accepts it. */
class CompressJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse
            && str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            && strlen((string) $response->getContent()) > 2048
            && function_exists('gzencode')) {
            $response->setContent(gzencode((string) $response->getContent(), 6));
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Vary', 'Accept-Encoding');
            $response->headers->remove('Content-Length');
        }

        return $response;
    }
}
