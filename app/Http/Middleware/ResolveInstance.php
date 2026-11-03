<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the instance the request targets and exposes it on the request so
 * the token middleware and the handlers downstream can scope to it.
 *
 * Routes with an `{instance}` segment target that instance; the legacy routes
 * without one target the default instance. Either way the instance row is the
 * single source of its tokens. An unknown or inactive instance is invisible:
 * 404, never 403.
 */
class ResolveInstance
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('instance');

        $instance = Instance::query()
            ->when(
                $key === null,
                fn ($query) => $query->where('is_default', true),
                fn ($query) => $query->where('instance_id', (string) $key),
            )
            ->where('active', true)
            ->first();

        if ($instance === null) {
            abort(404);
        }

        $request->attributes->set('warm.instance', $instance);

        return $next($request);
    }
}
