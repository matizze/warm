<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The composite uniqueness below replaces the former one-conversation-
        // per-contact/group rule: the same contact can now exist per instance.
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['contact_id']);
            $table->dropUnique(['group_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('instance_id')->nullable()->after('id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('instance_id')->nullable()->after('conversation_id');
        });

        // Every row that predates multi-instance support belongs to the
        // default instance.
        $default = DB::table('instances')->where('is_default', true)->value('instance_id');
        if ($default !== null) {
            DB::table('conversations')->whereNull('instance_id')->update(['instance_id' => $default]);
            DB::table('messages')->whereNull('instance_id')->update(['instance_id' => $default]);
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('instance_id')->nullable(false)->change();
            $table->foreign('instance_id')->references('instance_id')->on('instances');
            $table->unique(['contact_id', 'instance_id']);
            $table->unique(['group_id', 'instance_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('instance_id')->nullable(false)->change();
            $table->foreign('instance_id')->references('instance_id')->on('instances');
            $table->index('instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['contact_id', 'instance_id']);
            $table->dropUnique(['group_id', 'instance_id']);
            $table->dropForeign(['instance_id']);
            $table->dropColumn('instance_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['instance_id']);
            $table->dropForeign(['instance_id']);
            $table->dropColumn('instance_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique('contact_id');
            $table->unique('group_id');
        });
    }
};
