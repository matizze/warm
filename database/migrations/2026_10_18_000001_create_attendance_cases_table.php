<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Reuses the existing client<->instance<->conversation mapping:
            // a case is anchored to the conversation (and thus the instance and
            // the contact) the customer wrote from.
            $table->foreignUuid('conversation_id')->constrained('conversations');
            $table->string('instance_id');
            $table->foreign('instance_id')->references('instance_id')->on('instances');
            $table->foreignUuid('message_id')->nullable()->constrained('messages');
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts');
            $table->text('question');
            $table->string('decision');
            $table->text('answer')->nullable();
            $table->float('confidence')->nullable();
            $table->jsonb('knowledge')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->boolean('sent')->default(false);
            $table->string('sent_provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // One case per inbound message: reprocessing the same question
            // (queue retry) must not create a second case or a second reply.
            $table->unique('message_id');
            $table->index(['instance_id', 'decision']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_cases');
    }
};
