<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the instance named in the route path and exposes it on the request
 * so the token middleware and the handlers downstream can scope to it.
 *
 * An unknown or inactive instance is invisible: 404, never 403.
 */
class ResolveInstance
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->route('instance');

        $instance = Instance::query()
            ->where('instance_id', $key)
            ->where('active', true)
            ->first();

        if ($instance === null) {
            abort(404);
        }

        $request->attributes->set('warm.instance', $instance);

        return $next($request);
    }
}
