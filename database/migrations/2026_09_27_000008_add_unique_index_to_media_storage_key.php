<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deterministic storage keys make media downloads resumable.
        Schema::table('media', function (Blueprint $table) {
            $table->unique('storage_key');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropUnique(['storage_key']);
        });
    }
};
