<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\AuthenticateWebhook;
use App\Http\Middleware\ResolveInstance;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'instance.resolve' => ResolveInstance::class,
            'webhook.auth' => AuthenticateWebhook::class,
            'admin.auth' => AuthenticateAdmin::class,
            'mcp.auth' => AuthenticateMcp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The webhook contract is always JSON: any exception while handling a
        // payload is reported centrally (with Log::withContext context) and
        // rendered as a generic provider-facing error the sender can retry.
        $exceptions->render(function (Throwable $e, Request $request) {
            // Keeps HTTP errors (unknown instance -> 404) intact; only genuine
            // handling failures become the provider-facing error contract.
            if ($e instanceof HttpExceptionInterface) {
                return null;
            }

            if ($request->is('webhook', 'instance/*/webhook') && $request->isMethod('post')) {
                return response()->json(['status' => 'error'], 500);
            }
        });
    })->create();
