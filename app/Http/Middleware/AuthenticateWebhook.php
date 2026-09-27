<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('warm.webhook_token');

        // A missing token is only tolerable in local/testing; anywhere else it
        // means misconfiguration, so every request is rejected.
        if ($expected === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            return response()->json(['status' => 'unauthorized'], 401);
        }

        $provided = $request->header('x-webhook-token') ?? $request->query('token');

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
