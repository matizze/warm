<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Lexical search uses ILIKE on search_text; the tsvector GIN index was
        // never queried, so it only added write overhead.
        DB::statement('DROP INDEX IF EXISTS idx_messages_search_text');
    }

    public function down(): void
    {
        DB::statement(
            "CREATE INDEX idx_messages_search_text ON messages USING gin (to_tsvector('portuguese', coalesce(search_text, '')))"
        );
    }
};
