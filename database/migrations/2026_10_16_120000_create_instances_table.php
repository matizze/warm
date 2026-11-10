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

        // Upgrade path for installations that predate multi-instance support:
        // the instance they configured through env becomes the default one, so
        // existing data and the legacy routes keep working. Fresh installs set
        // no EVOLUTION_INSTANCE_ID and register instances with
        // `warm:instance:create` instead. env() is read directly because these
        // keys are no longer part of the config.
        $instanceId = (string) env('EVOLUTION_INSTANCE_ID', '');

        if ($instanceId === '') {
            return;
        }

        $now = now();

        DB::table('instances')->insertOrIgnore([
            'instance_id' => $instanceId,
            'evolution_url' => rtrim((string) env('EVOLUTION_URL', ''), '/'),
            'api_key' => (string) env('EVOLUTION_API_KEY', ''),
            'webhook_token' => (string) env('WEBHOOK_TOKEN', ''),
            'mcp_token' => ((string) env('MCP_TOKEN', '')) ?: null,
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
