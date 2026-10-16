<?php

use App\Actions\Search\AdminReindex;
use App\Actions\System\HealthCheck;
use App\Actions\Webhook\ReceiveWebhook;
use App\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheck::class);

// Per-instance surfaces: the path names the Evolution instance and the token
// is the one stored for it.
Route::post('/instance/{instance}/webhook', ReceiveWebhook::class)
    ->middleware(['instance.resolve', 'webhook.auth'])
    ->name('instance.webhook');

Route::post('/instance/{instance}/mcp', McpController::class)
    ->middleware(['instance.resolve', 'mcp.auth'])
    ->name('instance.mcp');

// Legacy single-instance routes route to the default instance.
Route::post('/webhook', ReceiveWebhook::class)->middleware('webhook.auth');

Route::post('/admin/reindex', AdminReindex::class)->middleware('admin.auth');

Route::post('/mcp', McpController::class)->middleware('mcp.auth');
