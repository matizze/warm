<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instance credentials move from plain text to the `encrypted` cast (APP_KEY).
 * Ciphertext outgrows varchar(255), so the columns become text first.
 */
return new class extends Migration
{
    private const COLUMNS = ['api_key', 'webhook_token', 'mcp_token'];

    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->text('api_key')->change();
            $table->text('webhook_token')->change();
            $table->text('mcp_token')->nullable()->change();
        });

        $this->transform(fn (string $value): string => Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->transform(fn (string $value): string => Crypt::decryptString($value));

        Schema::table('instances', function (Blueprint $table) {
            $table->string('api_key')->change();
            $table->string('webhook_token')->change();
            $table->string('mcp_token')->nullable()->change();
        });
    }

    /**
     * @param  Closure(string): string  $transform
     */
    private function transform(Closure $transform): void
    {
        DB::table('instances')->orderBy('instance_id')->get()->each(function (object $row) use ($transform): void {
            $values = [];

            foreach (self::COLUMNS as $column) {
                $values[$column] = $row->{$column} === null ? null : $transform((string) $row->{$column});
            }

            DB::table('instances')->where('instance_id', $row->instance_id)->update($values);
        });
    }
};
