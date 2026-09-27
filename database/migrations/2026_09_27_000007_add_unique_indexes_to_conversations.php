<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropIndex(['group_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique('contact_id');
            $table->unique('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['contact_id']);
            $table->dropUnique(['group_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('contact_id');
            $table->index('group_id');
        });
    }
};
