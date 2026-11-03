<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the bearer token against the resolved instance's `mcp_token`. Must run
 * after `instance.resolve`. An instance without a token rejects every request.
 */
class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Instance $instance */
        $instance = $request->attributes->get('warm.instance');

        $expected = (string) $instance->mcp_token;
        $provided = $request->bearerToken();

        if ($expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
