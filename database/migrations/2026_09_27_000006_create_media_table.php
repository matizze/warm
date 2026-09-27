<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: an upload may happen before any message exists.
            $table->foreignUuid('message_id')->nullable()->constrained('messages');
            $table->string('type');
            $table->string('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('storage_key');
            $table->text('extracted_text')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
