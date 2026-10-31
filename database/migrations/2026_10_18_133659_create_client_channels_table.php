<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable on purpose: a channel can be declared as a progress
            // channel before the client it serves is known; its audio then
            // lands as a pending entry instead of being dropped.
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('role');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // A conversation is the reversible link to at most one client:
            // relinking or deactivating this row stops new entries for it.
            $table->unique('conversation_id');
            $table->index(['client_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_channels');
    }
};
