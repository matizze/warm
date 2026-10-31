<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null marks an unidentified client: the entry is kept as pending.
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('instance_id');
            $table->foreignUuid('source_message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignUuid('author_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('content')->nullable();
            $table->string('status');
            $table->timestamp('occurred_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // The source message is the idempotency key: reprocessing the same
            // audio never creates a second entry.
            $table->unique('source_message_id');
            $table->foreign('instance_id')->references('instance_id')->on('instances');
            $table->index(['client_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_entries');
    }
};
