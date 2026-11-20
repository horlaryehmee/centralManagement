<?php

namespace App\Http\Middleware;

use App\Models\Installation;
use App\Services\ApiException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an installation's signed request.
 *
 * Headers: X-Installation-Id, X-Timestamp (unix seconds), X-Nonce (16–64 [A-Za-z0-9]),
 *          X-Signature = hex HMAC-SHA256(secret, METHOD \n path \n timestamp \n nonce \n sha256(body))
 * where path is the request path without a leading slash, e.g. "api/v1/heartbeat".
 *
 * Rejects: unknown/revoked/unconnected installations, wrong signatures, timestamps
 * outside ±5 minutes, and reused nonces (replay protection).
 */
class AuthenticateInstallation
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Installation-Id');
        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $signature = strtolower((string) $request->header('X-Signature'));

        if (! preg_match('/^[0-9a-f-]{36}$/i', $id) || ! ctype_digit($timestamp) || ! preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce) || ! preg_match('/^[0-9a-f]{64}$/', $signature)) {
            throw new ApiException('unauthenticated', 'Missing or malformed authentication headers.', 401);
        }

        $installation = Installation::query()->with('client')->where('uuid', $id)->first();
        // Same answer for "unknown" and "wrong secret": never reveal which installations exist.
        if (! $installation || ! $installation->secret || in_array($installation->status, ['pending', 'revoked'], true)) {
            throw new ApiException('unauthenticated', 'Invalid credentials.', 401);
        }

        if (abs(time() - (int) $timestamp) > (int) config('central.request_tolerance')) {
            throw new ApiException('clock_skew', 'Request timestamp is too far from the server time. Check the website server\'s clock.', 401);
        }

        $canonical = implode("\n", [strtoupper($request->method()), $request->path(), $timestamp, $nonce, hash('sha256', $request->getContent())]);
        if (! hash_equals(hash_hmac('sha256', $canonical, $installation->secret), $signature)) {
            throw new ApiException('unauthenticated', 'Invalid credentials.', 401);
        }

        try {
            DB::table('api_nonces')->insert(['installation_id' => $installation->id, 'nonce' => $nonce, 'created_at' => now()]);
        } catch (QueryException) {
            throw new ApiException('replayed', 'This request was already received.', 401);
        }

        $installation->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        $request->attributes->set('installation', $installation);

        return $next($request);
    }
}
