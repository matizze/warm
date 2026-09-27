<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            'webhook.auth' => AuthenticateWebhook::class,
            'admin.auth' => AuthenticateAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The webhook contract is always JSON: any exception while handling a
        // payload is reported centrally (with Log::withContext context) and
        // rendered as a generic provider-facing error the sender can retry.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('webhook') && $request->isMethod('post')) {
                return response()->json(['status' => 'error'], 500);
            }
        });
    })->create();
