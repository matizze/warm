<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('warm.admin_token');

        // Admin endpoints are disabled unless a token is configured.
        if ($expected === '') {
            abort(404);
        }

        $provided = $request->header('x-admin-token') ?? $request->query('token');

        if ($provided !== $expected) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
