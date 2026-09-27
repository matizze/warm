<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts');
            $table->foreignUuid('group_id')->nullable()->constrained('groups');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('contact_id');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
