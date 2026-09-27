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

        // Empty token disables authentication (dev only).
        if ($expected === '') {
            return $next($request);
        }

        $provided = $request->header('x-webhook-token') ?? $request->query('token');

        if ($provided !== $expected) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
