<?php

use App\Actions\Search\AdminReindex;
use App\Actions\System\HealthCheck;
use App\Actions\Webhook\ReceiveWebhook;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheck::class);

Route::post('/webhook', ReceiveWebhook::class)->middleware('webhook.auth');

Route::post('/admin/reindex', AdminReindex::class)->middleware('admin.auth');
