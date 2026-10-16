<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $instance = $request->attributes->get('warm.instance');

        // Instance-scoped route: the bearer belongs to that instance; missing
        // or wrong tokens are rejected (401), never silently disabled.
        if ($instance instanceof Instance) {
            $expected = (string) $instance->mcp_token;

            if ($expected === '') {
                return response()->json(['status' => 'unauthorized'], 401);
            }

            $provided = $request->bearerToken();

            if (! is_string($provided) || ! hash_equals($expected, $provided)) {
                return response()->json(['status' => 'unauthorized'], 401);
            }

            return $next($request);
        }

        $expected = (string) config('warm.mcp_token');

        // The MCP endpoint can send WhatsApp messages, so it stays disabled
        // (404) until an explicit token is configured.
        if ($expected === '') {
            abort(404);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
