<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations');
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('direction');
            $table->string('type');
            $table->foreignUuid('sender_contact_id')->nullable()->constrained('contacts');
            $table->text('content')->nullable();
            $table->text('search_text')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_contact_id');
        });

        $dimensions = (int) config('warm.embeddings.dimensions', 1536);

        DB::statement("ALTER TABLE messages ADD COLUMN embedding vector({$dimensions})");
        DB::statement('CREATE INDEX idx_messages_embedding ON messages USING hnsw (embedding vector_cosine_ops)');
        DB::statement(
            "CREATE INDEX idx_messages_search_text ON messages USING gin (to_tsvector('portuguese', coalesce(search_text, '')))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
