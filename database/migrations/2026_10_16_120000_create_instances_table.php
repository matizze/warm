<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instances', function (Blueprint $table) {
            $table->string('instance_id')->primary();
            $table->string('evolution_url');
            $table->string('api_key');
            $table->string('webhook_token');
            $table->string('mcp_token')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('is_default');
        });

        // The single instance this installation used so far (env-driven) becomes
        // the default instance, so existing data and the legacy routes keep
        // working without an operator having to register anything.
        $now = now();

        DB::table('instances')->insertOrIgnore([
            'instance_id' => (string) config('warm.evolution.instance', 'instance1'),
            'evolution_url' => (string) config('warm.evolution.url', ''),
            'api_key' => (string) config('warm.evolution.api_key', ''),
            'webhook_token' => (string) config('warm.webhook_token', ''),
            'mcp_token' => ((string) config('warm.mcp_token', '')) ?: null,
            'is_default' => true,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('instances');
    }
};
